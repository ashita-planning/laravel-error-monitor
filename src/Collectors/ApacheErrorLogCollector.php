<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

/**
 * Discovers Apache error logs, including the rotated `.gz` generations.
 *
 * Separate from the access log collector because the two are configured
 * independently: an installation may well have one of them readable and not the
 * other, and they are rotated on their own schedules.
 */
final class ApacheErrorLogCollector extends GlobLogCollector
{
    /** Source key every file collected here is tagged with. */
    public const SOURCE = 'apache_error';

    public function source(): string
    {
        return self::SOURCE;
    }
}
