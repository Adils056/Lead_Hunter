<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('domain')->nullable()->index();
            $table->string('reason', 50)->default('unsubscribe')->index();
            // unsubscribe, hard_bounce, complaint, manual, gdpr_request, do_not_contact
            $table->string('source', 100)->nullable(); // webhook, admin, unsubscribe_link
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email', 'reason']);
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('lawful_basis', 50)->default('legitimate_interest')->index();
            // legitimate_interest, explicit_consent, public_directory, contractual
            $table->string('channel', 50)->default('b2b_public');
            $table->string('source_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('suppressions');
    }
};
