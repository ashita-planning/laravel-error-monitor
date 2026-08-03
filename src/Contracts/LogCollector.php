<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\LogFileData;

interface LogCollector
{
    /** Collect log files from one source without reading their contents. @return iterable<LogFileData> */
    public function collect(): iterable;
}
