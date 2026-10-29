<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every email ends with one clear next step the owner chooses: book a meeting, call, consultation, a landing page, or reply. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_settings', function (Blueprint $t) {
            $t->string('cta_type', 20)->default('reply');
            $t->string('booking_url')->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('landing_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_settings', function (Blueprint $t) {
            $t->dropColumn(['cta_type', 'booking_url', 'contact_phone', 'landing_url']);
        });
    }
};
