<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buying_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('signal_type', 100)->index();
            // project_requirements, developer_hiring, vendor_request, business_launch, funding_announcement, erp_crm_request, procurement_rfp, tech_stack_change, website_issues, business_expansion
            $table->string('title');
            $table->string('source_url', 500)->nullable();
            $table->text('evidence_text');
            $table->date('observed_date')->index();
            $table->unsignedSmallInteger('confidence')->default(70); // 0-100
            $table->string('relevant_service', 100)->index(); // web_dev, ecommerce, erp_crm, laravel_php, etc.
            $table->date('expiry_date')->nullable()->index();
            $table->string('verification_status', 50)->default('unverified')->index(); // unverified, verified, expired, dismissed
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_signals');
    }
};
