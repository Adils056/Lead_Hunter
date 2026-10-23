<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('provider_type', 50)->index(); // search_api, website_crawler, rss_feed, hunter, apollo, job_board, csv_import, manual
            $table->text('description')->nullable();
            $table->json('config')->nullable(); // Target keywords, endpoints, queries, filters
            $table->boolean('is_active')->default(true)->index();
            $table->string('status', 50)->default('idle'); // idle, running, error, paused, needs_credentials
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_frequency', 50)->default('manual'); // manual, hourly, daily, weekly
            $table->unsignedInteger('total_discovered')->default(0);
            $table->unsignedInteger('total_errors')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
