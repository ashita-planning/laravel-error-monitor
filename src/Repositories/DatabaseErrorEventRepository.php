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

        $storedFirst = $eventModel->first_occurred_at;
        $storedLast = $eventModel->last_occurred_at;
        $incomingFirst = $this->firstOccurredAt($event);
        $incomingLast = $this->lastOccurredAt($event);

        $attributes = [
            'occurrence_count' => $eventModel->occurrence_count + max(1, $event->occurrenceCount),
            // The row spans every occurrence merged into it, so the range only
            // ever widens.
            'first_occurred_at' => $storedFirst === null || $incomingFirst < $storedFirst ? $incomingFirst : $storedFirst,
            'last_occurred_at' => $storedLast === null || $incomingLast > $storedLast ? $incomingLast : $storedLast,
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
            'first_occurred_at' => $this->firstOccurredAt($event),
            'last_occurred_at' => $this->lastOccurredAt($event),
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

    /**
     * Earliest moment the incoming event stands for.
     *
     * An event may already be an aggregate of several occurrences, in which
     * case `occurredAt` is only its representative timestamp and the real range
     * lives in `firstOccurredAt` / `lastOccurredAt`. Creating and updating a row
     * have to read that range the same way, or an aggregate loses its bounds
     * the moment it merges into an existing day.
     */
    private function firstOccurredAt(ErrorEventData $event): DateTimeInterface
    {
        return $event->firstOccurredAt ?? $event->occurredAt;
    }

    /** Latest moment the incoming event stands for. @see firstOccurredAt() */
    private function lastOccurredAt(ErrorEventData $event): DateTimeInterface
    {
        return $event->lastOccurredAt ?? $event->occurredAt;
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
