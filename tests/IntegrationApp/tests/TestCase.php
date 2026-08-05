<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Integration\Tests;

use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitorGithub\Contracts\Sleeper as GithubSleeper;
use Apkk\LaravelErrorMonitorGithub\GithubErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitorXserver\XserverLogSourceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * An application with all three packages installed at once.
 *
 * The packages are wired together here and nowhere else: the core does not
 * depend on the adapters, and neither adapter depends on the other. This is the
 * only place that proves the three of them agree about the contracts between
 * them, which is exactly what unit tests in each repository cannot show.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const REPOSITORY = 'acme/shop';

    protected const DOMAIN = 'shop.example.invalid';

    protected const SERVER_ID = 'sv00000';

    /** The day everything in the fixtures happened. */
    protected const DAY = '2026-08-03';

    /** Distinctive enough that a leak is unmistakable. */
    protected const TOKEN = 'ghp_INTEGRATIONtokenMUSTneverLEAK01';

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        // The GitHub provider decides at register() time whether to bind the
        // publisher at all, so its switch must be on before any provider
        // registers - defineEnvironment() is too late for that.
        $app['config']->set('error-monitor-github.enabled', true);

        return [
            ErrorMonitorServiceProvider::class,
            XserverLogSourceServiceProvider::class,
            GithubErrorMonitorServiceProvider::class,
        ];
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
        $app['config']->set('cache.default', 'array');

        // Everything is Japan time, because that is what the server writes and
        // what the XServer file names mean.
        $app['config']->set('error-monitor.timezone', 'Asia/Tokyo');
        $app['config']->set('error-monitor.laravel_log_path', $this->fixturePath('storage/logs'));
        $app['config']->set('error-monitor.laravel_log_patterns', ['laravel.log']);
        // Apache logs arrive through the XServer adapter, not from a local path.
        $app['config']->set('error-monitor.apache_access_log_path', $this->fixturePath('no-such-directory/access.log'));
        $app['config']->set('error-monitor.apache_error_log_path', $this->fixturePath('no-such-directory/error.log'));
        // Gateway errors are worth keeping in an integration run.
        $app['config']->set('error-monitor.status_codes', [500, 502, 503, 504]);

        $app['config']->set('error-monitor-xserver.enabled', true);
        $app['config']->set('error-monitor-xserver.server_id', self::SERVER_ID);
        $app['config']->set('error-monitor-xserver.domains', [self::DOMAIN]);
        $app['config']->set('error-monitor-xserver.log_base_path', $this->fixturePath('home/{server_id}/{domain}/log'));

        $app['config']->set('error-monitor-github.enabled', true);
        $app['config']->set('error-monitor-github.repository', self::REPOSITORY);
        $app['config']->set('error-monitor-github.token', self::TOKEN);

        // Waits are not taken during a test run.
        $app->instance(GithubSleeper::class, new class implements GithubSleeper
        {
            public function sleep(int $milliseconds): void {}
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/ashita-planning/laravel-error-monitor/database/migrations');
    }

    protected function fixturePath(string $suffix = ''): string
    {
        return rtrim(dirname(__DIR__).'/fixtures/'.$suffix, '/');
    }
}
