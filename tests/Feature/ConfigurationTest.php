<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseIssueLinkRepository;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_it_merges_every_documented_section(): void
    {
        $this->assertTrue(config('error-monitor.enabled'));
        $this->assertIsString(config('error-monitor.environment'));
        $this->assertSame([500], config('error-monitor.status_codes'));
        $this->assertIsArray(config('error-monitor.laravel_log_patterns'));
        $this->assertSame(1800, config('error-monitor.analysis.context_before_seconds'));
        $this->assertSame(900, config('error-monitor.analysis.lock_seconds'));
        $this->assertIsArray(config('error-monitor.masking.remove_headers'));
        $this->assertIsArray(config('error-monitor.masking.mask_keys'));
        $this->assertSame('{secret}', config('error-monitor.masking.replacement_tokens.secret'));
        $this->assertSame(3, config('error-monitor.fingerprint.stack_frame_limit'));
        $this->assertTrue(config('error-monitor.fingerprint.include_route'));
        $this->assertFalse(config('error-monitor.github.enabled'));
        $this->assertArrayHasKey('token', config('error-monitor.github'));
    }

    public function test_environment_variables_override_the_defaults(): void
    {
        putenv('ERROR_MONITOR_STATUS_CODES=500,502');
        putenv('ERROR_MONITOR_STACK_FRAME_LIMIT=5');

        try {
            $config = require __DIR__.'/../../config/error-monitor.php';

            $this->assertSame([500, 502], $config['status_codes']);
            $this->assertSame(5, $config['fingerprint']['stack_frame_limit']);
        } finally {
            putenv('ERROR_MONITOR_STATUS_CODES');
            putenv('ERROR_MONITOR_STACK_FRAME_LIMIT');
        }
    }

    public function test_it_binds_the_issue_link_repository(): void
    {
        $this->assertInstanceOf(DatabaseIssueLinkRepository::class, app(IssueLinkRepository::class));
    }

    public function test_a_disabled_package_analyzes_nothing(): void
    {
        config()->set('error-monitor.enabled', false);

        $result = app(ErrorMonitorAnalyzer::class)->analyze();

        $this->assertSame(0, $result->filesAnalyzed);
        $this->assertContains('Error monitor is disabled.', $result->warnings);
    }

    public function test_the_migrations_can_be_published(): void
    {
        $published = glob($this->app->databasePath('migrations').'/*create_error_monitor_events_table.php') ?: [];

        foreach ($published as $file) {
            @unlink($file);
        }

        $this->artisan('vendor:publish', ['--tag' => 'error-monitor-migrations', '--force' => true])->assertExitCode(0);

        $this->assertNotSame([], glob($this->app->databasePath('migrations').'/*create_error_monitor_events_table.php') ?: []);

        foreach (glob($this->app->databasePath('migrations').'/*error_monitor*.php') ?: [] as $file) {
            @unlink($file);
        }
    }
}
