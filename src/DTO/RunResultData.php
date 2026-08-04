<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

/**
 * Structured report of one daily run.
 *
 * Totals are derived from the per-source results rather than counted twice, so
 * the summary and the breakdown can never disagree.
 */
final readonly class RunResultData
{
    /**
     * @param  array<int, SourceRunData>  $sources  One entry per source that had files.
     * @param  array<int, string>  $warnings  Non-fatal notes about the run.
     * @param  int  $issuesPublished  Failures handed to the issue publisher.
     * @param  int  $eventsPruned  Aggregates removed by the retention policy.
     * @param  int  $sourcesConfigured  Collectors registered, whether or not they matched a file.
     */
    public function __construct(
        public array $sources = [],
        public array $warnings = [],
        public ?AnalysisWindowData $window = null,
        public bool $dryRun = false,
        public int $issuesPublished = 0,
        public int $eventsPruned = 0,
        public int $sourcesConfigured = 0,
    ) {}

    public function filesAnalyzed(): int
    {
        return $this->sum(static fn (SourceRunData $source): int => $source->filesAnalyzed);
    }

    public function eventsDetected(): int
    {
        return $this->sum(static fn (SourceRunData $source): int => $source->eventsDetected);
    }

    public function eventsSkipped(): int
    {
        return $this->sum(static fn (SourceRunData $source): int => $source->eventsSkipped);
    }

    public function eventsStored(): int
    {
        return $this->sum(static fn (SourceRunData $source): int => $source->eventsStored);
    }

    public function eventsCorrelated(): int
    {
        return $this->sum(static fn (SourceRunData $source): int => $source->eventsCorrelated);
    }

    /** @return array<int, SourceRunData> */
    public function failedSources(): array
    {
        return array_values(array_filter($this->sources, static fn (SourceRunData $source): bool => $source->failed()));
    }

    /** Whether every source that ran failed. */
    public function completelyFailed(): bool
    {
        return $this->sources !== [] && count($this->failedSources()) === count($this->sources);
    }

    /** Whether some sources finished and others did not. */
    public function partiallyFailed(): bool
    {
        return $this->failedSources() !== [] && ! $this->completelyFailed();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files_analyzed' => $this->filesAnalyzed(),
            'events_detected' => $this->eventsDetected(),
            'events_stored' => $this->eventsStored(),
            'events_skipped' => $this->eventsSkipped(),
            'events_correlated' => $this->eventsCorrelated(),
            'issues_published' => $this->issuesPublished,
            'events_pruned' => $this->eventsPruned,
            'sources_configured' => $this->sourcesConfigured,
            'sources' => array_map(static fn (SourceRunData $source): array => $source->toArray(), $this->sources),
            'warnings' => $this->warnings,
            'window' => $this->window?->toArray(),
            'dry_run' => $this->dryRun,
        ];
    }

    /** @param  callable(SourceRunData): int  $value */
    private function sum(callable $value): int
    {
        return array_sum(array_map($value, $this->sources));
    }
}
