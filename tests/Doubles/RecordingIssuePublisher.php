<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\IssuePublisher;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;

/**
 * Publisher standing in for an adapter package, so the core can be tested
 * without one existing and without any outbound call.
 */
final class RecordingIssuePublisher implements IssuePublisher
{
    /** @var array<int, ErrorEventData> */
    public array $published = [];

    /** @var array<int, ErrorEventData> */
    public array $commented = [];

    public function __construct(private readonly bool $enabled = true) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function publish(ErrorEventData $event): ?int
    {
        $this->published[] = $event;

        return count($this->published);
    }

    public function comment(ErrorEventData $event, int $issueNumber): bool
    {
        $this->commented[] = $event;

        return true;
    }
}
