<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use DateTimeImmutable;

/**
 * Discovers the log files written by the Laravel `single` and `daily` channels.
 *
 * The configured path may point at the directory itself or at one file inside
 * it - `storage/logs` and `storage/logs/laravel.log` both resolve to the same
 * directory, which is then scanned with the configured glob patterns. Reading
 * one file directly would miss the rotated `laravel-YYYY-MM-DD.log` siblings.
 */
final class LaravelLogCollector implements LogCollector
{
    /** Source key every file collected here is tagged with. */
    public const SOURCE = 'laravel';

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
            // be able to spend an unbounded amount of time on one file.
            if ($this->maxBytes > 0 && $size > $this->maxBytes) {
                continue;
            }

            $modifiedAt = filemtime($match);

            $files[] = new LogFileData(
                path: $match,
                source: self::SOURCE,
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

    /** Directory the patterns are applied in, or null when it does not exist. */
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
