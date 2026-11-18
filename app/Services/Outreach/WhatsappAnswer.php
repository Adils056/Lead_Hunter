<?php

namespace App\Services\Outreach;

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappReply;

/**
 * Answering someone who replied on WhatsApp, from the conversation screen: a ready answer that fits what they asked
 * (price, more details, a call), with the booking link when there is one, which the salesperson reads, edits and
 * sends with one click. Seen live: four businesses answered on WhatsApp and waited a week, because answering meant
 * opening WhatsApp on a phone and writing from scratch.
 * Nothing here is automatic: a person presses Send every time.
 */
class WhatsappAnswer
{
    public function __construct(protected WhatsappGateway $gateway)
    {
    }

    /** Every message we sent to this business: the first one and any to a number they gave us. */
    public function thread(WhatsappMessage $message): \Illuminate\Support\Collection
    {
        return WhatsappMessage::where(fn ($w) => $message->company_id ? $w->where('company_id', $message->company_id) : $w->whereKey($message->id))->get();
    }

    /** The latest message a person (not a machine) sent us in this conversation, or null. */
    public function lastRealReply(WhatsappMessage $message): ?WhatsappInboundMessage
    {
        return WhatsappInboundMessage::whereIn('whatsapp_message_id', $this->thread($message)->pluck('id'))
            ->where('action', 'reply')->latest('id')->first();
    }

    /** Why an answer cannot be sent from here, or null when it can. */
    public function blockReason(WhatsappMessage $message): ?string
    {
        $reply = $this->lastRealReply($message);

        return match (true) {
            !$reply => 'Only people who replied can be answered from here.',
            WhatsappInbox::optedOut($reply->from_number ?: $message->to_number) => 'They asked us to stop. Do not message them again.',
            !$this->gateway->isConfigured() => 'The WhatsApp gateway keys are missing (EZIMK_APPKEY, EZIMK_AUTHKEY).',
            default => null,
        };
    }

    /** A short answer that fits what they wrote. The salesperson edits it before sending. */
    public function draft(WhatsappMessage $message): string
    {
        $lead = $message->lead;
        $company = $message->company?->name ?? $lead?->company?->name;
        $said = mb_strtolower((string) $this->lastRealReply($message)?->body);
        $first = $lead && $lead->contact_status === 'identified' ? trim((string) $lead->first_name) : '';

        $lines = [($first !== '' ? "Hi {$first}, " : 'Hi, ') . 'thank you for your reply!'];
        if (preg_match('/\b(price|pricing|prices|cost|costs|charges|rates|quote|quotation|budget|how much|fee|fees)\b/u', $said)) {
            $lines[] = 'The price depends on what ' . ($company ?: 'you') . ' needs. After a short call about your website I can send you an exact quote the same day.';
        } elseif (preg_match('/\b(details|detail|more info|information|explain|portfolio|samples?|examples?|what do you (do|offer))\b/u', $said)) {
            $lines[] = 'Happy to explain. The report I sent shows the top fixes for ' . ($company ? "{$company}'s" : 'your') . ' website, and I can show you examples of similar work we have done.';
        } elseif (preg_match('/\b(call|phone|ring|talk|speak|contact me|meet|meeting)\b/u', $said)) {
            $lines[] = 'Sure, I would be glad to talk.';
        } else {
            $lines[] = 'I would be glad to walk you through the website report' . ($company ? " for {$company}" : '') . '. It takes about 10 minutes.';
        }
        $booking = (string) config('leadpilot.agency.booking_url');
        $lines[] = $booking !== ''
            ? "You can pick a time that suits you here: {$booking}"
            : 'Would a short call today or tomorrow work for you? Just tell me a time and I will call you on this number.';
        $lines[] = '— ' . config('leadpilot.agency.sender_name') . ', ' . config('leadpilot.agency.name');

        return implode("\n\n", $lines);
    }

    /** Send the salesperson's answer. Returns [ok, message for the screen]. */
    public function send(WhatsappMessage $message, string $body, ?User $by): array
    {
        if ($why = $this->blockReason($message)) {
            return [false, $why];
        }
        $to = preg_replace('/\D/', '', (string) ($this->lastRealReply($message)->from_number ?: $message->to_number));
        $body = trim($body);
        // a double click, or the same answer sent twice by two people
        if (WhatsappReply::where('to_number', $to)->where('body', $body)->where('status', 'sent')->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            return [false, 'This answer was already sent a moment ago.'];
        }

        $res = $this->gateway->send($to, $body);
        $reply = WhatsappReply::create([
            'whatsapp_message_id' => $message->id, 'lead_id' => $message->lead_id, 'user_id' => $by?->id, 'to_number' => $to, 'body' => $body,
            'status' => $res['ok'] ? 'sent' : 'failed', 'provider_response' => $res['response'], 'sent_at' => $res['ok'] ? now() : null,
        ]);
        AuditLog::record('whatsapp_answer', $message->lead ?? $message, null, ['to' => $to, 'status' => $reply->status, 'reply_id' => $reply->id]);
        if (!$res['ok']) {
            return [false, 'Not sent: the WhatsApp gateway refused it (' . mb_substr((string) $res['response'], 0, 120) . '). Try again, or answer from the phone.'];
        }
        // answered: the "answer now" task is done, so it leaves the morning list and is not escalated
        if ($message->lead_id) {
            Task::where('lead_id', $message->lead_id)->where('status', 'pending')->where('title', 'like', '%replied on whatsapp%')
                ->update(['status' => 'completed', 'completed_at' => now()]);
            $message->lead?->update(['last_contacted_at' => now()]);
        }

        return [true, 'Answer sent on WhatsApp to +' . $to . '.'];
    }
}
