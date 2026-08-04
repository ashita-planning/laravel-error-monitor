<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Services\DefaultSensitiveDataMasker;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @param  non-empty-string  $value
     */
    #[DataProvider('phoneNumbers')]
    public function test_it_masks_values_that_look_like_a_phone_number(string $value): void
    {
        $masked = app(DefaultSensitiveDataMasker::class)->mask($value);

        $this->assertStringContainsString('{phone}', $masked, sprintf('[%s] should be masked.', $value));
    }

    /** @return array<string, array{0: string}> */
    public static function phoneNumbers(): array
    {
        return [
            'mobile with separators' => ['090-1234-5678'],
            'landline with separators' => ['03-1234-5678'],
            'dotted freephone' => ['0120.123.456'],
            'bracketed area code' => ['03(1234)5678'],
            'international separated' => ['+81-90-1234-5678'],
            'international unseparated' => ['+819012345678'],
            'bare eleven digits' => ['09012345678'],
            'bare ten digits' => ['0521234567'],
            'labelled with a colon' => ['TEL: 03-1234-5678'],
            'labelled with equals' => ['phone=090-0000-0000'],
            'labelled in japanese' => ['電話: 0312345678'],
            'inside a sentence' => ['Callback to 090-1234-5678 failed'],
        ];
    }

    /**
     * The regression this rule exists for.
     *
     * A bare run of digits used to be read as a phone number, which destroyed
     * amounts, line numbers, ids and path segments. Masking runs before
     * normalization, so those values were gone before anything could keep them
     * - and because the masked message feeds the fingerprint, two unrelated
     * failures could collapse into one.
     *
     * @param  non-empty-string  $value
     */
    #[DataProvider('valuesThatAreNotPhoneNumbers')]
    public function test_it_keeps_numbers_that_only_look_like_one(string $value): void
    {
        $this->assertSame($value, app(DefaultSensitiveDataMasker::class)->mask($value));
    }

    /** @return array<string, array{0: string}> */
    public static function valuesThatAreNotPhoneNumbers(): array
    {
        return [
            'an amount' => ['Amount 15000 JPY'],
            'a large line number' => ['Undefined index at line 10234'],
            'an order id' => ['Order 12345 failed'],
            'an http status' => ['HTTP 500'],
            'a memory limit' => ['Allowed memory size of 134217728 bytes exhausted'],
            'a path segment' => ['/orders/12345'],
            'a driver error code' => ["SQLSTATE[42S02]: Base table not found: 1146 Table 'shop.orders'"],
            'a quantity' => ['Reserved 50000 units'],
            'a six digit id' => ['id=123456'],
            'a version number' => ['laravel/framework 10.48.29'],
            'a timestamp' => ['2026-08-03 10:11:12'],
        ];
    }

    public function test_a_phone_key_masks_its_value_whatever_it_looks_like(): void
    {
        // Free text cannot tell an unseparated number from an amount; the key
        // can, so it settles it.
        $masked = app(DefaultSensitiveDataMasker::class)->maskArray([
            'phone' => '09012345678',
            'telephone' => '0521234567',
            'mobile' => '08012345678',
            'contact-number' => '12345',
            'amount' => '15000',
        ]);

        $this->assertSame('{phone}', $masked['phone']);
        $this->assertSame('{phone}', $masked['telephone']);
        $this->assertSame('{phone}', $masked['mobile']);
        $this->assertSame('{phone}', $masked['contact-number'], 'Key comparison ignores separators.');
        $this->assertSame('15000', $masked['amount'], 'An amount stays an amount.');
    }

    public function test_it_fails_closed_when_a_rule_cannot_run(): void
    {
        // A unicode rule cannot run over a malformed byte sequence; the value
        // must be redacted rather than passed through unmasked.
        config()->set('error-monitor.masking.patterns', ['/\\p{L}+/u' => 'x']);

        $this->assertSame('{secret}', app(DefaultSensitiveDataMasker::class)->mask("secret \xB1\x31 value"));
    }
}
