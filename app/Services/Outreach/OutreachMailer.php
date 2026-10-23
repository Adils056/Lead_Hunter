<?php

namespace App\Services\Outreach;

use App\Models\InboundReply;
use App\Models\Lead;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The only path outbound prospect email takes.
 *  - Generates a real RFC 5322 Message-ID (recorded, used for reply threading + matching).
 *  - Adds RFC 8058 one-click unsubscribe headers (Gmail/Yahoo bulk-sender requirement) + a visible link.
 *  - Threads follow-ups with In-Reply-To / References.
 * Return value = Message-ID. "Sent" means the SMTP server accepted it — not that it reached the inbox.
 */
class OutreachMailer
{
    public static function unsubscribeToken(string $email): string
    {
        return md5(strtolower(trim($email)) . config('app.key')); // compatible with links already sent by v1-v3
    }

    public static function unsubscribeUrl(string $email): string
    {
        return route('campaigns.unsubscribe', ['email' => strtolower(trim($email)), 'token' => self::unsubscribeToken($email)]);
    }

    public function newMessageId(): string
    {
        $from = (string) config('mail.from.address', 'leadpilot@localhost');
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

        return Str::uuid()->toString() . '@' . $domain;
    }

    public function send(string $to, string $toName, string $subject, string $body, ?string $inReplyTo = null, ?string $references = null): string
    {
        $messageId = $this->newMessageId();
        $unsub = self::unsubscribeUrl($to);
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('leadpilot.agency.sender_name') ?: config('mail.from.name'));
        $replyTo = \App\Models\OutreachSetting::current()->reply_to_email ?: $fromAddress;
        if (!str_contains($body, $unsub)) {
            $body = rtrim($body) . "\n\n--\nNot relevant? Unsubscribe: {$unsub}";
        }

        Mail::raw($body, function ($msg) use ($to, $toName, $subject, $messageId, $unsub, $fromAddress, $fromName, $replyTo, $inReplyTo, $references) {
            $msg->to($to, $toName ?: null)->subject($subject)->from($fromAddress, $fromName)->replyTo($replyTo);
            $h = $msg->getSymfonyMessage()->getHeaders();
            $h->remove('Message-ID');
            $h->addIdHeader('Message-ID', $messageId);
            $h->addTextHeader('List-Unsubscribe', "<{$unsub}>, <mailto:{$replyTo}?subject=unsubscribe>");
            $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            if ($inReplyTo) {
                $h->addIdHeader('In-Reply-To', $inReplyTo);
                $h->addIdHeader('References', array_values(array_filter(array_unique(array_merge(preg_split('/\s+/', trim((string) $references, " <>")) ?: [], [$inReplyTo])))));
            }
        });

        return $messageId;
    }

    public function sendToLead(Lead $lead, string $subject, string $body, ?string $inReplyTo = null): string
    {
        abort_if(!$lead->email, 422, 'Lead has no email.');
        if ($why = OutreachGuard::blockReason('outreach', null, $lead->email)) {
            throw new \RuntimeException("Not sent: {$why}");
        }
        abort_if(\App\Models\Suppression::isSuppressed($lead->email, $lead->company?->domain), 422, 'Recipient is suppressed.');

        return $this->send($lead->email, $lead->contact_status === 'identified' ? (string) $lead->full_name : '', $subject, $body, $inReplyTo);
    }

    public function sendReply(InboundReply $reply, string $subject, string $body): string
    {
        abort_if(\App\Models\Suppression::isSuppressed($reply->from_email), 422, 'Recipient is suppressed.');
        if ($why = OutreachGuard::blockReason('reply_sync', null, $reply->from_email)) {
            throw new \RuntimeException("Not sent: {$why}");
        }

        return $this->send($reply->from_email, '', $subject, $body, $reply->message_id);
    }
}
