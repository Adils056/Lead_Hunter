<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto Market: ICPs the autopilot creates itself are keyed "country:segment" so re-syncing never duplicates them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('icp_profiles', function (Blueprint $table) {
            $table->string('auto_key', 80)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('icp_profiles', function (Blueprint $table) {
            $table->dropUnique(['auto_key']);
            $table->dropColumn('auto_key');
        });
    }
};
