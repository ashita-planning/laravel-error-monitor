<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A failure, described well enough for someone to act on it.
 *
 * This is what the package hands to an issue tracker, and what it deliberately
 * leaves out is the point: there is no Markdown here, no labels, no links and
 * no provider vocabulary. How a failure should read once it reaches GitHub,
 * Jira or a chat channel is that adapter's judgement, and baking one tracker's
 * formatting into this DTO would make every other one awkward.
 *
 * Everything here has already been masked - the report is built from a stored
 * aggregate, and nothing reaches storage unmasked.
 */
final readonly class ErrorReportData
{
    /**
     * @param  string  $title  One line naming the failure.
     * @param  string  $summary  Plain text; an adapter formats it however it likes.
     * @param  array<string, mixed>  $context  Masked request context.
     * @param  array<string, mixed>  $metadata  Analyser notes: how the status was resolved, correlation, ...
     *
     * @throws InvalidArgumentException When an externally supplied value is unusable.
     */
    public function __construct(
        public string $environment,
        public string $fingerprint,
        public string $title,
        public string $summary,
        public DateTimeImmutable $detectedDate,
        public DateTimeImmutable $firstOccurredAt,
        public DateTimeImmutable $lastOccurredAt,
        public int $occurrenceCount,
        public string $source,
        public ?string $exceptionClass = null,
        public ?string $normalizedMessage = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $method = null,
        public ?string $route = null,
        public ?int $statusCode = null,
        public array $context = [],
        public array $metadata = [],
    ) {
        if (trim($environment) === '') {
            throw new InvalidArgumentException('A report requires a non-empty environment.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('A report requires a 64 character SHA-256 fingerprint.');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('A report requires a non-empty title.');
        }

        if ($occurrenceCount < 1) {
            throw new InvalidArgumentException('A report must describe at least one occurrence.');
        }

        if ($firstOccurredAt > $lastOccurredAt) {
            throw new InvalidArgumentException('The first occurrence cannot be later than the last one.');
        }
    }

    /**
     * Build a report from a stored aggregate.
     *
     * @param  string  $timezone  Timezone the daily bucket is expressed in.
     */
    public static function fromEvent(ErrorEventData $event, string $timezone = 'UTC'): self
    {
        $first = $event->firstOccurredAt ?? $event->occurredAt;
        $last = $event->lastOccurredAt ?? $event->occurredAt;

        return new self(
            environment: $event->environment,
            fingerprint: $event->fingerprint,
            title: self::titleFor($event),
            summary: self::summaryFor($event),
            detectedDate: new DateTimeImmutable($event->detectedDate($timezone), new DateTimeZone($timezone)),
            firstOccurredAt: $first,
            lastOccurredAt: $last,
            occurrenceCount: max(1, $event->occurrenceCount),
            source: $event->source,
            exceptionClass: $event->exceptionClass,
            normalizedMessage: $event->normalizedMessage,
            file: $event->file,
            line: $event->line,
            method: $event->method,
            route: $event->route,
            statusCode: $event->statusCode,
            context: $event->context,
            metadata: $event->metadata,
        );
    }

    /**
     * Identity of one day's report.
     *
     * An adapter uses it to recognise a report it has already published rather
     * than posting the same thing twice.
     */
    public function identity(): string
    {
        return $this->environment.'|'.$this->fingerprint.'|'.$this->detectedDate->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'fingerprint' => $this->fingerprint,
            'title' => $this->title,
            'summary' => $this->summary,
            'detected_date' => $this->detectedDate->format('Y-m-d'),
            'first_occurred_at' => $this->firstOccurredAt->format(DATE_ATOM),
            'last_occurred_at' => $this->lastOccurredAt->format(DATE_ATOM),
            'occurrence_count' => $this->occurrenceCount,
            'source' => $this->source,
            'exception_class' => $this->exceptionClass,
            'normalized_message' => $this->normalizedMessage,
            'file' => $this->file,
            'line' => $this->line,
            'method' => $this->method,
            'route' => $this->route,
            'status_code' => $this->statusCode,
            'context' => $this->context,
            'metadata' => $this->metadata,
        ];
    }

    /** One line naming the failure, short enough to be an issue title. */
    private static function titleFor(ErrorEventData $event): string
    {
        $subject = $event->exceptionClass !== '' ? $event->exceptionClass : 'Failure';
        $where = $event->route ?? $event->file;
        $title = sprintf('[%s] %s', $event->environment, $subject);

        if ($where !== null && $where !== '') {
            $title .= ' at '.$where;
        }

        return mb_substr($title, 0, 200);
    }

    /** Plain text description; formatting belongs to the adapter. */
    private static function summaryFor(ErrorEventData $event): string
    {
        $lines = array_filter([
            $event->normalizedMessage,
            $event->file === null ? null : sprintf('%s:%s', $event->file, $event->line ?? '?'),
            $event->route === null ? null : sprintf('%s %s', $event->method ?? '', $event->route),
            $event->statusCode === null ? null : sprintf('HTTP %d', $event->statusCode),
        ], static fn (?string $line): bool => $line !== null && trim($line) !== '');

        return implode("\n", $lines);
    }
}
