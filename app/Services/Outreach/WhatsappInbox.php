<?php

namespace App\Services\Outreach;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Services\Sales\EngagementAlerts;
use App\Services\Sales\ReplyOutcomeService;
use Illuminate\Support\Arr;

/**
 * Messages prospects send back on WhatsApp (EZIMK posts each incoming message to our webhook).
 * STOP (or "unsubscribe", "remove me", "band karo"...) is honoured at once: the business is never messaged again,
 * on WhatsApp or by e-mail. Any other answer is a reply: follow-ups stop, the lead moves to "replied", and the
 * salesperson gets an instant alert. Only numbers we messaged are acted on; everything is logged with its raw payload,
 * so a payload shape we do not read yet is visible and nothing is lost.
 */
class WhatsappInbox
{
    protected const OPT_OUT = '/\b(stop|unsubscribe|opt[\s-]?out|remove me|do not (message|contact)|don\'?t (message|contact)|band karo|mat bhejo|mat bhejain)\b/i';

    /** The secret part of the webhook URL (WHATSAPP_WEBHOOK_TOKEN, else derived from APP_KEY so no setup is needed). */
    public static function token(): string
    {
        return (string) (config('leadpilot.whatsapp.webhook_token') ?: substr(hash_hmac('sha256', 'ezimk-inbound-webhook', (string) config('app.key')), 0, 40));
    }

    public static function webhookUrl(): string
    {
        return route('webhooks.whatsapp', self::token());
    }

    public static function isOptOut(?string $text): bool
    {
        $text = trim((string) $text);

        return $text !== '' && mb_strlen($text) <= 80 && preg_match(self::OPT_OUT, $text) === 1;
    }

    /** Whether this number has asked us to stop. */
    public static function optedOut(string $number): bool
    {
        $tail = substr(preg_replace('/\D/', '', $number), -10);

        return $tail !== '' && WhatsappInboundMessage::where('action', 'opt_out')->where('from_number', 'like', "%{$tail}")->exists();
    }

    /**
     * Sender, text, provider id and whether we sent it ourselves, from a gateway payload whose exact shape varies
     * (flat form fields, or JSON nested under data/message; numbers as 923..., +923... or 923...@s.whatsapp.net).
     */
    public function parse(array $payload): array
    {
        $flat = Arr::dot($payload);
        $byName = [];
        foreach ($flat as $key => $value) {
            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                $byName[strtolower((string) last(explode('.', (string) $key)))] ??= (string) $value;
            }
        }
        // names in order of preference, so a field like "number" never wins over "from"
        $pick = function (array $names) use ($byName) {
            foreach ($names as $n) {
                if (isset($byName[$n])) {
                    return $byName[$n];
                }
            }

            return null;
        };
        $rawFrom = $pick(['from', 'sender', 'remotejid', 'remote_jid', 'chatid', 'chat_id', 'wa_id', 'waid', 'author', 'participant', 'msisdn', 'phone', 'number', 'mobile']);
        $from = $rawFrom ? preg_replace('/\D/', '', explode(':', explode('@', $rawFrom)[0])[0]) : null;
        $fromMe = collect($flat)->first(fn ($v, $k) => in_array(strtolower((string) last(explode('.', (string) $k))), ['fromme', 'from_me'], true));
        // a message id: the named fields, or a plain "id" only where it is the message's own
        $id = $pick(['message_id', 'messageid', 'msg_id', 'msgid', 'wamid']);
        foreach (['id', 'data.id', 'message.id', 'key.id', 'data.key.id', 'data.message.id'] as $k) {
            $id ??= isset($flat[$k]) && (is_string($flat[$k]) || is_int($flat[$k])) ? (string) $flat[$k] : null;
        }

        return [
            'from' => $from !== null && strlen($from) >= 8 && strlen($from) <= 15 ? $from : null,
            'body' => $pick(['message', 'body', 'text', 'msg', 'content', 'conversation', 'caption']),
            'id' => $id,
            'from_me' => in_array($fromMe, [true, 1, '1', 'true'], true),
        ];
    }

    public function handle(array $payload): WhatsappInboundMessage
    {
        $m = $this->parse($payload);
        if ($m['id'] && ($seen = WhatsappInboundMessage::where('provider_message_id', $m['id'])->where('from_number', $m['from'])
            ->where('body', $m['body'] !== null ? mb_substr($m['body'], 0, 5000) : null)->first())) {
            return $seen; // the gateway retried the same message
        }
        $raw = mb_substr((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 20000);
        $sent = null;
        if ($m['from'] && !$m['from_me']) {
            $tail = substr($m['from'], -10);
            $sent = WhatsappMessage::where('status', 'sent')->where('to_number', 'like', "%{$tail}")->latest('id')->first();
        }
        $action = match (true) {
            $m['from_me'] || !$m['from'] => 'ignored',
            !$sent => 'unmatched',
            self::isOptOut($m['body']) => 'opt_out',
            default => 'reply',
        };
        $in = WhatsappInboundMessage::create(['provider_message_id' => $m['id'], 'from_number' => $m['from'], 'body' => $m['body'] !== null ? mb_substr($m['body'], 0, 5000) : null,
            'lead_id' => $sent?->lead_id, 'whatsapp_message_id' => $sent?->id, 'action' => $action, 'payload' => $raw]);

        $lead = $sent?->lead;
        if ($action === 'opt_out') {
            $this->optOut($sent, $in);
        } elseif ($action === 'reply' && $lead) {
            app(ReplyOutcomeService::class)->stopSequences($lead, 'Replied on WhatsApp');
            $lead->moveStage('replied');
            AuditLog::record('whatsapp_reply', $lead, null, ['from' => $m['from'], 'inbound_id' => $in->id]);
            app(EngagementAlerts::class)->whatsappReplied($lead, (string) $m['body'], $in);
        }

        return $in;
    }

    /** The business said stop: no more WhatsApp, no e-mail to anyone there, follow-ups cancelled. */
    protected function optOut(WhatsappMessage $sent, WhatsappInboundMessage $in): void
    {
        $leads = $sent->company_id ? Lead::where('company_id', $sent->company_id)->get() : collect([$sent->lead])->filter();
        foreach ($leads as $lead) {
            if ($lead->email) {
                Suppression::addSuppression($lead->email, 'unsubscribe', 'whatsapp_stop');
            }
            app(ReplyOutcomeService::class)->stopSequences($lead, 'Asked us to stop on WhatsApp');
            $lead->moveStage('opted_out');
        }
        if ($sent->lead) {
            AuditLog::record('whatsapp_opt_out', $sent->lead, null, ['from' => $in->from_number, 'inbound_id' => $in->id]);
        }
    }
}
