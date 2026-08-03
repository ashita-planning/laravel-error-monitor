<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Contracts;

interface LogNormalizer
{
    /** Normalize dynamic but non-identifying message values for stable grouping. */
    public function normalize(string $message): string;
}
