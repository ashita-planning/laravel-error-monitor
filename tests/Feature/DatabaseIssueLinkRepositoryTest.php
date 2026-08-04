<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\IssueLinkRepository;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class DatabaseIssueLinkRepositoryTest extends TestCase
{
    public function test_it_links_a_fingerprint_to_an_issue(): void
    {
        $link = app(IssueLinkRepository::class)->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '42');

        $this->assertSame('42', $link->external_id);
        $this->assertSame('open', $link->external_state);
        $this->assertSame(1, ErrorMonitorIssue::query()->count());
        // The original columns are still written for anything reading them.
        $this->assertSame(42, $link->issue_number);
        $this->assertSame('open', $link->issue_state);
        $this->assertSame('github', $link->provider);
    }

    public function test_the_same_failure_never_gets_a_second_issue(): void
    {
        $repository = app(IssueLinkRepository::class);

        $first = $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '42');
        $second = $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '99');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('42', $second->external_id, 'The first link wins the race.');
        $this->assertSame(1, ErrorMonitorIssue::query()->count());
    }

    public function test_another_repository_gets_its_own_link(): void
    {
        $repository = app(IssueLinkRepository::class);

        $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '42');
        $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/other', '7');

        $this->assertSame(2, ErrorMonitorIssue::query()->count());
        $this->assertNotNull($repository->find('github', 'production', $this->fingerprint(), 'ashita-planning/other'));
        $this->assertNull($repository->find('github', 'staging', $this->fingerprint(), 'ashita-planning/peu-connu'));
    }

    public function test_it_tracks_the_issue_state_and_the_resolution(): void
    {
        $repository = app(IssueLinkRepository::class);
        $link = $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '42');

        $closed = $repository->updateState($link->id, 'closed', new DateTimeImmutable('2026-08-04 10:00:00'));

        $this->assertSame('closed', $closed->external_state);
        $this->assertSame('closed', $closed->issue_state);
        $this->assertSame('2026-08-04 10:00:00', $closed->resolved_at?->format('Y-m-d H:i:s'));

        $reopened = $repository->updateState($link->id, 'open');

        $this->assertSame('open', $reopened->external_state);
        $this->assertNull($reopened->resolved_at);
    }

    public function test_it_records_the_pull_request_and_prevents_duplicate_comments(): void
    {
        $repository = app(IssueLinkRepository::class);
        $link = $repository->link('github', 'production', $this->fingerprint(), 'ashita-planning/peu-connu', '42');

        $this->assertSame(7, $repository->recordPullRequest($link->id, 7)->pull_request_number);

        $hash = hash('sha256', 'daily-comment');

        $this->assertFalse($repository->hasComment($link->id, $hash));

        $commented = $repository->recordComment($link->id, $hash, new DateTimeImmutable('2026-08-04 09:00:00'));

        $this->assertSame($hash, $commented->last_comment_hash);
        $this->assertSame('2026-08-04 09:00:00', $commented->last_reported_at?->format('Y-m-d H:i:s'));
        $this->assertTrue($repository->hasComment($link->id, $hash));
        $this->assertFalse($repository->hasComment($link->id, hash('sha256', 'another-comment')));
    }

    public function test_a_tracker_whose_keys_are_not_numeric_is_stored_too(): void
    {
        // Jira hands out OPS-42, which is why the identifier is a string.
        $link = app(IssueLinkRepository::class)
            ->link('jira', 'production', $this->fingerprint(), 'OPS', 'OPS-42', 'open', ['board' => 'ops']);

        $this->assertSame('OPS-42', $link->external_id);
        $this->assertSame('jira', $link->provider);
        $this->assertSame(['board' => 'ops'], $link->metadata);
        $this->assertSame(0, $link->issue_number, 'The numeric column cannot hold it, and says so.');
    }

    public function test_each_provider_gets_its_own_link(): void
    {
        $repository = app(IssueLinkRepository::class);

        $repository->link('github', 'production', $this->fingerprint(), 'acme/shop', '42');

        $this->assertNotNull($repository->find('github', 'production', $this->fingerprint(), 'acme/shop'));
        $this->assertNull($repository->find('jira', 'production', $this->fingerprint(), 'acme/shop'));
    }

    private function fingerprint(): string
    {
        return str_repeat('ab', 32);
    }
}
