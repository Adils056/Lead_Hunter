<?php

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\WebsiteAudit;
use App\Services\Audit\WebsiteAuditService;
use App\Support\TenderRelevance;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off data repair for the SQA report of 29 Oct 2026:
 *  - audits that recorded a site as "unreachable" because it answered 401/403/429/451 (it refused our server, it was
 *    not down) become "bot_protected" with no opportunity, so no "your website is down?" e-mail is drafted from them;
 *  - procurement signals for works / goods / licence tenders ("Overbridge Civils", "3D SLAM scanner") expire, so
 *    they stop ranking as the best leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        WebsiteAudit::where('status', 'unreachable')->whereIn('http_status', WebsiteAuditService::BLOCKED_STATUSES)->each(function (WebsiteAudit $a) {
            $a->update(['status' => 'bot_protected', 'opportunity_score' => 0, 'opportunities' => []]);
            BuyingSignal::where('company_id', $a->company_id)->where('signal_type', 'website_issues')->where('metadata->audit_id', $a->id)->delete();
            $latest = WebsiteAudit::where('company_id', $a->company_id)->latest('id')->first();
            if ($latest && $latest->id === $a->id) {
                Company::whereKey($a->company_id)->update(['website_opportunity_score' => 0]);
            }
        });

        BuyingSignal::where('signal_type', 'procurement_rfp')->each(function (BuyingSignal $s) {
            $cpv = $s->metadata['cpv'] ?? '';
            $cpv = is_array($cpv) ? (string) ($cpv[0] ?? '') : (string) $cpv;
            if (!TenderRelevance::isSoftwareWork($s->title . ' ' . $s->evidence_text, $cpv)) {
                $s->update(['expiry_date' => now()->subDay()->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        // data repair: nothing to undo
    }
};
