<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

final class DefaultSensitiveDataMaskerTest extends TestCase
{
    public function test_it_masks_sensitive_values_without_retaining_them(): void
    {
        $value = 'client=203.0.113.42 v6=2001:db8:0000:0000:0000:0000:0000:0001 email=user@example.invalid phone=090-0000-0000 uuid=123e4567-e89b-12d3-a456-426614174000 Bearer sample.token-value-which-is-long Authorization: Basic secret-value Cookie: laravel_session=session-value-which-is-long csrf_token=csrf-value-which-is-long api_key=api-value-which-is-long';

        $masked = app(DefaultSensitiveDataMasker::class)->mask($value);

        $this->assertStringContainsString('{ip}', $masked);
        $this->assertStringContainsString('{email}', $masked);
        $this->assertStringContainsString('{phone}', $masked);
        $this->assertStringContainsString('{uuid}', $masked);
        $this->assertStringContainsString('{token}', $masked);
        $this->assertStringContainsString('{session}', $masked);
        $this->assertStringNotContainsString('203.0.113.42', $masked);
        $this->assertStringNotContainsString('user@example.invalid', $masked);
        $this->assertStringNotContainsString('session-value-which-is-long', $masked);
    }
}
