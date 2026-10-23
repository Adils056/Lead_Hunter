<?php

namespace App\Services;

use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\LeadScore;
use App\Models\ScoringSetting;
use App\Services\Sales\OfferRecommender;
use App\Support\EmailStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Transparent, configurable 0-100 lead score (v3).
 *
 * IMPORTANT: this is a HEURISTIC fit/intent score built from evidence and admin-set weights.
 * It is NOT a probability of conversion. ScoreCalibrationService measures whether it actually
 * predicts replies/meetings/wins once enough outcomes exist.
 *
 * Each factor yields strength 0..1 (or null = cannot be evaluated). points = strength * weight.
 * Factors that cannot be evaluated are excluded and the total is renormalised to 100; the excluded
 * share lowers the reported confidence and is listed under missing information.
 */
class LeadScoringService
{
    public const DECISION_MAKER = '/\b(cto|ceo|coo|cio|cmo|chief|founder|co-founder|owner|proprietor|managing director|md|president|partner|principal|vp|vice president|head of|director)\b/i';
    public const INFLUENCER = '/\b(manager|lead|architect|product|head|it|marketing|operations|administrator|practice manager)\b/i';

    public function __construct(protected ?OfferRecommender $offers = null)
    {
        $this->offers ??= new OfferRecommender();
    }

