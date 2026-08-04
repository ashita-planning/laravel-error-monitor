<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

/**
 * Outcome of one log source inside a run.
 *
 * A run reports per source rather than as one number, because the sources fail
 * independently: an unreadable Apache directory says nothing about the Laravel
 * log next to it, and a run that stopped at the first problem would throw away
 * work that was already correct.
 */
final readonly class SourceRunData
{
    /**
     * @param  string  $source  Source key, e.g. `laravel`.
     * @param  int  $filesAnalyzed  Files this source contributed.
     * @param  int  $eventsDetected  Entries the parser turned into events.
     * @param  int  $eventsSkipped  Detected but outside the window or the status filter.
     * @param  int  $eventsStored  Occurrences written, or that would be written on a dry run.
     * @param  int  $eventsCorrelated  Events matched to an explaining Laravel exception.
     * @param  string|null  $failure  Why the source stopped, or null when it finished.
     */
    public function __construct(
        public string $source,
        public int $filesAnalyzed = 0,
        public int $eventsDetected = 0,
        public int $eventsSkipped = 0,
        public int $eventsStored = 0,
        public int $eventsCorrelated = 0,
        public ?string $failure = null,
    ) {}

    /** Copy with the given counts replaced; null keeps the current value. */
    public function with(
        ?int $eventsSkipped = null,
        ?int $eventsStored = null,
        ?int $eventsCorrelated = null,
    ): self {
        return new self(
            source: $this->source,
            filesAnalyzed: $this->filesAnalyzed,
            eventsDetected: $this->eventsDetected,
            eventsSkipped: $eventsSkipped ?? $this->eventsSkipped,
            eventsStored: $eventsStored ?? $this->eventsStored,
            eventsCorrelated: $eventsCorrelated ?? $this->eventsCorrelated,
            failure: $this->failure,
        );
    }

    public function failed(): bool
    {
        return $this->failure !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'files_analyzed' => $this->filesAnalyzed,
            'events_detected' => $this->eventsDetected,
            'events_skipped' => $this->eventsSkipped,
            'events_stored' => $this->eventsStored,
            'events_correlated' => $this->eventsCorrelated,
            'failure' => $this->failure,
        ];
    }
}
