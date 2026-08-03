<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface ErrorEventRepository
{
    /** Find an aggregate for one environment, source, fingerprint, and detected date. */
    public function findForDate(string $environment, string $source, string $fingerprint, DateTimeInterface $detectedAt): ?ErrorMonitorEvent;

    /** Find prior aggregates for a fingerprint, newest first. @return Collection<int, ErrorMonitorEvent> */
    public function findByFingerprint(string $environment, string $source, string $fingerprint): Collection;

    /** Return whether a payload has already been processed for this daily aggregate. */
    public function hasPayloadHash(string $environment, string $source, string $fingerprint, DateTimeInterface $detectedAt, string $payloadHash): bool;

    /** Atomically persist an event or update its daily aggregate. */
    public function record(ErrorEventData $event, string $payloadHash): ErrorMonitorEvent;
}
