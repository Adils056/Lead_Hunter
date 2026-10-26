<?php

namespace App\Services\Outreach;

use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Services\EligibilityGateService;
use App\Services\LeadScoringService;
use App\Services\Sales\OfferRecommender;
use App\Support\ScoreBands;
use App\Support\EmailStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Win Ladder — the one ordered answer to "who do we email first?".
 *
 * Every lead sits on exactly one rung, decided top-down (the first rule that applies wins):
 *   R  Replied            a human answers today; never a cold email
 *   D  Do not email       suppressed, declined, invalid address, country not allowed, ICP exclusion, closed
 *   S  In sequence        a draft is waiting for approval / scheduled, or the next follow-up is not due yet
 *   A  Email now          named contact, deliverable address, real evidence, passes every eligibility check
 *   B  One fix away       becomes A after ONE concrete action (verify the email, audit the website, switch the
 *                         outreach mode, approve the service, wait for tomorrow's quota ...)
 *   C  Nurture / enrich   no usable contact or no evidence yet
 *
 * Inside a rung, leads are ranked by buying-evidence points: an explicit request (RFP / enquiry) beats an
 * observed website gap, which beats a generic fit. The points are evidence-weighted heuristics, NOT a
 * probability of winning — nobody can promise a reply — but every rung says exactly what to do next, and the
 * autopilot sends to the top of rung A first.
 */
class WinLadderService
{
    public const TIERS = [
        'R' => ['label' => 'Replied — answer today', 'short' => 'Replied', 'icon' => 'bi-reply-all-fill', 'class' => 'danger',
            'help' => 'The prospect wrote back. A person replies within 24 hours; no automated email.'],
        'A' => ['label' => 'Email now', 'short' => 'Email now', 'icon' => 'bi-send-fill', 'class' => 'success',
            'help' => 'Named contact, deliverable address, real evidence, and every eligibility check passes. Top of this list = best next email.'],
        'B' => ['label' => 'One fix away', 'short' => 'One fix', 'icon' => 'bi-wrench-adjustable', 'class' => 'warning',
            'help' => 'Would be "Email now" after one concrete action. The blocker column says which.'],
        'S' => ['label' => 'In sequence', 'short' => 'In sequence', 'icon' => 'bi-hourglass-split', 'class' => 'info',
            'help' => 'Already being worked: a draft awaits approval, is scheduled, or the next follow-up is not due yet.'],
        'C' => ['label' => 'Nurture / enrich', 'short' => 'Nurture', 'icon' => 'bi-moisture', 'class' => 'secondary',
            'help' => 'No usable contact or no evidence yet. Enrich (find the decision maker + a direct email) or audit before emailing.'],
        'D' => ['label' => 'Do not email', 'short' => 'Do not email', 'icon' => 'bi-slash-circle', 'class' => 'dark',
            'help' => 'Suppressed, declined, invalid address, outside approved countries, ICP exclusion or closed. Emailing these loses trust and deliverability.'],
    ];

    public const ORDER = ['R', 'A', 'B', 'S', 'C', 'D'];

    /** Gate failures that one action fixes (rung B); anything else on the gate is a hard stop or a nurture case. */
    protected const FIXABLE = [
        'email_verification' => 'Verify the email address (Lead → Verify email)',
        'score_threshold' => 'Score below the outreach minimum (Settings → Outreach Engine → minimum lead score): add evidence (website audit / buying signal) and re-score, or lower the minimum',
        'concrete_evidence' => 'Run the website audit or record a buying signal — no evidence, no email',
        'dry_run_mode' => 'Outreach is in dry-run mode — switch to live in Settings → Outreach Engine',
        'public_url' => 'Set APP_URL to a public address (domain or tunnel) so unsubscribe links open for prospects',
        'controlled_pilot_restriction' => 'Outreach is in controlled-pilot mode — only the pilot address can receive mail',
        'daily_frequency_limit' => 'Daily send quota reached — eligible again tomorrow',
        'sender_authorization' => 'Set the sender identity in Settings → Outreach Engine',
        'emergency_stop' => 'Emergency stop is active — clear it in Settings → Outreach Engine',
        'follow_up_delay' => 'Follow-up spacing not met yet',
        'legitimate_source' => 'Record where this contact came from (source) before emailing',
    ];

    public function __construct(
        protected EligibilityGateService $gate,
        protected OfferRecommender $offers,
    ) {
    }

    // ------------------------------------------------------------------ evaluate one lead

    /** @return array{tier:string,points:int,reasons:array,blockers:array,next_action:string} */
    public function evaluate(Lead $lead): array
    {
        $lead->loadMissing(['company.latestWebsiteAudit', 'icpProfile']);
        $company = $lead->company;
        $settings = OutreachSetting::current();
        $icp = $lead->icpProfile ?? IcpProfile::where('is_active', true)->first();
        $signals = $company ? $company->buyingSignals()->active()->get() : collect();
        $stage = (string) $lead->lifecycle_stage;
        $email = strtolower(trim((string) $lead->email));
        $points = 0;
        $reasons = [];
        $add = function (int $p, string $why) use (&$points, &$reasons) {
            $points += max(0, $p);
            $reasons[] = $why;
        };

        // ---------- 1. Buying evidence → points (ranking inside every rung). Strongest evidence first.
        $rfp = $signals->whereIn('signal_type', ['procurement_rfp', 'project_requirements', 'vendor_request', 'erp_crm_request', 'voluntary_inquiry'])->sortByDesc('confidence')->first();
        if ($rfp) {
            $add((int) round(400 * $rfp->confidence / 100), "Asked for this work: \"{$rfp->title}\" ({$rfp->confidence}% confidence)");
        }
        $need = $signals->whereIn('signal_type', ['developer_hiring', 'tech_stack_change'])->sortByDesc('confidence')->first();
        if ($need) {
            $add((int) round(150 * $need->confidence / 100), "Active software need: \"{$need->title}\"");
        }
        $growth = $signals->whereIn('signal_type', ['business_expansion', 'business_launch', 'funding_announcement', 'digital_transformation'])->sortByDesc('confidence')->first();
        if ($growth) {
            $add((int) round(80 * $growth->confidence / 100), "Growing: \"{$growth->title}\"");
        }
        $noSite = $signals->first(fn ($s) => ($s->metadata['evidence_key'] ?? null) === 'no_website');
        if ($company && $company->website_audited_at && $company->website_opportunity_score !== null) {
            $s = (int) $company->website_opportunity_score;
            $top = $company->latestWebsiteAudit?->opportunities[0]['evidence'][0] ?? null;
            $add(2 * $s, "Website audit: {$s}/100 opportunity" . ($top ? " — {$top}" : ''));
        } elseif ($noSite) {
            $add(120, 'No website listed — full build opportunity');
        }

        // Who we would be talking to
        $title = (string) $lead->job_title;
        $preferred = $icp ? $icp->list('contact_roles') : [];
        $prefHit = $title !== '' && collect($preferred)->contains(fn ($r) => stripos($title, $r) !== false);
        if ($prefHit || ($title !== '' && preg_match(LeadScoringService::DECISION_MAKER, $title))) {
            $add(150, "Decision maker: {$title}");
        } elseif ($title !== '' && preg_match(LeadScoringService::INFLUENCER, $title)) {
            $add(80, "Influencer: {$title}");
        } elseif ($title !== '') {
            $add(30, "Known role: {$title}");
        }

        // Can we reach them
        $emailStatus = $lead->email_verification_status;
        if ($emailStatus === EmailStatus::VERIFIED) {
            $add(100, 'Email verified deliverable');
        } elseif (in_array($emailStatus, [EmailStatus::RISKY, EmailStatus::CATCH_ALL], true)) {
            $add(40, 'Email risky / catch-all');
        } elseif ($emailStatus === EmailStatus::MX_ONLY && EmailStatus::isSendable($emailStatus, $settings)) {
            $add(20, 'Email domain accepts mail (mailbox not probed)');
        }
        if ($lead->phone ?: $company?->public_phone) {
            $add(10, 'Phone number known');
        }

        // What we would offer, and may we offer it
        $offers = $this->offers->recommend($lead, 3);
        $offer = $offers[0] ?? null;
        $approved = (array) $settings->approved_services;
        $offerApproved = $offer && in_array($offer['service'], $approved, true);
        if ($offerApproved) {
            $add(80, "Approved offer fits: {$offer['label']}");
        }

        // Score band + freshness + business activity
        $score = (int) $lead->overall_score;
        $add($score, "Lead score {$score}/100 (" . ScoreBands::band($score) . ')');
        $newest = $signals->sortByDesc('observed_date')->first();
        $evidenceDate = $newest ? Carbon::parse($newest->observed_date) : ($company?->website_audited_at ?? $lead->created_at ?? now());
        $age = (int) $evidenceDate->diffInDays(now());
        if ($age <= 14) {
            $add(60, "Fresh evidence ({$age} day(s) old)");
        } elseif ($age <= 30) {
            $add(40, "Recent evidence ({$age} days old)");
        } elseif ($age <= 60) {
            $add(20, "Evidence {$age} days old");
        }
        if ($company && (int) $company->google_review_count >= 20) {
            $add(30, "Active business: {$company->google_review_count} Google reviews" . ($company->google_rating ? " ({$company->google_rating}★)" : ''));
        }

        // ---------- 2. Rung, top-down
        $lastReply = $lead->exists ? $lead->inboundReplies()->latest('received_at')->first() : null;
        $declined = $lastReply && in_array($lastReply->category ?? $lastReply->classification, ['not_interested', 'unsubscribe', 'negative'], true);
        $engaged = in_array($stage, ['replied', 'interested', 'meeting_booked', 'proposal_sent', 'negotiation'], true)
            || ($lastReply && !$declined && !in_array($lastReply->category, ['out_of_office'], true));

        if ($engaged && !in_array($stage, ['won', 'lost', 'not_a_fit', 'opted_out', 'disqualified'], true)) {
            $what = $lastReply ? 'Last reply: ' . ucfirst(str_replace('_', ' ', (string) ($lastReply->category ?: $lastReply->classification ?: 'uncertain'))) : 'Stage: ' . str_replace('_', ' ', $stage);
            return $this->rung('R', 1000 + $points, $reasons, [$what], 'A person replies within 24 hours (open the Sales Assistant) — no automated email.');
        }

        if ($stage === 'won') {
            return $this->rung('D', $points, $reasons, ['Existing customer'], 'Account management, not cold outreach.');
        }
        if (in_array($stage, ['lost', 'not_a_fit', 'opted_out', 'disqualified'], true)) {
            return $this->rung('D', $points, $reasons, ['Closed as ' . str_replace('_', ' ', $stage)], 'Do not email. Re-open only on a new, dated trigger.');
        }
        if ($email !== '' && Suppression::isSuppressed($email, $company?->domain)) {
            return $this->rung('D', $points, $reasons, ['On the do-not-contact / unsubscribe list'], 'Never email. Legal and deliverability risk.');
        }
        if ($declined) {
            return $this->rung('D', $points, $reasons, ['Prospect declined'], 'Do not email for at least 6 months; then only with a new trigger.');
        }
        if (\App\Support\PublicSector::matches($email, $company?->website, $company?->domain)) {
            return $this->rung('D', $points, $reasons, ['Public-sector body (government, state school, university or health service)'], 'Buys through procurement: answer its tenders, do not cold-email.');
        }
        if ($lead->email_verification_status === EmailStatus::INVALID) {
            return $this->rung('D', $points, $reasons, ['Email address is invalid / non-deliverable'], 'Find another address (website, LinkedIn, phone call) before any contact.');
        }
        $allowed = $settings->allowed_countries ?: ['Pakistan', 'United States', 'United Kingdom', 'Canada', 'Australia', 'Germany'];
        if (!empty($lead->country) && !in_array($lead->country, $allowed, true)) {
            return $this->rung('D', $points, $reasons, ["Country {$lead->country} is not approved for outreach"], 'Add the country in Settings → Outreach Engine only if you may lawfully email there.');
        }
        if ($icp && $company && ($why = $icp->exclusionReason($company->domain, $company->name))) {
            return $this->rung('D', $points, $reasons, ["ICP exclusion: {$why}"], 'Excluded by your own ICP rules.');
        }

        // In sequence
        if ($lead->exists && $lead->campaignRecipients()->whereIn('status', ['pending_approval', 'approved', 'scheduled', 'queued'])->exists()) {
            return $this->rung('S', $points, $reasons, ['Draft waiting for approval or scheduled'], 'Check Approvals; nothing else to do for this lead.');
        }
        if ($lead->last_contacted_at) {
            $days = (int) Carbon::parse($lead->last_contacted_at)->diffInDays(now());
            $delay = (int) ($settings->follow_up_delay_days ?? 3);
            $sent = $lead->exists ? $lead->campaignRecipients()->where('status', 'sent')->count() : 0;
            $maxTouches = min((int) config('leadpilot.outreach.max_touches', 5), 1 + (int) ($settings->max_follow_ups ?? 4));
            if ($sent >= $maxTouches) {
                return $this->rung('C', $points, $reasons, ["Sequence finished: {$sent} emails, no reply"], 'Stop emailing. Re-engage only when a new trigger appears (new audit finding, hiring, tender).');
            }
            if ($days < $delay) {
                return $this->rung('S', $points, $reasons, ["Contacted {$days} day(s) ago"], 'Follow-up due in ' . ($delay - $days) . ' day(s); the sequence engine sends it.');
            }
            $reasons[] = 'Follow-up #' . ($sent + 1) . ' is due';
        }

        // No usable contact
        if ($email === '') {
            return $this->rung('C', $points, $reasons, ['No email address'], 'Enrich: find the owner / practice manager and a direct email (website, LinkedIn, phone call).');
        }
        if (($lead->contact_status ?? 'identified') === 'needs_enrichment') {
            return $this->rung('C', $points, $reasons, ['No real contact known yet'], 'Enrich: identify a named decision maker before any email.');
        }

        // Evidence present at all? (no evidence = no honest first line = no email)
        $hasEvidence = $rfp || $need || $growth || $noSite || ($company && $company->website_audited_at) || $signals->isNotEmpty();

        // Full eligibility gate (same 10 checks the sender uses — one source of truth)
        $gate = $this->gate->evaluate($lead, null, null, false);
        $failed = $gate->failedChecks;
        $blockers = [];
        $hard = false;
        foreach ($failed as $key => $reason) {
            if ($key === 'contact_identity') {
                $blockers[] = $lead->contact_status === 'generic_inbox'
                    ? 'Only a shared inbox (info@ / contact@) is known — send manually after review, or find the owner'
                    : $reason;
                continue;
            }
            if (isset(self::FIXABLE[$key])) {
                $blockers[] = self::FIXABLE[$key];
                continue;
            }
            $hard = true;
            $blockers[] = $reason;
        }
        if (!$hasEvidence) {
            $blockers[] = 'No observation to personalise with — run the website audit or record a buying signal';
        }
        if ($offer && !$offerApproved) {
            $blockers[] = "Best-fit service \"{$offer['label']}\" is not in Approved outreach services (Settings → Outreach Engine)";
        }
        if (!$offer) {
            $blockers[] = 'No evidence-backed offer yet (audit the website or add a signal)';
        }

        if ($blockers === []) {
            return $this->rung('A', $points, $reasons, [], 'Email now: approve the autopilot draft, or open the Sales Assistant and send the evidence-based first email.');
        }
        if ($hard || $score < ScoreBands::nurture() || count($blockers) > 2) {
            return $this->rung('C', $points, $reasons, $blockers, 'Nurture: ' . $blockers[0]);
        }
        if ($lead->contact_status === 'generic_inbox' && count($blockers) === 1) {
            return $this->rung('B', $points, $reasons, $blockers, 'Send manually after review (shared inbox), or find the owner to make it automatic.');
        }

        return $this->rung('B', $points, $reasons, $blockers, 'One fix: ' . $blockers[0]);
    }

    protected function rung(string $tier, int $points, array $reasons, array $blockers, string $next): array
    {
        return [
            'tier' => $tier,
            'points' => max(0, $points),
            'reasons' => array_values(array_slice($reasons, 0, 8)),
            'blockers' => array_values(array_slice($blockers, 0, 5)),
            'next_action' => $next,
        ];
    }

    // ------------------------------------------------------------------ persistence

    /** Evaluate and store on the lead (no model events: scoring/state sync already ran). */
    public function refresh(Lead $lead): array
    {
        $r = $this->evaluate($lead);
        DB::table('leads')->where('id', $lead->id)->update([
            'win_tier' => $r['tier'],
            'win_points' => $r['points'],
            'win_reasons' => json_encode($r['reasons'], JSON_UNESCAPED_UNICODE),
            'win_blockers' => json_encode($r['blockers'], JSON_UNESCAPED_UNICODE),
            'win_next_action' => mb_substr($r['next_action'], 0, 255),
            'win_computed_at' => now(),
        ]);
        $lead->forceFill(['win_tier' => $r['tier'], 'win_points' => $r['points'], 'win_reasons' => $r['reasons'],
            'win_blockers' => $r['blockers'], 'win_next_action' => $r['next_action'], 'win_computed_at' => now()])->syncOriginal();

        return $r;
    }

    /** Recompute every lead (or only stale ones). Returns counts per rung. */
    public function refreshAll(bool $onlyStale = false, ?callable $progress = null): array
    {
        $q = Lead::query()->with(['company.latestWebsiteAudit', 'icpProfile']);
        if ($onlyStale) {
            $q->where(fn ($w) => $w->whereNull('win_computed_at')->orWhereColumn('win_computed_at', '<', 'updated_at'));
        }
        $counts = array_fill_keys(self::ORDER, 0);
        $q->orderBy('id')->chunkById(100, function ($leads) use (&$counts, $progress) {
            foreach ($leads as $lead) {
                $r = $this->refresh($lead);
                $counts[$r['tier']]++;
                if ($progress) {
                    $progress($lead, $r);
                }
            }
        });

        return $counts;
    }

    public function counts(): array
    {
        $db = Lead::query()->whereNotNull('win_tier')->selectRaw('win_tier, count(*) as c')->groupBy('win_tier')->pluck('c', 'win_tier')->all();
        $out = [];
        foreach (self::ORDER as $t) {
            $out[$t] = (int) ($db[$t] ?? 0);
        }

        return $out;
    }

    /** Ranked leads for one rung (best first). */
    public function ranked(string $tier, int $limit = 50)
    {
        return Lead::with(['company', 'icpProfile'])->where('win_tier', $tier)
            ->orderByDesc('win_points')->orderByDesc('overall_score')->orderBy('id')->limit($limit)->get();
    }

    public static function tierLabel(?string $tier): string
    {
        return self::TIERS[$tier]['label'] ?? 'Not ranked yet';
    }
}
