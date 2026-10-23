<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
                $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->string('direction', 20)->default('outbound'); // outbound, inbound
                $table->string('provider', 50)->default('smtp'); // smtp, resend, sendgrid, mailgun, ses
                $table->string('message_id', 150)->nullable()->index();
                $table->string('sender_email');
                $table->string('recipient_email')->index();
                $table->string('subject');
                $table->text('body_snippet')->nullable();
                $table->string('status', 50)->default('queued')->index(); // queued, sent, delivered, bounced, failed
                $table->json('event_metadata')->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['recipient_email', 'status']);
            });
        }

        if (!Schema::hasTable('inbound_replies')) {
            Schema::create('inbound_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->string('from_email')->index();
                $table->string('to_email');
                $table->string('subject');
                $table->text('body_text');
                $table->string('classification', 50)->default('uncertain')->index();
                // positive, negative, meeting_request, question, unsubscribe, auto_reply, out_of_office, uncertain
                $table->unsignedSmallInteger('ai_confidence')->default(50); // 0-100
                $table->text('ai_reasoning')->nullable();
                $table->boolean('is_reviewed')->default(false)->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamp('received_at')->useCurrent()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_replies');
        Schema::dropIfExists('email_logs');
    }
};
