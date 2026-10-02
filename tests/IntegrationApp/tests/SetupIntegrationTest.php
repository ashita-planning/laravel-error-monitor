<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Integration\Tests;

use Illuminate\Support\Facades\Http;

final class SetupIntegrationTest extends TestCase
{
    public function test_all_three_setups_are_discovered_and_preserve_the_host_environment(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $directory = sys_get_temp_dir().'/monitor-integration-setup-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->app->useEnvironmentPath($directory);
        $this->app->useConfigPath($directory);
        $commands = [
            'error-monitor:setup' => [],
            'error-monitor:github-setup' => ['--repository' => self::REPOSITORY, '--enable' => true],
            'error-monitor:xserver-setup' => ['--server-id' => self::SERVER_ID, '--domain' => self::DOMAIN, '--enable' => true],
        ];
        try {
            foreach ($commands as $command => $options) {
                $this->artisan($command, $options + ['--dry-run' => true, '--json' => true])->assertSuccessful();
            }
            $this->assertFileDoesNotExist($directory.'/.env');
            foreach ($commands as $command => $options) {
                $this->artisan($command, $options)->assertSuccessful();
            }
            $content = file_get_contents($directory.'/.env');
            foreach ($commands as $command => $options) {
                $this->artisan($command, $options)->assertSuccessful();
            }
            $this->assertSame($content, file_get_contents($directory.'/.env'));
            $this->assertStringNotContainsString(self::TOKEN, $content);
            $this->artisan('error-monitor:doctor', ['--json' => true])->assertSuccessful();
            $this->assertDatabaseCount('error_monitor_events', 0);
            Http::assertNothingSent();
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            @unlink($directory.'/.env');
            rmdir($directory);
        }
    }
}
