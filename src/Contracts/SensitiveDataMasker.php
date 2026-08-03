<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Contracts;

interface SensitiveDataMasker
{
    /** Remove sensitive values from text before it is persisted, displayed, or fingerprinted. */
    public function mask(string $value): string;
}
