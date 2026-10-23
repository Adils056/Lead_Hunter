<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 50)->default('dry_run')->index(); // dry_run, controlled_pilot, live
            $table->string('sender_name')->default('LeadPilot Outreach');
            $table->string('sender_email')->default('sales@leadpilot.test');
            $table->string('sending_domain')->default('leadpilot.test');
            $table->string('reply_to_email')->nullable();
            $table->json('allowed_countries')->nullable();
            $table->json('approved_services')->nullable();
            $table->unsignedSmallInteger('min_lead_score')->default(75);
            $table->unsignedSmallInteger('min_evidence_confidence')->default(65);
            $table->boolean('require_verified_email')->default(true);
            $table->unsignedInteger('max_daily_sends')->default(25);
            $table->unsignedSmallInteger('max_sends_per_recipient')->default(2);
            $table->string('sending_window_start', 10)->default('09:00');
            $table->string('sending_window_end', 10)->default('18:00');
            $table->string('sending_timezone', 50)->default('Asia/Karachi');
            $table->unsignedSmallInteger('follow_up_delay_days')->default(3);
            $table->unsignedSmallInteger('max_follow_ups')->default(2);
            $table->boolean('emergency_stop')->default(false)->index();
            $table->text('emergency_stop_reason')->nullable();
            $table->string('controlled_pilot_recipient')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_settings');
    }
};
