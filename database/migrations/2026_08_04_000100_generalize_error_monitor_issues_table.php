<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the issue link beyond one tracker's shape.
     *
     * `issue_number` is an unsigned integer, which is GitHub's idea of an
     * identifier and nobody else's - Jira answers `OPS-42`. `provider` and
     * `external_id` replace that assumption.
     *
     * Nothing is dropped or retyped. The old columns keep working for anything
     * already reading them, and `issue_number` is still filled in when the
     * identifier happens to be numeric.
     */
    public function up(): void
    {
        Schema::table('error_monitor_issues', function (Blueprint $table): void {
            $table->string('provider', 64)->default('github')->after('id');
            $table->string('external_id', 191)->nullable()->after('repository');
            $table->string('external_state', 32)->nullable()->after('issue_state');
            $table->json('metadata')->nullable()->after('resolved_at');

            $table->index(['provider', 'environment', 'fingerprint'], 'error_monitor_issues_provider_index');
        });

        // Existing rows predate the generalisation and were all GitHub.
        DB::table('error_monitor_issues')->update([
            'external_id' => DB::raw('issue_number'),
            'external_state' => DB::raw('issue_state'),
        ]);
    }

    public function down(): void
    {
        Schema::table('error_monitor_issues', function (Blueprint $table): void {
            $table->dropIndex('error_monitor_issues_provider_index');
            $table->dropColumn(['provider', 'external_id', 'external_state', 'metadata']);
        });
    }
};
