<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;

/**
 * Supplies log files the core cannot reach on its own.
 *
 * This is the whole boundary between the package and a hosting environment.
 * Everything on the far side of it - path conventions, SSH or API access,
 * credentials, retries, rate limits, and deciding which directories may be read
 * at all - belongs to the adapter. Path traversal and symlink checks are the
 * adapter's responsibility too: it is the only side that knows what "allowed"
 * means for its environment.
 *
 * The core's side of the bargain is small on purpose. It checks that what it
 * was handed exists and can be read, deduplicates by source, date and hash, and
 * then treats the file exactly like one it found itself.
 */
interface ServerLogSource
{
    /**
     * Stable identifier of this source, unique across registered sources.
     *
     * Not the same thing as a log source key: one adapter may well supply both
     * `apache_access` and `apache_error` files.
     */
    public function id(): string;

    /**
     * Files this source can offer for the given period.
     *
     * Implementations must not throw when they have nothing: an empty iterable
     * is the expected answer for a day with no logs. A null window means the
     * caller has no period in mind and the adapter should offer what it
     * considers current.
     *
     * @return iterable<CollectedLogFileData>
     */
    public function collect(?AnalysisWindowData $window = null): iterable;
}
