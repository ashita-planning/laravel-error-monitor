<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\DTO;

final readonly class StackFrameData
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public ?string $class = null,
        public ?string $function = null,
        public ?string $type = null,
        public bool $isApplicationFrame = false,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
