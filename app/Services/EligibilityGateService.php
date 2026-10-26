<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Support\EmailStatus;
use Carbon\Carbon;

class EligibilityGateService
{
    /**
     * The website we audited must be this business's site: a directory listing sometimes carries another business's URL,
     * and the e-mail would then claim gaps on a site the prospect does not own. Free-mail addresses prove nothing either way.
     */
    public static function websiteMismatch(Lead $lead): ?string
    {
        $email = strtolower(trim((string) $lead->email));
        $mailHost = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';
        $siteHost = \App\Models\Company::normalizeDomain($lead->company?->website) ?: strtolower((string) $lead->company?->domain);
        if ($mailHost === '' || $siteHost === '' || in_array($mailHost, (array) config('leadpilot.free_mail_domains', []), true)) {
            return null;
        }
        $siteHost = preg_replace('/^www\./', '', $siteHost);
        $same = $mailHost === $siteHost || str_ends_with($mailHost, '.' . $siteHost) || str_ends_with($siteHost, '.' . $mailHost);

        return $same ? null : "Website ({$siteHost}) and e-mail ({$mailHost}) are on different domains: confirm which site belongs to this business before claiming anything about it.";
    }

    /**
     * Run all 10 mandatory eligibility checks before any outreach email is queued or sent.
     *
     * @param bool $isAutomatedBackgroundDispatch When true, strictly enforces dry_run / mode restrictions.
     */
    public function evaluate(
        Lead $lead,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
        bool $isAutomatedBackgroundDispatch = false
    ): EligibilityResult {
        $result = new EligibilityResult(true);
        $settings = OutreachSetting::current();

        // 1. Emergency Stop & Outreach Mode Check (Mandatory Gatekeeper)
        if ($settings->isEmergencyStopped() || ($campaign && $campaign->emergency_stopped)) {
            $reason = $settings->emergency_stop_reason ?: ($campaign?->emergency_stop_reason ?: 'Global emergency stop switch is active.');
            $result->fail('emergency_stop', "Emergency stop active: {$reason}", 'ready_for_review');
            return $result; // Hard short-circuit
        }

        if ($isAutomatedBackgroundDispatch && $settings->isDryRun()) {
            $result->fail('dry_run_mode', "System is in DRY-RUN mode. Automated autonomous sending is disabled until activated by administrator.", 'ready_for_review');
        } elseif ($settings->isControlledPilot()) {
            $authorizedEmail = strtolower(trim($settings->controlled_pilot_recipient ?? ''));
            $leadEmail = strtolower(trim($lead->email ?? ''));

            if (!empty($authorizedEmail) && $leadEmail !== $authorizedEmail) {
                $result->fail('controlled_pilot_restriction', "System is in CONTROLLED-PILOT mode. Only the authorized test recipient ({$authorizedEmail}) can receive emails.", 'ready_for_review');
            } else {
                $result->pass('controlled_pilot_authorized', "Authorized controlled-pilot test recipient: {$leadEmail}");
            }
        } else {
            $result->pass('outreach_mode', "Outreach mode is active ({$settings->mode}).");
        }

        // 2. Score Threshold Check
        $minScore = $campaign ? ($campaign->min_lead_score ?: $settings->min_lead_score) : $settings->min_lead_score;
        if (($lead->overall_score ?? 0) < $minScore) {
            $result->fail('score_threshold', "Lead score ({$lead->overall_score}) does not meet the minimum required threshold ({$minScore}).", 'nurture');
        } else {
            $result->pass('score_threshold', "Lead score ({$lead->overall_score}) meets minimum threshold ({$minScore}).");
        }

        // 3. Concrete Stored Evidence Check
        $signals = $lead->company ? $lead->company->buyingSignals()->active()->get() : collect();
        $minConfidence = $settings->min_evidence_confidence;
        $qualifyingSignals = $signals->filter(fn($s) => ($s->confidence ?? 0) >= $minConfidence);

        if ($qualifyingSignals->isEmpty() && empty($lead->company?->tech_stack) && empty($lead->score_explanation) && ($lead->overall_score ?? 0) < 50) {
            $result->fail('concrete_evidence', "No concrete buying signals or technical evidence recorded. Unsupported outreach prohibited.", 'ready_for_review');
        } else {
            $signalTitle = $qualifyingSignals->first()?->title ?? 'Verified qualification score / profile match';
            $result->pass('concrete_evidence', "Concrete evidence confirmed: \"{$signalTitle}\".");
        }

        // 4. Legitimate Recorded Contact Source Check
        $sourceName = $lead->source_name ?: ($lead->company?->source ?: 'Direct Business Portal');
        if (empty($sourceName)) {
            $result->fail('legitimate_source', "Business contact source is missing or unrecorded.", 'ready_for_review');
        } else {
            $result->pass('legitimate_source', "Legitimate authorized source recorded: \"{$sourceName}\".");
        }

        // 5. Email Verification Check
        $email = strtolower(trim($lead->email ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result->fail('email_verification', "Missing or syntactically invalid email address.", 'not_a_fit');
        } elseif ($settings->require_verified_email && !EmailStatus::isSendable($lead->email_verification_status, $settings)) {
            $result->fail('email_verification', "Email status is '" . EmailStatus::label($lead->email_verification_status) . "'. Policy requires a mailbox confirmed by an SMTP probe or Hunter"
                . ($lead->email_verification_status === EmailStatus::MX_ONLY ? ' (MX-only addresses are allowed only when switched on in Settings).' : '.'), 'ready_for_review');
        } elseif (!$settings->require_verified_email) {
            $result->pass('email_verification', 'Email verification not required by policy (status: ' . EmailStatus::label($lead->email_verification_status) . ').');
        } else {
            $result->pass('email_verification', 'Email sendable (' . EmailStatus::label($lead->email_verification_status) . ').');
        }

        // 5b. Contact identity (v3): no automated outreach to unknown / shared / tender contacts.
        // v4.2: a local business's own published inbox may be used when the owner switched "allow shared inbox" on;
        // published tender contacts are never cold-emailed.
        $isPilotRecipient = $settings->isControlledPilot()
            && strtolower(trim((string) $lead->email)) === strtolower(trim((string) $settings->controlled_pilot_recipient));
        if (!$isPilotRecipient && ($why = self::websiteMismatch($lead))) { // the owner's own pilot address never matches a prospect site
            $result->fail('website_match', $why, 'ready_for_review');
        }
        if (\App\Support\PublicSector::matches($lead->email, $lead->company?->website, $lead->company?->domain)) {
            $result->fail('public_sector', 'Public-sector body (government, state school, university or health service): they buy through procurement, not cold e-mail.', 'not_a_fit');
        }
        $isTenderContact = ($lead->job_title === 'Tender contact (published)')
            || in_array($lead->company?->source_provider, ['uk_contracts_finder', 'uk_find_a_tender', 'eu_ted'], true);
        if ($lead->contact_status === 'generic_inbox' && $settings->allow_shared_inbox && !$isTenderContact) {
            $result->pass('contact_identity', "Business's own published address (shared inbox allowed by policy).");
        } elseif (($lead->contact_status ?? 'identified') !== 'identified') {
            $result->fail('contact_identity', match ($lead->contact_status) {
                'needs_enrichment' => 'No real contact known yet — enrich before any outreach.',
                'generic_inbox' => 'Only a shared/public inbox (e.g. info@) or published tender contact is known — send manually after review, not automatically.',
                default => "Contact status '{$lead->contact_status}' is not eligible for automated outreach.",
            }, 'ready_for_review');
        } else {
            $result->pass('contact_identity', 'Named contact identified.');
        }

        // 5c. Links in the email must open for the prospect (RFC 8058 unsubscribe, approval/booking links).
        //     A local APP_URL is fine for the owner's own pilot address only.
        $pilot = strtolower(trim((string) $settings->controlled_pilot_recipient));
        if ($settings->isLive() && OutreachSetting::publicUrlIsLocal() && $email !== '' && $email !== $pilot) {
            $result->fail('public_url', 'APP_URL is a local address (' . config('app.url') . '): the unsubscribe link in the email would not open for the prospect. Set APP_URL to a public address first.', 'ready_for_review');
        } else {
            $result->pass('public_url', 'Email links are reachable (' . config('app.url') . ').');
        }

        // 6. Suppression, Opt-Out, and Refusal Check
        if (Suppression::isSuppressed($email, $lead->company?->domain)) {
            $result->fail('suppression', "Recipient or company domain is suppressed on the do-not-contact / unsubscribe list.", 'opted_out');
        } else {
            $result->pass('suppression', "Recipient is not suppressed or opted-out.");
        }

        // 7. Allowed Country & Legal Jurisdiction Compliance
        $allowedCountries = $settings->allowed_countries ?: ['Pakistan', 'United States', 'United Kingdom', 'Canada', 'Australia', 'Germany'];
        if (!empty($lead->country) && !in_array($lead->country, $allowedCountries)) {
            $result->fail('country_compliance', "Recipient country ({$lead->country}) is not in the approved outreach list.", 'ready_for_review');
        } else {
            $result->pass('country_compliance', "Recipient location ({$lead->country}) is approved for B2B outreach.");
        }

        // 8. Authorized Sender Configuration Check
        $senderEmail = $settings->sender_email ?: config('mail.from.address');
        if (empty($senderEmail)) {
            $result->fail('sender_authorization', "Sender identity is not configured in Outreach Settings or mail configuration.", 'ready_for_review');
        } else {
            $result->pass('sender_authorization', "Authorized sender: {$senderEmail}.");
        }

        // 9. Daily and Per-Recipient Frequency Limits
        $todaySentCount = EmailLog::whereDate('occurred_at', Carbon::today())
            ->where('direction', 'outbound')
            ->count();

        if ($todaySentCount >= $settings->max_daily_sends) {
            $result->fail('daily_frequency_limit', "Daily send volume limit ({$settings->max_daily_sends}) reached for today ({$todaySentCount} sent).", 'ready_for_review');
        } else {
            $result->pass('daily_frequency_limit', "Daily quota available ({$todaySentCount}/{$settings->max_daily_sends} sent today).");
        }

        $recipientSentCount = EmailLog::where('recipient_email', $email)
            ->where('direction', 'outbound')
            ->count();

        if ($recipientSentCount >= $settings->max_sends_per_recipient) {
            $result->fail('recipient_frequency_limit', "Maximum allowable contacts ({$settings->max_sends_per_recipient}) reached for this recipient.", 'nurture');
        } else {
            $result->pass('recipient_frequency_limit', "Per-recipient frequency count ({$recipientSentCount}/{$settings->max_sends_per_recipient}) within policy.");
        }

        // Check follow-up spacing if previously contacted
        if ($lead->last_contacted_at) {
            $daysSinceContact = Carbon::parse($lead->last_contacted_at)->diffInDays(now());
            if ($daysSinceContact < $settings->follow_up_delay_days) {
                $result->fail('follow_up_delay', "Follow-up interval not met ({$daysSinceContact} days elapsed; {$settings->follow_up_delay_days} required).", 'nurture');
            } else {
                $result->pass('follow_up_delay', "Follow-up spacing satisfied ({$daysSinceContact} days since last contact).");
            }
        }

        // 10. Approved Template and Truthful Personalization Check
        if ($campaign && ($campaign->template || $campaign->is_autopilot)) {
            $approvedServices = $settings->approved_services ?: [];

            if (!empty($campaign->service_offered) && !empty($approvedServices) && !in_array($campaign->service_offered, $approvedServices)) {
                $serviceLower = strtolower($campaign->service_offered);
                $found = false;
                foreach ($approvedServices as $appr) {
                    $apprTerms = explode('_', $appr);
                    $allMatch = true;
                    foreach ($apprTerms as $term) {
                        if (strlen($term) > 2 && !str_contains($serviceLower, $term)) {
                            $allMatch = false;
                            break;
                        }
                    }
                    if ($allMatch) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result->fail('template_service_approval', "Service '{$campaign->service_offered}' is not in approved outreach services list.", 'ready_for_review');
                } else {
                    $result->pass('template_service_approval', "Service '{$campaign->service_offered}' is approved.");
                }
            } else {
                $result->pass('template_service_approval', "Service is approved.");
            }
        }

        $result->metadata = [
            'mode' => $settings->mode,
            'lead_id' => $lead->id,
            'lead_email' => $email,
            'evaluated_at' => now()->toIso8601String(),
        ];

        return $result;
    }
}
