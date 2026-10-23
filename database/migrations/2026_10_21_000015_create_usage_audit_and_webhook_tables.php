<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->index(); // openai, hunter, apollo, smtp
            $table->string('service_type', 50)->index(); // ai_research, email_verification, email_sending, lead_search
            $table->unsignedInteger('units_consumed')->default(1);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost_estimated', 8, 4)->default(0.0000);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index(); // login, lead_create, lead_export, campaign_approve, emergency_stop, settings_update
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->index(); // resend, sendgrid, mailgun, hunter, custom
            $table->string('event_type', 100)->index(); // delivered, bounced, complained, opened, clicked, replied
            $table->string('idempotency_key', 150)->unique();
            $table->string('signature', 255)->nullable();
            $table->json('payload');
            $table->boolean('is_processed')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('usage_logs');
    }
};
