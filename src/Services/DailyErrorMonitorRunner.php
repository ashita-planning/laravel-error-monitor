<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Collectors\ApacheAccessLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\ApacheErrorLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Collectors\ServerLogSourceCollector;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\DTO\RunResultData;
use Apkk\LaravelErrorMonitor\DTO\SourceRunData;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Runs every configured source once, correlates them, and stores the result.
 *
 * This class orchestrates and nothing else: the drivers, the masking, the
 * fingerprinting and the persistence all stay exactly where they were, and the
 * decisions about what counts as storable are asked of
 * {@see ErrorMonitorAnalyzer} rather than made again here.
 *
 * Two properties are worth stating outright. Sources are independent - an
 * unreadable Apache directory says nothing about the Laravel log beside it, so
 * one failing source does not discard the work of the others, and the run says
 * which ones failed. And nothing is written on a dry run: not the database, not
 * the issue tracker, not the retention delete.
 */
final class DailyErrorMonitorRunner
{
    /**
     * Order the sources are processed in.
     *
     * Laravel first on purpose: its exceptions are what explain the Apache
     * entries, so they exist by the time the correlation runs.
     */
    private const SOURCE_ORDER = [
        LaravelLogCollector::SOURCE,
        ApacheAccessLogCollector::SOURCE,
        ApacheErrorLogCollector::SOURCE,
    ];

    /**
     * @param  array<int, LogCollector>  $collectors
     * @param  array<int, LogParser>  $parsers
     * @param  IssuePublisher|null  $publisher  Absent unless an adapter package is installed.
     */
    public function __construct(
        private readonly ErrorMonitorAnalyzer $analyzer,
        private readonly ApacheLaravelCorrelationService $correlation,
        private readonly ErrorEventRepository $repository,
        private readonly array $collectors = [],
        private readonly array $parsers = [],
        private readonly ?IssuePublisher $publisher = null,
    ) {}

    /**
     * @param  AnalysisWindowData|null  $window  Period to analyse; null means everything.
     * @param  string|null  $source  Restrict the run to one source key.
     * @param  bool  $dryRun  Report only - no database write, no publishing, no pruning.
     * @param  bool  $force  Store occurrences again even when the payload is unchanged.
     * @param  bool  $skipPublishing  Suppress the issue publisher for this run.
     */
    public function run(
        ?AnalysisWindowData $window = null,
        ?string $source = null,
        bool $dryRun = false,
        bool $force = false,
        bool $skipPublishing = false,
    ): RunResultData {
        if (! config('error-monitor.enabled', true)) {
            return new RunResultData(warnings: ['Error monitor is disabled.'], window: $window, dryRun: $dryRun);
        }

        if ($this->collectors === []) {
            return new RunResultData(warnings: ['No log collector is configured yet.'], window: $window, dryRun: $dryRun);
        }

        $warnings = [];
        $grouped = $this->groupFilesBySource($source, $window);

        /** @var array<string, array<int, ErrorEventData>> $events */
        $events = [];
        /** @var array<string, SourceRunData> $results */
        $results = [];

        foreach ($grouped as $sourceKey => $files) {
            [$results[$sourceKey], $events[$sourceKey]] = $this->parseSource($sourceKey, $files, $window, $warnings);
        }

        $events = $this->correlate($events, $results);

        if ($dryRun) {
            // Report what a real run would write, without touching anything.
            foreach ($events as $sourceKey => $sourceEvents) {
                $results[$sourceKey] = $results[$sourceKey]->with(eventsStored: count($sourceEvents));
            }
        } else {
            $this->store($events, $results, $force);
        }

        $published = $dryRun || $skipPublishing ? 0 : $this->publish($events);
        $pruned = $dryRun ? 0 : $this->prune($results, $warnings);

        if ($dryRun) {
            $warnings[] = 'Dry run: nothing was written, published or pruned.';
        }

        return new RunResultData(
            sources: array_values($results),
            warnings: $warnings,
            window: $window,
            dryRun: $dryRun,
            issuesPublished: $published,
            eventsPruned: $pruned,
            sourcesConfigured: count($this->collectors),
        );
    }

    /**
     * Every collected file, keyed by source and in a fixed processing order.
     *
     * @return array<string, array<int, LogFileData>>
     */
    private function groupFilesBySource(?string $source, ?AnalysisWindowData $window): array
    {
        $grouped = [];

        foreach ($this->collectors as $collector) {
            $collector = $collector instanceof ServerLogSourceCollector ? $collector->withWindow($window) : $collector;

            foreach ($collector->collect() as $logFile) {
                if ($source !== null && $logFile->source !== $source) {
                    continue;
                }

                $grouped[$logFile->source][] = $logFile;
            }
        }

        uksort($grouped, static function (string $a, string $b): int {
            $left = array_search($a, self::SOURCE_ORDER, true);
            $right = array_search($b, self::SOURCE_ORDER, true);

            // A source this class does not know about runs last, in a stable
            // order, so a third party driver never reorders the built-in ones.
            return [$left === false ? PHP_INT_MAX : $left, $a] <=> [$right === false ? PHP_INT_MAX : $right, $b];
        });

        return $grouped;
    }

