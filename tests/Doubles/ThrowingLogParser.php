<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\LogParser;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use RuntimeException;

/**
 * Parser that claims one source and then fails, so a run can be observed
 * finishing the sources around it.
 */
final class ThrowingLogParser implements LogParser
{
    public function __construct(private readonly string $source) {}

    public function supports(LogFileData $logFile): bool
    {
        return $logFile->source === $this->source;
    }

    public function parse(LogFileData $logFile): iterable
    {
        throw new RuntimeException('Log is unreadable.');
    }
}
