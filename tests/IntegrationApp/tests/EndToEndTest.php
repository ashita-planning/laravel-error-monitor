<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Integration\Tests;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use Apkk\LaravelErrorMonitor\Support\LogSource;
use Apkk\LaravelErrorMonitorGithub\Github\GithubMarkerBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The whole thing, once, with all three packages doing their real work.
 *
 * A gzip log in XServer's own naming goes in; a GitHub issue comes out. Only
 * GitHub itself is faked, because everything else - reading the logs, parsing
 * two Apache formats, correlating them with the application's exception,
 * fingerprinting, aggregating, publishing - is what is under test.
 */
final class EndToEndTest extends TestCase
{
    public function test_a_days_logs_become_one_issue_per_distinct_failure(): void
    {
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        // All three sources were read, Apache through the XServer adapter.
        $sources = ErrorMonitorEvent::query()->pluck('source')->unique()->sort()->values()->all();
        $this->assertSame([LogSource::APACHE_ACCESS, LogSource::APACHE_ERROR, LogSource::LARAVEL], $sources);

        // Five distinct failures, five issues: the invariant is one issue per
        // fingerprint, not one per run.
        $failures = ErrorMonitorEvent::query()->count();
        $this->assertSame(5, $failures);
        $this->assertSame($failures, ErrorMonitorEvent::query()->distinct()->count('fingerprint'));
        $this->assertSame($failures, $this->createCalls());
        $this->assertSame($failures, ErrorMonitorIssue::query()->count());
    }

    public function test_the_apache_five_hundred_is_tied_to_the_laravel_exception(): void
    {
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $access = ErrorMonitorEvent::query()
            ->where('source', LogSource::APACHE_ACCESS)
            ->where('route', '/orders/{id}')
            ->firstOrFail();

        // The two entries describe the same request at the same instant, and
        // the strongest signal short of a request id says so.
        $this->assertSame('time_method_path', $access->metadata['correlation_method'] ?? null);
        $this->assertEqualsWithDelta(0.8, $access->metadata['correlation_confidence'] ?? null, 0.001);
        $this->assertSame(
            'Illuminate\Database\QueryException',
            $access->metadata['correlated_exception_class'] ?? null,
        );
    }

    public function test_an_unrelated_five_hundred_is_not_correlated_to_anything(): void
    {
        // The health check failed eight hours later. Correlating it with the
        // order failure would be worse than leaving it alone.
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $health = ErrorMonitorEvent::query()
            ->where('source', LogSource::APACHE_ACCESS)
            ->where('route', '/health')
            ->firstOrFail();

        $this->assertSame('none', $health->metadata['correlation_method'] ?? null);
        // Zero comes back from JSON as an int; the value is what matters.
        $this->assertEqualsWithDelta(0.0, $health->metadata['correlation_confidence'] ?? null, 0.001);
    }

    public function test_the_server_error_the_application_never_saw_is_kept(): void
    {
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $exhausted = ErrorMonitorEvent::query()->where('source', LogSource::APACHE_ERROR)->firstOrFail();

        $this->assertSame('memory_exhausted', $exhausted->metadata['error_category'] ?? null);
        // The number the masker used to destroy.
        $this->assertStringContainsString('134217728', (string) $exhausted->normalized_message);
    }

    public function test_running_the_same_day_again_changes_nothing_anywhere(): void
    {
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();
        $events = ErrorMonitorEvent::query()->count();
        $occurrences = (int) ErrorMonitorEvent::query()->sum('occurrence_count');
        $requests = $this->requestCount();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $this->assertSame($events, ErrorMonitorEvent::query()->count());
        $this->assertSame($occurrences, (int) ErrorMonitorEvent::query()->sum('occurrence_count'));
        // Not one further request: the core knows it already published this.
        $this->assertSame($requests, $this->requestCount());
        $this->assertSame(5, ErrorMonitorIssue::query()->count());
    }

    public function test_the_next_day_comments_rather_than_opening_a_second_issue(): void
    {
        $this->fakeGithub();
        // One source keeps the arithmetic legible: one failure, one issue.
        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--source' => LogSource::LARAVEL])->run();

        // The same failure happens again the following day.
        $this->appendTomorrowsFailure();
        $this->artisan('error-monitor:run', ['--date' => '2026-08-04', '--source' => LogSource::LARAVEL])->run();

        $this->assertSame(1, $this->createCalls(), 'One issue, not two.');
        $this->assertSame(1, $this->commentPosts(), 'One comment for the new day.');
        $this->assertSame(1, ErrorMonitorIssue::query()->count());
    }

