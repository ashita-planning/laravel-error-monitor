<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor;

use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Commands\StatusErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Parsers\LaravelLogParser;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseErrorEventRepository;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseIssueLinkRepository;
use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\HttpStatusResolver;
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

    private const CONFIG_PATH = __DIR__.'/../config/error-monitor.php';

    private const MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'error-monitor');

        $this->registerLaravelLogDriver();

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
                StatusErrorMonitorCommand::class,
            ]);
        }
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
