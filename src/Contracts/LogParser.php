<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\ErrorEventData;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;

interface LogParser
{
    /** Parse one log file into unpersisted error events. @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable;
}
