<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Buyer's Radar: every buying-intent sighting (forum posts, tenders, hiring, signals) with its intent level. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_items', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 40)->index();
            $t->string('external_id', 120);
            $t->foreignId('buying_signal_id')->nullable()->constrained('buying_signals')->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $t->string('title', 300);
            $t->text('excerpt')->nullable();
            $t->string('url', 500)->nullable();
            $t->string('author', 120)->nullable();
            $t->string('country', 80)->nullable()->index();
            $t->string('category', 80)->nullable()->index();
            $t->string('service', 60)->nullable()->index();
            $t->string('intent_level', 10)->index(); // hot, warm, normal
            $t->unsignedTinyInteger('intent_score');
            $t->json('reasons')->nullable();
            $t->string('status', 20)->default('new')->index(); // new, contacted, dismissed
            $t->timestamp('posted_at')->nullable()->index();
            $t->timestamps();
            $t->unique(['platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_items');
    }
};
