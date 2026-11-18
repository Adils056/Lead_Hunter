<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // where a meeting came from (calendly) and its id there, so a booking is recorded once
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('status');
            $table->string('external_ref', 191)->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique(['external_ref']);
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
