<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class SetupCommandsTest extends TestCase
{
    public function test_setup_preview_does_not_write_host_files_or_tables(): void
    {
        $directory = sys_get_temp_dir().'/error-monitor-command-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->app->useEnvironmentPath($directory);
        $this->app->useConfigPath($directory);
        try {
            $this->artisan('error-monitor:setup', ['--dry-run' => true, '--json' => true])->assertSuccessful();
            $this->assertFileDoesNotExist($directory.'/.env');
            $this->assertFileDoesNotExist($directory.'/error-monitor.php');
            $this->assertDatabaseCount('error_monitor_events', 0);
        } finally {
            rmdir($directory);
        }
    }

    public function test_doctor_detects_missing_occurrence_table_even_if_other_tables_exist(): void
    {
        $this->artisan('error-monitor:doctor', ['--json' => true])->assertSuccessful();
        Schema::drop('error_monitor_event_occurrences');
        $this->artisan('error-monitor:doctor', ['--json' => true])->assertFailed();
    }
}
