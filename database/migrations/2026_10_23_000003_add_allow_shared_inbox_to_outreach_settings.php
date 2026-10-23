<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local businesses found on OpenStreetMap publish one address for the business (info@, clinic@, a Gmail box);
 * there is no "named decision maker" to find. With this switch on, such a verified, published business
 * address may receive the evidence-based first email. Published tender contacts stay excluded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_settings', function (Blueprint $table) {
            $table->boolean('allow_shared_inbox')->default(false)->after('require_verified_email');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_settings', function (Blueprint $table) {
            $table->dropColumn('allow_shared_inbox');
        });
    }
};
