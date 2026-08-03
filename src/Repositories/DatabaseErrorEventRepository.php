<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Repositories;

use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class DatabaseErrorEventRepository implements ErrorEventRepository
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function findForDate(string $environment, string $source, string $fingerprint, DateTimeInterface $detectedAt): ?ErrorMonitorEvent
    {
        /** @var ErrorMonitorEvent|null $event */
        $event = ErrorMonitorEvent::query()
            ->where('environment', $environment)
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->whereDate('detected_date', $this->detectedDate($detectedAt))
            ->first();

        return $event;
    }

    /** @return Collection<int, ErrorMonitorEvent> */
    public function findByFingerprint(string $environment, string $source, string $fingerprint): Collection
    {
        /** @var Collection<int, ErrorMonitorEvent> $events */
        $events = ErrorMonitorEvent::query()
            ->where('environment', $environment)
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('detected_date')
            ->get();

        return $events;
    }

    public function hasPayloadHash(string $environment, string $source, string $fingerprint, DateTimeInterface $detectedAt, string $payloadHash): bool
    {
        return ErrorMonitorEvent::query()
            ->where('environment', $environment)
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->whereDate('detected_date', $this->detectedDate($detectedAt))
            ->where('payload_hash', $payloadHash)
            ->exists();
    }

    public function record(ErrorEventData $event, string $payloadHash): ErrorMonitorEvent
    {
        try {
            return $this->database->transaction(fn (): ErrorMonitorEvent => $this->recordLocked($event, $payloadHash));
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return $this->database->transaction(fn (): ErrorMonitorEvent => $this->recordLocked($event, $payloadHash));
        }
    }

    private function recordLocked(ErrorEventData $event, string $payloadHash): ErrorMonitorEvent
    {
        /** @var ErrorMonitorEvent|null $eventModel */
        $eventModel = ErrorMonitorEvent::query()
            ->where('environment', $event->environment)
            ->where('source', $event->source)
            ->where('fingerprint', $event->fingerprint)
            ->whereDate('detected_date', $this->detectedDate($event->occurredAt))
            ->lockForUpdate()
            ->first();

        if ($eventModel === null) {
            /** @var ErrorMonitorEvent $created */
            $created = ErrorMonitorEvent::query()->create($this->attributes($event, $payloadHash));

            return $created;
        }

        if (hash_equals($eventModel->payload_hash, $payloadHash)) {
            return $eventModel;
        }

        $firstOccurredAt = $eventModel->first_occurred_at;
        $lastOccurredAt = $eventModel->last_occurred_at;
        $occurredAt = $event->occurredAt;

        $attributes = [
            'occurrence_count' => $eventModel->occurrence_count + max(1, $event->occurrenceCount),
            'first_occurred_at' => $firstOccurredAt === null || $occurredAt < $firstOccurredAt ? $occurredAt : $firstOccurredAt,
            'last_occurred_at' => $lastOccurredAt === null || $occurredAt > $lastOccurredAt ? $occurredAt : $lastOccurredAt,
            'payload_hash' => $payloadHash,
        ];

        if ($event->context !== []) {
            $attributes['context'] = $event->context;
        }

        if ($event->metadata !== []) {
            $attributes['metadata'] = $event->metadata;
        }

        $eventModel->fill($attributes)->save();

        return $eventModel->refresh();
    }

    /** @return array<string, mixed> */
    private function attributes(ErrorEventData $event, string $payloadHash): array
    {
        return [
            'environment' => $event->environment,
            'source' => $event->source,
            'fingerprint' => $event->fingerprint,
            'detected_date' => $this->detectedDate($event->occurredAt),
            'first_occurred_at' => $event->firstOccurredAt ?? $event->occurredAt,
            'last_occurred_at' => $event->lastOccurredAt ?? $event->occurredAt,
            'occurrence_count' => max(1, $event->occurrenceCount),
            'exception_class' => $event->exceptionClass,
            'normalized_message' => $event->normalizedMessage,
            'file' => $event->file,
            'line' => $event->line,
            'method' => $event->method,
            'route' => $event->route,
            'status_code' => $event->statusCode,
            'payload_hash' => $payloadHash,
            'status' => 'open',
            'context' => $event->context === [] ? null : $event->context,
            'metadata' => $event->metadata === [] ? null : $event->metadata,
        ];
    }

    private function detectedDate(DateTimeInterface $occurredAt): string
    {
        $timezone = new DateTimeZone((string) config('error-monitor.timezone', 'UTC'));

        return DateTimeImmutable::createFromInterface($occurredAt)->setTimezone($timezone)->format('Y-m-d');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
