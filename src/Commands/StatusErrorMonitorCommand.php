<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Commands;

use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class StatusErrorMonitorCommand extends Command
{
    protected $signature = 'error-monitor:status';

    protected $description = 'Show error monitor configuration and storage status.';

    public function handle(): int
    {
        $tableExists = false;
        $databaseStatus = 'Unavailable';

        try {
            DB::connection()->getPdo();
            $databaseStatus = 'Connected';
            $tableExists = Schema::hasTable('error_monitor_events');
        } catch (Throwable $exception) {
            $databaseStatus = 'Unavailable: '.$exception->getMessage();
        }

        $eventCount = $tableExists ? ErrorMonitorEvent::query()->count() : 0;

        $this->table(['Setting', 'Value'], [
            ['Enabled', config('error-monitor.enabled') ? 'Yes' : 'No'],
            ['Timezone', (string) config('error-monitor.timezone')],
            ['Laravel log path', (string) config('error-monitor.laravel_log_path')],
            ['Database', $databaseStatus],
            ['Migrations', $tableExists ? 'error_monitor_events table found' : 'error_monitor_events table not found'],
            ['Registered events', (string) $eventCount],
        ]);

        return self::SUCCESS;
    }
}
