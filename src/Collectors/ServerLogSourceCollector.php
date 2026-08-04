<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\Services\LogSourceRegistry;
use Apkk\LaravelErrorMonitor\Support\LogSource;
use DateTimeImmutable;
use Throwable;

/**
 * Turns externally supplied files into ordinary collected log files.
 *
 * Once a file is here it is indistinguishable from one the package found in
 * `storage/logs`: the same parsers claim it, by the same source key. That is
 * the point of the boundary - an adapter widens where logs come from without
 * widening what the core has to understand.
 *
 * The checks here are the ones the core can honestly make. Whether a path was
 * allowed to be read at all is the adapter's judgement; whether the file is
 * actually there and readable is not.
 */
final class ServerLogSourceCollector implements LogCollector
{
    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(
        private readonly LogSourceRegistry $registry,
        private readonly ?AnalysisWindowData $window = null,
    ) {}

    /**
     * Copy bound to a period.
     *
     * The contract's `collect()` takes no arguments, so the window is carried
     * on the collector rather than smuggled through shared mutable state.
     */
    public function withWindow(?AnalysisWindowData $window): self
    {
        return new self($this->registry, $window);
    }

    /**
     * Identifier of this collector, not a source key.
     *
     * The files it yields carry their own source - `apache_access` and
     * `apache_error` may well arrive from the same adapter - and that is what a
     * parser matches on.
     */
    public function source(): string
    {
        return LogSource::SERVER_LOG_SOURCES;
    }

    /** Notes about files that could not be used. @return array<int, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<int, LogFileData> */
    public function collect(): iterable
    {
        $this->warnings = [];

        $files = [];
        $seen = [];

        foreach ($this->registry->all() as $id => $source) {
            foreach ($this->offered($id, $source) as $collected) {
                // The same file reached twice - two adapters pointed at one
                // directory, or one adapter matched it under two patterns - is
                // work already done.
                if (isset($seen[$collected->identity()])) {
                    continue;
                }

                if (! $this->usable($id, $collected)) {
                    continue;
                }

                $seen[$collected->identity()] = true;
                $files[] = $this->toLogFile($collected);
            }
        }

        return $files;
    }

    /**
     * What one source offers, with its failures contained.
     *
     * An adapter that cannot reach its host must not take the run down with it:
     * the local logs are still worth reading.
     *
     * @return array<int, CollectedLogFileData>
     */
    private function offered(string $id, ServerLogSource $source): array
    {
        try {
            $offered = [];

            foreach ($source->collect($this->window) as $collected) {
                $offered[] = $collected;
            }

            return $offered;
        } catch (Throwable $exception) {
            $this->warnings[] = sprintf('Server log source [%s] failed: %s', $id, $exception->getMessage());

            return [];
        }
    }

    private function usable(string $id, CollectedLogFileData $collected): bool
    {
        if (! is_file($collected->path) || ! is_readable($collected->path)) {
            $this->warnings[] = sprintf(
                'Server log source [%s] offered an unreadable file: %s',
                $id,
                basename($collected->path),
            );

            return false;
        }

        return true;
    }

    private function toLogFile(CollectedLogFileData $collected): LogFileData
    {
        $size = filesize($collected->path);
        $modifiedAt = filemtime($collected->path);

        return new LogFileData(
            path: $collected->path,
            source: $collected->source,
            modifiedAt: $modifiedAt === false ? null : (new DateTimeImmutable)->setTimestamp($modifiedAt),
            size: $size === false ? 0 : $size,
            targetDate: $collected->targetDate,
            fileHash: $collected->fileHash,
            compressed: $collected->compressed,
            metadata: $collected->metadata,
        );
    }
}
