<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $environment
 * @property string $fingerprint
 * @property string $repository
 * @property int $issue_number
 * @property string $issue_state
 * @property int|null $pull_request_number
 * @property string|null $last_comment_hash
 * @property DateTimeImmutable|null $last_reported_at
 * @property DateTimeImmutable|null $resolved_at
 */
final class ErrorMonitorIssue extends Model
{
    protected $table = 'error_monitor_issues';

    /** @var array<int, string> */
    protected $guarded = [];

    // Declared as a property rather than the casts() method: that method is
    // Laravel 11 and newer, and on Laravel 10 it is silently ignored, which
    // hands back raw strings instead of dates.
    /** @var array<string, string> */
    protected $casts = [
        'last_reported_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'issue_number' => 'integer',
        'pull_request_number' => 'integer',
    ];
}
