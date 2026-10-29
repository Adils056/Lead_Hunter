<?php

namespace App\Services\Autopilot;

use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Models\Integration;
use App\Models\ProposalDraft;
use App\Models\SpendBudget;
use App\Models\User;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Outreach\OutreachMailer;
use App\Services\Outreach\SequenceEngine;
use App\Services\Sales\ReplyOutcomeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Level-2 approvals. A workflow that needs a human creates a request and STAYS PAUSED until an
 * authorised user decides. Nothing is ever marked approved without a real user decision.
 */
class ApprovalService
{
    public const ACTIONS = ['approve', 'edit_approve', 'reject', 'request_info', 'reassign', 'escalate', 'pause', 'retry'];

    /** Types a sales rep may decide (for leads they can see). Everything else is admin-only. */
    public const SALES_TYPES = ['outreach_message', 'follow_up', 'reply_response', 'low_confidence_reply', 'meeting_slots'];

    public function request(string $type, string $dedupeKey, array $data, ?Model $subject = null): ApprovalRequest
    {
        $existing = ApprovalRequest::where('dedupe_key', $dedupeKey)->first();
        if ($existing) {
            return $existing; // idempotent: never duplicate a pending decision
        }
        $priority = $data['priority'] ?? 'normal';
        $hours = (int) (config('leadpilot.approvals.due_hours')[$priority] ?? 24);
        $assignedUser = $data['assigned_user_id'] ?? null;
        if (!$assignedUser && $subject && method_exists($subject, 'getAttribute')) {
            $assignedUser = $subject->getAttribute('assigned_user_id') ?? optional($subject->lead ?? null)->assigned_user_id;
        }

        $req = ApprovalRequest::create([
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'priority' => $priority,
            'title' => $data['title'],
            'summary' => $data['summary'],
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $data['payload'] ?? null,
            'evidence' => $data['evidence'] ?? null,
            'attempted' => $data['attempted'] ?? null,
            'options' => $data['options'] ?? ['approve', 'edit_approve', 'reject'],
            'recommended_action' => $data['recommended'] ?? 'approve',
            'assigned_role' => in_array($type, self::SALES_TYPES, true) ? ($data['assigned_role'] ?? 'sales') : 'admin',
            'assigned_user_id' => $assignedUser,
            'due_at' => now()->addHours($hours),
        ]);
        $this->event($req, null, 'created', ['data_keys' => array_keys($data)]);
        $this->notify($req);

        return $req;
    }

    public function notify(ApprovalRequest $req, bool $force = false): array
    {
        $payload = [
            'title' => $req->title,
            'priority' => $req->priority,
            'what' => $req->summary,
            'why' => 'This action is set to "approval required" in the Autonomy policy (or confidence was too low). The workflow is paused until you decide.',
            'affected' => $req->subject_type ? class_basename($req->subject_type) . ' #' . $req->subject_id : 'System',
            'evidence' => $req->evidence ?? [],
            'attempted' => $req->attempted ?? [],
            'options' => array_map(fn ($o) => ucfirst(str_replace('_', ' ', $o)), (array) $req->options),
            'recommended' => ucfirst(str_replace('_', ' ', (string) $req->recommended_action)),
            'deadline' => $req->due_at?->timezone(config('leadpilot.timezone'))->format('D j M, H:i') . ' (' . config('leadpilot.timezone') . ')',
            'url' => route('approvals.show', $req),
            'role' => $req->assigned_role,
            'approval_id' => $req->id,
        ];
        $notifier = app(Notifier::class);
        $key = "approval:{$req->id}:L{$req->escalation_level}";
        $results = $req->assigned_user_id && ($u = User::find($req->assigned_user_id)) && $u->is_active
            ? [$notifier->notifyUser($u, $payload, $key, $force)]
            : $notifier->notifyRole($req->assigned_role, $payload, $key);
        $req->update(['last_notified_at' => now()]);
        $this->event($req, null, 'notified', ['results' => $results]);

        return $results;
    }

    public function canDecide(User $user, ApprovalRequest $req): bool
    {
        if (!$user->is_active) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if (!in_array($req->type, self::SALES_TYPES, true)) {
            return false;
        }
        if ($req->assigned_user_id && $req->assigned_user_id !== $user->id) {
            return false;
        }
        $lead = $this->leadOf($req);

        return !$lead || $lead->assigned_user_id === null || $lead->assigned_user_id === $user->id;
    }

