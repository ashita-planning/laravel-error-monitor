<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests;

use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [ErrorMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        // Locks come from the array store so the command tests do not depend
        // on the cache table of the host application.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('error-monitor.timezone', 'UTC');
        // The bundled Laravel log driver is registered by default. Point it at
        // a directory that does not exist so a stray log in the Testbench
        // skeleton cannot influence a run; tests that want it point it at
        // tests/Fixtures themselves.
        // A file inside a directory that does not exist: the collector resolves
        // a file path to its directory, so the parent has to be missing too.
        $app['config']->set('error-monitor.laravel_log_path', __DIR__.'/Fixtures/no-logs/laravel.log');
        // The Apache default points at /var/log/apache2, which exists on a CI
        // runner and would put real system logs into a test run.
        $app['config']->set('error-monitor.apache_access_log_path', __DIR__.'/Fixtures/no-logs/access.log');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
