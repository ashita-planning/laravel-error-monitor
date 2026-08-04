<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\Services\ApacheLaravelCorrelationService;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;

final class ApacheLaravelCorrelationTest extends TestCase
{
    public function test_a_matching_request_id_wins_over_everything_else(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET', ['request_id' => 'req-abc']);
        $nearer = $this->laravel('2026-08-03 10:00:00', '/orders/12', 'GET');
        $tagged = $this->laravel('2026-08-03 10:00:03', '/other', 'POST', ['request_id' => 'req-abc']);

        $correlated = $this->service()->correlateOne($access, [$nearer, $tagged]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_REQUEST_ID, $correlated->metadata['correlation_method']);
        $this->assertSame(1.0, $correlated->metadata['correlation_confidence']);
        $this->assertSame('OtherException', $correlated->metadata['correlated_exception_class']);
    }

    public function test_time_method_and_path_match_together(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET');
        $laravel = $this->laravel('2026-08-03 10:00:02', '/orders/99', 'GET');

        // Both routes normalize to /orders/{id}, which is the point: the ids
        // differ per request and must not keep the two apart.
        $correlated = $this->service()->correlateOne($access, [$laravel]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_TIME_METHOD_PATH, $correlated->metadata['correlation_method']);
        $this->assertSame(0.8, $correlated->metadata['correlation_confidence']);
    }

    public function test_a_different_method_falls_back_to_the_path_match(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET');
        $laravel = $this->laravel('2026-08-03 10:00:02', '/orders/12', 'POST');

        $correlated = $this->service()->correlateOne($access, [$laravel]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_TIME_PATH, $correlated->metadata['correlation_method']);
        $this->assertSame(0.6, $correlated->metadata['correlation_confidence']);
    }

    public function test_a_different_path_falls_back_to_proximity_alone(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET');
        $laravel = $this->laravel('2026-08-03 10:00:02', '/invoices/3', 'GET');

        $correlated = $this->service()->correlateOne($access, [$laravel]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_TIME, $correlated->metadata['correlation_method']);
        $this->assertSame(0.3, $correlated->metadata['correlation_confidence']);
    }

    public function test_several_equal_candidates_lower_the_confidence(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET');
        $candidates = [
            $this->laravel('2026-08-03 10:00:01', '/orders/12', 'GET'),
            $this->laravel('2026-08-03 10:00:02', '/orders/13', 'GET'),
        ];

        $correlated = $this->service()->correlateOne($access, $candidates);

        $this->assertSame(2, $correlated->metadata['correlation_candidates']);
        $this->assertSame(0.4, $correlated->metadata['correlation_confidence']);
        // The pick is still the nearest one in time.
        $this->assertSame('/orders/12', $correlated->metadata['correlated_message']);
    }

    public function test_an_entry_outside_the_window_is_not_a_match(): void
    {
        $access = $this->access('2026-08-03 10:00:00', '/orders/12', 'GET');
        $laravel = $this->laravel('2026-08-03 10:05:00', '/orders/12', 'GET');

        $correlated = $this->service()->correlateOne($access, [$laravel]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_NONE, $correlated->metadata['correlation_method']);
    }

    public function test_a_five_hundred_with_no_laravel_entry_survives(): void
    {
        // A 502 never reaches PHP, so it has no counterpart by nature. Losing
        // it would lose the failures this source exists to catch.
        $access = $this->access('2026-08-03 10:00:00', '/checkout', 'POST', statusCode: 502);

        $correlated = $this->service()->correlateOne($access, []);

        $this->assertSame(502, $correlated->statusCode);
        $this->assertSame(ApacheLaravelCorrelationService::METHOD_NONE, $correlated->metadata['correlation_method']);
        $this->assertSame(0.0, $correlated->metadata['correlation_confidence']);
        $this->assertSame(0, $correlated->metadata['correlation_candidates']);
        // The status the server reported is untouched by the failed match.
        $this->assertSame('access_log', $correlated->metadata['status_source']);
    }

