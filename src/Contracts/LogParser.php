<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;

interface LogParser
{
    /**
     * Whether this parser understands the format of the given file.
     *
     * The analyzer asks every registered parser in turn, so a driver for a new
     * log format stays additive: it claims its own files and ignores the rest.
     */
    public function supports(LogFileData $logFile): bool;

    /** Parse one log file into unpersisted error events. @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable;
}
