<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use DateTimeImmutable;

final readonly class LogFileData
{
    public function __construct(
        public string $path,
        public string $source,
        public ?DateTimeImmutable $modifiedAt = null,
        public ?int $size = null,
    ) {}
}
