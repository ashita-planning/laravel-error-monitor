<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One distinct payload merged into a daily aggregate.
 *
 * The rows are the record of what has already been counted, which is what makes
 * re-analysing the same log file a no-op.
 *
 * @property int $id
 * @property int $error_monitor_event_id
 * @property string $payload_hash
 * @property DateTimeImmutable $occurred_at
 * @property DateTimeImmutable|null $first_occurred_at
 * @property DateTimeImmutable|null $last_occurred_at
 * @property int $occurrence_count
 */
final class ErrorMonitorEventOccurrence extends Model
{
    protected $table = 'error_monitor_event_occurrences';

    /** @var array<int, string> */
    protected $guarded = [];

    // Declared as a property rather than the casts() method: that method is
    // Laravel 11 and newer, and on Laravel 10 it is silently ignored.
    /** @var array<string, string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'first_occurred_at' => 'immutable_datetime',
        'last_occurred_at' => 'immutable_datetime',
        'occurrence_count' => 'integer',
    ];

    /** @return BelongsTo<ErrorMonitorEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ErrorMonitorEvent::class, 'error_monitor_event_id');
    }
}
