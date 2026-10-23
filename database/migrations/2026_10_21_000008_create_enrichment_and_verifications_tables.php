<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_records', function (Blueprint $table) {
            $table->id();
            $table->morphs('enrichable'); // Lead or Company
            $table->string('provider', 50)->index(); // openai, hunter, apollo, web_crawler, manual
            $table->string('operation', 50)->default('enrich'); // company_research, email_discovery, profile_enrich
            $table->string('status', 50)->default('completed')->index(); // pending, completed, failed, rate_limited
            $table->json('raw_payload')->nullable();
            $table->json('normalized_data')->nullable();
            $table->unsignedSmallInteger('credits_used')->default(0);
            $table->decimal('cost_estimated', 8, 4)->default(0.0000);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('email')->index();
            $table->string('status', 50)->index(); // verified, invalid, risky, catch_all, unknown
            $table->string('provider', 50)->default('hunter'); // hunter, zero_bounce, debounce, mock
            $table->unsignedTinyInteger('score')->nullable(); // provider quality score 0-100
            $table->boolean('is_disposable')->default(false);
            $table->boolean('is_free_mailbox')->default(false);
            $table->boolean('has_mx_records')->default(true);
            $table->boolean('has_smtp_check')->default(false);
            $table->json('provider_response')->nullable();
            $table->unsignedSmallInteger('credits_used')->default(1);
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
        Schema::dropIfExists('enrichment_records');
    }
};
