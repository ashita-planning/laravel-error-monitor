<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

/**
 * Discovers the log files written by the Laravel `single` and `daily` channels.
 *
 * The configured path may point at the directory itself or at one file inside
 * it - `storage/logs` and `storage/logs/laravel.log` both resolve to the same
 * directory, which is then scanned with the configured glob patterns. Reading
 * one file directly would miss the rotated `laravel-YYYY-MM-DD.log` siblings.
 */
final class LaravelLogCollector extends GlobLogCollector
{
    /** Source key every file collected here is tagged with. */
    public const SOURCE = 'laravel';

    public function source(): string
    {
        return self::SOURCE;
    }
}
