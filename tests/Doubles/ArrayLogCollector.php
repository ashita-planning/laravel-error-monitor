<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\LogCollector;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;

/**
 * Collector returning a fixed list of files, so the pipeline can be exercised
 * without shipping a log driver.
 */
final class ArrayLogCollector implements LogCollector
{
    /** @param array<int, LogFileData> $files */
    public function __construct(
        private readonly array $files = [],
        private readonly string $source = 'tests',
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    public function collect(): iterable
    {
        return $this->files;
    }
}
