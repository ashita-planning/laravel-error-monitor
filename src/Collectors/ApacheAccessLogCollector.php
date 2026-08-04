<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Collectors;

/**
 * Discovers Apache access logs, including the rotated `.gz` generations.
 *
 * Rotation is what makes yesterday's traffic worth collecting at all, so the
 * default patterns cover `access.log`, `access_log`, the numbered `access.log.1`
 * and the compressed `access.log.2.gz`. Compressed files are read as a stream by
 * the parser; nothing is ever expanded onto disk.
 */
final class ApacheAccessLogCollector extends GlobLogCollector
{
    /** Source key every file collected here is tagged with. */
    public const SOURCE = 'apache_access';

    public function source(): string
    {
        return self::SOURCE;
    }
}
