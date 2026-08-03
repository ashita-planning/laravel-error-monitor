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
    ) {}
}
