<?php

use App\Models\Company;
use App\Models\IcpProfile;
use App\Services\Discovery\Adapters\HnWhoIsHiringAdapter;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off data repair after the first Hacker News runs:
 *  - companies whose "website" was the first link in the post (a job board, a video, a news article) get their
 *    business e-mail domain instead, or no website, never someone else's;
 *  - the global hiring profile's role words move from niches (compared with the company's industry) to keywords.
 */
return new class extends Migration
{
    public function up(): void
    {
        $free = (array) config('leadpilot.free_mail_domains', []);
        Company::where('external_id', 'like', 'hn:%')->whereNotNull('website')->each(function (Company $c) use ($free) {
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url($c->website, PHP_URL_HOST)));
            if ($host !== '' && !preg_match(HnWhoIsHiringAdapter::NOT_A_COMPANY_SITE, $host) && HnWhoIsHiringAdapter::siteMatchesName($host, $c->name)) {
                return;
            }
            $mail = $c->public_email ?: $c->leads()->whereNotNull('email')->value('email');
            $mailHost = $mail ? strtolower(substr(strrchr($mail, '@'), 1)) : null;
            $own = $mailHost && !in_array($mailHost, $free, true) && !preg_match(HnWhoIsHiringAdapter::NOT_A_COMPANY_SITE, $mailHost) ? $mailHost : null;
            $c->update(['website' => $own ? "https://{$own}" : null, 'domain' => $own, 'website_audited_at' => null, 'website_opportunity_score' => null]);
        });

        if ($icp = IcpProfile::where('auto_key', 'global:hiring')->first()) {
            $icp->update(['keywords' => array_values(array_unique(array_merge((array) $icp->keywords, (array) $icp->niches))), 'niches' => []]);
        }
    }

    public function down(): void
    {
        // data repair: nothing to undo
    }
};
