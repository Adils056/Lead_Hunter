<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Audit\WebsiteAuditService;
use App\Services\LeadScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWebsiteAuditJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public Company $company)
    {
    }

    public function uniqueId(): string
    {
        return 'audit-' . $this->company->id;
    }

    public function handle(WebsiteAuditService $audits, LeadScoringService $scoring): void
    {
        $audits->audit($this->company);
        foreach ($this->company->leads as $lead) {
            $scoring->evaluateLead($lead->fresh());
        }
    }
}
