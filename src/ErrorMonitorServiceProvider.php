<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor;

use AshitaPlanning\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use AshitaPlanning\LaravelErrorMonitor\Commands\StatusErrorMonitorCommand;
use AshitaPlanning\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use AshitaPlanning\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use AshitaPlanning\LaravelErrorMonitor\Contracts\LogNormalizer;
use AshitaPlanning\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use AshitaPlanning\LaravelErrorMonitor\Repositories\DatabaseErrorEventRepository;
use AshitaPlanning\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use AshitaPlanning\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use AshitaPlanning\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
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
