<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

interface SensitiveDataMasker
{
    /** Remove sensitive values from text before it is persisted, displayed, or fingerprinted. */
    public function mask(string $value): string;

    /**
     * Mask every string of a possibly nested array.
     *
     * Keys are preserved; values behind sensitive keys are replaced wholesale.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function maskArray(array $values): array;
}
