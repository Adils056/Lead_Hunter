<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LeadPilot v4 — Autopilot: autonomy policy, approvals, notifications, incidents, provider registry
 * health, budgets/cost ledger, search-allocation learning, sequences, reply sync, proposals, heartbeats.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Lead pipeline state (separate from CRM lifecycle_stage) + outreach sequence fields
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pipeline_state', 40)->default('discovered')->after('lifecycle_stage')->index();
            $table->timestamp('pipeline_state_at')->nullable()->after('pipeline_state');
            $table->timestamp('outreach_eligible_at')->nullable()->after('pipeline_state_at');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence_step')->default(1)->after('lead_id');
            $table->foreignId('parent_recipient_id')->nullable()->after('sequence_step')->constrained('campaign_recipients')->nullOnDelete();
            $table->string('rfc_message_id', 255)->nullable()->after('provider_message_id')->index(); // real Message-ID header
            $table->json('facts_used')->nullable()->after('personalized_body');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('icp_profile_id')->nullable()->after('email_template_id')->constrained('icp_profiles')->nullOnDelete();
            $table->boolean('is_autopilot')->default(false)->after('icp_profile_id')->index();
        });

        Schema::table('inbound_replies', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('classification')->index(); // 8-category taxonomy
            $table->string('message_id', 255)->nullable()->after('category')->unique();
            $table->string('source', 20)->default('webhook')->after('message_id'); // webhook, imap, manual
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->string('capability', 50)->nullable()->after('service_type')->index();
            $table->unsignedInteger('cost_cents')->default(0)->after('cost_estimated');
            $table->boolean('is_estimate')->default(true)->after('cost_cents');
            $table->foreignId('icp_profile_id')->nullable()->after('is_estimate')->constrained('icp_profiles')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->after('icp_profile_id')->constrained('campaigns')->nullOnDelete();
        });

        // ---- Autonomy policy: action -> automatic | approval | prohibited
        Schema::create('autonomy_policies', function (Blueprint $table) {
            $table->id();
            $table->string('action', 80)->unique();
            $table->string('mode', 20)->default('approval'); // automatic, approval, prohibited
            $table->json('limits')->nullable();               // e.g. {"max_per_day": 10}
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---- Approvals (Level 2) with full history
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();               // outreach_message, follow_up, reply_response, spend_over_budget, integration_enable, low_confidence_reply, proposal_send
            $table->string('dedupe_key', 191)->unique();
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected, info_requested, expired, cancelled, executed, failed
            $table->string('priority', 10)->default('normal')->index(); // low, normal, high, critical
            $table->string('title');
            $table->text('summary');                           // what happened + why human needed
            $table->nullableMorphs('subject');                 // lead / campaign / recipient / integration
            $table->json('payload')->nullable();               // editable content (e.g. subject/body)
            $table->json('evidence')->nullable();
            $table->json('attempted')->nullable();             // what the system already tried
            $table->json('options')->nullable();
            $table->string('recommended_action', 60)->nullable();
            $table->string('assigned_role', 30)->default('admin');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('last_notified_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('execution_result')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);                       // created, notified, approved, edited_approved, rejected, info_requested, reassigned, escalated, paused, retried, executed, failed
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- Notifications: in-app store + per-channel delivery tracking
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);                     // database, mail
            $table->string('dedupe_key', 191)->index();
            $table->string('status', 30)->index();             // stored, accepted_by_smtp, failed  (never "delivered" without provider confirmation)
            $table->text('error')->nullable();
            $table->string('subject_ref', 120)->nullable();
            $table->timestamps();
        });

        // ---- Incidents (Level 3) and workflow pause switches
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();               // bounce_spike, complaint_spike, auth_failures, spend_exceeded, queue_stalled, scheduler_stalled, duplicate_send, provider_disabled, notification_failures, data_quality
            $table->string('severity', 10)->default('high');
            $table->string('workflow', 60)->nullable()->index();
            $table->string('dedupe_key', 191)->index();
            $table->string('status', 20)->default('open')->index(); // open, acknowledged, resolved
            $table->string('title');
            $table->text('details')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_controls', function (Blueprint $table) {
            $table->id();
            $table->string('workflow', 60)->unique();          // discovery, enrichment, outreach, follow_ups, reply_sync, ai_drafting
            $table->boolean('paused')->default(false);
            $table->string('reason')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });

        // ---- Provider registry health + circuit breaker
        Schema::create('provider_health', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60)->unique();
            $table->string('status', 20)->default('unknown');  // unknown, healthy, degraded, failing, disabled, unconfigured
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('auth_failures')->default(0);
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('total_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('disabled_until')->nullable();
            $table->timestamp('last_verified_at')->nullable(); // real connectivity test passed
            $table->timestamps();
        });

        // ---- Budgets
        Schema::create('spend_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);                        // global, provider, campaign, icp
            $table->string('scope_ref', 80)->nullable();        // provider slug / campaign id / icp id
            $table->string('period', 10);                       // daily, monthly
            $table->unsignedInteger('limit_cents');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['scope', 'scope_ref', 'period']);
        });

        // ---- Search-allocation learning (ICP x source arms)
        Schema::create('search_arms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('icp_profile_id')->constrained('icp_profiles')->cascadeOnDelete();
            $table->string('source_slug', 60);
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('qualified')->default(0);
            $table->unsignedInteger('positive')->default(0);   // positive replies / meetings / wins
            $table->unsignedInteger('cost_cents')->default(0);
            $table->unsignedInteger('query_cursor')->default(0); // checkpoint: next query index
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('exhausted_until')->nullable();
            $table->timestamps();
            $table->unique(['icp_profile_id', 'source_slug']);
        });

        // ---- Proposal drafts (never auto-sent)
        Schema::create('proposal_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('service', 60);
            $table->string('title');
            $table->longText('body');
            $table->unsignedInteger('price_low_usd')->nullable();
            $table->unsignedInteger('price_high_usd')->nullable();
            $table->string('status', 20)->default('draft');     // draft, pending_approval, approved, sent, rejected
            $table->json('evidence')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---- Health heartbeats (scheduler, queue worker, reply sync)
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('component', 40)->unique();
            $table->timestamp('beat_at');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ---- IMAP sync checkpoint
        Schema::create('mailbox_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 191)->unique();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mailbox_cursors', 'system_heartbeats', 'proposal_drafts', 'search_arms', 'spend_budgets', 'provider_health', 'workflow_controls', 'incidents', 'notification_deliveries', 'notifications', 'approval_events', 'approval_requests', 'autonomy_policies'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('icp_profile_id');
            $table->dropColumn(['capability', 'cost_cents', 'is_estimate']);
        });
        Schema::table('inbound_replies', function (Blueprint $table) {
            $table->dropUnique(['message_id']);
            $table->dropColumn(['category', 'message_id', 'source']);
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('icp_profile_id');
            $table->dropColumn('is_autopilot');
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_recipient_id');
            $table->dropColumn(['sequence_step', 'rfc_message_id', 'facts_used']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['pipeline_state', 'pipeline_state_at', 'outreach_eligible_at']);
        });
    }
};
