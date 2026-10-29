<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Success rate per lead (WinChance): estimated % chance of a reply / interested reply / deal, and its basis. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('win_segment', 30)->nullable()->after('win_points')->index();
            $t->decimal('win_reply_chance', 5, 1)->nullable()->after('win_segment');
            $t->decimal('win_interested_chance', 5, 1)->nullable()->after('win_reply_chance');
            $t->decimal('win_deal_chance', 5, 1)->nullable()->after('win_interested_chance');
            $t->string('win_chance_basis', 120)->nullable()->after('win_deal_chance');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['win_segment']);
            $t->dropColumn(['win_segment', 'win_reply_chance', 'win_interested_chance', 'win_deal_chance', 'win_chance_basis']);
        });
    }
};
