<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_monitor_events', function (Blueprint $table): void {
            // Masked request context and analyser notes (how the status was
            // resolved, correlation confidence, ...).
            $table->json('context')->nullable()->after('status');
            $table->json('metadata')->nullable()->after('context');
        });

        Schema::table('error_monitor_issues', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('last_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('error_monitor_events', function (Blueprint $table): void {
            $table->dropColumn(['context', 'metadata']);
        });

        Schema::table('error_monitor_issues', function (Blueprint $table): void {
            $table->dropColumn('resolved_at');
        });
    }
};
