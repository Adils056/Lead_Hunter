<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Auto Market "Companies hiring developers" profile gets the new free job feeds once (sources are only added). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('icp_profiles')->where('auto_key', 'global:hiring')->get(['id', 'sources']) as $icp) {
            $sources = (array) json_decode((string) $icp->sources, true);
            $merged = array_values(array_unique(array_merge($sources, ['remoteok', 'himalayas', 'arbeitnow'])));
            if ($merged !== $sources) {
                DB::table('icp_profiles')->where('id', $icp->id)->update(['sources' => json_encode($merged)]);
            }
        }
    }

    public function down(): void
    {
    }
};
