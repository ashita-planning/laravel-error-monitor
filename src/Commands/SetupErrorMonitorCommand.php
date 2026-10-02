<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\Services\SetupFileManager;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Throwable;

final class SetupErrorMonitorCommand extends Command
{
    protected $signature = 'error-monitor:setup {--dry-run : Preview without writing} {--json : Machine-readable output}';

    protected $description = 'Publish missing core configuration and append missing environment settings.';

    public function handle(SetupFileManager $files, Application $application): int
    {
        try {
            $result = $files->setup(
                $application->environmentFilePath(), config_path('error-monitor.php'),
                __DIR__.'/../../config/error-monitor.php', [
                    'ERROR_MONITOR_ENABLED' => 'true',
                    'ERROR_MONITOR_TIMEZONE' => (string) config('app.timezone', 'UTC'),
                ], (bool) $this->option('dry-run'),
            );
        } catch (Throwable) {
            $this->error('Setup failed. Check file permissions and target files; existing settings were not overwritten.');

            return self::FAILURE;
        }

        $result['configuration_cached'] = $application->configurationIsCached();
        $result['next_steps'] = ['Review migrate:status and apply migrations through your deployment process.',
            'If configuration is cached, rebuild it before checking the effective configuration.',
            'Run error-monitor:doctor and a dry run; configure the host scheduler separately.'];
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
