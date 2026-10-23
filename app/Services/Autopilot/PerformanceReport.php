<?php

namespace App\Services\Autopilot;

use App\Models\CampaignRecipient;
use App\Models\Deal;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\UsageLog;
use App\Support\ScoreBands;
use Illuminate\Support\Facades\DB;

/**
 * Funnel + cost metrics by source / ICP / industry / offer / sequence step, from real records only.
 * Costs are split into ACTUAL (is_estimate = false) and ESTIMATED.
 */
class PerformanceReport
{
    public const POSITIVE = ['interested', 'request_info', 'meeting_request', 'positive'];

    public function build(int $days = 90): array
    {
        $from = now()->subDays($days);
        $leads = Lead::with('company')->where('created_at', '>=', $from)->get();
        $replies = InboundReply::where('received_at', '>=', $from)->get();
        $positiveLeadIds = $replies->filter(fn ($r) => in_array($r->category ?? $r->classification, self::POSITIVE, true))->pluck('lead_id')->unique();
        $sentLeadIds = CampaignRecipient::whereNotNull('sent_at')->where('sent_at', '>=', $from)->pluck('lead_id')->unique();
        $meetingLeadIds = DB::table('meetings')->where('created_at', '>=', $from)->pluck('lead_id')->unique();
        $wonLeadIds = Deal::whereNotNull('closed_at')->where('stage', 'won')->where('closed_at', '>=', $from)->pluck('lead_id')->filter()->unique();

        $groups = [
            'source' => fn ($l) => $l->company?->source_provider ?? $l->source_name ?? 'unknown',
            'icp' => fn ($l) => optional($l->icpProfile)->name ?? '—',
            'industry' => fn ($l) => $l->industry ?: ($l->company?->industry ?: 'unknown'),
            'offer' => fn ($l) => $l->score_breakdown['recommended_offers'][0]['label'] ?? '—',
        ];
        $q = ScoreBands::qualified();
        $out = ['days' => $days, 'by' => []];
        foreach ($groups as $name => $fn) {
            $rows = [];
            foreach ($leads->groupBy($fn) as $key => $g) {
                $ids = $g->pluck('id');
                $rows[] = [
                    'key' => (string) $key,
                    'leads' => $g->count(),
                    'qualified' => $g->where('overall_score', '>=', $q)->count(),
                    'verified_contact' => $g->where('contact_status', 'identified')->where('email_verification_status', 'verified')->count(),
                    'contacted' => $ids->intersect($sentLeadIds)->count(),
                    'positive_replies' => $ids->intersect($positiveLeadIds)->count(),
                    'meetings' => $ids->intersect($meetingLeadIds)->count(),
                    'won' => $ids->intersect($wonLeadIds)->count(),
                ];
            }
            usort($rows, fn ($a, $b) => [$b['positive_replies'], $b['qualified']] <=> [$a['positive_replies'], $a['qualified']]);
            $out['by'][$name] = $rows;
        }

        $steps = CampaignRecipient::whereNotNull('sent_at')->where('sent_at', '>=', $from)->get(['id', 'lead_id', 'sequence_step', 'sent_at']);
        $out['by_step'] = $steps->groupBy('sequence_step')->map(function ($g, $step) use ($replies) {
            $replied = $g->filter(fn ($r) => $replies->where('lead_id', $r->lead_id)->where('received_at', '>=', $r->sent_at)->isNotEmpty())->count();

            return ['step' => (int) $step, 'sent' => $g->count(), 'replied_after' => $replied];
        })->sortKeys()->values()->all();

        $usage = UsageLog::where('occurred_at', '>=', $from)->get();
        $actual = (int) $usage->where('is_estimate', false)->sum('cost_cents');
        $est = (int) $usage->where('is_estimate', true)->sum('cost_cents');
        $total = $actual + $est;
        $n = fn ($x) => $x > 0 ? round($total / 100 / $x, 2) : null;
        $out['costs'] = [
            'actual_usd' => round($actual / 100, 2),
            'estimated_usd' => round($est / 100, 2),
            'by_provider' => $usage->groupBy('provider')->map(fn ($g) => ['calls' => $g->count(), 'usd' => round($g->sum('cost_cents') / 100, 2), 'estimated' => $g->where('is_estimate', true)->count() > 0])->all(),
            'per_unique_lead' => $n($leads->count()),
            'per_qualified_lead' => $n($leads->where('overall_score', '>=', $q)->count()),
            'per_verified_contact' => $n($leads->where('email_verification_status', 'verified')->count()),
            'per_positive_reply' => $n($positiveLeadIds->count()),
            'per_meeting' => $n($meetingLeadIds->count()),
            'per_sql' => $n(Deal::where('created_at', '>=', $from)->count()),
            'per_won_client' => $n($wonLeadIds->count()),
        ];
        $out['totals'] = [
            'leads' => $leads->count(), 'qualified' => $leads->where('overall_score', '>=', $q)->count(),
            'contacted' => $sentLeadIds->count(), 'positive_replies' => $positiveLeadIds->count(),
            'meetings' => $meetingLeadIds->count(), 'won' => $wonLeadIds->count(),
        ];

        return $out;
    }
}
