<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Throwable;

final class DoctorErrorMonitorCommand extends Command
{
    protected $signature = 'error-monitor:doctor {--json : Machine-readable output}';

    protected $description = 'Check effective core configuration and all package tables without modifying data.';

    public function handle(DatabaseManager $database, Application $application): int
    {
        $checks = ['enabled' => (bool) config('error-monitor.enabled', true)];
        try {
            new DateTimeZone((string) config('error-monitor.timezone'));
            $checks['timezone'] = true;
        } catch (Throwable) {
            $checks['timezone'] = false;
        }
        foreach (['error_monitor_events', 'error_monitor_event_occurrences', 'error_monitor_issues'] as $table) {
            try {
                $checks[$table] = $database->connection()->getSchemaBuilder()->hasTable($table);
            } catch (Throwable) {
                $checks[$table] = false;
            }
        }
        $path = (string) config('error-monitor.laravel_log_path');
        $directory = is_dir($path) ? $path : dirname($path);
        $files = [];
        foreach ((array) config('error-monitor.laravel_log_patterns', []) as $pattern) {
            foreach (glob($directory.'/'.$pattern) ?: [] as $file) {
                if (is_file($file) && is_readable($file)) {
                    $files[$file] = true;
                }
            }
        }
        $result = [
            'checks' => $checks,
            'healthy' => ! in_array(false, $checks, true),
            'readable_laravel_log_files' => count($files),
            'configuration_cached' => $application->configurationIsCached(),
            'notes' => ['Log absence may be normal; verify configured sources and expected file coverage.',
                'This check does not verify pending migrations, cron execution or external publishing.',
                'Run installed adapter status commands separately; publishing failures can accompany exit code 0.'],
        ];
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT));

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
