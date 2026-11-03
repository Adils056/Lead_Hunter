<?php

namespace App\Services\Sales;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\WebsiteReport;
use App\Services\Autopilot\Notifier;

/**
 * Moments when a prospect is paying attention, sent to the salesperson at once: a reply, or the prospect opening
 * the website report we sent them. Speed matters most here, so these go out on the instant channels too
 * (WhatsApp / SMS / webhook when configured), not only by e-mail and in the app.
 */
class EngagementAlerts
{
    /** Categories worth interrupting someone for. Auto-replies, unsubscribes and plain "no" are not. */
    public const ALERT_REPLY = ['interested', 'request_info', 'objection', 'referral', 'uncertain'];

    public function replyReceived(InboundReply $reply, string $category): void
    {
        $lead = $reply->lead;
        if (!$lead || !in_array($category, self::ALERT_REPLY, true)) {
            return;
        }
        $who = $this->who($lead);
        $this->notifyOwner($lead, [
            'title' => "Reply from {$who}: answer now",
            'priority' => 'high',
            'what' => 'They wrote: "' . mb_substr(trim(preg_replace('/\s+/', ' ', (string) $reply->body_text)), 0, 280) . '"',
            'why' => 'Replies answered within the hour turn into meetings far more often than ones answered the next day.',
            'affected' => $who,
            'recommended' => 'Open the Sales Assistant and reply',
            'url' => route('assistant.show', $lead),
            'short' => "LeadPilot: {$who} replied (" . str_replace('_', ' ', $category) . '). Answer now: ' . route('assistant.show', $lead),
        ], "reply-alert:{$reply->id}");
    }

    /** A person (not a link scanner) opened the report for the first time. */
    public function reportViewed(WebsiteReport $report): void
    {
        $company = $report->company;
        if (!$company) {
            return;
        }
        $emailed = CampaignRecipient::whereNotNull('sent_at')->whereHas('lead', fn ($q) => $q->where('company_id', $company->id))->latest('sent_at')->first();
        $lead = $emailed?->lead ?? Lead::where('company_id', $company->id)->orderByDesc('overall_score')->first();
        if (!$lead) {
            return;
        }
        $who = $this->who($lead);
        $this->task($lead, 'Opened their website report: follow up today');
        $this->notifyOwner($lead, [
            'title' => "{$who} is reading their website report",
            'priority' => 'high',
            'what' => 'The prospect opened the free website & competitor report from our e-mail just now.',
            'why' => 'This is the moment they are thinking about their website. A short, helpful follow-up (or a call) today is most likely to get an answer.',
            'affected' => $who,
            'recommended' => 'Follow up today: offer to walk them through the top 3 fixes',
            'url' => route('assistant.show', $lead),
            'short' => "LeadPilot: {$who} just opened their website report. Follow up today: " . route('assistant.show', $lead),
        ], "report-viewed:{$report->share_token}");
    }

    protected function notifyOwner(Lead $lead, array $payload, string $key): void
    {
        $users = $lead->assigned_user_id
            ? User::whereKey($lead->assigned_user_id)->where('is_active', true)->get()
            : collect();
        if ($users->isEmpty()) {
            $users = User::where('is_active', true)->where('role', 'admin')->get();
        }
        foreach ($users as $u) {
            app(Notifier::class)->notifyUser($u, $payload + ['urgent' => true], $key);
        }
    }

    protected function task(Lead $lead, string $title): void
    {
        $assignee = $lead->assigned_user_id ?? User::where('role', 'admin')->where('is_active', true)->value('id');
        if ($assignee) {
            Task::firstOrCreate(['lead_id' => $lead->id, 'title' => $title, 'status' => 'pending'], [
                'company_id' => $lead->company_id, 'assigned_to' => $assignee, 'due_date' => now()->toDateString(), 'priority' => 'high',
                'description' => 'Created by LeadPilot: the prospect is engaging right now.',
            ]);
        }
    }

    protected function who(Lead $lead): string
    {
        $company = $lead->company?->name;
        $person = $lead->contact_status === 'identified' ? trim($lead->first_name . ' ' . $lead->last_name) : ($lead->attention_name ?: '');

        return $person !== '' ? ($company ? "{$person} ({$company})" : $person) : ($company ?: (string) $lead->email);
    }
}
