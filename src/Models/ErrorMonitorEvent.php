<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
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

    /**
     * Distinct payloads already merged into this aggregate.
     *
     * `payload_hash` on this row only remembers the one processed last; the
     * full history is what makes a repeated analysis a no-op.
     *
     * @return HasMany<ErrorMonitorEventOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ErrorMonitorEventOccurrence::class, 'error_monitor_event_id');
    }
}
