<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;

final class ErrorEventDataTest extends TestCase
{
    public function test_it_exposes_every_documented_attribute(): void
    {
        $event = $this->event();

        $this->assertSame('production', $event->environment);
        $this->assertSame('laravel', $event->source);
        $this->assertSame('2026-08-03T10:20:30+00:00', $event->occurredAt->format('c'));
        $this->assertSame('RuntimeException', $event->exceptionClass);
        $this->assertSame('Invoice id=1001 failed', $event->message);
        $this->assertSame('Invoice id={id} failed', $event->normalizedMessage);
        $this->assertSame('/var/www/app/Services/InvoiceService.php', $event->file);
        $this->assertSame(44, $event->line);
        $this->assertSame('POST', $event->method);
        $this->assertSame('/invoices/{id}', $event->route);
        $this->assertSame(500, $event->statusCode);
        $this->assertCount(1, $event->stackFrames);
        $this->assertSame(1, $event->occurrenceCount);
        $this->assertSame(['status_estimated' => true], $event->metadata);
    }

    public function test_it_is_immutable(): void
    {
        $event = $this->event();

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line intentional write to a readonly property
        $event->environment = 'staging';
    }

    public function test_with_returns_a_new_instance(): void
    {
        $event = $this->event();
        $updated = $event->with(occurrenceCount: 4, metadata: ['confidence' => 'high']);

        $this->assertNotSame($event, $updated);
        $this->assertSame(1, $event->occurrenceCount);
        $this->assertSame(4, $updated->occurrenceCount);
        $this->assertSame(['confidence' => 'high'], $updated->metadata);
        $this->assertSame($event->environment, $updated->environment);
    }

    public function test_it_rejects_an_empty_environment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(environment: '  ');
    }

    public function test_it_rejects_an_impossible_status_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(statusCode: 99);
    }

    public function test_it_rejects_a_non_positive_occurrence_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(occurrenceCount: 0);
    }

    public function test_it_rejects_a_malformed_fingerprint(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(fingerprint: 'not-a-digest');
    }

    public function test_it_rejects_an_inverted_occurrence_window(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(
            firstOccurredAt: new DateTimeImmutable('2026-08-03 12:00:00'),
            lastOccurredAt: new DateTimeImmutable('2026-08-03 09:00:00'),
        );
    }

    public function test_it_serializes_to_an_array(): void
    {
        $array = $this->event()->toArray();

        $this->assertSame('production', $array['environment']);
        $this->assertSame('Invoice id={id} failed', $array['normalized_message']);
        $this->assertSame(['status_estimated' => true], $array['metadata']);
        $this->assertSame('/var/www/app/Services/InvoiceService.php', $array['stack_frames'][0]['file']);
    }

    public function test_it_reports_application_frames_and_the_daily_bucket(): void
    {
        $event = $this->event()->with(stackFrames: [
            new StackFrameData('/var/www/vendor/laravel/framework/src/Foo.php', 12, 'Foo', 'bar', '->', false),
            new StackFrameData('/var/www/app/Services/InvoiceService.php', 44, 'InvoiceService', 'charge', '->', true),
        ]);

        $this->assertCount(1, $event->applicationFrames());
        $this->assertSame('2026-08-03', $event->detectedDate('UTC'));
        $this->assertSame('2026-08-03', $event->detectedDate('Asia/Tokyo'));
    }

    private function event(
        string $environment = 'production',
        ?int $statusCode = 500,
        int $occurrenceCount = 1,
        string $fingerprint = '',
        ?DateTimeImmutable $firstOccurredAt = null,
        ?DateTimeImmutable $lastOccurredAt = null,
    ): ErrorEventData {
        return new ErrorEventData(
            environment: $environment,
            source: 'laravel',
            occurredAt: new DateTimeImmutable('2026-08-03 10:20:30'),
            exceptionClass: 'RuntimeException',
            message: 'Invoice id=1001 failed',
            normalizedMessage: 'Invoice id={id} failed',
            file: '/var/www/app/Services/InvoiceService.php',
            line: 44,
            method: 'POST',
            route: '/invoices/{id}',
            statusCode: $statusCode,
            stackFrames: [new StackFrameData('/var/www/app/Services/InvoiceService.php', 44, 'InvoiceService', 'charge', '->', true)],
            fingerprint: $fingerprint,
            occurrenceCount: $occurrenceCount,
            firstOccurredAt: $firstOccurredAt,
            lastOccurredAt: $lastOccurredAt,
            metadata: ['status_estimated' => true],
        );
    }
}