    public function test_it_annotates_a_whole_batch(): void
    {
        $accessEvents = [
            $this->access('2026-08-03 10:00:00', '/orders/12', 'GET'),
            $this->access('2026-08-03 11:00:00', '/checkout', 'POST', statusCode: 502),
        ];
        $laravelEvents = [$this->laravel('2026-08-03 10:00:01', '/orders/12', 'GET')];

        $correlated = $this->service()->correlate($accessEvents, $laravelEvents);

        $this->assertCount(2, $correlated);
        $this->assertSame(ApacheLaravelCorrelationService::METHOD_TIME_METHOD_PATH, $correlated[0]->metadata['correlation_method']);
        $this->assertSame(ApacheLaravelCorrelationService::METHOD_NONE, $correlated[1]->metadata['correlation_method']);
    }

    public function test_an_apache_error_entry_correlates_through_the_same_service(): void
    {
        // An error log entry carries no request path, so proximity is all there
        // is - which the confidence states rather than hides.
        $serverError = new ErrorEventData(
            environment: 'production',
            source: 'apache_error',
            occurredAt: new DateTimeImmutable('2026-08-03 10:00:01'),
            exceptionClass: 'TypeError',
            message: 'PHP Fatal error: Uncaught TypeError',
            normalizedMessage: 'PHP Fatal error: Uncaught TypeError',
            file: '/srv/app/app/Services/OrderService.php',
            line: 42,
            method: null,
            route: null,
            statusCode: 500,
            stackFrames: [],
            fingerprint: '',
            metadata: ['error_category' => 'php_fatal'],
        );

        $correlated = $this->service()->correlateOne($serverError, [
            $this->laravel('2026-08-03 10:00:00', '/orders/12', 'POST'),
        ]);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_TIME, $correlated->metadata['correlation_method']);
        $this->assertSame('RuntimeException', $correlated->metadata['correlated_exception_class']);
        // The classification survives the annotation.
        $this->assertSame('php_fatal', $correlated->metadata['error_category']);
    }

    public function test_a_server_error_with_nothing_to_match_keeps_its_classification(): void
    {
        $serverError = new ErrorEventData(
            environment: 'production',
            source: 'apache_error',
            occurredAt: new DateTimeImmutable('2026-08-03 10:00:00'),
            exceptionClass: 'ServerError',
            message: 'AH00052: child pid 9012 exit signal Segmentation fault',
            normalizedMessage: 'AH00052: child pid 9012 exit signal Segmentation fault',
            file: null,
            line: null,
            method: null,
            route: null,
            statusCode: 500,
            stackFrames: [],
            fingerprint: '',
            metadata: ['error_category' => 'server_internal', 'status_estimated' => true],
        );

        $correlated = $this->service()->correlateOne($serverError, []);

        $this->assertSame(ApacheLaravelCorrelationService::METHOD_NONE, $correlated->metadata['correlation_method']);
        $this->assertSame('server_internal', $correlated->metadata['error_category']);
        $this->assertSame(500, $correlated->statusCode);
    }

    private function service(int $windowSeconds = 5): ApacheLaravelCorrelationService
    {
        return new ApacheLaravelCorrelationService(app(LogNormalizer::class), $windowSeconds);
    }

    /** @param array<string, mixed> $context */
    private function access(string $occurredAt, string $route, string $method, array $context = [], int $statusCode = 500): ErrorEventData
    {
        return new ErrorEventData(
            environment: 'production',
            source: 'apache_access',
            occurredAt: new DateTimeImmutable($occurredAt),
            exceptionClass: 'HttpServerError',
            message: 'HTTP '.$statusCode,
            normalizedMessage: 'HTTP '.$statusCode,
            file: null,
            line: null,
            method: $method,
            route: $route,
            statusCode: $statusCode,
            stackFrames: [],
            fingerprint: '',
            context: $context,
            metadata: ['status_source' => 'access_log', 'status_estimated' => false],
        );
    }

    /** @param array<string, mixed> $context */
    private function laravel(string $occurredAt, string $route, string $method, array $context = []): ErrorEventData
    {
        return new ErrorEventData(
            environment: 'production',
            source: 'laravel',
            occurredAt: new DateTimeImmutable($occurredAt),
            exceptionClass: $context === [] ? 'RuntimeException' : 'OtherException',
            message: $route,
            normalizedMessage: $route,
            file: '/srv/app/app/Http/Controllers/OrderController.php',
            line: 42,
            method: $method,
            route: $route,
            statusCode: 500,
            stackFrames: [],
            fingerprint: '',
            context: $context,
        );
    }
}
