<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a business started: its company registration (Companies House) and its domain registration (RDAP). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->date('incorporated_on')->nullable()->index();
            $t->date('domain_registered_on')->nullable()->index();
            $t->timestamp('domain_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropIndex(['incorporated_on']);
            $t->dropIndex(['domain_registered_on']);
            $t->dropColumn(['incorporated_on', 'domain_registered_on', 'domain_checked_at']);
        });
    }
};
