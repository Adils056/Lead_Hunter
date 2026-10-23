<?php

namespace App\Services\Sales;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\Task;
use App\Services\Autopilot\ApprovalService;

/**
 * Applies a reply classification to CRM state, sequences and approvals.
 * Rules: any real reply stops automated follow-ups; unsubscribe is always honoured immediately;
 * low-confidence/ambiguous replies go to a human before any CRM change; replies to humans are
 * never sent without approval (policy reply.auto_respond defaults to prohibited).
 */
class ReplyOutcomeService
{
    public function handle(InboundReply $reply): void
    {
        $r = app(ReplyClassifier::class)->classify($reply->body_text, optional($reply->lead)->contact_status === 'identified' ? $reply->lead->first_name : null);
        $reply->update(['category' => $r['category'], 'classification' => ReplyClassifier::legacyLabel($r), 'ai_confidence' => $r['confidence'], 'ai_reasoning' => $r['reasoning']]);

        $threshold = (int) config('leadpilot.approvals.reply_confidence_threshold', 85);
        if ($r['category'] !== 'unsubscribe' && ($r['category'] === 'uncertain' || $r['confidence'] < $threshold)) {
            $this->stopSequences($reply->lead, 'Reply received — awaiting human classification.');
            app(ApprovalService::class)->request('low_confidence_reply', "reply-classify:{$reply->id}", [
                'title' => 'Classify a prospect reply: ' . ($reply->lead?->company?->name ?? $reply->from_email),
                'summary' => "LeadPilot is not confident how to read this reply ({$r['category']}, {$r['confidence']}%). Follow-ups are stopped for this lead until you confirm.",
                'priority' => 'high',
                'payload' => ['suggested_category' => $r['category'], 'category' => $r['category']],
                'evidence' => ['from' => $reply->from_email, 'reply' => mb_substr($reply->body_text, 0, 1500)],
                'attempted' => ['Rule-based classification', 'Stopped follow-ups for this lead'],
                'options' => ['approve', 'edit_approve', 'reject', 'reassign'],
                'recommended' => 'edit_approve',
            ], $reply);

            return;
        }
        $this->apply($reply, $r['category'], $r['confidence'], $r['reasoning'], $r);
    }

    public function apply(InboundReply $reply, string $category, int $confidence, string $reason, array $r = []): void
    {
        $lead = $reply->lead;
        $reply->update(['category' => $category, 'classification' => ReplyClassifier::legacyLabel(['category' => $category, 'subtype' => $r['subtype'] ?? null])]);
        if (!$lead) {
            return;
        }

        if ($category !== 'out_of_office') {
            $this->stopSequences($lead, "Prospect replied ({$category}).");
            CampaignRecipient::where('lead_id', $lead->id)->whereIn('status', ['sent', 'delivered', 'opened'])->update(['status' => 'replied', 'replied_at' => now()]);
        }

        switch ($category) {
            case 'unsubscribe':
                Suppression::addSuppression($reply->from_email, 'unsubscribe', 'reply_intent');
                $lead->update(['lifecycle_stage' => 'not_a_fit']);
                break;
            case 'not_interested':
                $lead->update(['lifecycle_stage' => 'lost']);
                break;
            case 'out_of_office':
                $back = app(ConversionAssistantService::class)->parseReturnDate($r['return_date_text'] ?? null) ?? now()->addDays(7);
                $lead->update(['next_action_date' => $back->toDateString(), 'next_action_note' => 'Out of office — follow-ups resume after return.']);
                break;
            case 'interested':
                $lead->update(['lifecycle_stage' => 'interested']);
                app(\App\Services\Autopilot\SearchAllocator::class)->recordPositive($lead);
                $this->task($lead, 'Hot lead replied with interest — respond today', 'high');
                $this->proposeMeeting($reply, $lead);
                break;
            case 'request_info':
            case 'objection':
            case 'referral':
                $lead->update(['lifecycle_stage' => 'replied']);
                if ($category === 'request_info') {
                    app(\App\Services\Autopilot\SearchAllocator::class)->recordPositive($lead);
                }
                $this->task($lead, ucfirst(str_replace('_', ' ', $category)) . ' from prospect — review suggested response', 'high');
                // reply.auto_respond: 'automatic' would send without review; default 'prohibited' = a human always reviews.
                // Objections and info requests (prices!) are always reviewed regardless of policy.
                if (!empty($r['suggested_reply'])) {
                    $this->replyApproval($reply, $lead, $category, $r['suggested_reply']);
                }
                if ($category === 'referral' && !empty($r['referred_email']) && !Lead::where('email', $r['referred_email'])->exists()) {
                    Lead::create([
                        'company_id' => $lead->company_id, 'icp_profile_id' => $lead->icp_profile_id,
                        'full_name' => ($lead->company?->name ?? 'Company') . ' — referred contact', 'email' => strtolower($r['referred_email']),
                        'contact_status' => 'generic_inbox', 'source_name' => 'Referral from ' . $reply->from_email,
                        'notes' => "Referred by {$reply->from_email} in reply #{$reply->id}. Mention the referral; do not cold-sequence.",
                        'lifecycle_stage' => 'new', 'country' => $lead->country, 'industry' => $lead->industry,
                    ]);
                }
                break;
            default:
                $lead->update(['lifecycle_stage' => 'replied']);
        }
    }

