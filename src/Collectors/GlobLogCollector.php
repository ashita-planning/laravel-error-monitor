<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use DateTimeImmutable;

/**
 * Finds log files by glob pattern inside one directory.
 *
 * Every file based driver answers the same three questions - which directory,
 * which names, and how much of it is worth reading in one run - so the
 * behaviour lives here and a driver only names its source key.
 */
abstract class GlobLogCollector implements LogCollector
{
    /**
     * @param  string  $path  Log directory, or any file inside it.
     * @param  array<int, string>  $patterns  Glob patterns applied inside the directory.
     * @param  int  $maxFiles  Newest files kept; 0 means "no limit".
     * @param  int  $maxBytes  Files bigger than this are skipped; 0 means "no limit".
     */
    public function __construct(
        private readonly string $path,
        private readonly array $patterns,
        private readonly int $maxFiles,
        private readonly int $maxBytes,
    ) {}

    /** Source key every file collected here is tagged with. */
    abstract public function source(): string;

    /** @return array<int, LogFileData> */
    public function collect(): iterable
    {
        $directory = $this->directory();

        if ($directory === null) {
            return [];
        }

        $files = [];

        foreach ($this->matches($directory) as $match) {
            $size = filesize($match);
            $size = $size === false ? 0 : $size;

            // An oversized log is skipped rather than streamed: a run must not
            // be able to spend an unbounded amount of time on one file. For a
            // compressed log this is the size on disk, not once expanded.
            if ($this->maxBytes > 0 && $size > $this->maxBytes) {
                continue;
            }

            $modifiedAt = filemtime($match);

            $files[] = new LogFileData(
                path: $match,
                source: $this->source(),
                modifiedAt: $modifiedAt === false ? null : (new DateTimeImmutable)->setTimestamp($modifiedAt),
                size: $size,
            );
        }

        usort(
            $files,
            static fn (LogFileData $a, LogFileData $b): int => ($b->modifiedAt?->getTimestamp() ?? 0)
                <=> ($a->modifiedAt?->getTimestamp() ?? 0),
        );

        return $this->maxFiles > 0 ? array_slice($files, 0, $this->maxFiles) : $files;
    }

    /**
     * Directory the patterns are applied in, or null when it does not exist.
     *
     * A path naming one file resolves to its directory, because the rotated
     * siblings next to it belong to the same source and would be missed.
     */
    private function directory(): ?string
    {
        if ($this->path === '') {
            return null;
        }

        $directory = is_dir($this->path) ? $this->path : dirname($this->path);

        return is_dir($directory) ? rtrim($directory, '/') : null;
    }

    /**
     * Existing files matching any configured pattern, deduplicated.
     *
     * @return array<int, string>
     */
    private function matches(string $directory): array
    {
        $matches = [];

        foreach ($this->patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            foreach (glob($directory.'/'.$pattern) ?: [] as $match) {
                if (is_file($match)) {
                    $matches[$match] = $match;
                }
            }
        }

        return array_values($matches);
    }
}
