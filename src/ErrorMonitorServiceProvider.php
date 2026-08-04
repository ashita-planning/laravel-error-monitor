<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor;

use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Commands\StatusErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseErrorEventRepository;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseIssueLinkRepository;
use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Package entry point.
 *
 * Merges and publishes the configuration, loads the migrations, registers the
 * Artisan commands and binds every contract to its default implementation.
 *
 * A disabled package stays cheap: migrations are not loaded and no log driver
 * is resolved, while the commands and bindings remain available so
 * `error-monitor:status` can still explain what is turned off.
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
