<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use RuntimeException;

/**
 * Parser returning fixed events, optionally throwing to prove that one broken
 * file does not abort the whole run. Claims every file unless a source is given.
 */
final class ArrayLogParser implements LogParser
{
    /** @param array<int, ErrorEventData> $events */
    public function __construct(
        private readonly array $events = [],
        private readonly bool $throws = false,
        private readonly ?string $source = null,
    ) {}

    public function supports(LogFileData $logFile): bool
    {
        return $this->source === null || $logFile->source === $this->source;
    }

    public function parse(LogFileData $logFile): iterable
    {
        if ($this->throws) {
            throw new RuntimeException('Malformed log entry.');
        }

        return $this->events;
    }
}
