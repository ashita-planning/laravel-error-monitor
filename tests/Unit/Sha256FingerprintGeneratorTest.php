<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Services\Sha256FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
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

    public function test_it_can_exclude_line_numbers_methods_and_routes(): void
    {
        config()->set('error-monitor.fingerprint.include_line_number', false);
        config()->set('error-monitor.fingerprint.include_method', false);
        config()->set('error-monitor.fingerprint.include_route', false);

        $generator = app(Sha256FingerprintGenerator::class);
        $material = $generator->material($this->event('Invoice id={id} failed'));

        $this->assertArrayNotHasKey('line', $material);
        $this->assertArrayNotHasKey('method', $material);
        $this->assertArrayNotHasKey('route', $material);
        $this->assertArrayNotHasKey('line', $material['application_frames'][0]);
    }

    public function test_a_moved_line_keeps_the_same_identity_when_line_numbers_are_excluded(): void
    {
        config()->set('error-monitor.fingerprint.include_line_number', false);

        $generator = app(Sha256FingerprintGenerator::class);

        $this->assertSame(
            $generator->generate($this->event('Invoice id={id} failed')),
            $generator->generate($this->event('Invoice id={id} failed', line: 4242)),
        );
    }

    public function test_it_falls_back_to_vendor_frames_when_there_is_no_application_frame(): void
    {
        $generator = app(Sha256FingerprintGenerator::class);
        $event = $this->event('Invoice id={id} failed')->with(stackFrames: [
            new StackFrameData('/var/www/vendor/laravel/framework/src/Router.php', 260, 'Router', 'run', '->', false),
        ]);

        $material = $generator->material($event);

        $this->assertSame('/var/www/vendor/laravel/framework/src/Router.php', $material['application_frames'][0]['file']);
    }

    public function test_a_different_environment_is_a_different_failure(): void
    {
        $generator = app(Sha256FingerprintGenerator::class);

        $this->assertNotSame(
            $generator->generate($this->event('Invoice id={id} failed')),
            $generator->generate($this->event('Invoice id={id} failed', environment: 'staging')),
        );
    }

    private function event(string $normalizedMessage, int $line = 44, string $environment = 'production'): ErrorEventData
    {
        return new ErrorEventData(
            environment: $environment,
            source: 'laravel',
            occurredAt: new DateTimeImmutable('2026-08-03 10:20:30'),
            exceptionClass: 'RuntimeException',
            message: $normalizedMessage,
            normalizedMessage: $normalizedMessage,
            file: '/var/www/app/Services/InvoiceService.php',
            line: $line,
            method: 'POST',
            route: '/invoices/1001?attempt=1',
            statusCode: 500,
            stackFrames: [new StackFrameData('/var/www/app/Services/InvoiceService.php', $line, 'App\\Services\\InvoiceService', 'charge', '->', true)],
            fingerprint: '',
        );
    }
}
