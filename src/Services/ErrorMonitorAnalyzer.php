<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Collectors\ServerLogSourceCollector;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\DTO\AnalysisResultData;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Throwable;

/**
 * Orchestrates the pipeline: collect, parse, mask, normalize, fingerprint and
 * store.
 *
 * Collectors and parsers are supplied from the outside; the package ships the
 * Laravel log driver and registers it under the container tags. A new log
 * format stays additive: one collector and one parser, tagged, no change here.
 */
final class ErrorMonitorAnalyzer
{
    /**
     * @param  array<int, LogCollector>  $collectors
     * @param  array<int, LogParser>  $parsers
     */
    public function __construct(
        private readonly SensitiveDataMasker $masker,
        private readonly LogNormalizer $normalizer,
        private readonly FingerprintGenerator $fingerprintGenerator,
        private readonly ErrorEventRepository $repository,
        private readonly array $collectors = [],
        private readonly array $parsers = [],
    ) {}

    /**
     * Run an analysis.
     *
     * @param  AnalysisWindowData|null  $window  Restrict the run to a period.
     * @param  string|null  $source  Restrict the run to one source key.
     * @param  bool  $dryRun  Analyse and report, but write nothing.
     * @param  bool  $force  Store occurrences again even when the payload is unchanged.
     */
    public function analyze(
        ?AnalysisWindowData $window = null,
        ?string $source = null,
        bool $dryRun = false,
        bool $force = false,
    ): AnalysisResultData {
        if (! config('error-monitor.enabled', true)) {
            return new AnalysisResultData(warnings: ['Error monitor is disabled.'], window: $window, dryRun: $dryRun);
        }

        if ($this->collectors === []) {
            return new AnalysisResultData(warnings: ['No log collector is configured yet.'], window: $window, dryRun: $dryRun);
        }

        $warnings = [];
        $files = 0;
        $detected = 0;
        $skipped = 0;
        $stored = 0;

        foreach ($this->collectorsFor($window) as $collector) {
            foreach ($collector->collect() as $logFile) {
                if ($source !== null && $logFile->source !== $source) {
                    continue;
                }

                $files++;
                $parser = $this->parserFor($logFile, $warnings);

                if (! $parser instanceof LogParser) {
                    continue;
                }

                try {
                    foreach ($parser->parse($logFile) as $event) {
                        $detected++;

                        if (! $this->accepts($event, $window)) {
                            $skipped++;

                            continue;
                        }

                        $prepared = $this->prepare($event);

                        if ($dryRun) {
                            $stored++;

                            continue;
                        }

                        $payloadHash = $this->payloadHash($prepared, $force);

                        if (! $force && $this->repository->hasPayloadHash(
                            $prepared->environment,
                            $prepared->source,
                            $prepared->fingerprint,
                            $prepared->occurredAt,
                            $payloadHash,
                        )) {
                            $skipped++;

                            continue;
                        }

                        $this->repository->record($prepared, $payloadHash);
                        $stored++;
                    }
                } catch (Throwable $exception) {
                    // A broken entry must not abort the whole run.
                    $warnings[] = sprintf('Parsing [%s] stopped early: %s', basename($logFile->path), $exception->getMessage());
                }
            }
        }

        if ($dryRun) {
            $warnings[] = 'Dry run: nothing was written to the database.';
        }

        return new AnalysisResultData(
            filesAnalyzed: $files,
            eventsDetected: $detected,
            eventsStored: $stored,
            warnings: $warnings,
            eventsSkipped: $skipped,
            window: $window,
            dryRun: $dryRun,
            sourcesConfigured: count($this->collectors),
        );
    }

    /**
     * Mask, normalize and fingerprint a freshly parsed event.
     *
     * Masking runs first, so nothing downstream - normalization, the
     * fingerprint, the database - ever sees an unmasked value.
     */
    public function prepare(ErrorEventData $event): ErrorEventData
    {
        $maskedMessage = $this->masker->mask($event->message);

        $prepared = $event->with(
            message: $maskedMessage,
            normalizedMessage: $this->normalizer->normalize($maskedMessage),
            context: $this->masker->maskArray($event->context),
            route: $this->normalizer->normalizeRoute($event->route) ?? $event->route,
        );

        return $prepared->with(fingerprint: $this->fingerprintGenerator->generate($prepared));
    }

    /** Hash of one occurrence, computed from masked values only. */
    public function payloadHash(ErrorEventData $event, bool $force = false): string
    {
        return hash('sha256', implode('|', [
            $event->fingerprint,
            $event->occurredAt->format(DATE_ATOM),
            $event->normalizedMessage,
            (string) $event->file,
            (string) $event->line,
            // With --force the hash must not match the stored one, so an
            // unchanged log is recorded again instead of being skipped.
            $force ? uniqid('force', true) : '',
        ]));
    }

    /**
     * Whether an event passes the status filter and the analysis window.
     *
     * Public so an orchestrator can make the same decision this class makes,
     * rather than reimplementing it and drifting from it.
     */
    public function accepts(ErrorEventData $event, ?AnalysisWindowData $window = null): bool
    {
        if ($window !== null && ! $window->contains($event->occurredAt)) {
            return false;
        }

        /** @var array<int, int> $statuses */
        $statuses = (array) config('error-monitor.status_codes', [500]);

        if ($statuses === []) {
            return true;
        }

        return $event->statusCode !== null && in_array($event->statusCode, $statuses, true);
    }

    /**
     * Collectors bound to the period being analysed.
     *
     * Only the collector fronting external sources needs one: an adapter is
     * asked for a specific day rather than for whatever it happens to hold.
     *
     * @return array<int, LogCollector>
     */
    private function collectorsFor(?AnalysisWindowData $window): array
    {
        return array_map(
            static fn (LogCollector $collector): LogCollector => $collector instanceof ServerLogSourceCollector
                ? $collector->withWindow($window)
                : $collector,
            $this->collectors,
        );
    }

    /**
     * First parser claiming the file, or null when no registered parser does.
     *
     * @param  array<int, string>  $warnings
     */
    private function parserFor(LogFileData $logFile, array &$warnings): ?LogParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($logFile)) {
                return $parser;
            }
        }

        $warnings[] = sprintf('No parser is registered for source [%s].', $logFile->source);

        return null;
    }
}
