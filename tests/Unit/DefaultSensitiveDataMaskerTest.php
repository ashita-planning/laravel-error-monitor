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

    public function test_it_masks_credentials_that_are_neither_long_nor_random(): void
    {
        $masker = app(DefaultSensitiveDataMasker::class);

        foreach ([
            'password=short1',
            'client_secret=fake-value',
            'refresh_token=fake-value',
            'channel_access_token=fake-value',
        ] as $subject) {
            $masked = $masker->mask($subject);

            $this->assertStringContainsString('{secret}', $masked, $subject);
            $this->assertStringNotContainsString('fake-value', $masked);
            $this->assertStringNotContainsString('short1', $masked);
        }

        $this->assertStringContainsString('{secret}', $masker->mask('key sk_test_0123456789abcdef'));
        $this->assertStringContainsString('{secret}', $masker->mask('key ghp_0123456789abcdefghij'));
        $this->assertStringContainsString('{token}', $masker->mask('eyJhbGciOiJub25lIn0.eyJzdWIiOiJmYWtlIn0.notarealsignature'));
    }

    public function test_it_removes_query_strings(): void
    {
        $masked = app(DefaultSensitiveDataMasker::class)->mask('GET /orders?token=fake-token-value&mail=user@example.invalid failed');

        $this->assertStringNotContainsString('fake-token-value', $masked);
        $this->assertStringNotContainsString('user@example.invalid', $masked);
    }

    public function test_it_masks_nested_arrays_and_sensitive_keys(): void
    {
        $masked = app(DefaultSensitiveDataMasker::class)->maskArray([
            'user' => ['email' => 'user@example.invalid', 'ip' => '203.0.113.42'],
            'Password' => 'not-a-pattern',
            'API-Key' => ['nested' => 'still-secret'],
            'headers' => [
                'Authorization' => 'Basic anything',
                'X-CSRF-TOKEN' => 'short',
                'accept' => 'application/json',
            ],
            'count' => 3,
        ]);

        $this->assertSame('{email}', $masked['user']['email']);
        $this->assertSame('{ip}', $masked['user']['ip']);
        $this->assertSame('{secret}', $masked['Password']);
        $this->assertSame('{secret}', $masked['API-Key']);
        $this->assertSame('{secret}', $masked['headers']['Authorization']);
        $this->assertSame('{secret}', $masked['headers']['X-CSRF-TOKEN']);
        $this->assertSame('application/json', $masked['headers']['accept']);
        $this->assertSame(3, $masked['count']);
    }

    public function test_masking_is_idempotent_and_leaves_ordinary_text_alone(): void
    {
        $masker = app(DefaultSensitiveDataMasker::class);
        $once = $masker->mask('mail user@example.invalid from 203.0.113.42');

        $this->assertSame($once, $masker->mask($once));
        $this->assertSame(
            'Undefined variable $customer in InvoiceService::charge()',
            $masker->mask('Undefined variable $customer in InvoiceService::charge()'),
        );
    }

    public function test_it_truncates_huge_values_and_can_be_disabled(): void
    {
        config()->set('error-monitor.masking.max_length', 128);

        $masked = app(DefaultSensitiveDataMasker::class)->mask(str_repeat('a', 5000));

        $this->assertLessThan(200, strlen($masked));
        $this->assertStringContainsString('{truncated}', $masked);

        config()->set('error-monitor.masking.enabled', false);

        $this->assertSame('mail user@example.invalid', app(DefaultSensitiveDataMasker::class)->mask('mail user@example.invalid'));
    }

    public function test_it_fails_closed_when_a_rule_cannot_run(): void
    {
        // A unicode rule cannot run over a malformed byte sequence; the value
        // must be redacted rather than passed through unmasked.
        config()->set('error-monitor.masking.patterns', ['/\\p{L}+/u' => 'x']);

        $this->assertSame('{secret}', app(DefaultSensitiveDataMasker::class)->mask("secret \xB1\x31 value"));
    }
}
