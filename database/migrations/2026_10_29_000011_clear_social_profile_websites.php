<?php

use App\Services\Enrichment\WebsiteFinder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A linktr.ee / Instagram / Facebook page stored as a company's "website" is not its site: audits of it claimed gaps
 * the business does not have, and the English-name step named a salon "Linktree" (29 Oct). Clear the website, its
 * audits and their signals, and give back the local name taken from such a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = [];
        DB::table('companies')->whereNotNull('website')->select('id', 'website', 'domain', 'local_name')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$cleared) {
                foreach ($rows as $c) {
                    if (WebsiteFinder::isOwnSite($c->website)) {
                        continue;
                    }
                    $host = preg_replace('/^(www|m|web)\./', '', strtolower((string) parse_url($c->website, PHP_URL_HOST)));
                    $update = ['website' => null, 'website_opportunity_score' => null, 'website_audited_at' => null];
                    if ($c->domain && (strtolower($c->domain) === $host || str_ends_with($host, '.' . strtolower($c->domain)) || !WebsiteFinder::isOwnSite('https://' . $c->domain))) {
                        $update['domain'] = null;
                    }
                    if ($c->local_name) {
                        $update['name'] = $c->local_name;
                        $update['local_name'] = null;
                    }
                    DB::table('companies')->where('id', $c->id)->update($update);
                    $ids = DB::table('website_audits')->where('company_id', $c->id)->pluck('id');
                    DB::table('buying_signals')->where('company_id', $c->id)->where('signal_type', 'website_issues')->delete();
                    DB::table('website_audits')->whereIn('id', $ids)->delete();
                    $cleared[] = $c->id;
                }
            });
        // the audit gaps were part of their score
        foreach (\App\Models\Lead::whereIn('company_id', $cleared)->get() as $lead) {
            app(\App\Services\LeadScoringService::class)->evaluateLead($lead);
        }
    }

    public function down(): void
    {
        // data repair: nothing to undo
    }
};