    public function evaluateLead(Lead $lead, ?int $evaluatorId = null): LeadScore
    {
        $cfg = ScoringSetting::effective();
        $W = $cfg['weights'];
        $T = $cfg['thresholds'];

        $company = $lead->company;
        $signals = $company ? $company->buyingSignals()->active()->get() : collect();
        $icp = $lead->icpProfile ?? $this->bestActiveIcp();

        $f = []; // key => [strength|null, reason, evidence[]]
        // Local SMBs (clinics, shops) almost never publish RFPs or hire developers; scoring them 0 there
        // would cap every local prospect. For local-only ICPs those factors are "not applicable" unless evidence exists.
        $localOnly = $icp && $icp->list('business_types') === ['local_business'];
        $missing = [];
        $evidence = [];

        // 1. Explicit request (RFP, tender, inbound enquiry)
        $rfp = $signals->whereIn('signal_type', ['procurement_rfp', 'project_requirements', 'vendor_request', 'erp_crm_request', 'voluntary_inquiry']);
        if ($rfp->isNotEmpty()) {
            $top = $rfp->sortByDesc('confidence')->first();
            $f['explicit_request'] = [$top->confidence / 100, "Explicit project request: \"{$top->title}\""];
            $evidence[] = $this->ev('explicit_request', $top);
        } elseif ($localOnly) {
            $f['explicit_request'] = [null, null];
        } else {
            $f['explicit_request'] = [0, null];
            $missing[] = 'No RFP, tender or inbound enquiry on record.';
        }

        // 2. Software need (hiring developers / stack change)
        $need = $signals->whereIn('signal_type', ['developer_hiring', 'tech_stack_change']);
        if ($need->isNotEmpty()) {
            $top = $need->sortByDesc('confidence')->first();
            $f['software_need'] = [$top->confidence / 100, "Active software need: \"{$top->title}\""];
            $evidence[] = $this->ev('software_need', $top);
        } elseif ($company && !empty($company->tech_stack)) {
            $f['software_need'] = [0.5, 'Known technology stack: ' . implode(', ', array_slice((array) $company->tech_stack, 0, 5))];
        } elseif ($localOnly) {
            $f['software_need'] = [null, null];
        } else {
            $f['software_need'] = [0, null];
            $missing[] = 'No hiring or technology-change evidence.';
        }

        // 3. Website gap (from real audit, or confirmed absence of a website)
        $noSite = $signals->first(fn ($s) => ($s->metadata['evidence_key'] ?? null) === 'no_website');
        if ($company && $company->website_audited_at !== null && $company->website_opportunity_score !== null) {
            $f['website_gap'] = [$company->website_opportunity_score / 100, "Website audit opportunity {$company->website_opportunity_score}/100 (audited {$company->website_audited_at->toDateString()})"];
            if ($a = $signals->first(fn ($s) => ($s->metadata['source'] ?? null) === 'website_audit')) {
                $evidence[] = $this->ev('website_gap', $a);
            }
        } elseif ($noSite) {
            $f['website_gap'] = [1.0, 'No website listed for the business'];
            $evidence[] = $this->ev('website_gap', $noSite);
        } else {
            $f['website_gap'] = [null, null];
            $missing[] = $company?->website ? 'Website not audited yet — run a website audit.' : 'Website unknown.';
        }

        // 4. Growth initiative
        $growth = $signals->whereIn('signal_type', ['business_expansion', 'business_launch', 'funding_announcement', 'digital_transformation']);
        if ($growth->isNotEmpty()) {
            $top = $growth->sortByDesc('confidence')->first();
            $f['growth_initiative'] = [$top->confidence / 100, "Growth initiative: \"{$top->title}\""];
            $evidence[] = $this->ev('growth_initiative', $top);
        } elseif ($company && in_array($company->size_range, ['51-200', '201-500', '500+'], true)) {
            $f['growth_initiative'] = [0.6, "Established company size ({$company->size_range})"];
        } elseif ($company && ($company->google_review_count ?? 0) >= 50) {
            $f['growth_initiative'] = [0.5, "Active local business ({$company->google_review_count} Google reviews)"];
        } else {
            $f['growth_initiative'] = [0, null];
            $missing[] = 'No growth, funding or expansion evidence.';
        }

        // 5. ICP match
        if ($icp) {
            [$s, $detail] = $this->icpMatch($icp, $lead);
            $f['icp_match'] = [$s, $s === null ? null : "Matches ICP \"{$icp->name}\": {$detail}"];
            if ($s === null) {
                $missing[] = "Not enough company data to compare with ICP \"{$icp->name}\".";
            }
        } else {
            $f['icp_match'] = [null, null];
        }

        // 6. Contact role
        $title = (string) $lead->job_title;
        $preferred = $icp ? $icp->list('contact_roles') : [];
        $prefHit = $title && collect($preferred)->contains(fn ($r) => stripos($title, $r) !== false);
        $f['contact_role'] = match (true) {
            $prefHit => [1.0, "Preferred ICP role reached: {$title}"],
            $title !== '' && (bool) preg_match(self::DECISION_MAKER, $title) => [1.0, "Decision maker: {$title}"],
            $title !== '' && (bool) preg_match(self::INFLUENCER, $title) => [0.7, "Influencer: {$title}"],
            $title !== '' => [0.4, "Known role: {$title}"],
            $lead->contact_status === 'generic_inbox' => [0.25, 'Only a public business inbox is known'],
            default => [0, null],
        };
        if ($f['contact_role'][0] < 0.7) {
            $missing[] = 'Decision-maker not identified.';
        }

        // 7. Service fit
        $offers = $this->offers->recommend($lead, 3);
        if ($offers) {
            $f['service_fit'] = [min(1, 0.5 + $offers[0]['score'] / 20), "Best-fit service: {$offers[0]['label']}"];
        } elseif ($company && in_array($company->industry, ['Information Technology', 'Software', 'Financial Services', 'Retail', 'Logistics'], true)) {
            $f['service_fit'] = [0.5, "High-affinity industry ({$company->industry})"];
        } else {
            $f['service_fit'] = [0.3, 'General digital-services applicability'];
            $missing[] = 'No evidence-backed service fit yet.';
        }

        // 8. Contact verification & source trust
        $v = match ($lead->email_verification_status) {
            EmailStatus::VERIFIED => 0.6, EmailStatus::RISKY, EmailStatus::CATCH_ALL => 0.3, EmailStatus::MX_ONLY => 0.1, default => 0,
        };
        $v += (!empty($lead->source_name) && $lead->source_name !== 'Manual Entry') ? 0.4 : 0.2;
        $f['contact_verification'] = [min(1, $v), 'Email ' . EmailStatus::label($lead->email_verification_status) . '; source: ' . ($lead->source_name ?: 'unknown')];
        if (!$lead->email) {
            $missing[] = 'No email address — needs enrichment before outreach.';
        }

        // 9. Data completeness
        $fields = ['website' => $company?->website, 'phone' => $lead->phone ?: $company?->public_phone, 'email' => $lead->email,
            'country' => $lead->country ?: $company?->country, 'city' => $company?->city, 'industry' => $lead->industry ?: $company?->industry,
            'job_title' => $lead->job_title];
        $have = count(array_filter($fields));
        $completeness = $have / count($fields);
        $f['data_completeness'] = [$completeness, "{$have}/" . count($fields) . ' key fields known'];

        // 10. Freshness
        $newest = $signals->sortByDesc('observed_date')->first();
        $evidenceDate = $newest ? Carbon::parse($newest->observed_date) : ($lead->created_at ?? now());
        $age = (int) $evidenceDate->diffInDays(now());
        $f['signal_freshness'] = [match (true) {
            $age <= 14 => 1.0, $age <= 30 => 0.8, $age <= 60 => 0.5, $age <= 120 => 0.25, default => 0.1,
        }, "Newest evidence {$age} day(s) old"];

        // ---- Aggregate
        $points = [];
        $evaluable = 0;
        $earned = 0.0;
        foreach ($W as $key => $weight) {
            [$s] = $f[$key] ?? [null];
            if ($s === null) {
                $points[$key] = null;
                continue;
            }
            $evaluable += $weight;
            $p = round($s * $weight, 1);
            $points[$key] = $p;
            $earned += $p;
        }
        $overall = $evaluable > 0 ? (int) round(100 * $earned / $evaluable) : 0;
        $overall = max(0, min(100, $overall));

        $coverage = array_sum($W) ? $evaluable / array_sum($W) : 0;
        $confidenceScore = (int) round(100 * (0.6 * $coverage + 0.4 * $completeness));
        $confidence = $confidenceScore >= 75 ? 'high' : ($confidenceScore >= 50 ? 'medium' : 'low');

        $priority = match (true) {
            $overall >= $T['high'] => 'high',
            $overall >= $T['qualified'] => 'qualified',
            $overall >= $T['nurture'] => 'medium',
            default => 'low',
        };
        $disq = [];
        if ($priority === 'low') {
            $disq[] = "Score {$overall}/100 below nurture threshold ({$T['nurture']}).";
        }
        if ($lead->email_verification_status === 'invalid') {
            $priority = 'needs_review';
            $disq[] = 'Email address is invalid or non-deliverable.';
        }
        if ($icp && $lead->company && ($why = $icp->exclusionReason($lead->company->domain, $lead->company->name))) {
            $priority = 'needs_review';
            $disq[] = "ICP exclusion: {$why}";
        }

        $reasons = [];
        $scores = [];
        foreach ($W as $key => $weight) {
            $scores[$key] = $points[$key];
            if ($points[$key] !== null && ($f[$key][1] ?? null)) {
                $reasons[] = "{$f[$key][1]} (+{$points[$key]}/{$weight})";
            }
        }

        $nextAction = $this->nextAction($lead, $priority, $rfp->isNotEmpty(), $offers);
        $qualificationReasons = array_slice(array_filter(array_map(fn ($k) => ($points[$k] ?? 0) >= 0.6 * $W[$k] ? $f[$k][1] : null, array_keys($W))), 0, 4);

        $breakdown = [
            'score_type' => 'heuristic',
            'score_note' => 'Heuristic fit/intent score from weighted evidence. Not a conversion probability until calibrated against outcomes.',
            'scoring_version' => config('leadpilot.scoring.version'),
            'scores' => $scores,
            'weights' => $W,
            'factor_reasons' => array_map(fn ($x) => $x[1], $f),
            'excluded_factors' => array_keys(array_filter($points, fn ($p) => $p === null)),
            'evidence' => $evidence,
            'missing_information' => array_values(array_unique($missing)),
            'qualification_reasons' => array_values($qualificationReasons),
            'disqualifications' => $disq,
            'recommended_offers' => $offers,
            'suggested_next_action' => $nextAction,
            'confidence' => $confidence,
            'confidence_score' => $confidenceScore,
            'data_freshness' => ['newest_evidence_date' => $evidenceDate->toDateString(), 'age_days' => $age],
            'icp_profile' => $icp?->only(['id', 'name']),
            'qualification_category' => $priority,
            'evaluated_at' => now()->toIso8601String(),
            // Back-compat aliases used by existing views/tests
            'explicit_request' => $points['explicit_request'],
            'technical_hiring' => $points['software_need'],
            'decision_maker_identified' => $points['contact_role'],
        ];

        $intent = (int) round(min(30, ($points['explicit_request'] ?? 0) + ($points['software_need'] ?? 0) * 0.5));
        $fit = (int) round(min(20, ($points['service_fit'] ?? 0) + ($points['icp_match'] ?? 0) * 0.5));
        $contactConf = (int) round(min(25, ($points['contact_role'] ?? 0) + ($points['contact_verification'] ?? 0)));
        $stage = $lead->lifecycle_stage ?? 'new';

        $lead->update([
            'intent_score' => $intent,
            'service_fit_score' => $fit,
            'contact_confidence_score' => $contactConf,
            'overall_score' => $overall,
            'score_explanation' => implode(' ', $reasons),
            'score_breakdown' => $breakdown,
            'scored_at' => now(),
            'lifecycle_stage' => ($stage === 'new' && in_array($priority, ['high', 'qualified'], true)) ? 'qualified' : $stage,
        ]);

        return LeadScore::create([
            'lead_id' => $lead->id,
            'company_id' => $company?->id,
            'scoring_version' => config('leadpilot.scoring.version'),
            'intent_score' => $intent,
            'service_fit_score' => $fit,
            'contact_confidence_score' => $contactConf,
            'overall_score' => $overall,
            'priority_level' => $priority,
            'reasoning' => implode(' ', $reasons),
            'breakdown' => $breakdown,
            'contributing_signals' => $signals->pluck('id')->toArray(),
            'evaluated_by' => $evaluatorId ?? Auth::id(),
            'evaluated_at' => now(),
        ]);
    }

