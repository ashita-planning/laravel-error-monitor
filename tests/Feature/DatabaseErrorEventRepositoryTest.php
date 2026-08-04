<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class DatabaseErrorEventRepositoryTest extends TestCase
{
    public function test_it_prevents_duplicate_registration_on_the_same_day(): void
    {
        $repository = app(ErrorEventRepository::class);
        $event = $this->event('2026-08-03 10:00:00');

        $first = $repository->record($event, hash('sha256', 'log-entry-1'));
        $second = $repository->record($event, hash('sha256', 'log-entry-1'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->occurrence_count);
        $this->assertTrue($repository->hasPayloadHash('production', 'laravel', $event->fingerprint, $event->occurredAt, hash('sha256', 'log-entry-1')));
    }

    public function test_it_updates_the_daily_aggregate_for_another_payload(): void
    {
        $repository = app(ErrorEventRepository::class);
        $first = $repository->record($this->event('2026-08-03 10:00:00'), hash('sha256', 'log-entry-1'));
        $updated = $repository->record($this->event('2026-08-03 11:00:00'), hash('sha256', 'log-entry-2'));

        $this->assertSame($first->id, $updated->id);
        $this->assertSame(2, $updated->occurrence_count);
        $this->assertSame('2026-08-03 10:00:00', $updated->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 11:00:00', $updated->last_occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_it_creates_a_new_event_on_the_following_day(): void
    {
        $repository = app(ErrorEventRepository::class);
        $first = $repository->record($this->event('2026-08-03 23:55:00'), hash('sha256', 'log-entry-1'));
        $second = $repository->record($this->event('2026-08-04 00:05:00'), hash('sha256', 'log-entry-2'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertCount(2, $repository->findByFingerprint('production', 'laravel', str_repeat('a', 64)));
    }

    public function test_it_stores_the_range_of_an_already_aggregated_event(): void
    {
        $repository = app(ErrorEventRepository::class);

        $stored = $repository->record(
            $this->event('2026-08-03 12:00:00', first: '2026-08-03 09:00:00', last: '2026-08-03 18:00:00', count: 5),
            hash('sha256', 'log-entry-1'),
        );

        $this->assertSame('2026-08-03 09:00:00', $stored->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 18:00:00', $stored->last_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $stored->occurrence_count);
    }

    public function test_an_aggregated_event_widens_the_range_of_an_existing_row(): void
    {
        $repository = app(ErrorEventRepository::class);
        $repository->record($this->event('2026-08-03 12:00:00'), hash('sha256', 'log-entry-1'));

        $updated = $repository->record(
            $this->event('2026-08-03 13:00:00', first: '2026-08-03 08:00:00', last: '2026-08-03 20:00:00', count: 3),
            hash('sha256', 'log-entry-2'),
        );

        // Both ends move outwards: the merged row spans every occurrence it
        // stands for, not just the representative timestamps.
        $this->assertSame('2026-08-03 08:00:00', $updated->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 20:00:00', $updated->last_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(4, $updated->occurrence_count);
    }

    public function test_a_range_inside_the_stored_one_leaves_it_untouched(): void
    {
        $repository = app(ErrorEventRepository::class);
        $repository->record(
            $this->event('2026-08-03 12:00:00', first: '2026-08-03 06:00:00', last: '2026-08-03 22:00:00'),
            hash('sha256', 'log-entry-1'),
        );

        $updated = $repository->record(
            $this->event('2026-08-03 13:00:00', first: '2026-08-03 10:00:00', last: '2026-08-03 14:00:00'),
            hash('sha256', 'log-entry-2'),
        );

        $this->assertSame('2026-08-03 06:00:00', $updated->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 22:00:00', $updated->last_occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_an_earlier_occurrence_moves_the_first_timestamp_back(): void
    {
        $repository = app(ErrorEventRepository::class);
        $repository->record($this->event('2026-08-03 12:00:00'), hash('sha256', 'log-entry-1'));

        $updated = $repository->record($this->event('2026-08-03 07:00:00'), hash('sha256', 'log-entry-2'));

        $this->assertSame('2026-08-03 07:00:00', $updated->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 12:00:00', $updated->last_occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_re_recording_the_same_payload_changes_neither_range_nor_count(): void
    {
        $repository = app(ErrorEventRepository::class);
        $event = $this->event('2026-08-03 12:00:00', first: '2026-08-03 09:00:00', last: '2026-08-03 18:00:00', count: 4);

        $repository->record($event, hash('sha256', 'log-entry-1'));
        $again = $repository->record($event, hash('sha256', 'log-entry-1'));

        $this->assertSame(4, $again->occurrence_count);
        $this->assertSame('2026-08-03 09:00:00', $again->first_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 18:00:00', $again->last_occurred_at->format('Y-m-d H:i:s'));
    }

    private function event(string $occurredAt, ?string $first = null, ?string $last = null, int $count = 1): ErrorEventData
    {
        return new ErrorEventData(
            environment: 'production',
            source: 'laravel',
            occurredAt: new DateTimeImmutable($occurredAt),
            exceptionClass: 'RuntimeException',
            message: 'Synthetic fixture message',
            normalizedMessage: 'Synthetic fixture message',
            file: '/var/www/app/Services/ExampleService.php',
            line: 32,
            method: 'POST',
            route: '/examples/{id}',
            statusCode: 500,
            stackFrames: [],
            fingerprint: str_repeat('a', 64),
            occurrenceCount: $count,
            firstOccurredAt: $first === null ? null : new DateTimeImmutable($first),
            lastOccurredAt: $last === null ? null : new DateTimeImmutable($last),
        );
    }
}
