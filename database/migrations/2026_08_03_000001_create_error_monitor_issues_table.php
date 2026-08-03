<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_monitor_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 100);
            $table->char('fingerprint', 64);
            $table->string('repository');
            $table->unsignedBigInteger('issue_number');
            $table->string('issue_state', 32);
            $table->unsignedBigInteger('pull_request_number')->nullable();
            $table->char('last_comment_hash', 64)->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();

            $table->unique(['environment', 'fingerprint', 'repository'], 'error_monitor_issues_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_monitor_issues');
    }
};