    /** @return array{0: ?float, 1: string} */
    public function icpMatch(IcpProfile $icp, Lead $lead): array
    {
        $c = $lead->company;
        $checks = [];
        $cmp = function (string $label, array $wanted, ?string $actual, bool $substring = false) use (&$checks) {
            if (!$wanted) {
                return;
            }
            if ($actual === null || $actual === '') {
                $checks[] = [$label, null];

                return;
            }
            $a = strtolower($actual);
            $ok = collect($wanted)->contains(fn ($w) => $substring ? (str_contains($a, strtolower($w)) || str_contains(strtolower($w), $a)) : strtolower($w) === $a);
            $checks[] = [$label, $ok];
        };
        $cmp('country', $icp->list('countries'), $lead->country ?: $c?->country);
        $cmp('city', $icp->list('cities'), $c?->city, true);
        $cmp('industry', array_merge($icp->list('industries'), $icp->list('niches')), $lead->industry ?: $c?->industry, true);
        $cmp('size', $icp->list('company_sizes'), $c?->size_range);

        if ($icp->website_requirement !== 'any' && $c) {
            $checks[] = ['website', match ($icp->website_requirement) {
                'has_website' => (bool) $c->website,
                'no_website' => !$c->website,
                'weak_website' => $c->website_opportunity_score === null ? null : $c->website_opportunity_score >= 30,
                default => null,
            }];
        }
        $stack = array_map('strtolower', (array) ($c?->tech_stack ?? []));
        if ($icp->list('tech_include')) {
            $checks[] = ['tech', $stack ? (bool) array_intersect($stack, array_map('strtolower', $icp->list('tech_include'))) : null];
        }
        if ($icp->list('tech_exclude') && $stack) {
            $checks[] = ['tech_exclude', !array_intersect($stack, array_map('strtolower', $icp->list('tech_exclude')))];
        }
        if ($icp->min_google_rating && $c?->google_rating !== null) {
            $checks[] = ['rating', $c->google_rating >= $icp->min_google_rating / 10];
        }

        $known = array_filter($checks, fn ($x) => $x[1] !== null);
        if (!$known) {
            return [null, ''];
        }
        $hit = array_filter($known, fn ($x) => $x[1] === true);
        $detail = implode(', ', array_map(fn ($x) => $x[0] . ($x[1] === null ? ' ?' : ($x[1] ? ' ✓' : ' ✗')), $checks));

        return [count($hit) / count($known), count($hit) . '/' . count($known) . " criteria ({$detail})"];
    }

