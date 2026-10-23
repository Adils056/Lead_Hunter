<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->index();
            $table->string('job_title')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('email_verification_status', 50)->default('unverified')->index(); // unverified, verified, risky, invalid, unknown, catch_all
            $table->string('phone', 50)->nullable();
            $table->string('profile_url', 500)->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->string('source_name', 100)->nullable()->index();
            $table->string('source_url', 500)->nullable();
            $table->timestamp('discovery_date')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('country', 100)->nullable()->index();
            $table->string('industry', 100)->nullable()->index();
            $table->json('relevant_services')->nullable(); // web_dev, ecommerce, custom_software, laravel_php, erp_crm, etc.
            
            // CRM Lifecycle Stage
            $table->string('lifecycle_stage', 50)->default('new')->index();
            // new, researching, qualified, ready_for_review, approved, contacted, replied, interested, meeting_booked, proposal_sent, negotiation, won, lost, not_a_fit

            // 100-Point Scoring Model
            $table->unsignedSmallInteger('intent_score')->default(0)->index();
            $table->unsignedSmallInteger('service_fit_score')->default(0)->index();
            $table->unsignedSmallInteger('contact_confidence_score')->default(0)->index();
            $table->unsignedSmallInteger('overall_score')->default(0)->index();
            $table->text('score_explanation')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->timestamp('scored_at')->nullable();

            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->date('next_action_date')->nullable()->index();
            $table->string('next_action_note')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lifecycle_stage', 'overall_score']);
            $table->index(['country', 'lifecycle_stage']);
            $table->index(['assigned_user_id', 'lifecycle_stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
