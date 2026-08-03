<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Tests\Feature;

use AshitaPlanning\LaravelErrorMonitor\Contracts\ErrorEventRepository;
use AshitaPlanning\LaravelErrorMonitor\Contracts\FingerprintGenerator;
use AshitaPlanning\LaravelErrorMonitor\Contracts\LogNormalizer;
use AshitaPlanning\LaravelErrorMonitor\Contracts\SensitiveDataMasker;
use AshitaPlanning\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use AshitaPlanning\LaravelErrorMonitor\Tests\TestCase;

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

        $this->artisan('vendor:publish', [
            '--provider' => ErrorMonitorServiceProvider::class,
            '--tag' => 'error-monitor-config',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($path);
    }

    public function test_it_registers_artisan_commands(): void
    {
        $this->artisan('error-monitor:analyze')
            ->expectsOutputToContain('Analysis completed')
            ->assertExitCode(0);

        $this->artisan('error-monitor:status')
            ->expectsOutputToContain('Registered events')
            ->assertExitCode(0);
    }
}
