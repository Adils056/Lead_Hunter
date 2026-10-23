<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('scoring_version', 20)->default('1.0');
            $table->unsignedSmallInteger('intent_score')->default(0);
            $table->unsignedSmallInteger('service_fit_score')->default(0);
            $table->unsignedSmallInteger('contact_confidence_score')->default(0);
            $table->unsignedSmallInteger('overall_score')->default(0);
            $table->string('priority_level', 20)->default('low')->index(); // high, medium, low, needs_review
            $table->text('reasoning')->nullable();
            $table->json('breakdown')->nullable();
            $table->json('contributing_signals')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
    }
};
