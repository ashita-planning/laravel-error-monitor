<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;

final class DefaultSensitiveDataMasker implements SensitiveDataMasker
{
    public function mask(string $value): string
    {
        $tokens = config('error-monitor.masking.replacement_tokens', []);
        $replace = static fn (string $key): string => $tokens[$key] ?? sprintf('{%s}', $key);

        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}\b/i' => $replace('token'),
            '/^Authorization\s*:\s*[^\r\n]+/mi' => 'Authorization: '.$replace('token'),
            '/^(?:Cookie|Set-Cookie)\s*:\s*[^\r\n]+/mi' => 'Cookie: '.$replace('session'),
            '/\b(session(?:[_-]?id)?|laravel_session|xsrf-token|csrf(?:[_-]?token)?)\s*[=:]\s*[A-Za-z0-9._~+\/-]{8,}/i' => '$1='.$replace('session'),
            '/\b(api[_-]?key|access[_-]?token|secret[_-]?key|token)\s*[=:]\s*["\']?[A-Za-z0-9._~+\/-]{16,}/i' => '$1='.$replace('token'),
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i' => $replace('uuid'),
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => $replace('email'),
            '/(?<![\w:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/' => $replace('ip'),
            '/(?<![\w:])(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}(?![\w:])/i' => $replace('ip'),
            '/(?<!\d)(?:\+?\d{1,3}[-.\s]?)?(?:\(?\d{1,4}\)?[-.\s]?){2}\d{3,4}(?!\d)/' => $replace('phone'),
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}
