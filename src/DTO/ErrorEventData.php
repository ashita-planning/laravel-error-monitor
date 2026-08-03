<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\DTO;

use DateTimeImmutable;

final readonly class ErrorEventData
{
    /**
     * @param  array<int, StackFrameData>  $stackFrames
     * @param  array<string, mixed>  $context
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
    ) {}
}
