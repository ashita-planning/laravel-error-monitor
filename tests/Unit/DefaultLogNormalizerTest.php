<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Services\DefaultLogNormalizer;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

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

    public function test_it_normalizes_generated_framework_files_and_random_values(): void
    {
        $normalizer = app(DefaultLogNormalizer::class);

        $this->assertSame(
            $normalizer->normalize('include(/var/www/storage/framework/views/0a1b2c3d4e5f6071.php)'),
            $normalizer->normalize('include(/var/www/storage/framework/views/ffeeddccbbaa9988.php)'),
        );

        $this->assertStringContainsString(
            '{generated}',
            $normalizer->normalize('Compiled view abcdef123456.php missing'),
        );

        $this->assertStringContainsString(
            '{tmp_path}',
            $normalizer->normalize('failed to open /tmp/phpA1b2C3 for writing'),
        );

        $this->assertSame(
            $normalizer->normalize('session sess1a2b3c4d5e6f7g8h9i0j1k2l3m expired'),
            $normalizer->normalize('session sess9z8y7x6w5v4u3t2s1r0q9p8o expired'),
        );
    }

    public function test_it_keeps_values_that_identify_the_failure(): void
    {
        $normalizer = app(DefaultLogNormalizer::class);

        // SQLSTATE and driver error codes.
        $this->assertNotSame(
            $normalizer->normalize('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
            $normalizer->normalize('SQLSTATE[42S02]: Base table or view not found: 1146 Table missing'),
        );

        // PHP error constants, line numbers, versions, amounts and quantities.
        $this->assertSame('E_WARNING raised on line 184', $normalizer->normalize('E_WARNING raised on line 184'));
        $this->assertSame('Composer 2.8.12 requires PHP 8.2', $normalizer->normalize('Composer 2.8.12 requires PHP 8.2'));
        $this->assertNotSame(
            $normalizer->normalize('Charge of 12000 JPY was refused'),
            $normalizer->normalize('Charge of 98000 JPY was refused'),
        );
        $this->assertNotSame(
            $normalizer->normalize('Cannot reserve 3 items'),
            $normalizer->normalize('Cannot reserve 8 items'),
        );
        $this->assertNotSame(
            $normalizer->normalize('upstream answered HTTP 502'),
            $normalizer->normalize('upstream answered HTTP 503'),
        );
    }

    public function test_it_normalizes_routes(): void
    {
        $normalizer = app(DefaultLogNormalizer::class);

        $this->assertSame('/orders/{id}', $normalizer->normalizeRoute('/orders/123'));
        $this->assertSame('/customers/{id}/orders', $normalizer->normalizeRoute('/customers/42/orders/'));
        $this->assertSame('/customers/{param}', $normalizer->normalizeRoute('/customers/{customer}'));
        $this->assertSame('/users/{uuid}', $normalizer->normalizeRoute('/users/123e4567-e89b-12d3-a456-426614174000'));
        $this->assertSame('/search', $normalizer->normalizeRoute('https://example.invalid/search?q=test'));
        $this->assertNull($normalizer->normalizeRoute(null));
    }
}
