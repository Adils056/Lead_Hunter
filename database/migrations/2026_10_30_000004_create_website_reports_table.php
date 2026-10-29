<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A saved competitor-benchmark report for one company (shown on screen and downloadable as PDF). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->json('benchmark');
            $t->json('solutions')->nullable();
            $t->json('ai')->nullable();
            $t->string('ai_provider')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_reports');
    }
};
