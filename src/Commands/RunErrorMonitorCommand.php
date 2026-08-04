<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\RunResultData;
use Apkk\LaravelErrorMonitor\DTO\SourceRunData;
use Apkk\LaravelErrorMonitor\Services\DailyErrorMonitorRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use Throwable;

/**
 * The one command a daily schedule runs.
 *
 * Where `error-monitor:analyze` analyses whatever it is pointed at, this reads
 * every configured source, correlates them, stores, publishes and prunes - and
 * defaults to yesterday, because that is the day a morning run is about.
 *
 * The command owns the period, the run lock and the rendering. Everything else
 * belongs to {@see DailyErrorMonitorRunner}.
 */
final class RunErrorMonitorCommand extends Command
{
    /** Every source finished. */
    public const EXIT_SUCCESS = 0;

    /** The run failed outright, or every source failed. */
    public const EXIT_FAILED = 1;

    /** Misconfiguration: disabled package, unusable date, contradictory options. */
    public const EXIT_INVALID_CONFIGURATION = 2;

    /** Another run holds the lock for this period. */
    public const EXIT_ALREADY_RUNNING = 3;

    /** The collectors ran and matched no log file. */
    public const EXIT_NO_LOGS = 4;

    /** Some sources finished and others did not. */
    public const EXIT_PARTIAL_FAILURE = 5;

    protected $signature = 'error-monitor:run
        {--date= : Analyze a single day, e.g. 2026-08-03, today or yesterday. Defaults to yesterday}
        {--from= : Start of the analyzed period, e.g. "2026-08-03 00:00:00"}
        {--to= : End of the analyzed period, e.g. "2026-08-03 23:59:59"}
        {--source= : Restrict the run to one log source, e.g. laravel}
        {--dry-run : Report only: nothing is stored, published or pruned}
        {--skip-github : Do not hand anything to the issue publisher}
        {--force : Store occurrences again even when the log has not changed}
        {--json : Output the result as JSON}';

    protected $description = 'Analyze every configured log source for one day, correlate and store the result.';

    public function handle(DailyErrorMonitorRunner $runner, CacheFactory $cache): int
    {
        if (! config('error-monitor.enabled', true)) {
            $this->components->error('Error monitor is disabled; set ERROR_MONITOR_ENABLED=true to run it.');

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
            $this->components->error('Another run is already analyzing this period.');

            return self::EXIT_ALREADY_RUNNING;
        }

        try {
            $result = $runner->run(
                window: $window,
                source: $source,
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
                skipPublishing: (bool) $this->option('skip-github'),
            );
        } catch (Throwable $exception) {
            $this->components->error('Run failed: '.$exception->getMessage());

            return self::EXIT_FAILED;
        } finally {
            $lock?->release();
        }

        $this->render($result);

        return $this->exitCode($result);
    }

    private function exitCode(RunResultData $result): int
    {
        if ($result->completelyFailed()) {
            return self::EXIT_FAILED;
        }

        if ($result->partiallyFailed()) {
            return self::EXIT_PARTIAL_FAILURE;
        }

        // "No logs" means the collectors ran and matched nothing. A package
        // with no collector at all has simply nothing to do.
        return $result->sourcesConfigured > 0 && $result->filesAnalyzed() === 0
            ? self::EXIT_NO_LOGS
            : self::EXIT_SUCCESS;
    }

    /**
     * Build the analysis window, defaulting to yesterday.
     *
     * @throws InvalidArgumentException When the options cannot be combined.
     */
    private function window(): AnalysisWindowData
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

        $timezone = (string) config('error-monitor.timezone', 'UTC');
        $before = (int) config('error-monitor.analysis.context_before_seconds', 0);
        $after = (int) config('error-monitor.analysis.context_after_seconds', 0);

        if ($from !== null || $to !== null) {
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('--from and --to must be used together.');
            }

            return AnalysisWindowData::between($from, $to, $timezone, $before, $after);
        }

        // A daily run is about the day that just ended, so an unqualified
        // invocation from the scheduler needs no options at all.
        return AnalysisWindowData::forDate($date ?? 'yesterday', $timezone, $before, $after);
    }

    /** Lock guarding this period, so two runs cannot process it at once. */
    private function lock(CacheFactory $cache, AnalysisWindowData $window, ?string $source): ?Lock
    {
        $store = $cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            // A store without locks still works; the database unique constraint
            // stays the real safety net.
            return null;
        }

        $key = 'error-monitor:run:'.md5(($source ?? 'all').'|'.$window->label());

        return $store->lock($key, (int) config('error-monitor.analysis.lock_seconds', 900));
    }

    private function render(RunResultData $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->info(sprintf(
            'Run completed: %d files, %d detected, %d stored, %d skipped, %d correlated.',
            $result->filesAnalyzed(),
            $result->eventsDetected(),
            $result->eventsStored(),
            $result->eventsSkipped(),
            $result->eventsCorrelated(),
        ));

        if ($result->window !== null) {
            $this->components->twoColumnDetail(
                'Window',
                $result->window->from->format('Y-m-d H:i:s').' - '.$result->window->to->format('Y-m-d H:i:s'),
            );
        }

        foreach ($result->sources as $source) {
            $this->components->twoColumnDetail($source->source, $this->describe($source));
        }

        if ($result->eventsPruned > 0) {
            $this->components->twoColumnDetail('Pruned', sprintf('%d aggregate(s) past retention', $result->eventsPruned));
        }

        if ($result->issuesPublished > 0) {
            $this->components->twoColumnDetail('Published', sprintf('%d issue(s)', $result->issuesPublished));
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($result->failedSources() as $source) {
            $this->components->error(sprintf('[%s] %s', $source->source, (string) $source->failure));
        }
    }

    private function describe(SourceRunData $source): string
    {
        if ($source->failed()) {
            return 'failed';
        }

        return sprintf(
            '%d file(s), %d detected, %d stored, %d skipped',
            $source->filesAnalyzed,
            $source->eventsDetected,
            $source->eventsStored,
            $source->eventsSkipped,
        );
    }
}
