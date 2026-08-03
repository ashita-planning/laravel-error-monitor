<?php

declare(strict_types=1);

namespace AshitaPlanning\LaravelErrorMonitor\Models;

use Illuminate\Database\Eloquent\Model;

final class ErrorMonitorIssue extends Model
{
    protected $table = 'error_monitor_issues';

    /** @var array<int, string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_reported_at' => 'immutable_datetime',
            'issue_number' => 'integer',
            'pull_request_number' => 'integer',
        ];
    }
}
