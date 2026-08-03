<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\DTO\AnalysisResultData;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use Throwable;

/**
 * Entry point of the analysis pipeline.
 *
 * The command owns three things only: turning options into an analysis window,
 * keeping two runs off the same period, and rendering the result. Everything
 * else belongs to the analyzer.
 */
final class AnalyzeErrorMonitorCommand extends Command
{
    /** Analysis finished. */
    public const EXIT_SUCCESS = 0;

    /** The analysis itself failed. */
    public const EXIT_FAILED = 1;

    /** Misconfiguration: disabled package, unusable date, contradictory options. */
    public const EXIT_INVALID_CONFIGURATION = 2;

    /** Another run is already analyzing the same period. */
    public const EXIT_ALREADY_RUNNING = 3;

    /** No log file matched the request. */
    public const EXIT_NO_LOGS = 4;

    protected $signature = 'error-monitor:analyze
        {--date= : Analyze a single day, e.g. 2026-08-03, today or yesterday}
        {--from= : Start of the analyzed period, e.g. "2026-08-03 00:00:00"}
        {--to= : End of the analyzed period, e.g. "2026-08-03 23:59:59"}
        {--source= : Restrict the run to one log source, e.g. laravel}
        {--dry-run : Analyze and report without writing anything}
        {--force : Store occurrences again even when the log has not changed}
        {--json : Output the result as JSON}';

    protected $description = 'Analyze configured logs for HTTP 500 errors.';

    public function handle(ErrorMonitorAnalyzer $analyzer, CacheFactory $cache): int
    {
        if (! config('error-monitor.enabled', true)) {
            $this->components->error('Error monitor is disabled; set ERROR_MONITOR_ENABLED=true to run an analysis.');

            return self::EXIT_INVALID_CONFIGURATION;
        }

        try {
            $window = $this->window();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::EXIT_INVALID_CONFIGURATION;
        }

        /** @var string|null $source */
        $source = $this->option('source');
        $lock = $this->lock($cache, $window, $source);

        if ($lock instanceof Lock && ! $lock->get()) {
            $this->components->error('Another analysis is already running for this period.');

            return self::EXIT_ALREADY_RUNNING;
        }

        try {
            $result = $analyzer->analyze($window, $source, (bool) $this->option('dry-run'), (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->components->error('Analysis failed: '.$exception->getMessage());

            return self::EXIT_FAILED;
        } finally {
            $lock?->release();
        }

        $this->render($result);

        // "No logs" means the collectors ran and matched nothing. A package
        // without any collector yet has simply nothing to do, which is not an
        // error state.
        $noLogs = $result->sourcesConfigured > 0 && $result->filesAnalyzed === 0;

        return $noLogs ? self::EXIT_NO_LOGS : self::EXIT_SUCCESS;
    }

    /**
     * Build the analysis window from the options, or null for "everything".
     *
     * @throws InvalidArgumentException When the options cannot be combined.
     */
    private function window(): ?AnalysisWindowData
    {
        /** @var string|null $date */
        $date = $this->option('date');
        /** @var string|null $from */
        $from = $this->option('from');
        /** @var string|null $to */
        $to = $this->option('to');

        if ($date !== null && ($from !== null || $to !== null)) {
            throw new InvalidArgumentException('--date cannot be combined with --from or --to.');
        }

        if ($date === null && $from === null && $to === null) {
            return null;
        }

        $timezone = (string) config('error-monitor.timezone', 'UTC');
        $before = (int) config('error-monitor.analysis.context_before_seconds', 0);
        $after = (int) config('error-monitor.analysis.context_after_seconds', 0);

        if ($date !== null) {
            return AnalysisWindowData::forDate($date, $timezone, $before, $after);
        }

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('--from and --to must be used together.');
        }

        return AnalysisWindowData::between($from, $to, $timezone, $before, $after);
    }

    /** Lock guarding this period, so two runs cannot analyze it at once. */
    private function lock(CacheFactory $cache, ?AnalysisWindowData $window, ?string $source): ?Lock
    {
        $store = $cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            // A store without locks still works; the database unique constraint
            // stays the real safety net.
            return null;
        }

        $key = 'error-monitor:analyze:'.md5(($source ?? 'all').'|'.($window?->label() ?? 'all'));

        return $store->lock($key, (int) config('error-monitor.analysis.lock_seconds', 900));
    }

    private function render(AnalysisResultData $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->info(sprintf(
            'Analysis completed: %d files, %d detected, %d stored, %d skipped.',
            $result->filesAnalyzed,
            $result->eventsDetected,
            $result->eventsStored,
            $result->eventsSkipped,
        ));

        if ($result->window !== null) {
            $this->components->twoColumnDetail(
                'Window',
                $result->window->from->format('Y-m-d H:i:s').' - '.$result->window->to->format('Y-m-d H:i:s'),
            );
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }
    }
}
