<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Services;

use AshitaPlanning\LaravelErrorMonitor\Contracts\LogNormalizer;

final class DefaultLogNormalizer implements LogNormalizer
{
    public function normalize(string $message): string
    {
        $patterns = [
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '{email}',
            '/\b\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/' => '{datetime}',
            '/\b\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\b/' => '{datetime}',
            '/(?<![\w:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/' => '{ip}',
            '/(?<![\w:])(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}(?![\w:])/i' => '{ip}',
            '#(?:https?://[^\s?"\']+|/[A-Za-z0-9._~/%-]+)\?[^\s"\']+#i' => static fn (array $match): string => preg_replace('/\?.*/', '?{query}', $match[0]) ?? $match[0],
            '#(?:/tmp|/private/tmp|/var/folders/[^/]+/[^/]+/T)/[^\s"\']+#' => '{tmp_path}',
            '#/bootstrap/cache/(?:config|events|packages|services|routes(?:-[A-Za-z0-9]+)?)\.php#' => '/bootstrap/cache/{generated}.php',
            '/\b((?:[A-Za-z_][A-Za-z0-9_]*[_-])?(?:id|uuid))\s*([=:]|\#)?\s*\d+\b/i' => '$1$2{id}',
            '#/([A-Za-z][A-Za-z0-9_-]*)/\d+(?=(?:/|\b))#' => '/$1/{id}',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = (string) preg_replace_callback(
                $pattern,
                is_callable($replacement) ? $replacement : static fn (array $match): string => (string) preg_replace($pattern, $replacement, $match[0]),
                $message,
            );
        }

        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
