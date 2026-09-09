<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('error_monitor_events', function (Blueprint $table): void {
            $table->timestamp('first_occurred_at')->nullable()->change();
            $table->timestamp('last_occurred_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring implicit TIMESTAMP defaults would reintroduce the defect.
    }
};
