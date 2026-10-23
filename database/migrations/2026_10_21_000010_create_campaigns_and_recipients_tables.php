<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_offered', 100)->index();
            $table->json('target_countries')->nullable();
            $table->json('target_industries')->nullable();
            $table->unsignedSmallInteger('min_lead_score')->default(60)->index();
            $table->json('eligible_sources')->nullable();
            $table->json('contact_rules')->nullable(); // require verified email, require intent score > x
            $table->unsignedInteger('daily_limit')->default(50);
            $table->unsignedInteger('sent_today')->default(0);
            $table->date('last_sent_date')->nullable();
            $table->json('schedule_config')->nullable(); // sending days, start_hour, end_hour
            $table->string('timezone', 50)->default('UTC');
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->json('follow_up_config')->nullable();
            $table->boolean('require_approval')->default(true)->index();
            $table->string('status', 50)->default('draft')->index(); // draft, pending_approval, approved, running, paused, completed, cancelled
            $table->boolean('emergency_stopped')->default(false)->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('status', 50)->default('draft')->index();
            // draft, pending_review, approved, queued, sent, delivered, replied, bounced, opted_out, skipped, failed
            $table->string('idempotency_key', 120)->unique();
            $table->string('personalized_subject')->nullable();
            $table->text('personalized_body')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('provider_message_id', 150)->nullable()->index();
            $table->string('bounce_type', 50)->nullable(); // hard, soft, complaint
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['lead_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