    public function stopSequences(?Lead $lead, string $why): int
    {
        if (!$lead) {
            return 0;
        }

        $ids = CampaignRecipient::where('lead_id', $lead->id)->whereIn('status', ['draft', 'pending_approval', 'approved', 'queued'])->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        CampaignRecipient::whereIn('id', $ids)->update(['status' => 'cancelled', 'error_message' => $why]);
        // a pending approval for a cancelled message must not be able to resurrect it
        foreach (\App\Models\ApprovalRequest::open()->whereIn('type', ['outreach_message', 'follow_up'])
            ->where('subject_type', (new CampaignRecipient())->getMorphClass())->whereIn('subject_id', $ids)->get() as $req) {
            $req->update(['status' => 'cancelled', 'execution_result' => "Cancelled automatically: {$why}"]);
            \App\Models\ApprovalEvent::create(['approval_request_id' => $req->id, 'event' => 'cancelled', 'data' => ['why' => $why], 'created_at' => now()]);
        }

        return $ids->count();
    }

    protected function replyApproval(InboundReply $reply, Lead $lead, string $category, string $draft): void
    {
        app(ApprovalService::class)->request('reply_response', "reply-response:{$reply->id}", [
            'title' => 'Approve response to ' . ($lead->company?->name ?? $reply->from_email) . " ({$category})",
            'summary' => "The prospect replied ({$category}). A suggested response is ready; edit it (especially any prices or commitments) and approve to send.",
            'priority' => 'high',
            'payload' => ['subject' => 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string) $reply->subject), 'body' => $draft],
            'evidence' => ['their_reply' => mb_substr($reply->body_text, 0, 1500)],
            'options' => ['approve', 'edit_approve', 'reject', 'reassign'],
            'recommended' => 'edit_approve',
        ], $reply);
    }

    protected function proposeMeeting(InboundReply $reply, Lead $lead): void
    {
        $mode = app(\App\Services\Autopilot\AutonomyPolicyService::class)->mode('meeting.propose_slots');
        if ($mode === 'prohibited') {
            return; // the high-priority task created above is the only action
        }
        $slotsSvc = app(MeetingSlotService::class);
        $booking = config('leadpilot.agency.booking_url');
        $times = $booking ? "You can pick any time that suits you here: {$booking}" : "Would any of these work?\n" . $slotsSvc->formatted($slotsSvc->slots());
        $name = $lead->contact_status === 'identified' && $lead->first_name ? $lead->first_name : 'there';
        $body = "Hi {$name},\n\nThanks for getting back to me. {$times}\n\nIf none fit, just suggest a time.\n\n" . config('leadpilot.agency.sender_name');

        if ($mode === 'automatic') {
            try {
                app(\App\Services\Outreach\OutreachMailer::class)->sendToLead($lead, 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string) $reply->subject), $body, $reply->message_id);

                return;
            } catch (\Throwable $e) {
                // fall through to an approval if it could not be sent automatically
            }
        }
        app(ApprovalService::class)->request('meeting_slots', "meeting:{$reply->id}", [
            'title' => 'Offer meeting times to ' . ($lead->company?->name ?? $reply->from_email),
            'summary' => 'Prospect is interested. These times come from your working hours minus meetings already in the CRM. Approve to send; no calendar event is created until they confirm.',
            'priority' => 'high',
            'payload' => ['subject' => 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string) $reply->subject), 'body' => $body, 'in_reply_to' => $reply->message_id],
            'evidence' => ['their_reply' => mb_substr($reply->body_text, 0, 1000)],
            'options' => ['approve', 'edit_approve', 'reject'],
            'recommended' => 'approve',
        ], $reply);
    }

    protected function task(Lead $lead, string $title, string $priority): void
    {
        $assignee = $lead->assigned_user_id ?? \App\Models\User::where('role', 'admin')->where('is_active', true)->value('id');
        if (!$assignee) {
            return;
        }
        Task::firstOrCreate(['lead_id' => $lead->id, 'title' => $title, 'status' => 'pending'], [
            'company_id' => $lead->company_id, 'assigned_to' => $assignee, 'due_date' => now()->toDateString(), 'priority' => $priority,
            'description' => 'Created by LeadPilot Autopilot from a classified reply.',
        ]);
    }
}
