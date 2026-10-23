<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\UsageLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CampaignDeliveryService
{
    protected EligibilityGateService $eligibilityGate;

    public function __construct(EligibilityGateService $eligibilityGate)
    {
        $this->eligibilityGate = $eligibilityGate;
    }

    /**
     * Populate campaign recipient drafts based on targeting rules.
     */
    public function buildCampaignDrafts(Campaign $campaign): int
    {
        $query = Lead::query()
            ->where('overall_score', '>=', $campaign->min_lead_score);

        // Filter countries
        if (!empty($campaign->target_countries)) {
            $query->whereIn('country', $campaign->target_countries);
        }

        // Filter industries
        if (!empty($campaign->target_industries)) {
            $query->whereIn('industry', $campaign->target_industries);
        }

        // Filter sources
        if (!empty($campaign->eligible_sources)) {
            $query->whereIn('source_name', $campaign->eligible_sources);
        }

        // Must have an email address
        $query->whereNotNull('email')->where('email', '!=', '');

        // Respect contact rules
        if ($campaign->contact_rules['require_verified_email'] ?? true) {
            $query->where('email_verification_status', 'verified');
        }

        $leads = $query->get();
        $template = $campaign->template;
        $createdCount = 0;

        foreach ($leads as $lead) {
            // Check suppression
            if ($lead->isSuppressed()) {
                continue;
            }

            // Check if already in this campaign
            $existing = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('lead_id', $lead->id)
                ->first();

            if ($existing) {
                continue;
            }

            $idempotencyKey = "cmp_{$campaign->id}_lead_{$lead->id}_" . Str::random(8);

            // Personalize template
            $optOutUrl = \App\Services\Outreach\OutreachMailer::unsubscribeUrl($lead->email);
            
            $placeholders = [
                'first_name' => $lead->first_name ?: ($lead->full_name ?: 'there'),
                'contact_name' => $lead->full_name ?: 'Valued Partner',
                'company_name' => $lead->company?->name ?: 'your company',
                'verified_industry' => $lead->industry ?: 'technology',
                'relevant_service' => $campaign->service_offered,
                'sender_name' => $campaign->creator?->name ?: config('mail.from.name', 'LeadPilot AI'),
                'agency_website' => config('app.url', 'https://example.com'),
                'opt_out_link' => $optOutUrl,
            ];

            $personalizedSubject = $template ? $template->renderSubject($placeholders) : "Partnership inquiry with {$placeholders['company_name']}";
            $personalizedBody = $template ? $template->renderBody($placeholders) : "Hello {$placeholders['first_name']},\n\nWe noticed {$placeholders['company_name']}'s growth...";

            $initialStatus = $campaign->require_approval ? 'draft' : 'approved';

            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
                'status' => $initialStatus,
                'idempotency_key' => $idempotencyKey,
                'personalized_subject' => $personalizedSubject,
                'personalized_body' => $personalizedBody,
                'scheduled_at' => now()->addMinutes(rand(10, 120)),
            ]);

            $createdCount++;
        }

        return $createdCount;
    }

    /**
     * Dispatch an approved recipient email with strict 10-point backend eligibility gate,
     * DB row-level locking, and idempotency guarantees.
     */
    public function dispatchRecipient(CampaignRecipient $recipient, bool $isAutomated = false): bool
    {
        return DB::transaction(function () use ($recipient, $isAutomated) {
            // Lock the recipient row to prevent concurrency races
            $locked = CampaignRecipient::where('id', $recipient->id)->lockForUpdate()->first();
            if (!$locked) {
                return false;
            }

            $campaign = $locked->campaign;
            $lead = $locked->lead;

            // Status check
            if ($campaign->emergency_stopped || $campaign->status === 'paused') {
                $locked->update(['status' => 'skipped', 'error_message' => 'Campaign is emergency stopped or paused.']);
                return false;
            }

            if ($campaign->status !== 'running') {
                return false;
            }

            // v4: never send something awaiting approval, cancelled or rejected
            if (in_array($locked->status, ['pending_approval', 'cancelled', 'rejected'], true)) {
                return false;
            }
            // autopilot messages must go through SequenceEngine (pause/policy/window/caps)
            if ($campaign->is_autopilot && !app()->bound('leadpilot.sequence_dispatch')) {
                return false;
            }

            // Avoid double-sending
            if (in_array($locked->status, ['sent', 'delivered', 'replied'])) {
                return false;
            }

            // Daily Quota Check
            if (!$campaign->canSendToday()) {
                $locked->update(['status' => 'queued', 'error_message' => 'Daily sending quota reached. Postponed to next cycle.']);
                return false;
            }

            // Run backend 10-Point Eligibility Gate
            $eligibility = $this->eligibilityGate->evaluate($lead, $campaign, $locked, $isAutomated);
            if (!$eligibility->isEligible) {
                $rejectionStatus = in_array($eligibility->recommendedLifecycleStage, ['opted_out', 'not_a_fit']) ? 'opted_out' : 'skipped';
                $locked->update([
                    'status' => $rejectionStatus,
                    'error_message' => "Eligibility Gate Rejected: " . $eligibility->primaryReason,
                ]);

                if ($eligibility->recommendedLifecycleStage !== $lead->lifecycle_stage) {
                    $lead->update(['lifecycle_stage' => $eligibility->recommendedLifecycleStage]);
                }

                return false;
            }

            try {
                // v4: real Message-ID, one-click unsubscribe headers, threading for follow-ups
                $parent = $locked->parent_recipient_id ? CampaignRecipient::find($locked->parent_recipient_id) : null;
                $chain = [];
                for ($p = $parent, $i = 0; $p && $i < 10; $p = $p->parent_recipient_id ? CampaignRecipient::find($p->parent_recipient_id) : null, $i++) {
                    if ($p->rfc_message_id) {
                        array_unshift($chain, '<' . $p->rfc_message_id . '>');
                    }
                }
                $providerMessageId = app(\App\Services\Outreach\OutreachMailer::class)->send(
                    $lead->email,
                    $lead->contact_status === 'identified' ? (string) $lead->full_name : '',
                    (string) $locked->personalized_subject,
                    (string) $locked->personalized_body,
                    $parent?->rfc_message_id,
                    implode(' ', $chain)
                );

                // "sent" = accepted by the SMTP server (not inbox delivery)
                $locked->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_message_id' => $providerMessageId,
                    'rfc_message_id' => $providerMessageId,
                ]);

                // Update lead contacted time
                $lead->update([
                    'last_contacted_at' => now(),
                    'lifecycle_stage' => in_array($lead->lifecycle_stage, ['new', 'qualified', 'ready_for_review', 'approved']) ? 'contacted' : $lead->lifecycle_stage,
                ]);

                // Increment campaign daily count
                $campaign->incrementSentCount();

                // Record into Email Logs
                EmailLog::create([
                    'campaign_recipient_id' => $locked->id,
                    'campaign_id' => $campaign->id,
                    'lead_id' => $lead->id,
                    'direction' => 'outbound',
                    'provider' => config('mail.default', 'smtp'),
                    'message_id' => $providerMessageId,
                    'sender_email' => config('mail.from.address', 'sales@leadpilot.test'),
                    'recipient_email' => $lead->email,
                    'subject' => $locked->personalized_subject,
                    'body_snippet' => substr($locked->personalized_body, 0, 200),
                    'status' => 'sent',
                    'occurred_at' => now(),
                ]);

                UsageLog::create([
                    'provider' => config('mail.default', 'smtp'),
                    'service_type' => 'email_sending',
                    'units_consumed' => 1,
                    'cost_estimated' => 0.001,
                    'user_id' => Auth::id() ?? $campaign->created_by,
                    'metadata' => ['campaign_id' => $campaign->id, 'lead_id' => $lead->id],
                    'occurred_at' => now(),
                ]);

                return true;
            } catch (\Exception $e) {
                $locked->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                return false;
            }
        });
    }

    /**
     * Send a safe test email to a designated administrative test email address.
     */
    public function sendTestEmail(Campaign $campaign, string $testEmail): bool
    {
        $testEmail = strtolower(trim($testEmail));
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $template = $campaign->template;
        $placeholders = [
            'first_name' => 'Jane (Test)',
            'contact_name' => 'Jane Doe',
            'company_name' => 'Acme Test Corp',
            'verified_industry' => 'Financial Technology',
            'relevant_service' => $campaign->service_offered,
            'sender_name' => Auth::user()?->name ?: 'Alex Vance',
            'agency_website' => config('app.url', 'https://example.com'),
            'opt_out_link' => route('campaigns.unsubscribe', ['email' => urlencode($testEmail), 'token' => 'preview_test_token']),
        ];

        $subject = '[TEST PREVIEW] ' . ($template ? $template->renderSubject($placeholders) : "Partnership inquiry with Acme Test Corp");
        $body = $template ? $template->renderBody($placeholders) : "Hello Jane,\n\nThis is a sample test delivery for {$campaign->name}.";

        Mail::raw($body, function ($msg) use ($testEmail, $subject) {
            $msg->to($testEmail)
                ->subject($subject);
        });

        AuditLog::record('campaign_test_sent', $campaign, null, ['test_email' => $testEmail]);

        return true;
    }

    /**
     * Emergency stop a campaign immediately.
     */
    public function emergencyStop(Campaign $campaign, ?string $reason = null): void
    {
        $campaign->update([
            'emergency_stopped' => true,
            'status' => 'paused',
        ]);

        AuditLog::record('campaign_emergency_stop', $campaign, null, ['reason' => $reason]);
    }
}
