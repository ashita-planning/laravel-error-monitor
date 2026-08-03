<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_monitor_events', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 100);
            $table->string('source', 100);
            $table->char('fingerprint', 64);
            $table->date('detected_date');
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->string('exception_class');
            $table->text('normalized_message');
            $table->text('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('method', 16)->nullable();
            $table->text('route')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->unique(['environment', 'source', 'fingerprint', 'detected_date'], 'error_monitor_events_daily_unique');
            $table->index(['environment', 'source', 'fingerprint'], 'error_monitor_events_fingerprint_index');
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_monitor_events');
    }
};
