<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorReportData;
use Apkk\LaravelErrorMonitor\DTO\IssuePublicationResultData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use DateTimeInterface;

/**
 * Persistence boundary for the failure / external issue correspondence.
 *
 * One link per `(environment, fingerprint, target)`, per provider. The unique
 * constraint is what prevents a second issue being opened for a failure that is
 * already tracked, even when two runs overlap.
 *
 * Identifiers are strings: GitHub hands out `1234`, Jira hands out `OPS-42`,
 * and a contract that assumed a number would have to be broken for the second.
 */
interface IssueLinkRepository
{
    /** Find the link of a failure at one destination. */
    public function find(string $provider, string $environment, string $fingerprint, string $target): ?ErrorMonitorIssue;

    /**
     * Create the link, or return the existing one when another run won the race.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function link(
        string $provider,
        string $environment,
        string $fingerprint,
        string $target,
        string $externalId,
        string $externalState = 'open',
        array $metadata = [],
    ): ErrorMonitorIssue;

    /** Update the tracker's state, marking it resolved or clearing the resolution when it re-opens. */
    public function updateState(int $id, string $externalState, ?DateTimeInterface $resolvedAt = null): ErrorMonitorIssue;

    /** Attach the pull or merge request that fixes the failure. */
    public function recordPullRequest(int $id, int $pullRequestNumber): ErrorMonitorIssue;

    /** Remember the last reported occurrence so it is not reported twice. */
    public function recordComment(int $id, string $commentHash, DateTimeInterface $reportedAt): ErrorMonitorIssue;

    /** Whether this exact occurrence has already been reported on the issue. */
    public function hasComment(int $id, string $commentHash): bool;

    /**
     * Store what a publisher did with a report.
     *
     * Creates the link when the report opened an issue, and records the report
     * as reported so the same one is not offered again.
     */
    public function recordPublication(
        string $provider,
        string $target,
        ErrorReportData $report,
        IssuePublicationResultData $result,
    ): ?ErrorMonitorIssue;
}