    protected function bestActiveIcp(): ?IcpProfile
    {
        $active = IcpProfile::where('is_active', true)->limit(2)->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    protected function nextAction(Lead $lead, string $priority, bool $hasRfp, array $offers): string
    {
        $offer = $offers[0]['label'] ?? 'a relevant service';

        return match (true) {
            $priority === 'needs_review' => 'Review manually: lead is excluded or has an invalid email.',
            $hasRfp => 'Read the tender/RFP and prepare a compliant response before the closing date.',
            $priority === 'low' => 'Do not contact automatically; keep for re-scoring when new evidence appears.',
            $priority === 'medium' => "Manual review (nurture band). If worth pursuing, " . (!$lead->email ? 'find a contact first' : 'verify the email') . " and offer {$offer}; otherwise re-score after new evidence.",
            !$lead->email && $lead->phone => "No email on record. Call the published business number to reach the decision-maker, then offer {$offer}.",
            !$lead->email => 'Enrich: find a decision-maker or public business email (run a website audit).',
            $lead->contact_status === 'generic_inbox' && in_array($priority, ['high', 'qualified'], true) => "Find the owner/decision-maker's name; meanwhile send a short evidence-based note to the public inbox offering {$offer}.",
            in_array($lead->email_verification_status, [EmailStatus::UNVERIFIED, EmailStatus::MX_ONLY, EmailStatus::UNKNOWN], true) => "Confirm the mailbox (SMTP probe or Hunter), then send a first-touch email offering {$offer}.",
            default => "Send personalised first-touch email offering {$offer}.",
        };
    }

    protected function ev(string $factor, $signal): array
    {
        return [
            'type' => $factor,
            'title' => $signal->title,
            'source_url' => $signal->source_url,
            'snippet' => mb_substr((string) $signal->evidence_text, 0, 240),
            'observed_date' => (string) $signal->observed_date,
        ];
    }
}
