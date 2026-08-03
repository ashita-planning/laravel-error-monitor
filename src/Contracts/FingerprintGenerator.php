<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Contracts;

use AshitaPlanning\LaravelErrorMonitor\DTO\ErrorEventData;

interface FingerprintGenerator
{
    /** Generate a deterministic SHA-256 fingerprint for an error event. */
    public function generate(ErrorEventData $event): string;

    /** @return array<string, mixed> Deterministic, inspectable fingerprint material. */
    public function material(ErrorEventData $event): array;
}
