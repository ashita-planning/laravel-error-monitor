<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\Parsers\ApacheAccessLogParser;
use PHPUnit\Framework\TestCase;

final class ApacheAccessLogParserTest extends TestCase
{
    private ApacheAccessLogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = $this->parser();
    }

    public function test_it_keeps_server_errors_and_drops_successful_traffic(): void
    {
        $events = $this->parseFixture('apache-access.log');

        $this->assertSame([500, 502, 503, 500, 500], array_map(
            static fn (ErrorEventData $event): ?int => $event->statusCode,
            $events,
        ));
    }

    public function test_it_extracts_the_combined_format_fields(): void
    {
        $event = $this->parseFixture('apache-access.log')[0];

        $this->assertSame('GET', $event->method);
        $this->assertSame('/orders/12345', $event->route);
        $this->assertSame(500, $event->statusCode);
        $this->assertSame('2026-08-03T10:11:12+09:00', $event->occurredAt->format('c'));
        $this->assertSame('203.0.113.10', $event->context['client_ip']);
        $this->assertSame('https://shop.example.invalid/cart', $event->context['referer']);
        $this->assertStringContainsString('Mozilla/5.0', (string) $event->context['user_agent']);
        $this->assertSame(1234, $event->context['response_bytes']);
        $this->assertSame('HTTP/1.1', $event->context['protocol']);
    }

    public function test_the_status_is_reported_not_assumed(): void
    {
        $event = $this->parseFixture('apache-access.log')[0];

        // Unlike a Laravel entry, the server states the status outright.
        $this->assertSame('access_log', $event->metadata['status_source']);
        $this->assertFalse($event->metadata['status_estimated']);
    }

    public function test_it_drops_the_query_string_at_the_source(): void
    {
        $event = $this->parseFixture('apache-access.log')[3];

        $this->assertSame('/search', $event->route);
        $this->assertStringNotContainsString('token', (string) $event->route);
        $this->assertStringNotContainsString('secret', (string) $event->route);
    }

    public function test_it_handles_escaped_quotes_inside_the_user_agent(): void
    {
        $event = $this->parseFixture('apache-access.log')[3];

        $this->assertStringContainsString('QuoteBot', (string) $event->context['user_agent']);
    }

    public function test_it_reads_the_common_format_without_referer_and_agent(): void
    {
        $events = $this->parseFixture('apache-access-common.log');

        $this->assertCount(1, $events);
        $this->assertSame('/orders/99', $events[0]->route);
        $this->assertArrayNotHasKey('referer', $events[0]->context);
        $this->assertArrayNotHasKey('user_agent', $events[0]->context);
    }

    public function test_it_reads_a_gzip_rotated_log(): void
    {
        $events = $this->parseFixture('apache-access-rotated.log.gz');

        $this->assertCount(5, $events);
        $this->assertSame('/orders/12345', $events[0]->route);
    }

    public function test_it_skips_a_malformed_line(): void
    {
        // The fixture holds one, and the entries after it still arrive.
        $events = $this->parseFixture('apache-access.log');

        $this->assertSame('/reports', $events[4]->route);
    }

    public function test_a_timestamp_offset_wins_over_the_configured_timezone(): void
    {
        $parser = $this->parser(timezone: 'UTC');

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent(
            '203.0.113.30 - - [03/Aug/2026:10:00:00 -0500] "GET /x HTTP/1.1" 500 1',
        ), false);

        $this->assertSame('2026-08-03T10:00:00-05:00', $events[0]->occurredAt->format('c'));
    }

    public function test_a_custom_log_format_is_supported_through_a_named_pattern(): void
    {
        $parser = $this->parser(patterns: [
            '/^(?P<time>[^\|]+)\|(?P<request_id>\S+)\|(?P<request>[^|]+)\|(?P<status>\d{3})$/',
        ]);

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent(
            '03/Aug/2026:10:11:12 +0900|req-abc-123|GET /orders/7 HTTP/1.1|503',
        ), false);

        $this->assertCount(1, $events);
        $this->assertSame(503, $events[0]->statusCode);
        $this->assertSame('req-abc-123', $events[0]->context['request_id']);
        $this->assertSame('/orders/7', $events[0]->route);
    }

    public function test_the_status_range_is_configurable(): void
    {
        $parser = $this->parser(statusRanges: [[502, 502]]);

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent(implode("\n", [
            '203.0.113.40 - - [03/Aug/2026:10:00:00 +0900] "GET /a HTTP/1.1" 500 1',
            '203.0.113.41 - - [03/Aug/2026:10:00:01 +0900] "GET /b HTTP/1.1" 502 1',
        ])), false);

        $this->assertCount(1, $events);
        $this->assertSame(502, $events[0]->statusCode);
    }

    public function test_it_claims_only_apache_access_files(): void
    {
        $this->assertTrue($this->parser->supports(new LogFileData('/var/log/apache2/access.log', 'apache_access')));
        $this->assertFalse($this->parser->supports(new LogFileData('/var/log/laravel.log', 'laravel')));
    }

    public function test_it_returns_nothing_for_a_missing_file(): void
    {
        $file = new LogFileData(__DIR__.'/does-not-exist.log', 'apache_access');

        $this->assertSame([], iterator_to_array($this->parser->parse($file)));
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $statusRanges
     * @param  array<int, string>  $patterns
     */
    private function parser(string $timezone = 'Asia/Tokyo', array $statusRanges = [[500, 599]], array $patterns = []): ApacheAccessLogParser
    {
        return new ApacheAccessLogParser(
            timezone: $timezone,
            statusRanges: $statusRanges,
            patterns: $patterns,
            environment: 'production',
        );
    }

    /** @return array<int, ErrorEventData> */
    private function parseFixture(string $name): array
    {
        $file = new LogFileData(dirname(__DIR__).'/Fixtures/'.$name, 'apache_access');

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($this->parser->parse($file), false);

        return $events;
    }
}
