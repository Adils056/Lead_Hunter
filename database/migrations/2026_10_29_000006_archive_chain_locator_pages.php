<?php

use App\Models\Company;
use App\Models\Lead;
use App\Support\ChainBrand;
use Illuminate\Database\Migrations\Migration;

/**
 * Second pass (live re-audit, Riyadh): chain restaurants whose website or audited page is the brand's store-locator
 * page (P.F. Chang's, Raising Cane's, Shake Shack) and more chain names. Same cleanup as the first pass.
 * Leads that already replied or have a deal are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $outcomes = app(\App\Services\Sales\ReplyOutcomeService::class);
        Company::where('status', '<>', 'archived')->with('latestWebsiteAudit')->select('id', 'name', 'domain', 'website', 'status')->orderBy('id')->chunkById(500, function ($companies) use ($outcomes) {
            foreach ($companies as $c) {
                if (!($why = ChainBrand::reason($c->name, $c->domain, $c->website) ?? ChainBrand::reason(null, null, $c->latestWebsiteAudit?->final_url))) {
                    continue;
                }
                $c->update(['status' => 'archived']);
                Lead::where('company_id', $c->id)->whereIn('lifecycle_stage', ['new', 'researching', 'qualified', 'ready_for_review', 'contacted', 'nurture'])
                    ->get()->each(function (Lead $lead) use ($outcomes, $why) {
                        $outcomes->stopSequences($lead, $why);
                        $lead->update(['lifecycle_stage' => 'not_a_fit']);
                    });
            }
        });
    }

    public function down(): void
    {
    }
};
