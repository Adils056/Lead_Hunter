<?php

namespace App\Services\Outreach;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\IcpProfile;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\IncidentService;
use App\Services\Autopilot\WorkflowControlService;
use App\Services\CampaignDeliveryService;
use App\Services\EligibilityGateService;
use App\Services\Sales\ConversionAssistantService;
use App\Support\ScoreBands;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Autopilot outreach: first touches + threaded follow-ups.
 * Every message passes: workflow pause switch -> autonomy policy -> eligibility gate (incl. suppression,
 * contact identity, dry-run/pilot mode, caps) -> sending window -> duplicate guard -> SMTP.
 */
class SequenceEngine
{
    public function __construct(
        protected AutonomyPolicyService $policy,
        protected WorkflowControlService $workflows,
        protected EligibilityGateService $gate,
        protected ConversionAssistantService $assistant,
        protected ApprovalService $approvals,
        protected CampaignDeliveryService $delivery,
    ) {
    }

    public function campaignFor(?IcpProfile $icp, string $service): ?Campaign
    {
        $owner = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->value('id');
        if (!$owner) {
            return null;
        }

        return Campaign::firstOrCreate(
            ['icp_profile_id' => $icp?->id, 'is_autopilot' => true],
            [
                'name' => 'Autopilot — ' . ($icp?->name ?? 'General'),
                'service_offered' => $service,
                'min_lead_score' => ScoreBands::qualified(),
                'daily_limit' => (int) config('leadpilot.autopilot.max_first_touch_per_day', 25),
                'require_approval' => !$this->policy->isAutomatic('outreach.first_touch'),
                'status' => 'running',
                'timezone' => config('leadpilot.timezone'),
                'contact_rules' => ['require_verified_email' => false],
                'created_by' => $owner,
            ]
        );
    }

