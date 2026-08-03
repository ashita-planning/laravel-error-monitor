<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Illuminate\Console\Command;

final class AnalyzeErrorMonitorCommand extends Command
{
    protected $signature = 'error-monitor:analyze';

    protected $description = 'Analyze configured logs for HTTP 500 errors.';

    public function handle(ErrorMonitorAnalyzer $analyzer): int
    {
        $result = $analyzer->analyze();

        $this->components->info(sprintf(
            'Analysis completed: %d files, %d detected, %d stored.',
            $result->filesAnalyzed,
            $result->eventsDetected,
            $result->eventsStored,
        ));

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
