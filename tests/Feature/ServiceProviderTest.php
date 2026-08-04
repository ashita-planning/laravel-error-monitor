<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Commands\AnalyzeErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use Apkk\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use Apkk\LaravelErrorMonitor\Contracts\LogNormalizer;
use Apkk\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitor\Parsers\LaravelLogParser;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_registers_the_provider_and_contract_bindings(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(ErrorMonitorServiceProvider::class));
        $this->assertInstanceOf(LogNormalizer::class, app(LogNormalizer::class));
        $this->assertInstanceOf(SensitiveDataMasker::class, app(SensitiveDataMasker::class));
        $this->assertInstanceOf(FingerprintGenerator::class, app(FingerprintGenerator::class));
        $this->assertInstanceOf(ErrorEventRepository::class, app(ErrorEventRepository::class));
    }

    public function test_it_publishes_configuration(): void
    {
        $path = $this->app->configPath('error-monitor.php');
        @unlink($path);

        try {
            $this->artisan('vendor:publish', [
                '--provider' => ErrorMonitorServiceProvider::class,
                '--tag' => 'error-monitor-config',
                '--force' => true,
            ])->assertExitCode(0);

            $this->assertFileExists($path);
        } finally {
            // The published file lands in the shared Testbench skeleton and
            // outlives the test. `mergeConfigFrom` merges only the top level,
            // so a stale copy overrides a whole section - a config key added
            // later then never reaches any test that runs after this one.
            @unlink($path);
        }
    }

    public function test_it_registers_artisan_commands(): void
    {
        // The configured log directory does not exist in the test environment,
        // so the bundled collector matches nothing and the command says so.
        $this->artisan('error-monitor:analyze')
            ->expectsOutputToContain('Analysis completed')
            ->assertExitCode(AnalyzeErrorMonitorCommand::EXIT_NO_LOGS);

        $this->artisan('error-monitor:status')
            ->expectsOutputToContain('Registered events')
            ->assertExitCode(0);
    }

    public function test_it_registers_the_bundled_laravel_log_driver(): void
    {
        $collectors = iterator_to_array($this->app->tagged(ErrorMonitorServiceProvider::COLLECTOR_TAG), false);
        $parsers = iterator_to_array($this->app->tagged(ErrorMonitorServiceProvider::PARSER_TAG), false);

        $this->assertInstanceOf(LaravelLogCollector::class, $collectors[0] ?? null);
        $this->assertInstanceOf(LaravelLogParser::class, $parsers[0] ?? null);
    }
}
