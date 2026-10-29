<?php

use App\Support\Industry;
use App\Support\ServiceCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQA round 4 data:
 *  - bug 23: companies.industry_group = one short, fixed industry list; "Vacant" & co. archived; blank lead industry from its company;
 *  - bug 13: "maintenance_support" in the approved services = the catalog's "maintenance" (it was listed twice);
 *  - bug 17: autopilot campaigns get their ICP's countries instead of showing "All Countries";
 *  - bug 24: companies whose city is plainly abroad get that country (and their leads).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'industry_group')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('industry_group', 60)->nullable()->after('industry')->index();
            });
        }

        DB::table('companies')->whereNotNull('industry')->select('id', 'industry')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows->groupBy(fn ($r) => Industry::group($r->industry)) as $group => $items) {
                DB::table('companies')->whereIn('id', $items->pluck('id'))->update(['industry_group' => $group]);
            }
            $closed = $rows->filter(fn ($r) => Industry::isNotABusiness($r->industry))->pluck('id');
            if ($closed->isNotEmpty()) {
                DB::table('companies')->whereIn('id', $closed)->update(['status' => 'archived']);
            }
        });

        DB::table('leads')->where(fn ($q) => $q->whereNull('industry')->orWhere('industry', ''))->whereNotNull('company_id')
            ->update(['industry' => DB::raw('(select c.industry from companies c where c.id = leads.company_id)')]);

        foreach (DB::table('outreach_settings')->get(['id', 'approved_services']) as $s) {
            $list = json_decode((string) $s->approved_services, true);
            if (is_array($list)) {
                $clean = array_values(array_unique(array_map([ServiceCatalog::class, 'normalize'], $list)));
                if ($clean !== $list) {
                    DB::table('outreach_settings')->where('id', $s->id)->update(['approved_services' => json_encode($clean)]);
                }
            }
        }

        // bug 24: a tender buyer whose city is plainly abroad was stored under the UK portal's country
        DB::table('companies')->where('industry', 'Public Sector')->where('country', 'United Kingdom')->whereNotNull('city')->whereIn(DB::raw('lower(city)'), array_keys(\App\Support\Country::CITIES))->get(['id', 'city', 'country'])
            ->each(function ($c) {
                $real = \App\Support\Country::fromCity($c->city);
                if ($real && $real !== $c->country) {
                    DB::table('companies')->where('id', $c->id)->update(['country' => $real]);
                    DB::table('leads')->where('company_id', $c->id)->where('country', $c->country)->update(['country' => $real]);
                }
            });

        $icps = DB::table('icp_profiles')->pluck('countries', 'id');
        DB::table('campaigns')->where('is_autopilot', true)->whereNotNull('icp_profile_id')->get(['id', 'icp_profile_id', 'target_countries'])
            ->each(function ($c) use ($icps) {
                $current = json_decode((string) $c->target_countries, true);
                $icpCountries = json_decode((string) ($icps[$c->icp_profile_id] ?? ''), true);
                if (empty($current) && !empty($icpCountries)) {
                    DB::table('campaigns')->where('id', $c->id)->update(['target_countries' => json_encode(array_values($icpCountries))]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'industry_group')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropIndex(['industry_group']);
                $t->dropColumn('industry_group');
            });
        }
    }
};
