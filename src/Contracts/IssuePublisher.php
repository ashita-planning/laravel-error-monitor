<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorReportData;
use Apkk\LaravelErrorMonitor\DTO\IssuePublicationResultData;

/**
 * Reports an aggregated failure to an issue tracker.
 *
 * No implementation ships with this package and none ever will: the moment a
 * tracker's API, vocabulary or formatting enters the core, every other tracker
 * becomes a special case. GitHub, Jira, Linear and a chat webhook are all
 * adapters on the far side of this contract.
 *
 * Implementations must be idempotent. A daily run may be repeated - after a
 * timeout, after a fix, by hand - and repeating it must not open a second issue
 * or repeat a comment. The core keeps its own record of what it published and
 * will not ask twice for the same report, but that is a first line of defence
 * rather than the only one: only the adapter can see what the tracker already
 * holds, including anything a previous run created before losing its answer.
 *
 * Adapters must never put a credential, an Authorization header or a raw
 * response body into a result, an exception or a log line.
 */
interface IssuePublisher
{
    /** Whether publishing is configured and turned on. */
    public function enabled(): bool;

    /** Short name of the tracker, e.g. `github`. Stored with every link. */
    public function provider(): string;

    /**
     * Where issues are filed - a repository, a project, a board.
     *
     * The core only uses it to tell two destinations apart; it never parses it.
     */
    public function target(): string;

    /**
     * Create, comment on, reopen or skip the issue tracking this failure.
     *
     * Implementations should answer with {@see IssuePublicationResultData::failure()}
     * rather than throwing: one unreachable tracker must not end a run that has
     * already analysed and stored everything correctly.
     */
    public function publish(ErrorReportData $report): IssuePublicationResultData;
}
