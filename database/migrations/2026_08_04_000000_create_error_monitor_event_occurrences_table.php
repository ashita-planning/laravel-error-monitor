<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per distinct payload merged into a daily aggregate.
     *
     * `error_monitor_events.payload_hash` can only remember the payload
     * processed last, so re-analysing a log file that produced several distinct
     * entries for the same fingerprint could not tell which of them had already
     * been counted, and added them again. The history lives here instead, and
     * the unique constraint is what makes a repeated analysis a no-op even when
     * two runs race.
     */
    public function up(): void
    {
        Schema::create('error_monitor_event_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('error_monitor_event_id')
                ->constrained('error_monitor_events')
                ->cascadeOnDelete();
            $table->char('payload_hash', 64);

            // The occurrence may itself stand for a range of merged entries,
            // which is why it carries its own bounds and count.
            $table->timestamp('occurred_at');
            $table->timestamp('first_occurred_at')->nullable();
            $table->timestamp('last_occurred_at')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamps();

            $table->unique(
                ['error_monitor_event_id', 'payload_hash'],
                'error_monitor_event_occurrences_payload_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_monitor_event_occurrences');
    }
};
