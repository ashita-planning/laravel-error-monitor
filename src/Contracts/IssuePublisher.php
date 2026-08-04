<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;

/**
 * Publishes an aggregated failure to an issue tracker.
 *
 * The contract exists so later phases can be written against it. No
 * implementation ships with this package: issue publishing is deferred work and
 * the package performs no outbound HTTP call today.
 *
 * Implementations must be idempotent - publishing the same failure twice has to
 * result in one issue and, at most, one comment per occurrence.
 */
interface IssuePublisher
{
    /** Whether publishing is configured and turned on. */
    public function enabled(): bool;

    /** Create or update the issue tracking a failure. @return int|null Issue number, null when nothing was published. */
    public function publish(ErrorEventData $event): ?int;

    /** Report a new occurrence on a published issue. @return bool False when the occurrence was already reported. */
    public function comment(ErrorEventData $event, int $issueNumber): bool;
}
