<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The ordered list of AI models (free tiers first) LeadPilot tries for drafting and analysis, each with its own daily limit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('priority')->default(100)->index();
            $t->string('name');
            $t->string('kind', 20)->default('openai'); // openai = any OpenAI-compatible API, gemini = Google's native API
            $t->string('base_url')->nullable();
            $t->string('model');
            $t->text('api_key')->nullable(); // encrypted cast
            $t->unsignedInteger('daily_limit')->nullable(); // requests per day; null = no limit (paid)
            $t->unsignedInteger('used_today')->default(0);
            $t->date('used_on')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamp('cooldown_until')->nullable();
            $t->string('last_status', 20)->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
