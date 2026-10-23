<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('industry', 100)->nullable()->index();
            $table->string('country', 100)->nullable()->index();
            $table->string('city', 100)->nullable();
            $table->string('size_range', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('public_email')->nullable();
            $table->string('public_phone', 50)->nullable();
            $table->text('public_address')->nullable();
            $table->json('tech_stack')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_provider', 100)->nullable()->index();
            $table->timestamp('discovery_date')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('status', 50)->default('prospect')->index();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ai_research')->nullable();
            $table->timestamp('ai_researched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country', 'industry']);
            $table->index(['status', 'assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
