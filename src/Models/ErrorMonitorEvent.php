<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $payload_hash
 * @property DateTimeImmutable|null $first_occurred_at
 * @property DateTimeImmutable|null $last_occurred_at
 * @property int $occurrence_count
 */
final class ErrorMonitorEvent extends Model
{
    protected $table = 'error_monitor_events';

    /** @var array<int, string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detected_date' => 'date',
            'first_occurred_at' => 'immutable_datetime',
            'last_occurred_at' => 'immutable_datetime',
            'occurrence_count' => 'integer',
            'line' => 'integer',
            'status_code' => 'integer',
        ];
    }
}