    public function test_a_failure_that_returns_after_the_issue_was_closed_reopens_it(): void
    {
        $this->fakeGithub();
        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--source' => LogSource::LARAVEL])->run();

        // Somebody fixed it and closed the issue. It came back.
        $this->closeTheIssue();
        $this->appendTomorrowsFailure();
        $this->artisan('error-monitor:run', ['--date' => '2026-08-04', '--source' => LogSource::LARAVEL])->run();

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && ($r->data()['state'] ?? '') === 'open');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/labels')
            && in_array('regression', (array) ($r->data()['labels'] ?? []), true));
        $this->assertSame(1, $this->createCalls(), 'Still one issue.');
        $this->assertSame('open', ErrorMonitorIssue::query()->firstOrFail()->external_state);
    }

    public function test_a_lost_create_response_does_not_produce_a_second_issue(): void
    {
        $posts = 0;

        Http::fake([
            '*/search/issues*' => Http::response(['items' => []]),
            '*/repos/*/issues/*/comments*' => Http::response([]),
            '*/repos/*/issues/*/labels*' => Http::response([]),
            '*/repos/*/issues?*' => function () use (&$posts) {
                // Empty until the create is attempted; afterwards GitHub shows
                // the issue it did open.
                return Http::response($posts === 0 ? [] : [$this->issuePayload()]);
            },
            '*/repos/*/issues' => function () use (&$posts) {
                $posts++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $this->artisan('error-monitor:run', ['--date' => self::DAY, '--source' => LogSource::LARAVEL])->run();

        $this->assertSame(1, $posts, 'The create was sent once and never repeated.');
        $this->assertSame(1, ErrorMonitorIssue::query()->count());
        $this->assertSame('7', ErrorMonitorIssue::query()->firstOrFail()->external_id);
    }

    public function test_a_missing_xserver_file_is_not_a_failure(): void
    {
        // The error log for the 3rd was never written - XServer skips it when
        // the account is above 80% disk. The run must not care.
        $this->fakeGithub();

        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, ErrorMonitorEvent::query()->count());
    }

    public function test_a_corrupt_archive_costs_only_its_own_source(): void
    {
        $this->fakeGithub();
        $broken = $this->fixturePath('home/'.self::SERVER_ID.'/'.self::DOMAIN.'/log/'.self::DOMAIN.'.access_log_20260804.gz');
        $original = (string) file_get_contents($broken);
        file_put_contents($broken, 'this is not gzip at all');

        try {
            $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

            // The Laravel log and the Apache error log still went through.
            $this->assertGreaterThan(0, ErrorMonitorEvent::query()->where('source', LogSource::LARAVEL)->count());
            $this->assertGreaterThan(0, ErrorMonitorEvent::query()->where('source', LogSource::APACHE_ERROR)->count());
        } finally {
            file_put_contents($broken, $original);
        }
    }

    public function test_a_rate_limited_github_leaves_the_analysis_intact(): void
    {
        Http::fake(['*' => Http::response(['message' => 'API rate limit exceeded'], 429, ['retry-after' => '3600'])]);

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        // The logs were still analysed and stored; only the publishing failed,
        // and it recorded nothing so the next run tries again.
        $this->assertGreaterThan(0, ErrorMonitorEvent::query()->count());
        $this->assertSame(0, ErrorMonitorIssue::query()->count());
    }

    public function test_a_broken_github_leaves_the_analysis_intact(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $this->assertGreaterThan(0, ErrorMonitorEvent::query()->count());
        $this->assertSame(0, ErrorMonitorIssue::query()->count());
    }

    public function test_retention_removes_history_past_its_horizon(): void
    {
        $this->fakeGithub();
        $stale = ErrorMonitorEvent::query()->create([
            'environment' => 'production',
            'source' => LogSource::LARAVEL,
            'fingerprint' => str_repeat('f', 64),
            'detected_date' => '2020-01-01',
            'first_occurred_at' => '2020-01-01 00:00:00',
            'last_occurred_at' => '2020-01-01 00:00:00',
            'exception_class' => 'RuntimeException',
            'normalized_message' => 'Long gone',
            'payload_hash' => str_repeat('e', 64),
        ]);

        config()->set('error-monitor.retention_days', 1);

        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        // Six years old, and the run completed cleanly, so it goes. (The other
        // half - retention skipped while a source is failing - is pinned in the
        // core package, where a failing source can be arranged directly.)
        $this->assertFalse(ErrorMonitorEvent::query()->whereKey($stale->id)->exists());
        $this->assertGreaterThan(0, ErrorMonitorEvent::query()->count(), 'Today survived.');
    }

    /** Everything the run wrote, in one string, for a leak check to search. */
    protected function everythingWritten(): string
    {
        $parts = [];

        foreach (ErrorMonitorEvent::query()->get() as $event) {
            $parts[] = (string) json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        foreach (ErrorMonitorIssue::query()->get() as $issue) {
            $parts[] = (string) json_encode($issue->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        Http::recorded(function (Request $request) use (&$parts): bool {
            $parts[] = (string) $request->body();

            return false;
        });

        return implode("\n", $parts);
    }

    protected function fakeGithub(): void
    {
        $created = false;
        $closed = false;

        Http::fake([
            '*/search/issues*' => function () use (&$created, &$closed) {
                return Http::response(['items' => $created ? [$this->issuePayload($closed ? 'closed' : 'open')] : []]);
            },
            '*/repos/*/issues/*/comments*' => Http::response([]),
            '*/repos/*/issues/*/labels*' => Http::response([]),
            '*/repos/*/issues/7' => function (Request $request) use (&$closed) {
                if ($request->method() === 'PATCH') {
                    $closed = false;
                }

                return Http::response($this->issuePayload($closed ? 'closed' : 'open'));
            },
            '*/repos/*/issues?*' => function () use (&$created, &$closed) {
                return Http::response($created ? [$this->issuePayload($closed ? 'closed' : 'open')] : []);
            },
            '*/repos/*/issues' => function () use (&$created) {
                $created = true;

                return Http::response($this->issuePayload(), 201);
            },
        ]);

        $this->closer = static function () use (&$closed): void {
            $closed = true;
        };
    }

    /** @var callable|null */
    private $closer = null;

    private function closeTheIssue(): void
    {
        ($this->closer)();
    }

    /** A second day of the same failure, appended to the Laravel log. */
    private function appendTomorrowsFailure(): void
    {
        $path = $this->fixturePath('storage/logs/laravel.log');
        $original = (string) file_get_contents($path);

        $this->beforeApplicationDestroyed(static function () use ($path, $original): void {
            file_put_contents($path, $original);
        });

        file_put_contents($path, $original.str_replace('2026-08-03 11:19:29', '2026-08-04 09:05:00', $this->firstEntry($original)));
    }

    private function firstEntry(string $log): string
    {
        $lines = explode("\n", $log);
        $entry = [];

        foreach ($lines as $line) {
            if ($entry !== [] && str_starts_with($line, '[2026-')) {
                break;
            }

            $entry[] = $line;
        }

        return implode("\n", $entry)."\n";
    }

    /** @return array<string, mixed> */
    private function issuePayload(string $state = 'open'): array
    {
        $markers = app(GithubMarkerBuilder::class);
        $fingerprint = (string) (ErrorMonitorEvent::query()
            ->where('source', LogSource::LARAVEL)
            ->value('fingerprint') ?: str_repeat('a', 64));

        return [
            'number' => 7,
            'state' => $state,
            'title' => '[Laravel Error] QueryException',
            'body' => $markers->fingerprint($fingerprint)."\n".$markers->environment('production'),
            'html_url' => 'https://github.invalid/'.self::REPOSITORY.'/issues/7',
            'labels' => [],
        ];
    }

    private function createCalls(): int
    {
        return $this->countRequests(fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/repos/'.self::REPOSITORY.'/issues'));
    }

    private function commentPosts(): int
    {
        return $this->countRequests(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/comments'));
    }

    protected function requestCount(): int
    {
        return $this->countRequests(static fn (Request $r): bool => true);
    }

    /** @param callable(Request): bool $matches */
    private function countRequests(callable $matches): int
    {
        $count = 0;

        Http::recorded(function (Request $request) use ($matches, &$count): bool {
            if ($matches($request)) {
                $count++;
            }

            return false;
        });

        return $count;
    }
}
