<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Commands\RunErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Parsers\ApacheErrorLogParser;
use Apkk\LaravelErrorMonitor\Services\DailyErrorMonitorRunner;
use Apkk\LaravelErrorMonitor\Tests\Doubles\RecordingIssuePublisher;
use Apkk\LaravelErrorMonitor\Tests\Doubles\ThrowingLogParser;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

/**
 * The daily command is the one a schedule runs, so what matters here is the
 * whole path: three sources, correlation, storage, publishing and pruning.
 */
final class RunCommandTest extends TestCase
{
    private const DAY = '2026-08-03';

    public function test_it_analyzes_every_source_in_one_run(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);

        $sources = ErrorMonitorEvent::query()->pluck('source')->unique()->sort()->values()->all();

        $this->assertSame(['apache_access', 'apache_error', 'laravel'], $sources);
    }

    public function test_it_reports_each_source_separately(): void
    {
        $this->useFixtureLogs();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertCount(3, $result->sources);
        // Laravel is processed first: its exceptions are what explain the
        // Apache entries, so they have to exist before correlation runs.
        $this->assertSame('laravel', $result->sources[0]->source);
        $this->assertSame('apache_access', $result->sources[1]->source);
        $this->assertSame('apache_error', $result->sources[2]->source);
    }

    public function test_apache_entries_outside_the_status_filter_are_detected_but_not_stored(): void
    {
        $this->useFixtureLogs();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());
        $error = $result->sources[2];

        // The 403 and the 404 the classifier derived are real entries; they are
        // simply not what `status_codes` asked for.
        $this->assertGreaterThan($error->eventsStored, $error->eventsDetected);
        $this->assertGreaterThan(0, $error->eventsSkipped);
        $this->assertSame(0, ErrorMonitorEvent::query()->whereIn('status_code', [403, 404])->count());
    }

    public function test_running_the_same_day_twice_stores_nothing_new(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();
        $before = ErrorMonitorEvent::query()->sum('occurrence_count');

        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame($before, ErrorMonitorEvent::query()->sum('occurrence_count'));
    }

    public function test_it_can_be_restricted_to_one_source(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--source' => 'laravel'])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);

        $this->assertSame(['laravel'], ErrorMonitorEvent::query()->pluck('source')->unique()->all());
    }

    public function test_a_dry_run_writes_publishes_and_prunes_nothing(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->registerPublisher();
        $stale = $this->staleEvent();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window(), dryRun: true);

        $this->assertGreaterThan(0, $result->eventsStored(), 'A dry run still reports what it would store.');
        $this->assertSame(0, ErrorMonitorEvent::query()->where('id', '!=', $stale->id)->count());
        $this->assertSame([], $publisher->published);
        $this->assertSame(0, $result->eventsPruned);
        $this->assertTrue(ErrorMonitorEvent::query()->whereKey($stale->id)->exists(), 'Retention did not run.');
    }

    public function test_it_outputs_json(): void
    {
        $this->useFixtureLogs();

        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--json' => true])
            ->expectsOutputToContain('"events_correlated"')
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);
    }

    public function test_it_ties_an_apache_five_hundred_to_the_exception_that_explains_it(): void
    {
        // The whole point of running the sources together: the access log knows
        // a request failed, the application log knows why, and only a run that
        // sees both can join them.
        $directory = $this->temporaryLogs([
            'laravel.log' => '[2026-08-03 10:00:00] production.ERROR: SQLSTATE[HY000]: General error '
                .'{"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: HY000): SQLSTATE[HY000] '
                .'at /srv/app/app/Repositories/OrderRepository.php:31)","method":"post","url":"/orders/12"}',
            'access.log' => '203.0.113.10 - - [03/Aug/2026:10:00:02 +0000] "POST /orders/99 HTTP/1.1" 500 512',
        ]);

        config()->set('error-monitor.apache_access_log_patterns', ['access.log']);
        config()->set('error-monitor.laravel_log_path', $directory);
        config()->set('error-monitor.apache_access_log_path', $directory);
        config()->set('error-monitor.apache_error_log_path', $directory.'/none');
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame(1, $result->eventsCorrelated());

        $stored = ErrorMonitorEvent::query()->where('source', 'apache_access')->firstOrFail();

        // Both routes normalize to /orders/{id} and the method agrees, which is
        // the strongest signal available without a request id.
        $this->assertSame('time_method_path', $stored->metadata['correlation_method'] ?? null);
        $this->assertSame(0.8, $stored->metadata['correlation_confidence'] ?? null);
        $this->assertSame('Illuminate\\Database\\QueryException', $stored->metadata['correlated_exception_class'] ?? null);
    }

    public function test_it_refuses_to_run_twice_for_the_same_period(): void
    {
        $window = $this->window();
        $lock = $this->app->make('cache')->store()->getStore()->lock(
            'error-monitor:run:'.md5('all|'.$window->label()),
            60,
        );

        $this->assertTrue($lock->get());

        try {
            $this->artisan('error-monitor:run', ['--date' => self::DAY])
                ->assertExitCode(RunErrorMonitorCommand::EXIT_ALREADY_RUNNING);
        } finally {
            $lock->release();
        }
    }

    public function test_it_reports_when_no_log_matched(): void
    {
        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_NO_LOGS);
    }

    public function test_it_refuses_contradictory_period_options(): void
    {
        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--from' => '2026-08-03 00:00:00'])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);

        $this->artisan('error-monitor:run', ['--from' => '2026-08-03 00:00:00'])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_INVALID_CONFIGURATION);
    }

    public function test_one_failing_source_does_not_discard_the_others(): void
    {
        $this->useFixtureLogs();
        $this->breakApacheErrorParsing();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_PARTIAL_FAILURE);

        // The two healthy sources were still stored.
        $this->assertSame(
            ['apache_access', 'laravel'],
            ErrorMonitorEvent::query()->pluck('source')->unique()->sort()->values()->all(),
        );
    }

    public function test_retention_is_skipped_while_a_source_is_failing(): void
    {
        $this->useFixtureLogs();
        $stale = $this->staleEvent();
        $this->breakApacheErrorParsing();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame(0, $result->eventsPruned);
        $this->assertTrue(ErrorMonitorEvent::query()->whereKey($stale->id)->exists());
        $this->assertContains('Retention pruning was skipped because a source failed.', $result->warnings);
    }

    public function test_it_prunes_aggregates_past_the_retention_horizon(): void
    {
        $this->useFixtureLogs();
        $stale = $this->staleEvent();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame(1, $result->eventsPruned);
        $this->assertFalse(ErrorMonitorEvent::query()->whereKey($stale->id)->exists());
    }

    public function test_the_publisher_is_used_only_when_one_is_installed(): void
    {
        $this->useFixtureLogs();

        // Nothing bound: the run must not attempt to publish anything.
        $this->assertSame(0, app(DailyErrorMonitorRunner::class)->run($this->window())->issuesPublished);
    }

    public function test_skip_github_suppresses_the_publisher(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->registerPublisher();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window(), skipPublishing: true);

        $this->assertSame(0, $result->issuesPublished);
        $this->assertSame([], $publisher->published);
    }

    public function test_an_installed_publisher_receives_every_stored_failure(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->registerPublisher();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertGreaterThan(0, $result->issuesPublished);
        $this->assertCount($result->issuesPublished, $publisher->published);
    }

    public function test_the_window_respects_the_configured_timezone(): void
    {
        config()->set('error-monitor.timezone', 'Asia/Tokyo');
        config()->set('error-monitor.analysis.context_before_seconds', 0);
        config()->set('error-monitor.analysis.context_after_seconds', 0);
        $this->useFixtureLogs();

        // 2026-08-03 in Tokyo ends at 14:59:59 UTC, so an entry logged at
        // 23:00 Tokyo belongs to that day and not to the next one.
        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame('Asia/Tokyo', $result->window?->toArray()['timezone']);
        $this->assertGreaterThan(0, $result->eventsStored());
    }

    private function window(): AnalysisWindowData
    {
        return AnalysisWindowData::forDate(
            self::DAY,
            (string) config('error-monitor.timezone', 'UTC'),
            (int) config('error-monitor.analysis.context_before_seconds', 0),
            (int) config('error-monitor.analysis.context_after_seconds', 0),
        );
    }

    /** Point every bundled driver at the synthetic fixtures. */
    private function useFixtureLogs(): void
    {
        $fixtures = dirname(__DIR__).'/Fixtures';

        config()->set('error-monitor.laravel_log_path', $fixtures);
        config()->set('error-monitor.apache_access_log_path', $fixtures);
        config()->set('error-monitor.apache_error_log_path', $fixtures);
        config()->set('error-monitor.apache_access_log_patterns', ['apache-access.log']);
        config()->set('error-monitor.apache_error_log_patterns', ['apache-error.log']);

        $this->app->forgetInstance(DailyErrorMonitorRunner::class);
    }

    /**
     * Make the Apache error log source fail.
     *
     * The tagged parser is replaced rather than joined by a second one: the
     * runner hands a file to the first parser claiming it, which would still be
     * the real one.
     */
    private function breakApacheErrorParsing(): void
    {
        $this->app->bind(ApacheErrorLogParser::class, fn (): ThrowingLogParser => new ThrowingLogParser('apache_error'));
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);
    }

    /**
     * A throwaway log directory for one test.
     *
     * @param  array<string, string>  $files
     */
    private function temporaryLogs(array $files): string
    {
        $directory = sys_get_temp_dir().'/error-monitor-run-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        foreach ($files as $name => $contents) {
            file_put_contents($directory.'/'.$name, $contents."\n");
        }

        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        });

        return $directory;
    }

    private function registerPublisher(): RecordingIssuePublisher
    {
        $publisher = new RecordingIssuePublisher;

        $this->app->instance(IssuePublisher::class, $publisher);
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);

        return $publisher;
    }

    /** An aggregate old enough for the retention policy to remove. */
    private function staleEvent(): ErrorMonitorEvent
    {
        $old = (new DateTimeImmutable('now'))->modify('-400 days');

        /** @var ErrorMonitorEvent $event */
        $event = ErrorMonitorEvent::query()->create([
            'environment' => 'production',
            'source' => 'laravel',
            'fingerprint' => str_repeat('f', 64),
            'detected_date' => $old->format('Y-m-d'),
            'first_occurred_at' => $old->format('Y-m-d H:i:s'),
            'last_occurred_at' => $old->format('Y-m-d H:i:s'),
            'exception_class' => 'RuntimeException',
            'normalized_message' => 'Long gone',
            'payload_hash' => str_repeat('e', 64),
        ]);

        return $event;
    }
}
