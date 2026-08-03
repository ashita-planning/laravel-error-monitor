<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reports the health of the package: configuration, log path, database, storage
 * and the number of events recorded so far.
 *
 * Secrets are never printed. The GitHub token is only reported as configured or
 * not, so the output can be pasted into an issue or a CI log.
 */
final class StatusErrorMonitorCommand extends Command
{
    protected $signature = 'error-monitor:status {--json : Output the status as JSON}';

    protected $description = 'Show error monitor configuration and storage status.';

    public function handle(DatabaseManager $database): int
    {
        $connection = $database->connection();

        $tableExists = false;
        $issuesTableExists = false;
        $connected = true;
        $databaseStatus = 'Connected';

        try {
            $connection->getPdo();
            $tableExists = $connection->getSchemaBuilder()->hasTable('error_monitor_events');
            $issuesTableExists = $connection->getSchemaBuilder()->hasTable('error_monitor_issues');
        } catch (Throwable $exception) {
            $connected = false;
            $databaseStatus = 'Unavailable: '.$exception->getMessage();
        }

        $logPath = (string) config('error-monitor.laravel_log_path');
        $token = config('error-monitor.github.token');

        $status = [
            'Enabled' => config('error-monitor.enabled') ? 'Yes' : 'No',
            'Environment' => (string) config('error-monitor.environment'),
            'Timezone' => (string) config('error-monitor.timezone'),
            'Laravel log path' => $logPath,
            'Log path exists' => $this->pathState($logPath, static fn (string $path): bool => file_exists($path)),
            'Log path readable' => $this->pathState($logPath, static fn (string $path): bool => is_readable($path)),
            'Log files found' => (string) $this->countLogFiles($logPath),
            'Analyzed status codes' => implode(', ', array_map('strval', (array) config('error-monitor.status_codes', []))),
            'Masking' => config('error-monitor.masking.enabled') ? 'Enabled' : 'Disabled',
            'Results path' => (string) config('error-monitor.results_path'),
            'Retention (days)' => (string) config('error-monitor.retention_days'),
            'Database' => $databaseStatus,
            'Migrations' => $tableExists ? 'error_monitor_events table found' : 'error_monitor_events table not found',
            'Issue table' => $issuesTableExists ? 'error_monitor_issues table found' : 'error_monitor_issues table not found',
            'Registered events' => $tableExists ? (string) ErrorMonitorEvent::query()->count() : '0',
            'GitHub integration' => config('error-monitor.github.enabled') ? 'Enabled (not implemented yet)' : 'Disabled',
            'GitHub repository' => (string) (config('error-monitor.github.repository') ?? 'Not configured'),
            'GitHub token' => is_string($token) && $token !== '' ? 'Configured' : 'Not configured',
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $rows = [];

            foreach ($status as $setting => $value) {
                $rows[] = [$setting, $value];
            }

            $this->table(['Setting', 'Value'], $rows);
        }

        if (! $connected) {
            return AnalyzeErrorMonitorCommand::EXIT_INVALID_CONFIGURATION;
        }

        if (! $tableExists || ! $issuesTableExists) {
            $this->components->warn('Run "php artisan migrate" to create the missing error monitor tables.');
        }

        return AnalyzeErrorMonitorCommand::EXIT_SUCCESS;
    }

    /** @param  callable(string): bool  $check */
    private function pathState(string $path, callable $check): string
    {
        if ($path === '') {
            return 'Not configured';
        }

        return $check($path) ? 'Yes' : 'No';
    }

    /** Number of files the configured log patterns currently match. */
    private function countLogFiles(string $logPath): int
    {
        if ($logPath === '') {
            return 0;
        }

        $directory = is_dir($logPath) ? $logPath : dirname($logPath);

        if (! is_dir($directory)) {
            return 0;
        }

        /** @var array<int, string> $patterns */
        $patterns = (array) config('error-monitor.laravel_log_patterns', []);
        $files = [];

        foreach ($patterns as $pattern) {
            foreach (glob(rtrim($directory, '/').'/'.$pattern) ?: [] as $file) {
                $files[$file] = true;
            }
        }

        return count($files);
    }
}