    /** @return array{planned:int, pending_approval:int, skipped:array} */
    public function planFirstTouches(int $limit = 10): array
    {
        $out = ['planned' => 0, 'pending_approval' => 0, 'skipped' => []];
        if ($this->workflows->isPaused('outreach') || $this->policy->isProhibited('outreach.first_touch')) {
            $out['skipped'][] = 'outreach workflow paused or prohibited by policy';

            return $out;
        }
        $leads = Lead::with('company')
            ->whereIn('pipeline_state', ['qualified', 'eligible_for_outreach'])
            ->whereIn('contact_status', OutreachSetting::current()->allow_shared_inbox ? ['identified', 'generic_inbox'] : ['identified'])->whereNotNull('email')
            ->whereDoesntHave('campaignRecipients')
            ->where(fn ($q) => $q->whereNull('win_tier')->orWhere('win_tier', 'A')) // only "Email now" (or not yet ranked); the gate below re-checks everything
            ->orderByDesc('win_points')->orderByDesc('overall_score')->limit($limit * 3)->get(); // Win Ladder order: strongest evidence first

        foreach ($leads as $lead) {
            if ($out['planned'] + $out['pending_approval'] >= $limit) {
                break;
            }
            $draft = $this->assistant->draftFirstEmail($lead, $this->policy->isAutomatic('drafting.ai') && !$this->workflows->isPaused('ai_drafting'));
            if (!($draft['ok'] ?? false)) {
                $out['skipped'][] = "lead #{$lead->id}: " . ($draft['reason'] ?? 'no draft');
                continue;
            }
            $campaign = $this->campaignFor($lead->icpProfile, $draft['offer']['service']);
            if (!$campaign) {
                $out['skipped'][] = 'no active admin user to own autopilot campaign';
                break;
            }
            $eligibility = $this->gate->evaluate($lead, $campaign, null, false);
            if (!$eligibility->isEligible) {
                $out['skipped'][] = "lead #{$lead->id}: gate — " . $eligibility->primaryReason;
                continue;
            }
            $lead->update(['outreach_eligible_at' => now()]);

            $auto = $this->policy->isAutomatic('outreach.first_touch');
            $rec = CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
                'sequence_step' => 1,
                'status' => $auto ? 'approved' : 'pending_approval',
                'idempotency_key' => "ap_{$campaign->id}_{$lead->id}_1",
                'personalized_subject' => $draft['draft']['subject'],
                'personalized_body' => $draft['draft']['body'],
                'facts_used' => $draft['facts_used'],
                'scheduled_at' => $this->nextSendTime(null, $lead),
            ]);
            if ($auto) {
                $out['planned']++;
                continue;
            }
            $this->approvals->request('outreach_message', "outreach:{$rec->id}", [
                'title' => 'Approve first email to ' . ($lead->company?->name ?? $lead->email),
                'summary' => "{$lead->full_name} ({$lead->job_title}) at {$lead->company?->name} — score {$lead->overall_score}. Offer: {$draft['offer']['label']}. {$draft['why']}",
                'priority' => ScoreBands::band($lead->overall_score) === 'high' ? 'high' : 'normal',
                'payload' => ['subject' => $draft['draft']['subject'], 'body' => $draft['draft']['body']],
                'evidence' => array_map(fn ($f) => ucfirst($f['text']) . " ({$f['source']}, {$f['observed']})", $draft['facts_used']),
                'attempted' => ['Website audit + scoring', 'Eligibility gate passed (' . count($eligibility->passedChecks ?? []) . ' checks)', 'Draft generated from verified facts only'],
                'options' => ['approve', 'edit_approve', 'reject', 'reassign', 'pause'],
                'recommended' => 'approve',
            ], $rec);
            $out['pending_approval']++;
        }

        return $out;
    }

    /** @return array{created:int, pending_approval:int} */
    public function planFollowUps(int $limit = 25): array
    {
        $out = ['created' => 0, 'pending_approval' => 0];
        if ($this->workflows->isPaused('follow_ups') || $this->policy->isProhibited('outreach.follow_up')) {
            return $out;
        }
        // one source of truth with the Outreach Engine settings: longer delay wins, smaller cap wins
        $settings = \App\Models\OutreachSetting::current();
        $minDelay = (int) ($settings->follow_up_delay_days ?? 0);
        $cadence = array_map(fn ($d) => max((int) $d, $minDelay), (array) config('leadpilot.outreach.follow_up_days', [3, 4, 4, 5]));
        $max = min((int) config('leadpilot.outreach.max_touches', 5), 1 + (int) ($settings->max_follow_ups ?? 4));

        $sent = CampaignRecipient::with('lead.company', 'campaign')
            ->whereHas('campaign', fn ($q) => $q->where('is_autopilot', true))
            ->whereIn('status', ['sent', 'delivered', 'opened'])
            ->whereDoesntHave('children')
            ->orderBy('sent_at')->limit($limit * 2)->get();

        foreach ($sent as $rec) {
            if ($out['created'] + $out['pending_approval'] >= $limit) {
                break;
            }
            $lead = $rec->lead;
            $step = (int) $rec->sequence_step;
            if (!$lead || $step >= $max || !$rec->sent_at) {
                continue;
            }
            if (InboundReply::where('lead_id', $lead->id)->where('received_at', '>=', $rec->sent_at)->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'out_of_office'))->exists()) {
                continue; // any real reply stops the sequence
            }
            if (in_array($lead->pipeline_state, ['suppressed', 'engaged', 'meeting_booked', 'won', 'lost'], true)) {
                continue;
            }
            $due = $rec->sent_at->copy()->addDays($cadence[min($step - 1, count($cadence) - 1)]);
            if ($lead->next_action_date && Carbon::parse($lead->next_action_date)->gt($due)) {
                $due = Carbon::parse($lead->next_action_date)->addDay(); // out-of-office pause
            }
            if ($due->isFuture()) {
                continue;
            }
            $f = $this->assistant->suggestFollowUp($lead);
            if (empty($f['draft']) || !in_array($f['action'], ['send_now', 'scheduled'], true)) {
                continue;
            }
            $auto = $this->policy->isAutomatic('outreach.follow_up');
            $child = CampaignRecipient::create([
                'campaign_id' => $rec->campaign_id, 'lead_id' => $lead->id, 'sequence_step' => $step + 1,
                'parent_recipient_id' => $rec->id, 'status' => $auto ? 'approved' : 'pending_approval',
                'idempotency_key' => "ap_{$rec->campaign_id}_{$lead->id}_" . ($step + 1),
                'personalized_subject' => 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string) $rec->personalized_subject),
                'personalized_body' => $f['draft'],
                'scheduled_at' => $this->nextSendTime(null, $lead),
            ]);
            if ($auto) {
                $out['created']++;
                continue;
            }
            $this->approvals->request('follow_up', "followup:{$child->id}", [
                'title' => "Approve follow-up #" . ($step + 1) . ' to ' . ($lead->company?->name ?? $lead->email),
                'summary' => "No reply {$rec->sent_at->diffForHumans()} after touch {$step}.",
                'payload' => ['subject' => $child->personalized_subject, 'body' => $child->personalized_body],
                'options' => ['approve', 'edit_approve', 'reject'],
            ], $child);
            $out['pending_approval']++;
        }

        return $out;
    }

    /** Send approved, due messages, each inside its prospect's own working hours. */
    public function dispatchDue(int $limit = 20): array
    {
        $out = ['sent' => 0, 'not_sent' => [], 'outside_window' => false];
        $firstToday = CampaignRecipient::where('sequence_step', 1)->whereHas('campaign', fn ($q) => $q->where('is_autopilot', true))
            ->where('sent_at', '>=', now(config('leadpilot.timezone'))->startOfDay()->utc())->count();
        $cap = OutreachSetting::current()->firstTouchCap();

        $due = CampaignRecipient::with('campaign', 'lead')->where('status', 'approved')
            ->whereHas('campaign', fn ($q) => $q->where('is_autopilot', true))
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('sequence_step', 'desc')->orderBy('scheduled_at')->limit($limit * 10)->get();

        $tried = 0;
        $outside = 0;
        foreach ($due as $rec) {
            if (!$this->inSendingWindow(null, $rec->lead)) {
                $outside++; // stays approved; goes out when its own working day starts
                continue;
            }
            if ($tried++ >= $limit) {
                break;
            }
            $workflow = $rec->sequence_step > 1 ? 'follow_ups' : 'outreach';
            if ($this->workflows->isPaused($workflow)) {
                $out['not_sent'][] = "#{$rec->id}: {$workflow} paused";
                continue;
            }
            if ($rec->sequence_step === 1 && $firstToday >= $cap) {
                $out['not_sent'][] = "#{$rec->id}: daily first-touch cap ({$cap}) reached";
                continue;
            }
            $res = $this->dispatch($rec, false);
            if ($res['sent']) {
                $out['sent']++;
                if ($rec->sequence_step === 1) {
                    $firstToday++;
                }
            } else {
                $out['not_sent'][] = "#{$rec->id}: {$res['reason']}";
            }
        }
        $out['outside_window'] = $due->isNotEmpty() && $outside === $due->count();
        $out['waiting_for_local_hours'] = $outside;

        return $out;
    }

    /** @return array{sent: bool, reason: ?string, message_id: ?string} */
    public function dispatch(CampaignRecipient $rec, bool $approvedByHuman): array
    {
        $rec->refresh();
        if ($rec->status !== 'approved') {
            return ['sent' => false, 'reason' => "recipient is {$rec->status}, not approved", 'message_id' => null];
        }
        $workflow = $rec->sequence_step > 1 ? 'follow_ups' : 'outreach';
        $action = $rec->sequence_step > 1 ? 'outreach.follow_up' : 'outreach.first_touch';
        if ($why = OutreachGuard::blockReason($workflow, $action, $rec->lead?->email)) {
            return ['sent' => false, 'reason' => "deferred — {$why}", 'message_id' => null]; // stays approved; resumes when cleared
        }
        // Duplicate guard: never two emails to the same lead within 24h from autopilot
        $recent = CampaignRecipient::where('lead_id', $rec->lead_id)->where('id', '!=', $rec->id)
            ->whereIn('status', ['sent', 'delivered', 'opened', 'replied'])->where('sent_at', '>=', now()->subDay())->exists();
        if ($recent) {
            $rec->update(['status' => 'queued', 'scheduled_at' => now()->addDay(), 'error_message' => 'Deferred: another email went to this lead in the last 24h.']);

            return ['sent' => false, 'reason' => 'deferred (24h spacing)', 'message_id' => null];
        }
        if ($approvedByHuman && !$this->inSendingWindow(null, $rec->lead)) {
            $rec->update(['status' => 'approved', 'scheduled_at' => $this->nextSendTime(null, $rec->lead)]);

            return ['sent' => false, 'reason' => 'approved — scheduled for the prospect\'s next working hours (' . $rec->fresh()->scheduled_at?->timezone(config('leadpilot.timezone'))->format('D H:i') . ' your time)', 'message_id' => null];
        }

        app()->instance('leadpilot.sequence_dispatch', true);
        try {
            $ok = $this->delivery->dispatchRecipient($rec, !$approvedByHuman);
        } finally {
            app()->forgetInstance('leadpilot.sequence_dispatch');
        }
        $rec->refresh();

        // Post-send duplicate detection (defence in depth): same lead, >1 sends within 1 minute
        if ($ok && CampaignRecipient::where('lead_id', $rec->lead_id)->where('sent_at', '>=', now()->subMinute())->count() > 1) {
            app(IncidentService::class)->raise('duplicate_send', 'Duplicate emails to the same prospect', "Lead #{$rec->lead_id} received more than one email within a minute. Outreach paused.", 'outreach', ['lead_id' => $rec->lead_id]);
        }

        return ['sent' => $ok, 'reason' => $ok ? null : ($rec->error_message ?: $rec->status), 'message_id' => $rec->rfc_message_id];
    }

    /** Working hours (config sending_hours, e.g. 9-17) on a working day, on the PROSPECT's clock when a lead is given. */
    public function inSendingWindow(?Carbon $at = null, ?Lead $lead = null): bool
    {
        [$zone, $days] = LocalTime::forLead($lead);
        $t = ($at ?? now())->copy()->timezone($zone);
        [$from, $to] = config('leadpilot.autopilot.sending_hours', [9, 17]);

        return in_array($t->dayOfWeekIso, $days, true) && $t->hour >= $from && $t->hour < $to;
    }

    public function nextSendTime(?Carbon $from = null, ?Lead $lead = null): Carbon
    {
        [$zone] = LocalTime::forLead($lead);
        [$start] = config('leadpilot.autopilot.sending_hours', [9, 17]);
        $t = ($from ?? now())->copy()->timezone($zone);
        for ($i = 0; $i < 14 * 24 && !$this->inSendingWindow($t, $lead); $i++) {
            $t = $t->copy()->addHour()->minute(0);
            if ($t->hour < $start) {
                $t->hour($start);
            }
        }
        // small jitter so messages don't leave in a burst
        return $t->copy()->addMinutes(random_int(0, 40))->utc();
    }
}
