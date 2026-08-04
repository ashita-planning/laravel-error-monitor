<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;

/**
 * Regular expression based masker.
 *
 * Rules run in a fixed order: query strings and credential carrying headers
 * first (their values may look like anything), then structured identifiers,
 * then free floating personal data. The matched text is discarded - no copy of
 * the original value is returned, stored or logged.
 *
 * Two safety properties matter as much as the rules themselves: masking is
 * bounded, so a pathological log line cannot burn CPU inside the expressions,
 * and it fails closed, so a rule that errors redacts the value instead of
 * passing it through unmasked.
 */
final class DefaultSensitiveDataMasker implements SensitiveDataMasker
{
    /** @var array<string, string> */
    private const DEFAULT_TOKENS = [
        'ip' => '{ip}',
        'email' => '{email}',
        'phone' => '{phone}',
        'uuid' => '{uuid}',
        'token' => '{token}',
        'session' => '{session}',
        'secret' => '{secret}',
    ];

    /** @var array<int, string> */
    private const DEFAULT_MASK_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'session',
        'session_id',
        'api_key',
        'client_secret',
    ];

    public function mask(string $value): string
    {
        if ($value === '' || ! (bool) config('error-monitor.masking.enabled', true)) {
            return $value;
        }

        $maxLength = (int) config('error-monitor.masking.max_length', 262144);

        if ($maxLength > 0 && strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength).' {truncated}';
        }

        foreach ($this->patterns() as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $value);

            if ($masked === null) {
                // Fail closed: a failing rule must never leak the raw value.
                return $this->token('secret');
            }

            $value = $masked;
        }

        return $value;
    }

    public function maskArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isMaskedKey($key)) {
                $values[$key] = $this->token('secret');

                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->mask($value);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->maskArray($value);
            }
        }

        return $values;
    }

    /** Whether the value behind this key is replaced wholesale. */
    public function isMaskedKey(string $key): bool
    {
        /** @var array<int, string> $maskKeys */
        $maskKeys = (array) config('error-monitor.masking.mask_keys', self::DEFAULT_MASK_KEYS);
        /** @var array<int, string> $headers */
        $headers = (array) config('error-monitor.masking.remove_headers', []);

        $normalized = $this->normalizeKey($key);

        foreach (array_merge($maskKeys, $headers) as $maskKey) {
            if ($this->normalizeKey((string) $maskKey) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ordered rule set.
     *
     * @return array<string, string>
     */
    public function patterns(): array
    {
        $patterns = [];

        if ((bool) config('error-monitor.masking.remove_query_strings', true)) {
            // Query strings routinely carry tokens and mail addresses.
            $patterns['/\?[A-Za-z0-9_\-\[\]%.]+=[^\s"\')\]]*/'] = '';
        }

        $patterns += [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}\b/i' => $this->token('token'),
            '/^Authorization\s*:\s*[^\r\n]+/mi' => 'Authorization: '.$this->token('token'),
            '/\bAuthorization\s*[:=]\s*["\']?(?:Bearer|Basic|Token)?\s*[^\s"\',;\]\}]+/i' => 'Authorization: '.$this->token('token'),
            '/^(?:Cookie|Set-Cookie)\s*:\s*[^\r\n]+/mi' => 'Cookie: '.$this->token('session'),
            '/\b(?:Set-)?Cookie\s*[:=]\s*["\']?[^\r\n"\'\]\}]+/i' => 'Cookie: '.$this->token('session'),
            '/\b(session(?:[_-]?id)?|laravel_session|xsrf-token|csrf(?:[_-]?token)?)\s*[=:]\s*[A-Za-z0-9._~+\/-]{8,}/i' => '$1='.$this->token('session'),
            '/\b(api[_-]?key|access[_-]?token|secret[_-]?key|token)\s*[=:]\s*["\']?[A-Za-z0-9._~+\/-]{16,}/i' => '$1='.$this->token('token'),
            // Credentials that are neither long nor random enough for the rules above.
            '/\b(password|passwd|passphrase|client[_-]?secret|refresh[_-]?token|private[_-]?key|channel[_-]?secret|channel[_-]?access[_-]?token|secret)\s*[=:]\s*["\']?[^\s"\',;\]\}]{4,}/i' => '$1='.$this->token('secret'),
            // JSON Web Tokens and provider specific key formats.
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => $this->token('token'),
            '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{6,}\b/' => $this->token('secret'),
            '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/' => $this->token('secret'),
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i' => $this->token('uuid'),
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => $this->token('email'),
            '/(?<![\w:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/' => $this->token('ip'),
            '/(?<![\w:])(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}(?![\w:])/i' => $this->token('ip'),
            '/(?<!\d)(?:\+?\d{1,3}[-.\s]?)?(?:\(?\d{1,4}\)?[-.\s]?){2}\d{3,4}(?!\d)/' => $this->token('phone'),
        ];

        /** @var array<string, string> $extra */
        $extra = (array) config('error-monitor.masking.patterns', []);

        return array_merge($patterns, $extra);
    }

    private function token(string $key): string
    {
        /** @var array<string, string> $tokens */
        $tokens = (array) config('error-monitor.masking.replacement_tokens', []);

        return $tokens[$key] ?? self::DEFAULT_TOKENS[$key] ?? sprintf('{%s}', $key);
    }

    /** Case and punctuation insensitive key comparison. */
    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' ', '.'], '_', trim($key)));
    }
}
