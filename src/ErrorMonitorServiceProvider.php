<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor;

use Apkk\LaravelErrorMonitor\Collectors\ApacheAccessLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\ApacheErrorLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\ServerLogSourceCollector;
use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Commands\RunErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Commands\StatusErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use Apkk\LaravelErrorMonitor\Parsers\ApacheAccessLogParser;
use Apkk\LaravelErrorMonitor\Parsers\ApacheErrorLogParser;
use Apkk\LaravelErrorMonitor\Parsers\LaravelLogParser;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseErrorEventRepository;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseIssueLinkRepository;
use Apkk\LaravelErrorMonitor\Services\ApacheLaravelCorrelationService;
use Apkk\LaravelErrorMonitor\Services\DailyErrorMonitorRunner;
use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Services\LogSourceRegistry;
use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\HttpStatusResolver;
use Apkk\LaravelErrorMonitor\Support\ServerErrorClassifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Package entry point.
 *
 * Merges and publishes the configuration, loads the migrations, registers the
 * Artisan commands and binds every contract to its default implementation.
 *
 * A disabled package stays cheap: migrations are not loaded and the log drivers
 * are left untagged, so nothing is collected, while the commands and bindings
 * remain available so `error-monitor:status` can still explain what is off.
 */
final class ErrorMonitorServiceProvider extends ServiceProvider
{
    /** Container tag additional collectors are registered under. */
    public const COLLECTOR_TAG = 'error-monitor.collectors';

    /** Container tag additional parsers are registered under. */
    public const PARSER_TAG = 'error-monitor.parsers';

    /**
     * Container tag external log sources are registered under.
     *
     * An adapter package tags its {@see ServerLogSource} here and needs nothing
     * else; the core discovers it, and works exactly as before when none is
     * installed.
     */
    public const SERVER_LOG_SOURCE_TAG = 'error-monitor.server-log-sources';

    private const CONFIG_PATH = __DIR__.'/../config/error-monitor.php';

