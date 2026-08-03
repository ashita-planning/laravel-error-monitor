<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor;

use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Commands\StatusErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseErrorEventRepository;
use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use Illuminate\Support\ServiceProvider;

final class ErrorMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-monitor.php', 'error-monitor');

        $this->app->singleton(LogNormalizer::class, DefaultLogNormalizer::class);
        $this->app->singleton(SensitiveDataMasker::class, DefaultSensitiveDataMasker::class);
        $this->app->singleton(FingerprintGenerator::class, Sha256FingerprintGenerator::class);
        $this->app->singleton(ErrorEventRepository::class, DatabaseErrorEventRepository::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/error-monitor.php' => config_path('error-monitor.php'),
        ], 'error-monitor-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeErrorMonitorCommand::class,
                StatusErrorMonitorCommand::class,
            ]);
        }
    }
}
