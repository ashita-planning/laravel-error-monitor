<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

interface LogNormalizer
{
    /** Normalize dynamic but non-identifying message values for stable grouping. */
    public function normalize(string $message): string;

    /** Normalize a route or URI: drop the query string and replace id-like segments. */
    public function normalizeRoute(?string $route): ?string;
}
