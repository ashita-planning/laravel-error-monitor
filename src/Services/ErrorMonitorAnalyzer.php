<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\DTO\AnalysisResultData;

final class ErrorMonitorAnalyzer
{
    public function analyze(): AnalysisResultData
    {
        if (! config('error-monitor.enabled', true)) {
            return new AnalysisResultData(warnings: ['Error monitor is disabled.']);
        }

        // Collectors and parsers will be supplied by Phase 2 drivers.
        return new AnalysisResultData(warnings: ['No log collector is configured yet.']);
    }
}