    /**
     * @param array $input action-specific: payload (edit_approve), note, user_id (reassign)
     */
    public function decide(ApprovalRequest $req, User $user, string $action, array $input = []): ApprovalRequest
    {
        abort_unless(in_array($action, self::ACTIONS, true), 422, 'Unknown action.');
        abort_unless($this->canDecide($user, $req), 403, 'You are not authorised to decide this request.');

        return DB::transaction(function () use ($req, $user, $action, $input) {
            $req = ApprovalRequest::whereKey($req->id)->lockForUpdate()->first();
            $note = $input['note'] ?? null;

            if ($action === 'retry') {
                abort_unless($req->status === 'failed', 409, 'Only failed executions can be retried.');
                $this->event($req, $user, 'retried');

                return $this->execute($req, $user);
            }
            abort_unless($req->isOpen(), 409, "Request is already {$req->status}.");

            switch ($action) {
                case 'approve':
                case 'edit_approve':
                    if ($action === 'edit_approve') {
                        $req->payload = array_merge((array) $req->payload, array_intersect_key((array) ($input['payload'] ?? []), (array) $req->payload + ['category' => 1, 'subject' => 1, 'body' => 1]));
                    }
                    $req->fill(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
                    $this->event($req, $user, $action === 'approve' ? 'approved' : 'edited_approved', ['note' => $note]);
                    app(Notifier::class)->retire($req->id); // decided: the bell stops asking

                    return $this->execute($req, $user);

                case 'reject':
                    $req->fill(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
                    $this->event($req, $user, 'rejected', ['note' => $note]);
                    app(Notifier::class)->retire($req->id);
                    $this->onReject($req);
                    break;

                case 'request_info':
                    $req->update(['status' => 'info_requested', 'decision_note' => $note]);
                    $this->event($req, $user, 'info_requested', ['note' => $note]);
                    break;

                case 'reassign':
                    $target = User::where('is_active', true)->findOrFail((int) ($input['user_id'] ?? 0));
                    $req->update(['assigned_user_id' => $target->id, 'assigned_role' => $target->role]);
                    $this->event($req, $user, 'reassigned', ['to' => $target->id]);
                    $this->notify($req->fresh(), true);
                    break;

                case 'escalate':
                    $this->escalate($req, $user);
                    break;

                case 'pause':
                    abort_unless($user->isAdmin(), 403, 'Only administrators can pause a workflow.');
                    $wf = $this->workflowOf($req);
                    app(WorkflowControlService::class)->pause($wf, "Paused by {$user->name} from approval #{$req->id}" . ($note ? ": {$note}" : ''));
                    $this->event($req, $user, 'paused', ['workflow' => $wf]);
                    break;
            }

            return $req->fresh();
        });
    }

    public function escalate(ApprovalRequest $req, ?User $by = null): ApprovalRequest
    {
        $chain = (array) config('leadpilot.approvals.escalation_chain', ['sales', 'admin']);
        $level = $req->escalation_level + 1;
        $role = $chain[min($level, count($chain) - 1)] ?? 'admin';
        if ($req->assigned_role === 'admin') {
            $role = 'admin';
        }
        $hours = (int) (config('leadpilot.approvals.due_hours')[$req->priority] ?? 24);
        $req->update(['escalation_level' => $level, 'assigned_role' => $role, 'assigned_user_id' => null, 'due_at' => now()->addHours($hours),
            'priority' => $req->priority === 'low' ? 'normal' : ($req->priority === 'normal' ? 'high' : $req->priority)]);
        $this->event($req, $by, 'escalated', ['level' => $level, 'role' => $role]);
        $this->notify($req->fresh(), true);

        return $req->fresh();
    }

    /** Scheduled: escalate overdue requests (workflow stays paused) and re-notify stale ones. */
    public function processOverdue(): array
    {
        $escalated = 0;
        $renotified = 0;
        foreach (ApprovalRequest::open()->whereNotNull('due_at')->where('due_at', '<', now())->get() as $req) {
            $this->escalate($req);
            $escalated++;
        }
        $h = (int) config('leadpilot.approvals.renotify_hours', 12);
        foreach (ApprovalRequest::open()->where('last_notified_at', '<', now()->subHours($h))->get() as $req) {
            $this->notify($req, true);
            $renotified++;
        }

        return compact('escalated', 'renotified');
    }

    // ------------------------------------------------------------------ execution (resume workflow)

    protected function execute(ApprovalRequest $req, ?User $user): ApprovalRequest
    {
        try {
            $result = match ($req->type) {
                'outreach_message', 'follow_up' => $this->executeOutreach($req),
                'reply_response' => $this->executeReplyResponse($req),
                'low_confidence_reply' => $this->executeReplyClassification($req, $user),
                'spend_over_budget' => $this->executeSpend($req),
                'integration_enable' => $this->executeIntegration($req),
                'proposal_send' => $this->executeProposal($req),
                'meeting_slots' => $this->executeMeetingSlots($req),
                'paid_discovery' => $this->executePaidDiscovery($req),
                default => 'No executor for this type; decision recorded.',
            };
            $req->update(['status' => 'executed', 'execution_result' => mb_substr((string) $result, 0, 2000)]);
            $this->event($req, $user, 'executed', ['result' => $result]);
        } catch (\Throwable $e) {
            $req->update(['status' => 'failed', 'execution_result' => mb_substr($e->getMessage(), 0, 2000)]);
            $this->event($req, $user, 'failed', ['error' => $e->getMessage()]);
        }

        return $req->fresh();
    }

    protected function executeOutreach(ApprovalRequest $req): string
    {
        $rec = CampaignRecipient::findOrFail($req->subject_id);
        if (!in_array($rec->status, ['pending_approval', 'approved'], true)) {
            throw new \RuntimeException("Not sent: this message is now {$rec->status} (e.g. the prospect replied or it was cancelled).");
        }
        $p = (array) $req->payload;
        $rec->update(array_filter([
            'personalized_subject' => $p['subject'] ?? null,
            'personalized_body' => $p['body'] ?? null,
        ]) + ['status' => 'approved']);
        $res = app(SequenceEngine::class)->dispatch($rec->fresh(), true);

        return $res['sent'] ? "Sent (SMTP accepted, Message-ID {$res['message_id']})." : 'Approved — not sent yet: ' . $res['reason'];
    }

    protected function executeReplyResponse(ApprovalRequest $req): string
    {
        $reply = InboundReply::findOrFail($req->subject_id);
        $p = (array) $req->payload;
        $msgId = app(OutreachMailer::class)->sendReply($reply, (string) ($p['subject'] ?? ('Re: ' . $reply->subject)), (string) $p['body']);
        $reply->update(['is_reviewed' => true, 'reviewed_at' => now()]);

        return "Reply sent (SMTP accepted, Message-ID {$msgId}).";
    }

    protected function executeReplyClassification(ApprovalRequest $req, ?User $user): string
    {
        $reply = InboundReply::findOrFail($req->subject_id);
        $category = (string) (($req->payload['category'] ?? null) ?: $req->payload['suggested_category'] ?? 'uncertain');
        if ($category === 'uncertain') {
            // SQA bug 7: approving "uncertain" used to leave the reply unlabelled; read it again with the current rules
            $category = app(\App\Services\Sales\ReplyClassifier::class)->classify((string) $reply->body_text)['category'];
        }
        app(ReplyOutcomeService::class)->apply($reply, $category, 100, 'Confirmed by ' . ($user?->name ?? 'reviewer'));
        $reply->update(['is_reviewed' => true, 'reviewed_by' => $user?->id, 'reviewed_at' => now()]);

        return "Reply classified as {$category}; CRM updated.";
    }

    protected function executeSpend(ApprovalRequest $req): string
    {
        $p = (array) $req->payload;
        $ledger = app(\App\Services\Integrations\CostLedger::class);
        $need = (int) ($p['estimate_cents'] ?? 0);
        $limit = (int) $p['new_limit_cents'];
        SpendBudget::updateOrCreate(['scope' => 'provider', 'scope_ref' => $p['provider'], 'period' => 'monthly'], ['limit_cents' => $limit, 'is_active' => true]);
        // the approved spend must also fit the global budgets, otherwise approving would achieve nothing
        foreach (['daily', 'monthly'] as $period) {
            $current = SpendBudget::where('scope', 'global')->where('scope_ref', '*')->where('period', $period)->value('limit_cents') ?? (int) config("leadpilot.budgets.global_{$period}", 0);
            $required = $ledger->spentCents(null, $period) + $need;
            if ($current < $required) {
                SpendBudget::updateOrCreate(['scope' => 'global', 'scope_ref' => '*', 'period' => $period], ['limit_cents' => $required, 'is_active' => true]);
            }
        }
        if (!empty($p['icp_profile_id'])) {
            \App\Jobs\RunHuntJob::dispatch(\App\Models\IcpProfile::findOrFail($p['icp_profile_id']), $req->decided_by, true, (string) $p['provider'], true);
        }

        return "Budgets raised for {$p['provider']} (monthly $" . number_format($limit / 100, 2) . ') and the approved search was queued.';
    }

    protected function executeIntegration(ApprovalRequest $req): string
    {
        $slug = (string) ($req->payload['provider'] ?? '');
        Integration::where('provider', $slug)->update(['is_active' => true]);
        app(ProviderHealthService::class)->row($slug)->update(['status' => 'unknown', 'disabled_until' => null, 'consecutive_failures' => 0, 'auth_failures' => 0]);

        return "Integration {$slug} enabled (will be verified on next health check).";
    }

    protected function executeProposal(ApprovalRequest $req): string
    {
        $draft = ProposalDraft::findOrFail($req->subject_id);
        $p = (array) $req->payload;
        $draft->update(array_filter(['title' => $p['subject'] ?? null, 'body' => $p['body'] ?? null]) + ['status' => 'approved', 'approved_by' => $req->decided_by]);
        $msgId = app(OutreachMailer::class)->sendToLead($draft->lead, $draft->title, $draft->body);
        $draft->update(['status' => 'sent']);
        $draft->lead->update(['lifecycle_stage' => 'proposal_sent']);

        return "Proposal sent (SMTP accepted, Message-ID {$msgId}).";
    }

    protected function executeMeetingSlots(ApprovalRequest $req): string
    {
        $p = (array) $req->payload;
        $lead = $this->leadOf($req);
        $msgId = app(OutreachMailer::class)->sendToLead($lead, (string) $p['subject'], (string) $p['body'], $p['in_reply_to'] ?? null);

        return "Meeting times offered (SMTP accepted, Message-ID {$msgId}). No calendar event is created until the prospect confirms.";
    }

    protected function executePaidDiscovery(ApprovalRequest $req): string
    {
        $p = (array) $req->payload;
        $icp = \App\Models\IcpProfile::findOrFail($p['icp_profile_id']);
        \App\Jobs\RunHuntJob::dispatch($icp, $req->decided_by, true, (string) $p['provider'], true);

        return "Paid search on {$p['provider']} queued (estimated $" . number_format(($p['estimate_cents'] ?? 0) / 100, 2) . ').';
    }

    protected function onReject(ApprovalRequest $req): void
    {
        if (in_array($req->type, ['outreach_message', 'follow_up'], true)) {
            CampaignRecipient::whereKey($req->subject_id)->update(['status' => 'rejected', 'error_message' => 'Rejected by reviewer: ' . ($req->decision_note ?? '')]);
        }
        if ($req->type === 'proposal_send') {
            ProposalDraft::whereKey($req->subject_id)->update(['status' => 'rejected']);
        }
    }

    protected function workflowOf(ApprovalRequest $req): string
    {
        return match ($req->type) {
            'outreach_message' => 'outreach',
            'follow_up' => 'follow_ups',
            'reply_response', 'low_confidence_reply', 'meeting_slots' => 'reply_sync',
            'spend_over_budget', 'integration_enable' => 'discovery',
            default => 'outreach',
        };
    }

    public function leadOf(ApprovalRequest $req): ?\App\Models\Lead
    {
        $s = $req->subject;

        return match (true) {
            $s instanceof \App\Models\Lead => $s,
            $s && isset($s->lead_id) => \App\Models\Lead::find($s->lead_id),
            default => null,
        };
    }

    protected function event(ApprovalRequest $req, ?User $user, string $event, array $data = []): void
    {
        ApprovalEvent::create(['approval_request_id' => $req->id, 'user_id' => $user?->id, 'event' => $event, 'data' => $data, 'created_at' => now()]);
    }
}
