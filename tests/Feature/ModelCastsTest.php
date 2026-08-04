<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorIssue;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

/**
 * The casts have to be declared as a property, not through the `casts()`
 * method: that method arrived in Laravel 11 and is silently ignored on Laravel
 * 10, which hands the repository raw strings where it expects dates and arrays.
 * The failure only appears on the oldest supported framework, so it is pinned
 * here rather than left to the compatibility matrix to discover.
 */
final class ModelCastsTest extends TestCase
{
    public function test_the_event_casts_are_visible_on_every_supported_laravel(): void
    {
        $casts = (new ErrorMonitorEvent)->getCasts();

        $this->assertSame('date', $casts['detected_date'] ?? null);
        $this->assertSame('immutable_datetime', $casts['first_occurred_at'] ?? null);
        $this->assertSame('immutable_datetime', $casts['last_occurred_at'] ?? null);
        $this->assertSame('array', $casts['context'] ?? null);
        $this->assertSame('array', $casts['metadata'] ?? null);
    }

    public function test_the_issue_casts_are_visible_on_every_supported_laravel(): void
    {
        $casts = (new ErrorMonitorIssue)->getCasts();

        $this->assertSame('immutable_datetime', $casts['last_reported_at'] ?? null);
        $this->assertSame('immutable_datetime', $casts['resolved_at'] ?? null);
    }

    public function test_a_stored_event_reads_back_as_dates_and_arrays(): void
    {
        ErrorMonitorEvent::query()->create([
            'environment' => 'production',
            'source' => 'laravel',
            'fingerprint' => str_repeat('a', 64),
            'detected_date' => '2026-08-03',
            'first_occurred_at' => '2026-08-03 10:00:00',
            'last_occurred_at' => '2026-08-03 11:00:00',
            'exception_class' => 'RuntimeException',
            'normalized_message' => 'Boom',
            'payload_hash' => str_repeat('b', 64),
            'context' => ['level' => 'ERROR'],
            'metadata' => ['status_estimated' => true],
        ]);

        $stored = ErrorMonitorEvent::query()->firstOrFail();

        $this->assertInstanceOf(DateTimeImmutable::class, $stored->first_occurred_at);
        $this->assertInstanceOf(DateTimeImmutable::class, $stored->last_occurred_at);
        $this->assertSame('2026-08-03', $stored->detected_date->format('Y-m-d'));
        $this->assertSame(['level' => 'ERROR'], $stored->context);
        $this->assertSame(['status_estimated' => true], $stored->metadata);
    }
}
