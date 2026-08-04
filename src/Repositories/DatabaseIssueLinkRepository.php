<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Repositories;

use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\DTO\ErrorReportData;
use Apkk\LaravelErrorMonitor\DTO\IssuePublicationResultData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Eloquent backed issue link repository.
 *
 * Like the event repository it leans on the unique constraint rather than on a
 * preceding read: two runs that publish at the same moment end up sharing one
 * link instead of opening two issues for the same failure.
 *
 * Nothing about a particular tracker lives here. `provider` says which one a
 * link belongs to and `external_id` holds whatever identifier it hands out, so
 * a numeric issue number and a `OPS-42` key are stored the same way. The
 * original GitHub-shaped columns are still written when the identifier happens
 * to be numeric, so anything already reading them keeps working.
 */
final class DatabaseIssueLinkRepository implements IssueLinkRepository
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function find(string $provider, string $environment, string $fingerprint, string $target): ?ErrorMonitorIssue
    {
        /** @var ErrorMonitorIssue|null $link */
        $link = ErrorMonitorIssue::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->where('fingerprint', $fingerprint)
            ->where('repository', $target)
            ->first();

        return $link;
    }

    public function link(
        string $provider,
        string $environment,
        string $fingerprint,
        string $target,
        string $externalId,
        string $externalState = 'open',
        array $metadata = [],
    ): ErrorMonitorIssue {
        try {
            /** @var ErrorMonitorIssue $created */
            $created = ErrorMonitorIssue::query()->create([
                'provider' => $provider,
                'environment' => $environment,
                'fingerprint' => $fingerprint,
                'repository' => $target,
                'external_id' => $externalId,
                'external_state' => $externalState,
                // The original columns stay filled in for anything already
                // reading them. A tracker whose keys are not numeric simply
                // records a zero here and lives in `external_id`.
                'issue_number' => ctype_digit($externalId) ? (int) $externalId : 0,
                'issue_state' => $externalState,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            return $created;
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        $existing = $this->find($provider, $environment, $fingerprint, $target);

        if ($existing === null) {
            throw new RuntimeException('The issue link could not be created nor read back.');
        }

        return $existing;
    }

    public function updateState(int $id, string $externalState, ?DateTimeInterface $resolvedAt = null): ErrorMonitorIssue
    {
        return $this->update($id, static function (ErrorMonitorIssue $link) use ($externalState, $resolvedAt): void {
            $link->external_state = $externalState;
            $link->issue_state = $externalState;

            // Re-opening a closed issue clears the resolution again.
            $link->resolved_at = $resolvedAt === null ? null : DateTimeImmutable::createFromInterface($resolvedAt);
        });
    }

    public function recordPullRequest(int $id, int $pullRequestNumber): ErrorMonitorIssue
    {
        return $this->update($id, static function (ErrorMonitorIssue $link) use ($pullRequestNumber): void {
            $link->pull_request_number = $pullRequestNumber;
        });
    }

    public function recordComment(int $id, string $commentHash, DateTimeInterface $reportedAt): ErrorMonitorIssue
    {
        return $this->update($id, static function (ErrorMonitorIssue $link) use ($commentHash, $reportedAt): void {
            $link->last_comment_hash = $commentHash;
            $link->last_reported_at = DateTimeImmutable::createFromInterface($reportedAt);
        });
    }

    public function hasComment(int $id, string $commentHash): bool
    {
        return ErrorMonitorIssue::query()
            ->whereKey($id)
            ->where('last_comment_hash', $commentHash)
            ->exists();
    }

    public function recordPublication(
        string $provider,
        string $target,
        ErrorReportData $report,
        IssuePublicationResultData $result,
    ): ?ErrorMonitorIssue {
        // A failure leaves no trace: the next run has to try again rather than
        // believe the report was delivered.
        if ($result->failed()) {
            return null;
        }

        $link = $this->find($provider, $report->environment, $report->fingerprint, $target);

        if ($link === null) {
            if ($result->externalId === '') {
                return null;
            }

            $link = $this->link(
                provider: $provider,
                environment: $report->environment,
                fingerprint: $report->fingerprint,
                target: $target,
                externalId: $result->externalId,
                externalState: $result->state === '' ? 'open' : $result->state,
                metadata: $result->metadata,
            );
        } elseif ($result->state !== '' && $result->state !== $link->external_state) {
            $link = $this->updateState($link->id, $result->state);
        }

        // Remembering the report is what keeps the next run from offering it
        // again, so it happens whether the tracker created, commented or
        // decided there was nothing to do.
        return $this->recordComment($link->id, self::reportHash($report), $report->lastOccurredAt);
    }

    /**
     * Identity of one report, as stored on the link.
     *
     * The occurrence count is part of it on purpose: a day whose failure count
     * has grown since the last run is worth reporting again, and one that has
     * not is not.
     */
    public static function reportHash(ErrorReportData $report): string
    {
        return hash('sha256', implode('|', [
            $report->identity(),
            (string) $report->occurrenceCount,
            $report->lastOccurredAt->format(DATE_ATOM),
        ]));
    }

    /** @param callable(ErrorMonitorIssue): void $mutation */
    private function update(int $id, callable $mutation): ErrorMonitorIssue
    {
        /** @var ErrorMonitorIssue $link */
        $link = $this->database->transaction(function () use ($id, $mutation): ErrorMonitorIssue {
            /** @var ErrorMonitorIssue|null $link */
            $link = ErrorMonitorIssue::query()->lockForUpdate()->find($id);

            if ($link === null) {
                throw new RuntimeException(sprintf('Error monitor issue link [%d] does not exist.', $id));
            }

            $mutation($link);
            $link->save();

            return $link;
        });

        return $link;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
