<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use Apkk\LaravelErrorMonitor\Parsers\LaravelLogParser;
use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use Apkk\LaravelErrorMonitor\Support\HttpStatusResolver;
use PHPUnit\Framework\TestCase;

final class LaravelLogParserTest extends TestCase
{
    private LaravelLogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new LaravelLogParser(
            frameDetector: new ApplicationFrameDetector(
                applicationPaths: ['app/', 'routes/'],
                vendorPaths: ['vendor/'],
            ),
            statusResolver: new HttpStatusResolver,
            timezone: 'Asia/Tokyo',
            levels: ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
        );
    }

    public function test_it_parses_every_error_entry_and_skips_lower_levels(): void
    {
        $this->assertCount(4, $this->parseFixture());
    }

    public function test_it_extracts_the_exception_details(): void
    {
        $event = $this->parseFixture()[0];

        $this->assertSame('Illuminate\Database\Eloquent\ModelNotFoundException', $event->exceptionClass);
        $this->assertSame('No query results for model [App\Models\Reservation] 12345', $event->message);
        $this->assertSame('/srv/app/app/Http/Controllers/ReservationController.php', $event->file);
        $this->assertSame(42, $event->line);
        $this->assertSame('production', $event->environment);
        $this->assertSame('laravel', $event->source);
        $this->assertSame('2026-08-03T10:11:12+09:00', $event->occurredAt->format('c'));
    }

    public function test_it_leaves_masking_and_fingerprinting_to_the_analyzer(): void
    {
        $event = $this->parseFixture()[0];

        $this->assertSame('', $event->fingerprint);
        $this->assertSame($event->message, $event->normalizedMessage);
    }

    public function test_it_flags_application_frames(): void
    {
        $event = $this->parseFixture()[0];

        $this->assertCount(2, $event->stackFrames);
        $this->assertTrue($event->stackFrames[0]->isApplicationFrame);
        $this->assertFalse($event->stackFrames[1]->isApplicationFrame, 'A vendor frame is not application code.');

        $applicationFrames = $event->applicationFrames();

        $this->assertCount(1, $applicationFrames);
        $this->assertSame('/srv/app/app/Http/Controllers/ReservationController.php', $applicationFrames[0]->file);
        $this->assertSame('App\Http\Controllers\ReservationController', $applicationFrames[0]->class);
        $this->assertSame('findReservation', $applicationFrames[0]->function);
        $this->assertSame('->', $applicationFrames[0]->type);
    }

    public function test_it_resolves_http_status_codes_from_the_exception_class(): void
    {
        $events = $this->parseFixture();

        $this->assertSame(404, $events[0]->statusCode, 'ModelNotFoundException answers 404.');
        $this->assertSame(500, $events[2]->statusCode, 'A query exception answers 500.');
        $this->assertSame(404, $events[3]->statusCode, 'NotFoundHttpException answers 404.');
    }

    public function test_it_records_how_the_status_was_resolved(): void
    {
        $events = $this->parseFixture();

        $this->assertSame('exception_class', $events[0]->metadata['status_source']);
        $this->assertFalse($events[0]->metadata['status_estimated']);
        $this->assertSame('ERROR', $events[0]->metadata['log_level']);

        // A QueryException has no mapped status, so 500 is an assumption and
        // has to be reported as one instead of as a fact.
        $this->assertSame('assumed', $events[2]->metadata['status_source']);
        $this->assertTrue($events[2]->metadata['status_estimated']);
    }

    public function test_an_explicit_status_in_the_context_wins(): void
    {
        $events = $this->parseContent('[2026-08-03 10:11:12] production.ERROR: Boom {"status":503,"method":"get","url":"/health"}');

        $this->assertSame(503, $events[0]->statusCode);
        $this->assertSame('context', $events[0]->metadata['status_source']);
        $this->assertFalse($events[0]->metadata['status_estimated']);
    }

    public function test_it_reads_the_json_context_when_it_is_decodable(): void
    {
        $events = $this->parseContent('[2026-08-03 10:11:12] production.ERROR: Boom {"method":"post","url":"/reservations/12"}');

        $this->assertCount(1, $events);
        $this->assertSame('POST', $events[0]->method);
        $this->assertSame('/reservations/12', $events[0]->route);
        $this->assertSame('Boom', $events[0]->message);
        $this->assertSame('ERROR', $events[0]->context['level']);
    }

    public function test_an_entry_without_a_throwable_reports_an_unknown_exception(): void
    {
        $events = $this->parseContent('[2026-08-03 10:11:12] production.ERROR: Queue worker stopped');

        $this->assertSame(LaravelLogParser::UNKNOWN_EXCEPTION, $events[0]->exceptionClass);
        $this->assertSame('Queue worker stopped', $events[0]->message);
        $this->assertNull($events[0]->file);
        $this->assertNull($events[0]->line);
        $this->assertSame([], $events[0]->stackFrames);
    }

    public function test_it_survives_a_malformed_entry(): void
    {
        $events = $this->parseContent(implode("\n", [
            'this line is not a log entry at all',
            '[2026-08-03 10:11:12] production.ERROR: first',
            '#0 [truncated',
            '[2026-08-03 10:12:12] production.ERROR: second',
        ]));

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]->message);
        $this->assertSame('second', $events[1]->message);
    }

    public function test_it_honours_the_configured_levels(): void
    {
        $parser = new LaravelLogParser(
            frameDetector: new ApplicationFrameDetector(applicationPaths: [], vendorPaths: []),
            statusResolver: new HttpStatusResolver,
            timezone: 'UTC',
            levels: ['EMERGENCY'],
        );

        $content = implode("\n", [
            '[2026-08-03 10:11:12] production.ERROR: ignored',
            '[2026-08-03 10:12:12] production.EMERGENCY: kept',
        ]);

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent($content), false);

        $this->assertCount(1, $events);
        $this->assertSame('kept', $events[0]->message);
    }

    public function test_it_can_override_the_environment_written_in_the_log(): void
    {
        $parser = new LaravelLogParser(
            frameDetector: new ApplicationFrameDetector(applicationPaths: [], vendorPaths: []),
            statusResolver: new HttpStatusResolver,
            timezone: 'UTC',
            levels: ['ERROR'],
            environment: 'staging',
        );

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($parser->parseContent('[2026-08-03 10:11:12] production.ERROR: Boom'), false);

        $this->assertSame('staging', $events[0]->environment);
    }

    public function test_it_claims_only_laravel_log_files(): void
    {
        $this->assertTrue($this->parser->supports(new LogFileData('/var/log/laravel.log', 'laravel')));
        $this->assertFalse($this->parser->supports(new LogFileData('/var/log/access.log', 'apache_access')));
    }

    public function test_it_returns_nothing_for_an_unreadable_file(): void
    {
        $file = new LogFileData(__DIR__.'/does-not-exist.log', 'laravel');

        $this->assertSame([], iterator_to_array($this->parser->parse($file)));
    }

    /** @return array<int, ErrorEventData> */
    private function parseFixture(): array
    {
        $file = new LogFileData(dirname(__DIR__).'/Fixtures/laravel.log', 'laravel');

        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($this->parser->parse($file), false);

        return $events;
    }

    /** @return array<int, ErrorEventData> */
    private function parseContent(string $content): array
    {
        /** @var array<int, ErrorEventData> $events */
        $events = iterator_to_array($this->parser->parseContent($content), false);

        return $events;
    }
}
