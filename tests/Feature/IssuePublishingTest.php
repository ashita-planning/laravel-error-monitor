<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\ErrorReportData;
use Apkk\LaravelErrorMonitor\DTO\IssuePublicationResultData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use Apkk\LaravelErrorMonitor\Repositories\DatabaseIssueLinkRepository;
use Apkk\LaravelErrorMonitor\Services\DailyErrorMonitorRunner;
use Apkk\LaravelErrorMonitor\Tests\Doubles\RecordingIssuePublisher;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

/**
 * The core's half of issue publishing.
 *
 * Nothing here knows what a tracker is: the point of these tests is that the
 * core builds a report, asks once, stores the answer, and stays out of the way.
 */
final class IssuePublishingTest extends TestCase
{
    private const DAY = '2026-08-03';

    public function test_nothing_is_published_when_no_adapter_is_installed(): void
    {
        $this->useFixtureLogs();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame(0, $result->issuesPublished);
        $this->assertSame(0, ErrorMonitorIssue::query()->count());
        $this->assertSame([], $result->warnings, 'A missing publisher is not a problem.');
    }

    public function test_a_first_report_opens_an_issue_and_is_remembered(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->publisher();

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertGreaterThan(0, $result->issuesPublished);
        $this->assertSame(
            IssuePublicationResultData::ACTION_CREATED,
            $publisher->results[0]->action,
        );

        $link = ErrorMonitorIssue::query()->firstOrFail();

        $this->assertSame('tests', $link->provider);
        $this->assertSame('example/repository', $link->repository);
        $this->assertSame('open', $link->external_state);
        $this->assertNotNull($link->last_comment_hash, 'The report was recorded as delivered.');
    }

    public function test_the_same_report_is_not_offered_twice(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->publisher();

        app(DailyErrorMonitorRunner::class)->run($this->window());
        $offered = count($publisher->published);

        app(DailyErrorMonitorRunner::class)->run($this->window());

        // Not "the adapter skipped it" - the adapter was never asked. A
        // repeated run must not become a repeated API call.
        $this->assertCount($offered, $publisher->published);
    }

    public function test_a_grown_occurrence_count_is_worth_reporting_again(): void
    {
        $publisher = $this->publisher();
        $repository = app(IssueLinkRepository::class);
        $report = $this->report(occurrenceCount: 1);

        $repository->recordPublication('tests', 'example/repository', $report, $publisher->publish($report));

        $grown = $this->report(occurrenceCount: 9);
        $link = $repository->find('tests', 'production', $report->fingerprint, 'example/repository');

        $this->assertNotNull($link);
        $this->assertFalse(
            $repository->hasComment($link->id, DatabaseIssueLinkRepository::reportHash($grown)),
            'A day that has got worse is not the same report.',
        );
    }

    public function test_a_failure_that_comes_back_after_being_closed_is_reopened(): void
    {
        $publisher = $this->publisher();
        $repository = app(IssueLinkRepository::class);
        $report = $this->report();

        $created = $publisher->publish($report);
        $repository->recordPublication('tests', 'example/repository', $report, $created);

        $publisher->close($report->fingerprint, $created->externalId);
        $reopened = $publisher->publish($this->report(occurrenceCount: 2));

        $this->assertSame(IssuePublicationResultData::ACTION_REOPENED, $reopened->action);
        $this->assertTrue($reopened->metadata['regression'] ?? false);
        $this->assertSame($created->externalId, $reopened->externalId, 'The same issue, not a new one.');
    }

    public function test_a_further_occurrence_becomes_a_comment(): void
    {
        $publisher = $this->publisher();

        $publisher->publish($this->report());
        $second = $publisher->publish($this->report(occurrenceCount: 5));

        $this->assertSame(IssuePublicationResultData::ACTION_COMMENTED, $second->action);
    }

    public function test_a_publisher_failure_is_a_warning_and_leaves_no_link(): void
    {
        $this->useFixtureLogs();
        $this->publisher(failWith: 'the tracker is unreachable');

        $result = app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame(0, $result->issuesPublished);
        // Nothing recorded, so the next run tries again rather than believing
        // the report was delivered.
        $this->assertSame(0, ErrorMonitorIssue::query()->count());
        $this->assertStringContainsString('the tracker is unreachable', implode(' ', $result->warnings));
    }

    public function test_a_disabled_publisher_is_never_asked(): void
    {
        $this->useFixtureLogs();
        $publisher = $this->publisher(enabled: false);

        app(DailyErrorMonitorRunner::class)->run($this->window());

        $this->assertSame([], $publisher->published);
    }

    public function test_skipped_is_an_outcome_rather_than_a_problem(): void
    {
        $skipped = IssuePublicationResultData::skipped('1234', 'open');

        $this->assertSame(IssuePublicationResultData::ACTION_SKIPPED, $skipped->action);
        $this->assertFalse($skipped->changedAnything());
        $this->assertFalse($skipped->failed());
    }

    public function test_a_report_carries_no_tracker_formatting(): void
    {
        $report = $this->report();

        // No Markdown, no labels, no links: how this reads is the adapter's
        // judgement, and baking one tracker's formatting in would make every
        // other one awkward.
        $this->assertStringNotContainsString('#', $report->summary);
        $this->assertStringNotContainsString('```', $report->summary);
        $this->assertArrayNotHasKey('labels', $report->toArray());
        $this->assertArrayNotHasKey('body', $report->toArray());
    }

    public function test_a_report_describes_the_failure_usefully(): void
    {
        $report = $this->report();

        $this->assertStringContainsString('production', $report->title);
        $this->assertStringContainsString('RuntimeException', $report->title);
        $this->assertStringContainsString('Order failed', $report->summary);
        $this->assertSame(self::DAY, $report->detectedDate->format('Y-m-d'));
        $this->assertSame(500, $report->statusCode);
    }

    private function report(int $occurrenceCount = 1): ErrorReportData
    {
        $occurredAt = new DateTimeImmutable(self::DAY.' 10:00:00');

        return ErrorReportData::fromEvent(new ErrorEventData(
            environment: 'production',
            source: 'laravel',
            occurredAt: $occurredAt,
            exceptionClass: 'RuntimeException',
            message: 'Order failed',
            normalizedMessage: 'Order failed',
            file: '/srv/app/app/Services/OrderService.php',
            line: 44,
            method: 'POST',
            route: '/orders/{id}',
            statusCode: 500,
            stackFrames: [],
            fingerprint: str_repeat('ab', 32),
            occurrenceCount: $occurrenceCount,
        ), 'UTC');
    }

    private function publisher(bool $enabled = true, ?string $failWith = null): RecordingIssuePublisher
    {
        $publisher = new RecordingIssuePublisher(enabled: $enabled, failWith: $failWith);

        $this->app->instance(IssuePublisher::class, $publisher);
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);

        return $publisher;
    }

    private function window(): AnalysisWindowData
    {
        return AnalysisWindowData::forDate(self::DAY, 'UTC');
    }

    private function useFixtureLogs(): void
    {
        config()->set('error-monitor.laravel_log_path', dirname(__DIR__).'/Fixtures');
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);
    }
}
