<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use DateTimeInterface;

/**
 * Persistence boundary for the fingerprint / issue correspondence.
 *
 * One link per `(environment, fingerprint, repository)`. The unique constraint
 * is what prevents a second issue from being opened for a failure that is
 * already tracked, even when two runs overlap.
 */
interface IssueLinkRepository
{
    /** Find the link of a failure inside a repository. */
    public function find(string $environment, string $fingerprint, string $repository): ?ErrorMonitorIssue;

    /** Create the link, or return the existing one when another run won the race. */
    public function link(string $environment, string $fingerprint, string $repository, int $issueNumber, string $issueState = 'open'): ErrorMonitorIssue;

    /** Update the issue state, marking it resolved or clearing the resolution when it re-opens. */
    public function updateState(int $id, string $issueState, ?DateTimeInterface $resolvedAt = null): ErrorMonitorIssue;

    /** Attach the pull request that fixes the failure. */
    public function recordPullRequest(int $id, int $pullRequestNumber): ErrorMonitorIssue;

    /** Remember the last reported occurrence so it is not reported twice. */
    public function recordComment(int $id, string $commentHash, DateTimeInterface $reportedAt): ErrorMonitorIssue;

    /** Whether this exact occurrence has already been reported on the issue. */
    public function hasComment(int $id, string $commentHash): bool;
}
