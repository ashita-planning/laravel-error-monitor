<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Tests\Unit;

use AshitaPlanning\LaravelErrorMonitor\DTO\ErrorEventData;
use AshitaPlanning\LaravelErrorMonitor\DTO\StackFrameData;
use AshitaPlanning\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use AshitaPlanning\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use AshitaPlanning\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class Sha256FingerprintGeneratorTest extends TestCase
{
    public function test_it_is_reproducible_for_dynamic_values_of_the_same_error(): void
    {
        $normalizer = app(DefaultLogNormalizer::class);
        $generator = app(Sha256FingerprintGenerator::class);
        $first = $this->event($normalizer->normalize('Invoice id=1001 failed at 2026-08-03 10:20:30'));
        $second = $this->event($normalizer->normalize('Invoice id=2002 failed at 2026-08-04 10:20:30'));

        $this->assertSame($generator->generate($first), $generator->generate($second));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $generator->generate($first));
        $this->assertSame($generator->material($first), $generator->material($second));
    }

    public function test_it_distinguishes_a_genuinely_different_error(): void
    {
        $generator = app(Sha256FingerprintGenerator::class);

        $this->assertNotSame(
            $generator->generate($this->event('Invoice id={id} failed')),
            $generator->generate($this->event('Payment gateway signature verification failed')),
        );
    }

    private function event(string $normalizedMessage): ErrorEventData
    {
        return new ErrorEventData(
            environment: 'production',
            source: 'laravel',
            occurredAt: new DateTimeImmutable('2026-08-03 10:20:30'),
            exceptionClass: 'RuntimeException',
            message: $normalizedMessage,
            normalizedMessage: $normalizedMessage,
            file: '/var/www/app/Services/InvoiceService.php',
            line: 44,
            method: 'POST',
            route: '/invoices/1001?attempt=1',
            statusCode: 500,
            stackFrames: [new StackFrameData('/var/www/app/Services/InvoiceService.php', 44, 'App\\Services\\InvoiceService', 'charge', '->', true)],
            fingerprint: '',
        );
    }
}
