<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Repositories;

use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
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
 * Nothing in this package writes to the table yet - it is the persistence half
 * of the deferred issue publishing work, kept here so the schema and the
 * duplicate protection exist before the API integration does.
 */
final class DatabaseIssueLinkRepository implements IssueLinkRepository
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function find(string $environment, string $fingerprint, string $repository): ?ErrorMonitorIssue
    {
        /** @var ErrorMonitorIssue|null $link */
        $link = ErrorMonitorIssue::query()
            ->where('environment', $environment)
            ->where('fingerprint', $fingerprint)
            ->where('repository', $repository)
            ->first();

        return $link;
    }

    public function link(string $environment, string $fingerprint, string $repository, int $issueNumber, string $issueState = 'open'): ErrorMonitorIssue
    {
        try {
            /** @var ErrorMonitorIssue $created */
            $created = ErrorMonitorIssue::query()->create([
                'environment' => $environment,
                'fingerprint' => $fingerprint,
                'repository' => $repository,
                'issue_number' => $issueNumber,
                'issue_state' => $issueState,
            ]);

            return $created;
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        $existing = $this->find($environment, $fingerprint, $repository);

        if ($existing === null) {
            throw new RuntimeException('The issue link could not be created nor read back.');
        }

        return $existing;
    }

    public function updateState(int $id, string $issueState, ?DateTimeInterface $resolvedAt = null): ErrorMonitorIssue
    {
        return $this->update($id, static function (ErrorMonitorIssue $link) use ($issueState, $resolvedAt): void {
            $link->issue_state = $issueState;

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
