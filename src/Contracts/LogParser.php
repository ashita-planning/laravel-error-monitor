<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Contracts;

use AshitaPlanning\LaravelErrorMonitor\DTO\ErrorEventData;
use AshitaPlanning\LaravelErrorMonitor\DTO\LogFileData;

interface LogParser
{
    /** Parse one log file into unpersisted error events. @return iterable<ErrorEventData> */
    public function parse(LogFileData $logFile): iterable;
}
