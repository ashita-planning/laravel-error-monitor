<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Collectors\ServerLogSourceCollector;
use Apkk\LaravelErrorMonitor\Commands\RunErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;
use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Services\DailyErrorMonitorRunner;
use Apkk\LaravelErrorMonitor\Services\LogSourceRegistry;
use Apkk\LaravelErrorMonitor\Support\LogSource;
use Apkk\LaravelErrorMonitor\Tests\Doubles\FakeServerLogSource;
use Apkk\LaravelErrorMonitor\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The boundary an adapter package such as `laravel-error-monitor-xserver` uses.
 *
 * Nothing here knows anything about XServer: the point of these tests is that
 * the core does not have to.
 */
final class ServerLogSourceTest extends TestCase
{
    private const DAY = '2026-08-03';

    public function test_the_core_works_with_no_adapter_installed(): void
    {
        $registry = app(LogSourceRegistry::class);

        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], $registry->ids());
        $this->assertSame([], iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false));
    }

    public function test_a_registered_source_supplies_files_to_the_core(): void
    {
        $this->registerSource('xserver-like', [
            $this->collected('apache-access.log', LogSource::APACHE_ACCESS),
        ]);

        $files = iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false);

        $this->assertCount(1, $files);
        $this->assertSame(LogSource::APACHE_ACCESS, $files[0]->source);
        $this->assertSame(self::DAY, $files[0]->targetDate?->format('Y-m-d'));
        $this->assertNotNull($files[0]->fileHash);
        $this->assertSame('shop.example.invalid', $files[0]->metadata['domain'] ?? null);
    }

    public function test_several_sources_can_be_registered(): void
    {
        $this->registerSource('adapter-a', [$this->collected('apache-access.log', LogSource::APACHE_ACCESS)]);
        $this->registerSource('adapter-b', [$this->collected('apache-error.log', LogSource::APACHE_ERROR)]);

        $this->assertSame(['adapter-a', 'adapter-b'], app(LogSourceRegistry::class)->ids());
        $this->assertCount(2, iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false));
    }

    public function test_access_and_error_files_keep_their_own_source(): void
    {
        // One adapter routinely supplies both, which is why the collector's own
        // identifier is not a source key.
        $this->registerSource('both', [
            $this->collected('apache-access.log', LogSource::APACHE_ACCESS),
            $this->collected('apache-error.log', LogSource::APACHE_ERROR),
        ]);

        $sources = array_map(
            static fn ($file): string => $file->source,
            iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false),
        );

        $this->assertSame([LogSource::APACHE_ACCESS, LogSource::APACHE_ERROR], $sources);
        $this->assertSame(LogSource::SERVER_LOG_SOURCES, app(ServerLogSourceCollector::class)->source());
    }

    public function test_the_compressed_flag_and_metadata_survive(): void
    {
        $this->registerSource('gz', [
            $this->collected('apache-access-rotated.log.gz', LogSource::APACHE_ACCESS, compressed: true),
        ]);

        $file = iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false)[0];

        $this->assertTrue($file->compressed);
        $this->assertSame('web01', $file->metadata['server_identifier'] ?? null);
    }

    public function test_a_duplicate_source_id_is_refused(): void
    {
        $registry = new LogSourceRegistry;
        $registry->register(new FakeServerLogSource('same'));

        // Two adapters answering to one name would make it impossible to say
        // which of them produced a given file.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[same] is already registered');

        $registry->register(new FakeServerLogSource('same'));
    }

    public function test_an_empty_source_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LogSourceRegistry)->register(new FakeServerLogSource('  '));
    }

    public function test_the_same_file_offered_twice_is_collected_once(): void
    {
        $file = $this->collected('apache-access.log', LogSource::APACHE_ACCESS);

        $this->registerSource('adapter-a', [$file]);
        $this->registerSource('adapter-b', [$file]);

        // Same source, same day, same hash: work already done.
        $this->assertCount(1, iterator_to_array(app(ServerLogSourceCollector::class)->collect(), false));
    }

    public function test_an_unreadable_file_is_reported_and_skipped(): void
    {
        $this->registerSource('broken', [
            new CollectedLogFileData(
                source: LogSource::APACHE_ACCESS,
                path: '/nowhere/access.log',
                targetDate: new DateTimeImmutable(self::DAY),
                fileHash: str_repeat('a', 64),
            ),
        ]);

        $collector = app(ServerLogSourceCollector::class);

        $this->assertSame([], iterator_to_array($collector->collect(), false));
        $this->assertStringContainsString('unreadable file', implode(' ', $collector->warnings()));
    }

    public function test_a_failing_adapter_does_not_take_the_run_down(): void
    {
        $this->app->bind('tests.broken-source', fn (): FakeServerLogSource => new FakeServerLogSource('broken', throws: true));
        $this->app->bind('tests.good-source', fn (): FakeServerLogSource => new FakeServerLogSource('good', [
            $this->collected('apache-access.log', LogSource::APACHE_ACCESS),
        ]));
        $this->app->tag(['tests.broken-source', 'tests.good-source'], ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG);
        $this->forgetServices();

        $collector = app(ServerLogSourceCollector::class);
        $files = iterator_to_array($collector->collect(), false);

        $this->assertCount(1, $files, 'The healthy adapter still delivered.');
        $this->assertStringContainsString('[broken] failed', implode(' ', $collector->warnings()));
    }

    public function test_the_adapter_is_asked_for_the_period_being_analysed(): void
    {
        $source = new FakeServerLogSource('windowed', [$this->collected('apache-access.log', LogSource::APACHE_ACCESS)]);
        $this->app->instance('tests.windowed', $source);
        $this->app->tag(['tests.windowed'], ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG);
        $this->forgetServices();

        $window = AnalysisWindowData::forDate(self::DAY, 'UTC');
        app(DailyErrorMonitorRunner::class)->run($window, dryRun: true);

        $this->assertSame($window->label(), $source->askedFor?->label());
    }

    public function test_an_externally_supplied_log_reaches_the_daily_command(): void
    {
        // The whole point of the contract: a package the core knows nothing
        // about hands over a file, and everything downstream is unchanged.
        $this->registerSource('xserver-like', [
            $this->collected('apache-access.log', LogSource::APACHE_ACCESS),
        ]);

        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);

        $stored = ErrorMonitorEvent::query()->where('source', LogSource::APACHE_ACCESS)->get();

        $this->assertGreaterThan(0, $stored->count());
        $this->assertSame(500, $stored->first()?->status_code);
    }

    public function test_the_canonical_hash_helper_identifies_the_contents(): void
    {
        $path = dirname(__DIR__).'/Fixtures/apache-access.log';

        $this->assertSame(hash_file('sha256', $path), CollectedLogFileData::hashOf($path));
        $this->assertSame('', CollectedLogFileData::hashOf('/nowhere.log'), 'A missing file hashes to nothing.');
    }

    /** @param array<int, CollectedLogFileData> $files */
    private function registerSource(string $id, array $files): void
    {
        $this->app->bind('tests.source.'.$id, fn (): FakeServerLogSource => new FakeServerLogSource($id, $files));
        $this->app->tag(['tests.source.'.$id], ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG);
        $this->forgetServices();
    }

    private function forgetServices(): void
    {
        $this->app->forgetInstance(LogSourceRegistry::class);
        $this->app->forgetInstance(DailyErrorMonitorRunner::class);
    }

    private function collected(string $fixture, string $source, bool $compressed = false): CollectedLogFileData
    {
        $path = dirname(__DIR__).'/Fixtures/'.$fixture;

        return new CollectedLogFileData(
            source: $source,
            path: $path,
            targetDate: new DateTimeImmutable(self::DAY),
            fileHash: CollectedLogFileData::hashOf($path),
            compressed: $compressed,
            metadata: ['domain' => 'shop.example.invalid', 'server_identifier' => 'web01'],
        );
    }
}
