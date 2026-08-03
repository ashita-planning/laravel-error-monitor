<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ErrorEventData
{
    /**
     * @param  array<int, StackFrameData>  $stackFrames
     * @param  array<string, mixed>  $context  Masked extra context.
     * @param  array<string, mixed>  $metadata  Analyser notes, e.g. how the status was resolved.
     *
     * @throws InvalidArgumentException When an externally supplied value is out of range.
     */
    public function __construct(
        public string $environment,
        public string $source,
        public DateTimeImmutable $occurredAt,
        public string $exceptionClass,
        public string $message,
        public string $normalizedMessage,
        public ?string $file,
        public ?int $line,
        public ?string $method,
        public ?string $route,
        public ?int $statusCode,
        public array $stackFrames,
        public string $fingerprint,
        public array $context = [],
        public int $occurrenceCount = 1,
        public ?DateTimeImmutable $firstOccurredAt = null,
        public ?DateTimeImmutable $lastOccurredAt = null,
        public array $metadata = [],
    ) {
        if (trim($environment) === '') {
            throw new InvalidArgumentException('An error event requires a non-empty environment.');
        }

        if (trim($source) === '') {
            throw new InvalidArgumentException('An error event requires a non-empty source.');
        }

        if ($occurrenceCount < 1) {
            throw new InvalidArgumentException('An error event must have at least one occurrence.');
        }

        if ($statusCode !== null && ($statusCode < 100 || $statusCode > 599)) {
            throw new InvalidArgumentException(sprintf('[%d] is not a valid HTTP status code.', $statusCode));
        }

        if ($line !== null && $line < 0) {
            throw new InvalidArgumentException('A line number cannot be negative.');
        }

        if ($fingerprint !== '' && preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('A fingerprint must be a 64 character SHA-256 digest.');
        }

        if ($firstOccurredAt !== null && $lastOccurredAt !== null && $firstOccurredAt > $lastOccurredAt) {
            throw new InvalidArgumentException('The first occurrence cannot be later than the last one.');
        }
    }

    /**
     * Copy of the event with the given attributes replaced.
     *
     * `null` means "keep the current value"; the pipeline never has to unset one.
     *
     * @param  array<int, StackFrameData>|null  $stackFrames
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $metadata
     */
    public function with(
        ?string $message = null,
        ?string $normalizedMessage = null,
        ?string $fingerprint = null,
        ?array $stackFrames = null,
        ?array $context = null,
        ?array $metadata = null,
        ?int $occurrenceCount = null,
        ?DateTimeImmutable $firstOccurredAt = null,
        ?DateTimeImmutable $lastOccurredAt = null,
        ?string $route = null,
    ): self {
        return new self(
            environment: $this->environment,
            source: $this->source,
            occurredAt: $this->occurredAt,
            exceptionClass: $this->exceptionClass,
            message: $message ?? $this->message,
            normalizedMessage: $normalizedMessage ?? $this->normalizedMessage,
            file: $this->file,
            line: $this->line,
            method: $this->method,
            route: $route ?? $this->route,
            statusCode: $this->statusCode,
            stackFrames: $stackFrames ?? $this->stackFrames,
            fingerprint: $fingerprint ?? $this->fingerprint,
            context: $context ?? $this->context,
            occurrenceCount: $occurrenceCount ?? $this->occurrenceCount,
            firstOccurredAt: $firstOccurredAt ?? $this->firstOccurredAt,
            lastOccurredAt: $lastOccurredAt ?? $this->lastOccurredAt,
            metadata: $metadata ?? $this->metadata,
        );
    }

    /**
     * Stack frames that belong to the application itself.
     *
     * @return array<int, StackFrameData>
     */
    public function applicationFrames(): array
    {
        return array_values(array_filter(
            $this->stackFrames,
            static fn (StackFrameData $frame): bool => $frame->isApplicationFrame,
        ));
    }

    /** Date bucket the event belongs to, rendered in the given timezone. */
    public function detectedDate(string $timezone): string
    {
        return $this->occurredAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'source' => $this->source,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'normalized_message' => $this->normalizedMessage,
            'file' => $this->file,
            'line' => $this->line,
            'method' => $this->method,
            'route' => $this->route,
            'status_code' => $this->statusCode,
            'stack_frames' => array_map(
                static fn (StackFrameData $frame): array => $frame->toArray(),
                $this->stackFrames,
            ),
            'fingerprint' => $this->fingerprint,
            'context' => $this->context,
            'occurrence_count' => $this->occurrenceCount,
            'first_occurred_at' => $this->firstOccurredAt?->format(DateTimeInterface::ATOM),
            'last_occurred_at' => $this->lastOccurredAt?->format(DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
