<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\Parsers\ApacheErrorLogParser;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\ServerErrorClassifier;
use PHPUnit\Framework\TestCase;

final class ApacheErrorLogParserTest extends TestCase
{
    private ApacheErrorLogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = $this->parser();
    }

    public function test_it_reads_every_entry_of_the_fixture(): void
    {
        $events = $this->parseFixture('apache-error.log');

        $this->assertCount(9, $events);
    }

    public function test_it_classifies_each_kind_of_failure(): void
    {
        $categories = array_map(
            static fn (ErrorEventData $event): mixed => $event->metadata['error_category'],
            $this->parseFixture('apache-error.log'),
        );

        $this->assertSame([
            ServerErrorClassifier::PHP_FATAL,
            // The AH01071 quotes a PHP fatal, but "the memory limit is too low"
            // is the actionable statement, so that wins over the transport.
            ServerErrorClassifier::MEMORY_EXHAUSTED,
            ServerErrorClassifier::TIMEOUT,
            ServerErrorClassifier::PERMISSION,
            ServerErrorClassifier::FASTCGI,
            ServerErrorClassifier::MISSING_FILE,
            ServerErrorClassifier::CONFIGURATION,
            ServerErrorClassifier::SERVER_INTERNAL,
            ServerErrorClassifier::PHP_FATAL,
        ], $categories);
    }

    public function test_it_extracts_the_header_fields(): void
    {
        $event = $this->parseFixture('apache-error.log')[0];

        $this->assertSame('2026-08-03T10:11:12+09:00', $event->occurredAt->format('c'));
        $this->assertSame('php', $event->context['module']);
        $this->assertSame('error', $event->context['level']);
        $this->assertSame('12345', $event->context['pid']);
        $this->assertSame('203.0.113.10', $event->context['client_ip'], 'The source port is not part of the client identity.');
        $this->assertSame('/srv/app/app/Services/OrderService.php', $event->file);
        $this->assertSame(42, $event->line);
        $this->assertSame('TypeError', $event->exceptionClass);
    }

    public function test_a_multi_line_stack_trace_becomes_one_event(): void
    {
        $event = $this->parseFixture('apache-error.log')[0];

        $this->assertCount(2, $event->stackFrames);
        $this->assertSame('/srv/app/app/Http/Controllers/OrderController.php', $event->stackFrames[0]->file);
        $this->assertSame(88, $event->stackFrames[0]->line);
        $this->assertTrue($event->stackFrames[0]->isApplicationFrame);
        $this->assertFalse($event->stackFrames[1]->isApplicationFrame, 'The framework frame is not ours.');
        // The trace belongs to the frames; the message stays the first line.
        $this->assertStringNotContainsString('Stack trace', $event->message);
    }

    public function test_the_status_is_derived_from_the_category_and_says_so(): void
    {
        $events = $this->parseFixture('apache-error.log');

        $this->assertSame(500, $events[0]->statusCode);
        $this->assertSame('error_category', $events[0]->metadata['status_source']);
        $this->assertTrue($events[0]->metadata['status_estimated'], 'An error log reports no HTTP status.');

        // Keeping these off 500 is what stops scanner noise and denied probes
        // from being stored as server errors under the default status filter.
        $this->assertSame(403, $events[3]->statusCode, 'A permission failure is a 403.');
        $this->assertSame(404, $events[5]->statusCode, 'A missing file is a 404.');
    }

    public function test_it_reads_the_errno_prefix_that_precedes_the_client(): void
    {
        $event = $this->parseFixture('apache-error.log')[3];

        $this->assertSame('13', $event->context['errno']);
        $this->assertSame('203.0.113.13', $event->context['client_ip']);
        $this->assertStringContainsString('Permission denied', $event->message);
    }

    public function test_it_keeps_the_referer_out_of_the_message(): void
    {
        $event = $this->parseFixture('apache-error.log')[1];

        $this->assertSame('https://shop.example.invalid/reports', $event->context['referer']);
        $this->assertStringNotContainsString('referer:', $event->message);
    }

    public function test_an_entry_without_a_client_is_still_read(): void
    {
        $event = $this->parseFixture('apache-error.log')[8];

        $this->assertArrayNotHasKey('client_ip', $event->context);
        $this->assertStringContainsString('PHP Startup', $event->message);
    }

    public function test_it_reads_a_gzip_rotated_log(): void
    {
        $this->assertCount(9, $this->parseFixture('apache-error-rotated.log.gz'));
    }

    public function test_it_survives_a_line_belonging_to_no_entry(): void
    {
        // The fixture holds one between two entries; both still arrive.
        $events = $this->parseFixture('apache-error.log');

        $this->assertStringContainsString('child pid', $events[7]->message);
        $this->assertStringContainsString('PHP Startup', $events[8]->message);
    }

    public function test_the_configured_timezone_is_used_when_apache_writes_no_offset(): void
    {
        $parser = $this->parser(timezone: 'UTC');

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent(
            '[Mon Aug 03 10:11:12.123456 2026] [php:error] [pid 1] PHP Fatal error: boom',
        ), false);

        $this->assertSame('2026-08-03T10:11:12+00:00', $events[0]->occurredAt->format('c'));
    }

    public function test_it_reads_the_apache_2_2_header_without_a_module(): void
    {
        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($this->parser->parseContent(
            '[Mon Aug 03 10:11:12 2026] [error] [client 203.0.113.9] File does not exist: /srv/app/public/robots.txt',
        ), false);

        $this->assertCount(1, $events);
        $this->assertArrayNotHasKey('module', $events[0]->context);
        $this->assertSame('error', $events[0]->context['level']);
        $this->assertSame('203.0.113.9', $events[0]->context['client_ip']);
    }

    public function test_the_level_filter_is_honoured(): void
    {
        $parser = $this->parser(levels: ['emerg']);

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent(implode("\n", [
            '[Mon Aug 03 10:11:12.000000 2026] [php:error] [pid 1] ignored',
            '[Mon Aug 03 10:12:12.000000 2026] [core:emerg] [pid 2] kept',
        ])), false);

        $this->assertCount(1, $events);
        $this->assertSame('kept', $events[0]->message);
    }

    public function test_it_claims_only_apache_error_files(): void
    {
        $this->assertTrue($this->parser->supports(new LogFileData('/var/log/apache2/error.log', 'apache_error')));
        $this->assertFalse($this->parser->supports(new LogFileData('/var/log/apache2/access.log', 'apache_access')));
    }

    public function test_it_returns_nothing_for_a_missing_file(): void
    {
        $file = new LogFileData(__DIR__.'/does-not-exist.log', 'apache_error');

        $this->assertSame([], iterator_to_array($this->parser->parse($file)));
    }

    /** @param array<int, string> $levels */
    private function parser(string $timezone = 'Asia/Tokyo', array $levels = ['error', 'crit', 'alert', 'emerg', 'warn', 'notice']): ApacheErrorLogParser
    {
        return new ApacheErrorLogParser(
            frameDetector: new ApplicationFrameDetector(applicationPaths: ['app/', 'routes/'], vendorPaths: ['vendor/']),
            classifier: new ServerErrorClassifier,
            timezone: $timezone,
            levels: $levels,
            environment: 'production',
        );
    }

    /** @return array<int, ErrorEventData> */
    private function parseFixture(string $name): array
    {
        $file = new LogFileData(dirname(__DIR__).'/Fixtures/'.$name, 'apache_error');

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($this->parser->parse($file), false);

        return $events;
    }
}
