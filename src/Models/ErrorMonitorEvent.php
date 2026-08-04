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
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $metadata
 */
final class ErrorMonitorEvent extends Model
{
    protected $table = 'error_monitor_events';

    /** @var array<int, string> */
    protected $guarded = [];

    // Declared as a property rather than the casts() method: that method is
    // Laravel 11 and newer, and on Laravel 10 it is silently ignored, which
    // hands back raw strings instead of dates and arrays.
    /** @var array<string, string> */
    protected $casts = [
        'detected_date' => 'date',
        'first_occurred_at' => 'immutable_datetime',
        'last_occurred_at' => 'immutable_datetime',
        'occurrence_count' => 'integer',
        'line' => 'integer',
        'status_code' => 'integer',
        'context' => 'array',
        'metadata' => 'array',
    ];
}
