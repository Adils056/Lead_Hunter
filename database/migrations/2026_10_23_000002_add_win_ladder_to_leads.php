<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Win Ladder: each lead's rung (R/A/B/S/C/D), its rank inside the rung and the human-readable reasons,
 * so "who do we email first?" is one indexed query. Computed by App\Services\Outreach\WinLadderService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('win_tier', 2)->nullable()->after('outreach_eligible_at')->index();
            $table->unsignedInteger('win_points')->default(0)->after('win_tier')->index();
            $table->json('win_reasons')->nullable()->after('win_points');
            $table->json('win_blockers')->nullable()->after('win_reasons');
            $table->string('win_next_action', 255)->nullable()->after('win_blockers');
            $table->timestamp('win_computed_at')->nullable()->after('win_next_action');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['win_tier', 'win_points', 'win_reasons', 'win_blockers', 'win_next_action', 'win_computed_at']);
        });
    }
};
