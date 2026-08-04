<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Contracts;

use Apkk\LaravelErrorMonitor\DTO\LogFileData;

interface LogCollector
{
    /**
     * Identifier of this collector.
     *
     * Usually the source key of the files it yields, e.g. `laravel`. A
     * collector that fronts several sources answers with its own name instead;
     * the files always carry the source a parser claims them by, so this is for
     * describing a run rather than for routing one.
     */
    public function source(): string;

    /** Collect log files from one source without reading their contents. @return iterable<LogFileData> */
    public function collect(): iterable;
}
