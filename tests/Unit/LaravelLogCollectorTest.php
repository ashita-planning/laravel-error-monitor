<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\DTO\LogFileData;
use PHPUnit\Framework\TestCase;

final class LaravelLogCollectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/error-monitor-collector-'.bin2hex(random_bytes(6));

        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_collects_the_single_and_the_daily_channel_layout(): void
    {
        $this->write('laravel.log');
        $this->write('laravel-2026-08-03.log');
        $this->write('worker.log');

        $paths = $this->paths($this->collector($this->directory));

        $this->assertCount(2, $paths);
        $this->assertContains($this->directory.'/laravel.log', $paths);
        $this->assertContains($this->directory.'/laravel-2026-08-03.log', $paths);
    }

    public function test_a_file_path_resolves_to_its_directory(): void
    {
        $this->write('laravel.log');
        $this->write('laravel-2026-08-03.log');

        // The configured path defaults to storage/logs/laravel.log, and reading
        // that one file would miss every rotated sibling.
        $paths = $this->paths($this->collector($this->directory.'/laravel.log'));

        $this->assertCount(2, $paths);
    }

    public function test_it_returns_the_newest_files_first_and_honours_the_limit(): void
    {
        $this->write('laravel-2026-08-01.log');
        $this->write('laravel-2026-08-02.log');
        $this->write('laravel-2026-08-03.log');

        touch($this->directory.'/laravel-2026-08-01.log', 1_000_000);
        touch($this->directory.'/laravel-2026-08-02.log', 2_000_000);
        touch($this->directory.'/laravel-2026-08-03.log', 3_000_000);

        $paths = $this->paths($this->collector($this->directory, maxFiles: 2));

        $this->assertSame([
            $this->directory.'/laravel-2026-08-03.log',
            $this->directory.'/laravel-2026-08-02.log',
        ], $paths);
    }

    public function test_it_skips_a_file_over_the_size_limit(): void
    {
        $this->write('laravel.log', str_repeat('x', 64));

        $this->assertSame([], $this->paths($this->collector($this->directory, maxBytes: 16)));
        $this->assertCount(1, $this->paths($this->collector($this->directory, maxBytes: 0)));
    }

    public function test_it_tags_every_file_with_the_laravel_source(): void
    {
        $this->write('laravel.log');

        $files = iterator_to_array($this->collector($this->directory)->collect());

        $this->assertSame(LaravelLogCollector::SOURCE, $files[0]->source);
        $this->assertSame(5, $files[0]->size);
        $this->assertNotNull($files[0]->modifiedAt);
    }

    public function test_a_missing_directory_is_not_an_error(): void
    {
        $this->assertSame([], $this->paths($this->collector($this->directory.'/nope')));
        $this->assertSame([], $this->paths($this->collector('')));
    }

    private function collector(string $path, int $maxFiles = 31, int $maxBytes = 536870912): LaravelLogCollector
    {
        return new LaravelLogCollector(
            path: $path,
            patterns: ['laravel.log', 'laravel-*.log'],
            maxFiles: $maxFiles,
            maxBytes: $maxBytes,
        );
    }

    private function write(string $name, string $contents = 'entry'): void
    {
        file_put_contents($this->directory.'/'.$name, $contents);
    }

    /** @return array<int, string> */
    private function paths(LaravelLogCollector $collector): array
    {
        return array_map(
            static fn (LogFileData $file): string => $file->path,
            iterator_to_array($collector->collect(), false),
        );
    }
}