    private const MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'error-monitor');

        $this->registerLaravelLogDriver();
        $this->registerApacheAccessLogDriver();
        $this->registerApacheErrorLogDriver();
        // Last, so the bundled drivers keep their order and an adapter only
        // ever adds to the end of the list.
        $this->registerServerLogSources();

        $this->app->singleton(LogNormalizer::class, DefaultLogNormalizer::class);
        $this->app->singleton(SensitiveDataMasker::class, DefaultSensitiveDataMasker::class);
        $this->app->singleton(FingerprintGenerator::class, Sha256FingerprintGenerator::class);
        $this->app->singleton(ErrorEventRepository::class, DatabaseErrorEventRepository::class);
        $this->app->singleton(IssueLinkRepository::class, DatabaseIssueLinkRepository::class);

        $this->app->singleton(ErrorMonitorAnalyzer::class, function (Application $app): ErrorMonitorAnalyzer {
            $enabled = (bool) $app->make('config')->get('error-monitor.enabled', true);

            return new ErrorMonitorAnalyzer(
                masker: $app->make(SensitiveDataMasker::class),
                normalizer: $app->make(LogNormalizer::class),
                fingerprintGenerator: $app->make(FingerprintGenerator::class),
                repository: $app->make(ErrorEventRepository::class),
                collectors: $enabled ? $this->tagged($app, self::COLLECTOR_TAG, LogCollector::class) : [],
                parsers: $enabled ? $this->tagged($app, self::PARSER_TAG, LogParser::class) : [],
            );
        });

        $this->app->singleton(DailyErrorMonitorRunner::class, function (Application $app): DailyErrorMonitorRunner {
            $enabled = (bool) $app->make('config')->get('error-monitor.enabled', true);

            return new DailyErrorMonitorRunner(
                analyzer: $app->make(ErrorMonitorAnalyzer::class),
                correlation: $app->make(ApacheLaravelCorrelationService::class),
                repository: $app->make(ErrorEventRepository::class),
                collectors: $enabled ? $this->tagged($app, self::COLLECTOR_TAG, LogCollector::class) : [],
                parsers: $enabled ? $this->tagged($app, self::PARSER_TAG, LogParser::class) : [],
                // Resolved only when an adapter package has bound the contract:
                // this package ships no publisher and performs no outbound call.
                publisher: $app->bound(IssuePublisher::class) ? $app->make(IssuePublisher::class) : null,
                links: $app->make(IssueLinkRepository::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('error-monitor.php'),
        ], 'error-monitor-config');

        $this->publishes([
            self::MIGRATIONS_PATH => database_path('migrations'),
        ], 'error-monitor-migrations');

        if ((bool) $this->app->make('config')->get('error-monitor.enabled', true)) {
            $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeErrorMonitorCommand::class,
                RunErrorMonitorCommand::class,
                StatusErrorMonitorCommand::class,
            ]);
        }
    }

    /**
     * Bind the registry of external log sources and the collector fronting it.
     *
     * Both exist whether or not an adapter is installed: an empty registry
     * simply yields no files, which is the normal state of a core-only install.
     */
    private function registerServerLogSources(): void
    {
        $this->app->singleton(LogSourceRegistry::class, function (Application $app): LogSourceRegistry {
            return new LogSourceRegistry($this->tagged($app, self::SERVER_LOG_SOURCE_TAG, ServerLogSource::class));
        });

        $this->app->bind(
            ServerLogSourceCollector::class,
            static fn (Application $app): ServerLogSourceCollector => new ServerLogSourceCollector(
                $app->make(LogSourceRegistry::class),
            ),
        );

        $this->app->tag([ServerLogSourceCollector::class], self::COLLECTOR_TAG);
    }

    /**
     * Bind the Laravel log driver and register it under the container tags.
     *
     * Registration is unconditional and lazy: `enabled` is not readable yet
     * while providers register, and a disabled package is already handled where
     * the analyzer resolves the tags - it then receives no driver at all.
     */
    private function registerLaravelLogDriver(): void
    {
        $this->app->bind(LaravelLogCollector::class, function (Application $app): LaravelLogCollector {
            $config = $app->make('config');

            /** @var array<int, string> $patterns */
            $patterns = (array) $config->get('error-monitor.laravel_log_patterns', []);

            return new LaravelLogCollector(
                path: (string) $config->get('error-monitor.laravel_log_path', ''),
                patterns: array_values($patterns),
                maxFiles: (int) $config->get('error-monitor.laravel_log_max_files', 31),
                maxBytes: (int) $config->get('error-monitor.laravel_log_max_bytes', 536870912),
            );
        });

        $this->app->bind(LaravelLogParser::class, function (Application $app): LaravelLogParser {
            $config = $app->make('config');

            /** @var array<int, string> $applicationPaths */
            $applicationPaths = (array) $config->get('error-monitor.fingerprint.application_paths', []);
            /** @var array<int, string> $vendorPaths */
            $vendorPaths = (array) $config->get('error-monitor.fingerprint.vendor_paths', []);
            /** @var array<int, string> $levels */
            $levels = (array) $config->get('error-monitor.laravel_log_levels', []);

            return new LaravelLogParser(
                frameDetector: new ApplicationFrameDetector(
                    applicationPaths: array_values($applicationPaths),
                    vendorPaths: array_values($vendorPaths),
                ),
                statusResolver: new HttpStatusResolver,
                timezone: (string) $config->get('error-monitor.timezone', 'UTC'),
                levels: array_values($levels),
                // No override: a Laravel entry states its own environment,
                // which is more precise than the configured one when several
                // environments write into the same directory.
                environment: null,
            );
        });

        $this->app->tag([LaravelLogCollector::class], self::COLLECTOR_TAG);
        $this->app->tag([LaravelLogParser::class], self::PARSER_TAG);
    }

    /**
     * Bind the Apache access log driver and the correlation service.
     *
     * Tagged like the Laravel driver, so an installation with Apache logs in
     * reach analyses both sources without any wiring of its own.
     */
    private function registerApacheAccessLogDriver(): void
    {
        $this->app->bind(ApacheAccessLogCollector::class, function (Application $app): ApacheAccessLogCollector {
            $config = $app->make('config');

            /** @var array<int, string> $patterns */
            $patterns = (array) $config->get('error-monitor.apache_access_log_patterns', []);

            return new ApacheAccessLogCollector(
                path: (string) $config->get('error-monitor.apache_access_log_path', ''),
                patterns: array_values($patterns),
                maxFiles: (int) $config->get('error-monitor.laravel_log_max_files', 31),
                maxBytes: (int) $config->get('error-monitor.laravel_log_max_bytes', 536870912),
            );
        });

        $this->app->bind(ApacheAccessLogParser::class, function (Application $app): ApacheAccessLogParser {
            $config = $app->make('config');

            /** @var array<int, string> $statuses */
            $statuses = (array) $config->get('error-monitor.apache_access_status_codes', []);
            /** @var array<int, string> $patterns */
            $patterns = (array) $config->get('error-monitor.apache_access_patterns', []);

            return new ApacheAccessLogParser(
                timezone: (string) $config->get('error-monitor.timezone', 'UTC'),
                statusRanges: $this->statusRanges($statuses),
                patterns: array_values($patterns),
                // An access log states no environment, so the configured one is
                // all there is.
                environment: (string) $config->get('error-monitor.environment', 'production'),
            );
        });

        $this->app->singleton(ApacheLaravelCorrelationService::class, function (Application $app): ApacheLaravelCorrelationService {
            return new ApacheLaravelCorrelationService(
                normalizer: $app->make(LogNormalizer::class),
                windowSeconds: (int) $app->make('config')->get('error-monitor.correlation.window_seconds', 5),
            );
        });

        $this->app->tag([ApacheAccessLogCollector::class], self::COLLECTOR_TAG);
        $this->app->tag([ApacheAccessLogParser::class], self::PARSER_TAG);
    }

    /**
     * Bind the Apache error log driver.
     *
     * Registered separately from the access log driver: an installation may
     * have one of the two readable and not the other, and the error log is the
     * one that holds the failures which never reached the application.
     */
    private function registerApacheErrorLogDriver(): void
    {
        $this->app->bind(ApacheErrorLogCollector::class, function (Application $app): ApacheErrorLogCollector {
            $config = $app->make('config');

            /** @var array<int, string> $patterns */
            $patterns = (array) $config->get('error-monitor.apache_error_log_patterns', []);

            return new ApacheErrorLogCollector(
                path: (string) $config->get('error-monitor.apache_error_log_path', ''),
                patterns: array_values($patterns),
                maxFiles: (int) $config->get('error-monitor.laravel_log_max_files', 31),
                maxBytes: (int) $config->get('error-monitor.laravel_log_max_bytes', 536870912),
            );
        });

        $this->app->bind(ApacheErrorLogParser::class, function (Application $app): ApacheErrorLogParser {
            $config = $app->make('config');

            /** @var array<int, string> $applicationPaths */
            $applicationPaths = (array) $config->get('error-monitor.fingerprint.application_paths', []);
            /** @var array<int, string> $vendorPaths */
            $vendorPaths = (array) $config->get('error-monitor.fingerprint.vendor_paths', []);
            /** @var array<int, string> $levels */
            $levels = (array) $config->get('error-monitor.apache_error_log_levels', []);

            return new ApacheErrorLogParser(
                frameDetector: new ApplicationFrameDetector(
                    applicationPaths: array_values($applicationPaths),
                    vendorPaths: array_values($vendorPaths),
                ),
                classifier: new ServerErrorClassifier,
                timezone: (string) $config->get('error-monitor.timezone', 'UTC'),
                levels: array_values($levels),
                environment: (string) $config->get('error-monitor.environment', 'production'),
            );
        });

        $this->app->tag([ApacheErrorLogCollector::class], self::COLLECTOR_TAG);
        $this->app->tag([ApacheErrorLogParser::class], self::PARSER_TAG);
    }

    /**
     * Turn `500-599` / `502` entries into inclusive ranges.
     *
     * @param  array<int, string>  $statuses
     * @return array<int, array{0: int, 1: int}>
     */
    private function statusRanges(array $statuses): array
    {
        $ranges = [];

        foreach ($statuses as $status) {
            $status = trim((string) $status);

            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $status, $matches) === 1) {
                $ranges[] = [(int) $matches[1], (int) $matches[2]];

                continue;
            }

            if (ctype_digit($status)) {
                $ranges[] = [(int) $status, (int) $status];
            }
        }

        return $ranges;
    }

    /**
     * Resolve every service registered under a container tag.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $type
     * @return array<int, TService>
     */
    private function tagged(Application $app, string $tag, string $type): array
    {
        $services = [];

        foreach ($app->tagged($tag) as $service) {
            if ($service instanceof $type) {
                $services[] = $service;
            }
        }

        return $services;
    }
}
