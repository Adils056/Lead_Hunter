<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RescoreAllLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function handle(LeadScoringService $scoring): void
    {
        Lead::query()->orderBy('id')->chunkById(200, function ($chunk) use ($scoring) {
            foreach ($chunk as $lead) {
                $scoring->evaluateLead($lead);
            }
        });
    }
}