    /**
     * Parse one source into prepared events.
     *
     * A failure here belongs to this source alone: it is recorded on the source
     * result and the remaining sources still run.
     *
     * @param  array<int, LogFileData>  $files
     * @param  array<int, string>  $warnings
     * @return array{0: SourceRunData, 1: array<int, ErrorEventData>}
     */
    private function parseSource(string $source, array $files, ?AnalysisWindowData $window, array &$warnings): array
    {
        $detected = 0;
        $skipped = 0;
        $prepared = [];
        $failure = null;

        foreach ($files as $logFile) {
            $parser = $this->parserFor($logFile);

            if (! $parser instanceof LogParser) {
                $warnings[] = sprintf('No parser is registered for source [%s].', $logFile->source);

                continue;
            }

            try {
                foreach ($parser->parse($logFile) as $event) {
                    $detected++;

                    // An Apache entry classified as a 403 or a 404 is detected
                    // and then deliberately not stored: it is real, but it is
                    // not what `status_codes` asked for.
                    if (! $this->analyzer->accepts($event, $window)) {
                        $skipped++;

                        continue;
                    }

                    $prepared[] = $this->analyzer->prepare($event);
                }
            } catch (Throwable $exception) {
                $failure = sprintf('%s: %s', basename($logFile->path), $exception->getMessage());

                break;
            }
        }

        return [
            new SourceRunData(
                source: $source,
                filesAnalyzed: count($files),
                eventsDetected: $detected,
                eventsSkipped: $skipped,
                failure: $failure,
            ),
            $prepared,
        ];
    }

    /**
     * Annotate the web server events with the exception that explains them.
     *
     * @param  array<string, array<int, ErrorEventData>>  $events
     * @param  array<string, SourceRunData>  $results
     * @return array<string, array<int, ErrorEventData>>
     */
    private function correlate(array $events, array &$results): array
    {
        if (! (bool) config('error-monitor.correlation.enabled', true)) {
            return $events;
        }

        $laravelEvents = $events[LaravelLogCollector::SOURCE] ?? [];

        foreach ([ApacheAccessLogCollector::SOURCE, ApacheErrorLogCollector::SOURCE] as $source) {
            if (($events[$source] ?? []) === []) {
                continue;
            }

            $events[$source] = $this->correlation->correlate($events[$source], $laravelEvents);

            $matched = count(array_filter(
                $events[$source],
                static fn (ErrorEventData $event): bool => ($event->metadata['correlation_method'] ?? ApacheLaravelCorrelationService::METHOD_NONE)
                    !== ApacheLaravelCorrelationService::METHOD_NONE,
            ));

            $results[$source] = $results[$source]->with(eventsCorrelated: $matched);
        }

        return $events;
    }

    /**
     * @param  array<string, array<int, ErrorEventData>>  $events
     * @param  array<string, SourceRunData>  $results
     */
    private function store(array $events, array &$results, bool $force): void
    {
        foreach ($events as $source => $sourceEvents) {
            $stored = 0;
            $skipped = $results[$source]->eventsSkipped;

            foreach ($sourceEvents as $event) {
                $payloadHash = $this->analyzer->payloadHash($event, $force);

                if (! $force && $this->repository->hasPayloadHash(
                    $event->environment,
                    $event->source,
                    $event->fingerprint,
                    $event->occurredAt,
                    $payloadHash,
                )) {
                    // Already counted on an earlier run over the same log.
                    $skipped++;

                    continue;
                }

                $this->repository->record($event, $payloadHash);
                $stored++;
            }

            $results[$source] = $results[$source]->with(eventsSkipped: $skipped, eventsStored: $stored);
        }
    }

    /**
     * Hand the failures to the issue publisher, when one is installed.
     *
     * Nothing tracker specific happens here. The contract requires publishing
     * to be idempotent, so this asks once per failure and lets the adapter
     * decide whether that means an issue, a comment or nothing at all.
     *
     * @param  array<string, array<int, ErrorEventData>>  $events
     */
    private function publish(array $events): int
    {
        if (! $this->publisher instanceof IssuePublisher || ! $this->publisher->enabled()) {
            return 0;
        }

        $published = 0;

        foreach ($events as $sourceEvents) {
            foreach ($sourceEvents as $event) {
                if ($this->publisher->publish($event) !== null) {
                    $published++;
                }
            }
        }

        return $published;
    }

    /**
     * Drop aggregates past the retention horizon.
     *
     * Only after a clean run: deleting history on the strength of an analysis
     * that partly failed would remove data the failed source might still have
     * had something to say about.
     *
     * @param  array<string, SourceRunData>  $results
     * @param  array<int, string>  $warnings
     */
    private function prune(array $results, array &$warnings): int
    {
        $retentionDays = (int) config('error-monitor.retention_days', 90);

        if ($retentionDays <= 0) {
            return 0;
        }

        foreach ($results as $result) {
            if ($result->failed()) {
                $warnings[] = 'Retention pruning was skipped because a source failed.';

                return 0;
            }
        }

        $timezone = new DateTimeZone((string) config('error-monitor.timezone', 'UTC'));

        return $this->repository->prune(
            (new DateTimeImmutable('now', $timezone))->modify(sprintf('-%d days', $retentionDays)),
        );
    }

    private function parserFor(LogFileData $logFile): ?LogParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($logFile)) {
                return $parser;
            }
        }

        return null;
    }
}
