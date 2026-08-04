<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Tests\Doubles\ArrayLogCollector;
use Apkk\LaravelErrorMonitor\Tests\Doubles\ArrayLogParser;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class AnalyzeCommandTest extends TestCase
{
    public function test_it_reports_that_the_bundled_driver_matched_no_file(): void
    {
        $this->artisan('error-monitor:analyze')
            ->expectsOutputToContain('Analysis completed')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_NO_LOGS);
    }

    public function test_it_analyzes_a_real_laravel_log_file(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-03'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);

        // The fixture holds four ERROR entries; only the query exception is a
        // server error, the two 404s and the INFO line are not stored.
        $this->assertSame(1, ErrorMonitorEvent::query()->count());

        $stored = ErrorMonitorEvent::query()->firstOrFail();

        $this->assertSame('laravel', $stored->source);
        $this->assertSame('production', $stored->environment);
        $this->assertSame('Illuminate\Database\QueryException', $stored->exception_class);
        $this->assertSame(500, $stored->status_code);
        $this->assertSame('2026-08-03', $stored->detected_date->format('Y-m-d'));
        $this->assertSame(64, strlen((string) $stored->fingerprint));
        // The 500 was assumed, and the record has to say so.
        $this->assertSame('assumed', $stored->metadata['status_source'] ?? null);
    }

    public function test_reanalyzing_the_same_laravel_log_stores_nothing_new(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-03'])->run();
        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-03'])->run();

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
        $this->assertSame(1, ErrorMonitorEvent::query()->firstOrFail()->occurrence_count);
    }

    public function test_it_refuses_to_run_when_the_package_is_disabled(): void
    {
        config()->set('error-monitor.enabled', false);

        $this->artisan('error-monitor:analyze')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);
    }

    public function test_it_refuses_contradictory_period_options(): void
    {
        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-03', '--from' => '2026-08-03 00:00:00'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);

        $this->artisan('error-monitor:analyze', ['--from' => '2026-08-03 00:00:00'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);

        $this->artisan('error-monitor:analyze', ['--date' => 'not-a-date'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);
    }

    public function test_it_reports_when_the_collectors_matched_no_file(): void
    {
        $this->registerDrivers(files: []);

        $this->artisan('error-monitor:analyze')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_NO_LOGS);
    }

    public function test_it_refuses_to_run_twice_for_the_same_period(): void
    {
        $lock = $this->app->make('cache')->store()->getStore()->lock(
            'error-monitor:analyze:'.md5('all|all'),
            60,
        );

        $this->assertTrue($lock->get());

        try {
            $this->artisan('error-monitor:analyze')
                ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_ALREADY_RUNNING);
        } finally {
            $lock->release();
        }
    }

    public function test_it_stores_the_events_of_the_requested_day_only(): void
    {
        $this->registerDrivers();

        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-04'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame(0, ErrorMonitorEvent::query()->count());

        $this->artisan('error-monitor:analyze', ['--date' => '2026-08-03'])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
    }

    public function test_it_accepts_an_explicit_period(): void
    {
        $this->registerDrivers();

        $this->artisan('error-monitor:analyze', [
            '--from' => '2026-08-03 10:00:00',
            '--to' => '2026-08-03 11:00:00',
        ])->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->registerDrivers();

        $this->artisan('error-monitor:analyze', ['--dry-run' => true])
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }

    public function test_it_can_output_json(): void
    {
        $this->registerDrivers();

        $this->artisan('error-monitor:analyze', ['--json' => true])
            ->expectsOutputToContain('"events_stored": 1')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_SUCCESS);
    }

    /** Point the bundled Laravel driver at the fixture log. */
    private function useFixtureLogs(): void
    {
        config()->set('error-monitor.laravel_log_path', dirname(__DIR__).'/Fixtures');

        // The analyzer is a singleton holding the already resolved collector.
        $this->app->forgetInstance(ErrorMonitorAnalyzer::class);
    }

    /** Register the fake log drivers under the container tags the provider reads. */
    private function registerDrivers(?array $files = null): void
    {
        // A source of its own, so the bundled Laravel parser does not claim the
        // fake file and try to read a path that is not there.
        $files ??= [new LogFileData('/var/log/tests.log', 'tests')];

        $this->app->bind('tests.collector', fn (): ArrayLogCollector => new ArrayLogCollector($files));
        $this->app->bind('tests.parser', fn (): ArrayLogParser => new ArrayLogParser([$this->event()]));
        $this->app->tag(['tests.collector'], ErrorMonitorServiceProvider::COLLECTOR_TAG);
        $this->app->tag(['tests.parser'], ErrorMonitorServiceProvider::PARSER_TAG);

        // The analyzer is a singleton resolved with the tagged drivers.
        $this->app->forgetInstance(ErrorMonitorAnalyzer::class);
    }

    private function event(): ErrorEventData
    {
        return new ErrorEventData(
            environment: 'production',
            source: 'tests',
            occurredAt: new DateTimeImmutable('2026-08-03 10:20:30'),
            exceptionClass: 'RuntimeException',
            message: 'Order id=1201 failed',
            normalizedMessage: 'Order id=1201 failed',
            file: '/var/www/app/Services/OrderService.php',
            line: 44,
            method: 'POST',
            route: '/orders/1201',
            statusCode: 500,
            stackFrames: [new StackFrameData('/var/www/app/Services/OrderService.php', 44, 'OrderService', 'charge', '->', true)],
            fingerprint: '',
        );
    }
}
