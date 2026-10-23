<?php

namespace App\Services\Autopilot;

use App\Models\Lead;
use App\Support\ScoreBands;

/**
 * Pipeline state = where the lead is in the acquisition journey, derived from facts.
 * Deliberately separate from the CRM lifecycle_stage and from the score:
 * a high score never implies "eligible for outreach" — only the eligibility gate sets outreach_eligible_at.
 */
class LeadStateService
{
    public const STATES = [
        'discovered' => 'Discovered',
        'enrichment_required' => 'Enrichment required',
        'qualified' => 'Qualified',
        'eligible_for_outreach' => 'Eligible for outreach',
        'contacted' => 'Contacted',
        'engaged' => 'Engaged (replied)',
        'meeting_booked' => 'Meeting booked',
        'sales_qualified' => 'Sales-qualified opportunity',
        'proposal_sent' => 'Proposal sent',
        'won' => 'Won',
        'lost' => 'Lost',
        'suppressed' => 'Suppressed / disqualified',
    ];

    public function compute(Lead $lead): string
    {
        $stage = (string) $lead->lifecycle_stage;
        $score = (int) $lead->overall_score;

        if (in_array($stage, ['not_a_fit', 'opted_out', 'disqualified'], true)
            || ($lead->email && \App\Models\Suppression::isSuppressed($lead->email, $lead->company?->domain))) {
            return 'suppressed';
        }
        if ($stage === 'won') {
            return 'won';
        }
        if ($stage === 'lost') {
            return 'lost';
        }
        if (in_array($stage, ['proposal_sent', 'negotiation'], true)) {
            return 'proposal_sent';
        }
        if ($lead->exists && $lead->deals()->whereNotIn('stage', ['lost', 'closed_lost'])->exists()) {
            return 'sales_qualified';
        }
        if ($stage === 'meeting_booked' || ($lead->exists && $lead->meetings()->where('status', 'scheduled')->exists())) {
            return 'meeting_booked';
        }
        if (in_array($stage, ['replied', 'interested'], true)) {
            return 'engaged';
        }
        if ($stage === 'contacted' || $lead->last_contacted_at) {
            return 'contacted';
        }
        // A usable contact = a named person, or (when the owner allows it) the business's own published inbox.
        // Published tender contacts never count: they are answered through the portal, not cold-emailed.
        $status = $lead->contact_status ?? 'identified';
        $isTenderContact = $lead->job_title === 'Tender contact (published)'
            || in_array($lead->company?->source_provider, ['uk_contracts_finder', 'uk_find_a_tender', 'eu_ted'], true);
        $hasContact = $lead->email && ($status === 'identified'
            || ($status === 'generic_inbox' && !$isTenderContact && \App\Models\OutreachSetting::current()->allow_shared_inbox));
        if ($score >= ScoreBands::qualified()) {
            if (!$hasContact) {
                return 'enrichment_required';
            }

            return $lead->outreach_eligible_at ? 'eligible_for_outreach' : 'qualified';
        }
        if ($score >= ScoreBands::nurture() && !$hasContact) {
            return 'enrichment_required';
        }

        return 'discovered';
    }

    public const RELEVANT = ['lifecycle_stage', 'overall_score', 'contact_status', 'email', 'last_contacted_at', 'outreach_eligible_at', 'email_verification_status'];

    public function refresh(Lead $lead): void
    {
        $state = $this->compute($lead);
        if ($state !== $lead->pipeline_state) {
            $lead->forceFill(['pipeline_state' => $state, 'pipeline_state_at' => now()])->saveQuietly();
        }
    }

    public function sync(Lead $lead): void
    {
        if ($lead->exists && !$lead->isDirty(self::RELEVANT)) {
            return; // avoid 3-4 queries on unrelated saves
        }
        $state = $this->compute($lead);
        if ($state !== $lead->pipeline_state) {
            $lead->pipeline_state = $state;
            $lead->pipeline_state_at = now();
        }
    }
}
