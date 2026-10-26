<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Public job board found on the company's own website ("greenhouse:acme", "lever:acme", "ashby:acme", "workable:acme"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('ats_board', 120)->nullable()->index();
            $table->timestamp('ats_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['ats_board']);
            $table->dropColumn(['ats_board', 'ats_checked_at']);
        });
    }
};
