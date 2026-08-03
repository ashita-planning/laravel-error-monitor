<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;

final class AnalysisWindowDataTest extends TestCase
{
    public function test_it_covers_a_whole_day_in_the_given_timezone(): void
    {
        $window = AnalysisWindowData::forDate('2026-08-03', 'Asia/Tokyo');

        $this->assertSame('2026-08-03T00:00:00+09:00', $window->from->format('c'));
        $this->assertSame('2026-08-03T23:59:59+09:00', $window->to->format('c'));
        $this->assertTrue($window->contains(new DateTimeImmutable('2026-08-03T12:00:00+09:00')));
        $this->assertFalse($window->contains(new DateTimeImmutable('2026-08-04T12:00:00+09:00')));
    }

    public function test_it_accepts_relative_expressions(): void
    {
        $today = AnalysisWindowData::forDate('today', 'UTC');
        $yesterday = AnalysisWindowData::forDate('yesterday', 'UTC');

        $this->assertSame($today->from->modify('-1 day')->format('Y-m-d'), $yesterday->from->format('Y-m-d'));
    }

    public function test_the_context_widens_the_window(): void
    {
        $window = AnalysisWindowData::forDate('2026-08-03', 'Asia/Tokyo', 1800, 1800);

        $this->assertSame('2026-08-02T23:30:00+09:00', $window->contextFrom()->format('c'));
        $this->assertSame('2026-08-04T00:29:59+09:00', $window->contextTo()->format('c'));
        $this->assertTrue($window->contains(new DateTimeImmutable('2026-08-02T23:45:00+09:00')));
    }

    public function test_it_builds_a_window_between_two_timestamps(): void
    {
        $window = AnalysisWindowData::between('2026-08-03 09:00:00', '2026-08-03 10:00:00', 'UTC');

        $this->assertTrue($window->contains(new DateTimeImmutable('2026-08-03T09:30:00+00:00')));
        $this->assertFalse($window->contains(new DateTimeImmutable('2026-08-03T10:30:00+00:00')));
        $this->assertSame('UTC', $window->timezone);
        $this->assertSame('2026-08-03T09:00:00+00:00', $window->toArray()['from']);
    }

    public function test_it_rejects_an_inverted_window(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AnalysisWindowData::between('2026-08-03 10:00:00', '2026-08-03 09:00:00');
    }

    public function test_it_rejects_a_meaningless_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AnalysisWindowData::forDate('not-a-date');
    }

    public function test_it_rejects_negative_context_seconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AnalysisWindowData(
            new DateTimeImmutable('2026-08-03 00:00:00'),
            new DateTimeImmutable('2026-08-03 23:59:59'),
            'UTC',
            -1,
        );
    }
}
