<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Tests\Feature;

use AshitaPlanning\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use AshitaPlanning\LaravelErrorMonitor\DTO\ErrorEventData;
use AshitaPlanning\LaravelErrorMonitor\Tests\TestCase;
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
            fingerprint: str_repeat('a', 64),
        );
    }
}
