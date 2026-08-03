<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use RuntimeException;

/**
 * Parser returning fixed events, optionally throwing to prove that one broken
 * file does not abort the whole run.
 */
final class ArrayLogParser implements LogParser
{
    /** @param array<int, ErrorEventData> $events */
    public function __construct(
        private readonly array $events = [],
        private readonly bool $throws = false,
    ) {}

    public function parse(LogFileData $logFile): iterable
    {
        if ($this->throws) {
            throw new RuntimeException('Malformed log entry.');
        }

        return $this->events;
    }
}
