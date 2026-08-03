<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

final class StatusCommandTest extends TestCase
{
    public function test_it_reports_the_configuration_and_the_storage_state(): void
    {
        $this->artisan('error-monitor:status')
            ->expectsOutputToContain('Enabled')
            ->expectsOutputToContain('Environment')
            ->expectsOutputToContain('Timezone')
            ->expectsOutputToContain('Laravel log path')
            ->expectsOutputToContain('error_monitor_events table found')
            ->expectsOutputToContain('Registered events')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }

    public function test_it_counts_the_registered_events(): void
    {
        ErrorMonitorEvent::query()->create([
            'environment' => 'production',
            'source' => 'laravel',
            'fingerprint' => str_repeat('a', 64),
            'detected_date' => '2026-08-03',
            'first_occurred_at' => '2026-08-03 10:00:00',
            'last_occurred_at' => '2026-08-03 10:00:00',
            'occurrence_count' => 3,
            'exception_class' => 'RuntimeException',
            'normalized_message' => 'Synthetic fixture message',
            'payload_hash' => str_repeat('b', 64),
        ]);

        $this->artisan('error-monitor:status')
            ->expectsOutputToContain('Registered events')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }

    public function test_it_never_prints_the_github_token(): void
    {
        config()->set('error-monitor.github.token', 'fake-token-value-should-not-be-printed');

        $this->artisan('error-monitor:status')
            ->doesntExpectOutputToContain('fake-token-value-should-not-be-printed')
            ->expectsOutputToContain('Configured')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }

    public function test_it_can_output_json(): void
    {
        $this->artisan('error-monitor:status', ['--json' => true])
            ->expectsOutputToContain('"Timezone": "UTC"')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }

    public function test_it_still_reports_a_disabled_package(): void
    {
        config()->set('error-monitor.enabled', false);

        $this->artisan('error-monitor:status')
            ->expectsOutputToContain('Enabled')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }
}
