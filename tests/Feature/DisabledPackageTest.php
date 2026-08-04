<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Collectors\LaravelLogCollector;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Services\ErrorMonitorAnalyzer;
use Apkk\LaravelErrorMonitor\Tests\TestCase;

/**
 * A disabled package must not read a single log line, whatever is configured.
 */
final class DisabledPackageTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('error-monitor.enabled', false);
        // Pointing at a directory that does have logs proves the driver is
        // skipped because the package is off, not because nothing matched.
        $app['config']->set('error-monitor.laravel_log_path', dirname(__DIR__).'/Fixtures');
    }

    public function test_the_driver_stays_resolvable_for_an_application_that_wants_it(): void
    {
        $this->assertInstanceOf(LaravelLogCollector::class, app(LaravelLogCollector::class));
    }

    public function test_an_analysis_collects_nothing(): void
    {
        $result = app(ErrorMonitorAnalyzer::class)->analyze();

        $this->assertSame(0, $result->sourcesConfigured);
        $this->assertSame(0, $result->filesAnalyzed);
        $this->assertSame(0, ErrorMonitorEvent::query()->count());
    }
}
