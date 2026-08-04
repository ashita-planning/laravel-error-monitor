<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Doubles;

use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;
use RuntimeException;

/**
 * Stands in for an adapter package such as `laravel-error-monitor-xserver`.
 *
 * It does exactly what a real one does from the core's point of view: hands
 * over readable local paths with a source, a target date and a hash, and knows
 * nothing about how the core will read them.
 */
final class FakeServerLogSource implements ServerLogSource
{
    /** Window the source was last asked for, so a test can assert it arrived. */
    public ?AnalysisWindowData $askedFor = null;

    public int $calls = 0;

    /** @param array<int, CollectedLogFileData> $files */
    public function __construct(
        private readonly string $id,
        private readonly array $files = [],
        private readonly bool $throws = false,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function collect(?AnalysisWindowData $window = null): iterable
    {
        $this->calls++;
        $this->askedFor = $window;

        if ($this->throws) {
            throw new RuntimeException('The host is unreachable.');
        }

        return $this->files;
    }
}
