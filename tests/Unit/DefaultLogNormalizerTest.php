<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Tests\Unit;

use AshitaPlanning\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use AshitaPlanning\LaravelErrorMonitor\Tests\TestCase;

final class DefaultLogNormalizerTest extends TestCase
{
    public function test_it_normalizes_only_dynamic_values(): void
    {
        $normalizer = app(DefaultLogNormalizer::class);

        $first = $normalizer->normalize('Order id=1201 failed at 2026-08-03 10:20:30 from 203.0.113.42 for user@example.invalid: https://example.invalid/orders/1201?attempt=1');
        $second = $normalizer->normalize('Order id=8877 failed at 2026-08-04 11:20:30 from 203.0.113.43 for other@example.invalid: https://example.invalid/orders/8877?attempt=2');

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Order id={id} failed', $first);
        $this->assertStringContainsString('/orders/{id}?{query}', $first);
    }
}
