<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQA bug 29: "Max sends per recipient 2" with "Max follow-ups 2" (= 3 emails) contradicted each other; the send
 * gate already stopped at 2, so follow-ups are lowered to match what really happens (no extra emails go out).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('outreach_settings')) {
            return;
        }
        DB::table('outreach_settings')->whereRaw('max_follow_ups > max_sends_per_recipient - 1')
            ->update(['max_follow_ups' => DB::raw('CASE WHEN max_sends_per_recipient > 0 THEN max_sends_per_recipient - 1 ELSE 0 END')]);
    }

    public function down(): void
    {
    }
};
