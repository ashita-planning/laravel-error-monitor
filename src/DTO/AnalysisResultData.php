<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

final readonly class AnalysisResultData
{
    /** @param array<int, string> $warnings */
    public function __construct(
        public int $filesAnalyzed = 0,
        public int $eventsDetected = 0,
        public int $eventsStored = 0,
        public array $warnings = [],
        public int $eventsSkipped = 0,
        public ?AnalysisWindowData $window = null,
        public bool $dryRun = false,
        public int $sourcesConfigured = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files_analyzed' => $this->filesAnalyzed,
            'events_detected' => $this->eventsDetected,
            'events_stored' => $this->eventsStored,
            'events_skipped' => $this->eventsSkipped,
            'warnings' => $this->warnings,
            'sources_configured' => $this->sourcesConfigured,
            'window' => $this->window?->toArray(),
            'dry_run' => $this->dryRun,
        ];
    }
}
