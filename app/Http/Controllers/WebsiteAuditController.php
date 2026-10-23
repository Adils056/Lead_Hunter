<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebsiteAudit;
use App\Services\Audit\WebsiteAuditService;
use App\Services\LeadScoringService;

class WebsiteAuditController extends Controller
{
    public function store(Company $company)
    {
        $this->authorize('update', $company);
        if (!$company->website && !$company->domain) {
            return back()->with('error', 'This company has no website to audit.');
        }
        \App\Jobs\RunWebsiteAuditJob::dispatch($company);

        $audit = $company->websiteAudits()->latest('id')->first();

        return ($audit ? redirect()->route('audits.show', $audit) : back())
            ->with('success', 'Website audit queued — refresh in a minute (requires the queue worker). Leads are re-scored automatically.');
    }

    public function show(WebsiteAudit $audit)
    {
        $audit->load('company');
        $this->authorize('view', $audit->company);

        return view('audits.show', compact('audit'));
    }
}
