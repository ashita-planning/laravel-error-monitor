<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\DTO\StackFrameData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Tests\Doubles\ArrayLogCollector;
use Apkk\LaravelErrorMonitor\Tests\Doubles\ArrayLogParser;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class ErrorMonitorAnalyzerTest extends TestCase
{
    public function test_the_bundled_drivers_are_configured_by_default(): void
    {
        $result = app(ErrorMonitorAnalyzer::class)->analyze();

        // The Laravel log driver and the Apache access log driver.
        $this->assertSame(2, $result->sourcesConfigured);
        // Both test log paths point at a directory that does not exist.
        $this->assertSame(0, $result->filesAnalyzed);
        $this->assertSame([], $result->warnings);
    }

    public function test_it_reports_that_no_collector_is_configured(): void
    {
        $analyzer = new ErrorMonitorAnalyzer(
            masker: app(SensitiveDataMasker::class),
            normalizer: app(LogNormalizer::class),
            fingerprintGenerator: app(FingerprintGenerator::class),
            repository: app(ErrorEventRepository::class),
        );

        $result = $analyzer->analyze();

        $this->assertSame(0, $result->filesAnalyzed);
        $this->assertSame(0, $result->sourcesConfigured);
        $this->assertContains('No log collector is configured yet.', $result->warnings);
    }

    public function test_a_parser_that_does_not_claim_the_file_is_skipped(): void
    {
        $analyzer = new ErrorMonitorAnalyzer(
            masker: app(SensitiveDataMasker::class),
            normalizer: app(LogNormalizer::class),
            fingerprintGenerator: app(FingerprintGenerator::class),
            repository: app(ErrorEventRepository::class),
            collectors: [new ArrayLogCollector([new LogFileData('/var/log/laravel.log', 'laravel')])],
            parsers: [new ArrayLogParser([$this->event()], source: 'apache_access')],
        );

        $result = $analyzer->analyze();

        $this->assertSame(0, $result->eventsDetected);
        $this->assertStringContainsString('No parser is registered for source [laravel]', implode(' ', $result->warnings));
    }

    public function test_it_masks_normalizes_and_fingerprints_before_storing(): void
    {
        $result = $this->analyzer([$this->event()])->analyze();

        $this->assertSame(1, $result->filesAnalyzed);
        $this->assertSame(1, $result->eventsDetected);
        $this->assertSame(1, $result->eventsStored);

        $stored = ErrorMonitorEvent::query()->firstOrFail();

        $this->assertStringNotContainsString('user@example.invalid', $stored->normalized_message);
        $this->assertStringNotContainsString('203.0.113.42', $stored->normalized_message);
        $this->assertStringContainsString('{email}', $stored->normalized_message);
        $this->assertStringContainsString('{ip}', $stored->normalized_message);
        $this->assertSame(64, strlen((string) $stored->fingerprint));
        $this->assertSame('{secret}', $stored->context['password'] ?? null);
        $this->assertSame(['status_estimated' => true], $stored->metadata);
    }

    public function test_running_it_twice_over_the_same_log_stores_one_event(): void
    {
        $analyzer = $this->analyzer([$this->event()]);

        $analyzer->analyze();
        $second = $analyzer->analyze();

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
        $this->assertSame(1, ErrorMonitorEvent::query()->firstOrFail()->occurrence_count);
        $this->assertSame(1, $second->eventsSkipped);
        $this->assertSame(0, $second->eventsStored);
    }

    public function test_force_records_the_occurrence_again(): void
    {
        $analyzer = $this->analyzer([$this->event()]);

        $analyzer->analyze();
        $analyzer->analyze(force: true);

        $this->assertSame(1, ErrorMonitorEvent::query()->count());
        $this->assertSame(2, ErrorMonitorEvent::query()->firstOrFail()->occurrence_count);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $result = $this->analyzer([$this->event()])->analyze(dryRun: true);

        $this->assertSame(1, $result->eventsStored);
        $this->assertTrue($result->dryRun);
        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }

    public function test_it_skips_events_outside_the_window(): void
    {
        $window = AnalysisWindowData::forDate('2026-08-04', 'UTC');
        $result = $this->analyzer([$this->event()])->analyze($window);

        $this->assertSame(1, $result->eventsDetected);
        $this->assertSame(1, $result->eventsSkipped);
        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }

    public function test_it_skips_events_outside_the_configured_status_codes(): void
    {
        $result = $this->analyzer([$this->event(statusCode: 404)])->analyze();

        $this->assertSame(1, $result->eventsSkipped);
        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }

    public function test_it_can_be_restricted_to_one_source(): void
    {
        $result = $this->analyzer([$this->event()])->analyze(source: 'apache_access');

        $this->assertSame(0, $result->filesAnalyzed);
        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }

    public function test_a_broken_log_does_not_abort_the_run(): void
    {
        $analyzer = new ErrorMonitorAnalyzer(
            masker: app(SensitiveDataMasker::class),
            normalizer: app(LogNormalizer::class),
            fingerprintGenerator: app(FingerprintGenerator::class),
            repository: app(ErrorEventRepository::class),
            collectors: [new ArrayLogCollector([new LogFileData('/var/log/laravel.log', 'laravel')])],
            parsers: [new ArrayLogParser(throws: true)],
        );

        $result = $analyzer->analyze();

        $this->assertSame(1, $result->filesAnalyzed);
        $this->assertSame(0, $result->eventsStored);
        $this->assertStringContainsString('Malformed log entry.', implode(' ', $result->warnings));
    }

    public function test_it_warns_when_no_parser_is_registered(): void
    {
        $analyzer = new ErrorMonitorAnalyzer(
            masker: app(SensitiveDataMasker::class),
            normalizer: app(LogNormalizer::class),
            fingerprintGenerator: app(FingerprintGenerator::class),
            repository: app(ErrorEventRepository::class),
            collectors: [new ArrayLogCollector([new LogFileData('/var/log/laravel.log', 'laravel')])],
        );

        $this->assertStringContainsString('No parser is registered', implode(' ', $analyzer->analyze()->warnings));
    }

    /** @param array<int, ErrorEventData> $events */
    private function analyzer(array $events): ErrorMonitorAnalyzer
    {
        return new ErrorMonitorAnalyzer(
            masker: app(SensitiveDataMasker::class),
            normalizer: app(LogNormalizer::class),
            fingerprintGenerator: app(FingerprintGenerator::class),
            repository: app(ErrorEventRepository::class),
            collectors: [new ArrayLogCollector([new LogFileData('/var/log/laravel.log', 'laravel')])],
            parsers: [new ArrayLogParser($events)],
        );
    }

    private function event(?int $statusCode = 500): ErrorEventData
    {
        $message = 'Order id=1201 failed for user@example.invalid from 203.0.113.42';

        return new ErrorEventData(
            environment: 'production',
            source: 'laravel',
            occurredAt: new DateTimeImmutable('2026-08-03 10:20:30'),
            exceptionClass: 'RuntimeException',
            message: $message,
            normalizedMessage: $message,
            file: '/var/www/app/Services/OrderService.php',
            line: 44,
            method: 'POST',
            route: '/orders/1201?attempt=1',
            statusCode: $statusCode,
            stackFrames: [new StackFrameData('/var/www/app/Services/OrderService.php', 44, 'OrderService', 'charge', '->', true)],
            fingerprint: '',
            context: ['password' => 'fake-value', 'client_ip' => '203.0.113.42'],
            metadata: ['status_estimated' => true],
        );
    }
}
