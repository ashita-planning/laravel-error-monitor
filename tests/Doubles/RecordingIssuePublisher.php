<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\DTO\ErrorReportData;
use Apkk\LaravelErrorMonitor\DTO\IssuePublicationResultData;

/**
 * Publisher standing in for an adapter package, so the core can be tested
 * without one existing and without any outbound call.
 *
 * It behaves the way a real adapter is required to: the first report of a
 * failure opens something, later ones comment on it, and a failure that comes
 * back after being closed reopens it.
 */
final class RecordingIssuePublisher implements IssuePublisher
{
    /** @var array<int, ErrorReportData> */
    public array $published = [];

    /** @var array<int, IssuePublicationResultData> */
    public array $results = [];

    /** Fingerprints the tracker holds, and the state each is in. @var array<string, string> */
    private array $issues = [];

    /** Identifier handed out per fingerprint. @var array<string, string> */
    private array $identifiers = [];

    private int $nextId = 1000;

    /** @param array<int, string> $closed Fingerprints the tracker already holds as closed. */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $provider = 'tests',
        private readonly string $target = 'example/repository',
        private readonly ?string $failWith = null,
        array $closed = [],
    ) {
        foreach ($closed as $fingerprint) {
            $this->issues[$fingerprint] = 'closed';
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function publish(ErrorReportData $report): IssuePublicationResultData
    {
        $this->published[] = $report;

        if ($this->failWith !== null) {
            return $this->remember(IssuePublicationResultData::failure($this->failWith));
        }

        $state = $this->issues[$report->fingerprint] ?? null;

        if ($state === null) {
            $this->issues[$report->fingerprint] = 'open';

            return $this->remember(new IssuePublicationResultData(
                externalId: $this->identifierFor($report),
                state: 'open',
                action: IssuePublicationResultData::ACTION_CREATED,
                url: 'https://tracker.example.invalid/'.$this->identifierFor($report),
            ));
        }

        if ($state === 'closed') {
            $this->issues[$report->fingerprint] = 'open';

            return $this->remember(new IssuePublicationResultData(
                externalId: $this->identifierFor($report),
                state: 'open',
                action: IssuePublicationResultData::ACTION_REOPENED,
                metadata: ['regression' => true],
            ));
        }

        return $this->remember(new IssuePublicationResultData(
            externalId: $this->identifierFor($report),
            state: 'open',
            action: IssuePublicationResultData::ACTION_COMMENTED,
        ));
    }

    /** Close a failure on the tracker, so a later report reopens it. */
    public function close(string $fingerprint, string $externalId): void
    {
        $this->issues[$fingerprint] = 'closed';
        $this->identifiers[$fingerprint] = $externalId;
    }

    private function identifierFor(ErrorReportData $report): string
    {
        return $this->identifiers[$report->fingerprint] ??= (string) $this->nextId++;
    }

    private function remember(IssuePublicationResultData $result): IssuePublicationResultData
    {
        $this->results[] = $result;

        return $result;
    }
}
