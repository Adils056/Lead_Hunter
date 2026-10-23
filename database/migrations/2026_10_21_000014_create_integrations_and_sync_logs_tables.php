<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique(); // openai, hunter, apollo, smtp, resend, sendgrid, google_search
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->json('settings')->nullable(); // model, timeouts, rate limits, spending caps
            $table->boolean('is_active')->default(false)->index();
            $table->string('status', 50)->default('pending_credentials')->index(); // connected, pending_credentials, error, rate_limited
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('monthly_spend_limit_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('operation', 100);
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->json('log_details')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('running')->index(); // running, success, partial, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('integrations');
    }
};
