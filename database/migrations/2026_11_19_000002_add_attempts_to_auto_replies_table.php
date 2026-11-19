<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // an answer the gateway or mail server refused is tried again (seen live: two WhatsApp answers refused at once)
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
