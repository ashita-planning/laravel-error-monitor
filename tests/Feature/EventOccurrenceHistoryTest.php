<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEventOccurrence;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * A daily aggregate can only hold one `payload_hash`, so it remembers the entry
 * processed last and nothing else. Every distinct payload merged into it is
 * recorded separately, which is what stops a re-analysis from counting the
 * earlier entries of the same day all over again.
 */
final class EventOccurrenceHistoryTest extends TestCase
{
    public function test_the_first_payload_is_recorded(): void
    {
        $repository = app(ErrorEventRepository::class);

        $stored = $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));

        $this->assertSame(1, ErrorMonitorEventOccurrence::query()->count());
        $this->assertTrue($repository->hasPayloadHash('production', 'laravel', $this->fingerprint(), $stored->first_occurred_at, $this->hash('a')));
        $this->assertSame(1, $stored->occurrences()->count());
    }

    public function test_re_recording_the_same_payload_does_not_add_to_the_count(): void
    {
        $repository = app(ErrorEventRepository::class);

        $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));
        $again = $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));

        $this->assertSame(1, $again->occurrence_count);
        $this->assertSame(1, ErrorMonitorEventOccurrence::query()->count());
    }

    public function test_a_different_payload_adds_to_the_count(): void
    {
        $repository = app(ErrorEventRepository::class);

        $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));
        $updated = $repository->record($this->event('2026-08-03 11:00:00'), $this->hash('b'));

        $this->assertSame(2, $updated->occurrence_count);
        $this->assertSame(2, ErrorMonitorEventOccurrence::query()->count());
    }

    public function test_an_earlier_payload_of_the_same_day_stays_recognised(): void
    {
        $repository = app(ErrorEventRepository::class);
        $detectedAt = new DateTimeImmutable('2026-08-03 10:00:00');

        $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));
        $repository->record($this->event('2026-08-03 11:00:00'), $this->hash('b'));

        // The aggregate's own payload_hash now holds "b". Asking about "a" has
        // to keep answering true, or the analyzer replays it on the next run.
        $this->assertTrue($repository->hasPayloadHash('production', 'laravel', $this->fingerprint(), $detectedAt, $this->hash('a')));
        $this->assertTrue($repository->hasPayloadHash('production', 'laravel', $this->fingerprint(), $detectedAt, $this->hash('b')));
        $this->assertFalse($repository->hasPayloadHash('production', 'laravel', $this->fingerprint(), $detectedAt, $this->hash('c')));
    }

    public function test_re_analysing_the_whole_day_changes_nothing(): void
    {
        $repository = app(ErrorEventRepository::class);
        $entries = [
            ['2026-08-03 10:00:00', 'a'],
            ['2026-08-03 11:00:00', 'b'],
            ['2026-08-03 12:00:00', 'c'],
        ];

        foreach ($entries as [$occurredAt, $payload]) {
            $repository->record($this->event($occurredAt), $this->hash($payload));
        }

        foreach ($entries as [$occurredAt, $payload]) {
            $repository->record($this->event($occurredAt), $this->hash($payload));
        }

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
        $this->assertSame(3, ErrorMonitorEvent::query()->firstOrFail()->occurrence_count);
        $this->assertSame(3, ErrorMonitorEventOccurrence::query()->count());
    }

    public function test_the_same_payload_cannot_be_recorded_twice_for_one_aggregate(): void
    {
        $repository = app(ErrorEventRepository::class);
        $stored = $repository->record($this->event('2026-08-03 10:00:00'), $this->hash('a'));

        // What a concurrent run would attempt after both passed the read.
        $this->expectException(QueryException::class);

        ErrorMonitorEventOccurrence::query()->create([
            'error_monitor_event_id' => $stored->id,
            'payload_hash' => $this->hash('a'),
            'occurred_at' => new DateTimeImmutable('2026-08-03 10:00:00'),
            'occurrence_count' => 1,
        ]);
    }

    public function test_the_following_day_gets_its_own_aggregate_and_history(): void
    {
        $repository = app(ErrorEventRepository::class);

        $first = $repository->record($this->event('2026-08-03 23:55:00'), $this->hash('a'));
        $second = $repository->record($this->event('2026-08-04 00:05:00'), $this->hash('a'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $first->occurrences()->count());
        $this->assertSame(1, $second->occurrences()->count());
    }

    public function test_the_migration_rolls_back_and_forward(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_04_000000_create_error_monitor_event_occurrences_table.php';

        $migration->down();
        $this->assertFalse(Schema::hasTable('error_monitor_event_occurrences'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('error_monitor_event_occurrences'));
    }

    private function hash(string $seed): string
    {
        return hash('sha256', 'log-entry-'.$seed);
    }

    private function fingerprint(): string
    {
        return str_repeat('a', 64);
    }

    private function event(string $occurredAt): ErrorEventData
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
            fingerprint: $this->fingerprint(),
        );
    }
}
