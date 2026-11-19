<?php

namespace App\Services\Sales;

use App\Models\ApprovalRequest;
use App\Models\AutoReply;
use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappReply;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Outreach\OutreachMailer;
use App\Services\Outreach\SequenceEngine;
use App\Services\Outreach\WhatsappAnswer;
use App\Services\Outreach\WhatsappInbox;
use App\Support\BookingLink;
use Illuminate\Support\Str;

/**
 * Answers prospects who replied, by e-mail or WhatsApp, without waiting for a person: the AI (free Gemini first, see
 * LlmClient) reads what they wrote and writes a short answer that responds to it and offers a 15-minute call with the
 * booking link. Seen live: replies waited one to three weeks for a person, and no meeting was booked.
 *
 * Safety:
 *  - on only when the autonomy policy "reply.auto_respond" is automatic (Autopilot → Autonomy policy);
 *  - never to a STOP, an unsubscribe, a "not interested", an out-of-office or a machine's greeting;
 *  - the AI hands a message to a person (task + the morning list) when it is a complaint, angry, legal, or asks for a
 *    commitment only a person can make; that message is not answered automatically;
 *  - the answer never states a price, number, date, discount or guarantee, and holds no link but ours: an answer that
 *    breaks this is thrown away and a fixed safe answer is sent instead;
 *  - at most `max_per_lead` automatic answers per lead, at least `min_gap_minutes` apart, and none once a person has
 *    answered or a meeting is booked; WhatsApp only in the prospect's working hours;
 *  - e-mail addresses and phone numbers are masked before the text goes to the AI (the free tier may keep inputs).
 */
class AutoReplier
{
    public const ANSWERABLE = ['interested', 'request_info', 'objection', 'referral', 'uncertain'];

    public const CLOSED_STAGES = ['meeting_booked', 'proposal_sent', 'negotiation', 'won', 'lost', 'not_a_fit', 'opted_out'];

    public function __construct(protected LlmClient $llm, protected AutonomyPolicyService $policy, protected WhatsappAnswer $whatsapp)
    {
    }

    public function enabled(): bool
    {
        return $this->policy->isAutomatic('reply.auto_respond');
    }

    /** @return array{sent: array, held: array, skipped: array, failed: array, waiting: int, disabled?: string} */
    public function run(bool $dry = false, int $limit = 5): array
    {
        $out = ['sent' => [], 'held' => [], 'skipped' => [], 'failed' => [], 'waiting' => 0];
        if (!$dry && !$this->enabled()) {
            return $out + ['disabled' => 'Automatic answers are off: set "Answer prospect replies without review" (reply.auto_respond) to Automatic in Autopilot → Autonomy policy.'];
        }
        $cfg = (array) config('leadpilot.auto_reply', []);
        $ready = now()->subMinutes((int) ($cfg['delay_minutes'] ?? 3));
        $waReady = now()->subMinutes((int) ($cfg['whatsapp_delay_minutes'] ?? 10)); // time for someone to answer from the phone first
        $since = now()->subDays((int) ($cfg['lookback_days'] ?? 30));
        $done = 0;

        // e-mail replies
        $emails = InboundReply::with('lead.company')->whereIn('category', self::ANSWERABLE)->whereNotNull('lead_id')
            ->whereBetween('received_at', [$since, $ready])
            ->whereNotExists(fn ($q) => $this->handled($q, 'email', 'inbound_replies.id'))
            ->oldest('received_at')->limit(50)->get();
        foreach ($emails as $reply) {
            if ($done >= $limit) {
                $out['waiting']++;
                continue;
            }
            if ($r = $this->email($reply, $dry)) {
                $out[$r[0]][] = $r[1];
                $done += $r[0] !== 'skipped' ? 1 : 0;
            }
        }

        // WhatsApp replies from people (machines' greetings, wrong numbers and STOPs are labelled otherwise and left out)
        $wa = WhatsappInboundMessage::with('lead.company')->where('action', 'reply')->whereNotNull('whatsapp_message_id')
            ->whereBetween('created_at', [$since, $waReady])
            ->whereNotExists(fn ($q) => $this->handled($q, 'whatsapp', 'whatsapp_inbound_messages.id'))
            ->oldest('id')->limit(50)->get();
        foreach ($wa as $in) {
            if ($done >= $limit) {
                $out['waiting']++;
                continue;
            }
            if ($r = $this->whatsappReply($in, $dry)) {
                $out[$r[0]][] = $r[1];
                $done += $r[0] !== 'skipped' ? 1 : 0;
            }
        }

        return $out;
    }

    /** Messages already dealt with: answered, held or skipped for good, or failed just now (tried again later, a few times). */
    protected function handled($q, string $channel, string $column)
    {
        return $q->selectRaw('1')->from('auto_replies')->where('auto_replies.channel', $channel)->whereColumn('auto_replies.source_id', $column)
            ->where(fn ($w) => $w->where('auto_replies.status', '!=', 'failed')
                ->orWhere('auto_replies.attempts', '>=', self::MAX_ATTEMPTS)
                ->orWhere('auto_replies.updated_at', '>', now()->subMinutes(20)));
    }

    public const MAX_ATTEMPTS = 3;

    /** EZIMK's own error for a contact it cannot send to (a chat using a WhatsApp privacy id): the same on every try. */
    public const GATEWAY_CANNOT_REACH = '/array offset on value of type bool/i';

    /** @return array{0: string, 1: string}|null [sent|held|skipped, line] or null to look again later */
    protected function email(InboundReply $reply, bool $dry): ?array
    {
        $lead = $reply->lead;
        $who = $this->who($lead);
        $answered = EmailLog::where('lead_id', $lead->id)->where('direction', 'outbound')->where('occurred_at', '>', $reply->received_at)->exists()
            || CampaignRecipient::where('lead_id', $lead->id)->where('sent_at', '>', $reply->received_at)->exists();
        if ($why = $this->notForUs($lead, $answered, $reply->from_email)) {
            return $this->record('email', $reply->id, $lead, 'skipped', $why, $dry, $who);
        }
        if ($this->tooSoon($lead)) {
            return null;
        }
        // a later reply from the same person is answered instead, with this one read as part of it
        $later = InboundReply::where('lead_id', $lead->id)->whereIn('category', self::ANSWERABLE)->where('received_at', '>', $reply->received_at)->exists();
        if ($later) {
            return $this->record('email', $reply->id, $lead, 'skipped', 'answered together with their later reply', $dry, $who);
        }

        $ours = CampaignRecipient::where('lead_id', $lead->id)->whereNotNull('sent_at')->latest('sent_at')->value('personalized_body');
        $theirs = InboundReply::where('lead_id', $lead->id)->whereIn('category', self::ANSWERABLE)->where('received_at', '>=', now()->subDays(30))
            ->oldest('received_at')->get()->map(fn ($r) => $this->clean((string) $r->body_text))->filter()->implode("\n---\n");
        if ($theirs === '') {
            return $this->hold('email', $reply->id, $lead, ['action' => 'human', 'reason' => 'no text of theirs to read (only quoted history or an attachment)', 'writer' => 'rules'], $dry, $who);
        }
        $c = $this->compose('email', $lead, $theirs, (string) $ours, $reply->received_at);
        if ($c['action'] !== 'reply') {
            return $this->hold('email', $reply->id, $lead, $c, $dry, $who);
        }
        if ($dry) {
            return ['sent', "{$who} (e-mail, {$c['writer']}" . ($c['writer'] === 'template' ? ", {$c['reason']}" : '') . '): ' . Str::limit(str_replace("\n", ' ', $c['reply']), 400)];
        }

        try {
            $subject = 'Re: ' . preg_replace('/^(re:\s*)+/i', '', (string) $reply->subject);
            $msgId = app(OutreachMailer::class)->sendReply($reply, $subject, $c['reply']);
        } catch (\Throwable $e) {
            return $this->record('email', $reply->id, $lead, 'failed', 'not sent: ' . mb_substr($e->getMessage(), 0, 200), false, $who);
        }
        OutreachMailer::logOutbound($lead->id, $reply->from_email, $subject, $c['reply'], $msgId, ['auto_reply' => true, 'writer' => $c['writer']]);
        $reply->update(['is_reviewed' => true, 'reviewed_at' => now()]);
        // the drafts waiting for approval for this reply are now answered: never send a second answer
        foreach (ApprovalRequest::where('status', 'pending')->whereIn('dedupe_key', ["reply-response:{$reply->id}", "meeting:{$reply->id}"])->get() as $req) {
            $req->update(['status' => 'cancelled', 'execution_result' => 'Answered automatically by LeadPilot.']);
        }
        $this->closeTasks($lead);
        $this->record('email', $reply->id, $lead, 'sent', $c['writer'] === 'template' ? $c['reason'] : null, false, $who, $c);

        return ['sent', "{$who} (e-mail)"];
    }

    /** @return array{0: string, 1: string}|null */
    protected function whatsappReply(WhatsappInboundMessage $in, bool $dry): ?array
    {
        $msg = WhatsappMessage::with('lead.company', 'company')->find($in->whatsapp_message_id);
        $lead = $msg?->lead;
        $who = $lead ? $this->who($lead) : '+' . $in->from_number;
        if (!$msg || !$lead) {
            return $this->record('whatsapp', $in->id, $lead, 'skipped', 'no lead for this conversation', $dry, $who);
        }
        $thread = $this->whatsapp->thread($msg)->pluck('id');
        $answered = WhatsappReply::whereIn('whatsapp_message_id', $thread)->where('status', 'sent')->where('created_at', '>', $in->created_at)->exists();
        if ($why = $this->notForUs($lead, $answered, null) ?? (WhatsappInbox::optedOut($in->from_number ?: $msg->to_number) ? 'they asked us to stop' : null)) {
            return $this->record('whatsapp', $in->id, $lead, 'skipped', $why, $dry, $who);
        }
        if ($this->tooSoon($lead) || !app(SequenceEngine::class)->inSendingWindow(null, $lead)) {
            return null; // later: too soon after our last answer, or outside their working hours
        }
        // several messages in a row get one answer, written after the last of them
        if (WhatsappInboundMessage::whereIn('whatsapp_message_id', $thread)->where('action', 'reply')->where('id', '>', $in->id)->exists()) {
            return $this->record('whatsapp', $in->id, $lead, 'skipped', 'answered together with their later message', $dry, $who);
        }

        $lastOurs = WhatsappReply::whereIn('whatsapp_message_id', $thread)->where('status', 'sent')->max('created_at');
        $theirs = WhatsappInboundMessage::whereIn('whatsapp_message_id', $thread)->where('action', 'reply')
            ->when($lastOurs, fn ($q) => $q->where('created_at', '>', $lastOurs))->oldest('id')->pluck('body')
            ->map(fn ($b) => $this->clean((string) $b))->filter()->implode("\n");
        if ($theirs === '') {
            return $this->hold('whatsapp', $in->id, $lead, ['action' => 'human', 'reason' => 'a photo, voice note or file (no text to read)', 'writer' => 'rules'], $dry, $who);
        }
        $c = $this->compose('whatsapp', $lead, $theirs, (string) $msg->body, $in->created_at);
        if ($c['action'] !== 'reply') {
            return $this->hold('whatsapp', $in->id, $lead, $c, $dry, $who);
        }
        if ($dry) {
            return ['sent', "{$who} (WhatsApp, {$c['writer']}" . ($c['writer'] === 'template' ? ", {$c['reason']}" : '') . '): ' . Str::limit(str_replace("\n", ' ', $c['reply']), 400)];
        }
        [$ok, $text] = $this->whatsapp->send($msg, $c['reply'], null);
        if (!$ok && preg_match(self::GATEWAY_CANNOT_REACH, $text)) {
            // seen live (19 Nov): the contact chats with a WhatsApp privacy id ("@lid"); EZIMK cannot send to it and fails
            // the same way every time, so retrying only delays the answer. The phone's WhatsApp can.
            return $this->hold('whatsapp', $in->id, $lead, ['action' => 'human', 'reason' => 'the WhatsApp gateway cannot send to this contact (WhatsApp privacy id); answer from the phone. Ready answer: ' . $c['reply']], false, $who);
        }
        if (!$ok) {
            return $this->record('whatsapp', $in->id, $lead, 'failed', mb_substr($text, 0, 200), false, $who);
        }
        $this->closeTasks($lead);
        $this->record('whatsapp', $in->id, $lead, 'sent', $c['writer'] === 'template' ? $c['reason'] : null, false, $who, $c);

        return ['sent', "{$who} (WhatsApp)"];
    }

    /** Why this lead must not get an automatic answer at all, or null. */
    protected function notForUs(Lead $lead, bool $answered, ?string $email): ?string
    {
        return match (true) {
            $answered => 'a person already answered',
            in_array($lead->lifecycle_stage, self::CLOSED_STAGES, true) => 'lead is ' . str_replace('_', ' ', (string) $lead->lifecycle_stage),
            $lead->pipeline_state === 'suppressed' => 'lead is suppressed',
            $email !== null && \App\Models\Suppression::isSuppressed($email) => 'address is suppressed',
            AutoReply::where('lead_id', $lead->id)->where('status', 'sent')->count() >= (int) config('leadpilot.auto_reply.max_per_lead', 3) => 'automatic answers used up for this lead; a person continues',
            default => null,
        };
    }

    protected function tooSoon(Lead $lead): bool
    {
        $last = AutoReply::where('lead_id', $lead->id)->where('status', 'sent')->latest('id')->value('created_at');

        return $last && \Carbon\Carbon::parse($last)->gt(now()->subMinutes((int) config('leadpilot.auto_reply.min_gap_minutes', 30)));
    }

    /**
     * The AI reads their message and writes the answer, or says a person should answer. A broken answer (a price, a
     * number, a foreign link, too long) falls back to the fixed safe answer.
     *
     * @return array{action: string, reply?: string, reason: string, writer: string}
     */
    public function compose(string $channel, Lead $lead, string $theirs, string $ours, \DateTimeInterface $receivedAt): array
    {
        $company = $lead->company?->name ?: 'your business';
        $link = BookingLink::for($lead, $channel) ?: '';
        $sender = (string) config('leadpilot.agency.sender_name');
        $agency = (string) config('leadpilot.agency.name');
        $late = \Carbon\Carbon::instance($receivedAt)->lt(now()->subDays(2));
        $words = $channel === 'whatsapp' ? 60 : 90;
        $facts = collect(app(ConversionAssistantService::class)->facts($lead))->take(3)->pluck('text')->implode('; ');

        $system = "You answer a small-business owner who replied to a short message from {$sender} at {$agency}, a web design and digital marketing agency. "
            . "We sent them a free report about their website. Decide what to do and write the answer {$sender} sends.\n"
            . "Return JSON: action = \"reply\", \"human\" or \"none\"; reply = the answer text (empty unless action is reply); reason = a few words why.\n"
            . "action \"none\": they said no, not interested, stop, unsubscribe, or the message was written by a machine (an automatic greeting, an away notice).\n"
            . "action \"human\": they complain, are angry or rude, mention a lawyer or legal action, ask us to sign or commit to something, or the message cannot be understood.\n"
            . "Otherwise action \"reply\". The answer:\n"
            . "- answers what they actually wrote, in the language they wrote in, warm and plain, at most {$words} words;\n"
            . "- uses only the facts given; never invents services, clients, results or anything about them; mentions a website fix only if it is in the report findings below, word for word in meaning;\n"
            . "- never promises to send, e-mail, prepare or do anything later: the only next step is the call;\n"
            . "- never writes a price, cost, number, percentage, date, deadline, discount or guarantee; if they ask about price, say it depends on what they need and that a 15-minute call is enough to give an exact quote the same day;\n"
            . ($link !== '' ? "- ends by inviting them to pick a time for a 15-minute call here: {$link} (write the link exactly once, unchanged);\n"
                : "- ends by asking when suits them for a 15-minute call;\n")
            . "- contains no other link, no placeholders in brackets, no subject line;\n"
            . ($late ? "- right after the greeting, says sorry for the slow answer in one short sentence;\n" : '')
            . "- " . ($channel === 'email' ? "starts with a greeting and ends with the signature \"{$sender}\"." : "is a WhatsApp message: no signature block, at most one short greeting.");
        $user = "Business: {$company}\nWhat our report found on their website: " . ($facts ?: 'not available') . "\n\n"
            . "Our message to them:\n" . Str::limit($this->clean($ours), 900) . "\n\nTheir reply:\n" . Str::limit($theirs, 1500);
        $schema = ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['reply', 'human', 'none']],
            'reply' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['action', 'reply', 'reason'], 'additionalProperties' => false];

        $ai = null;
        try {
            $ai = $this->llm->json($system, $user, $schema, 500);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auto-reply AI failed: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $data = (array) ($ai['data'] ?? []);
        $action = in_array($data['action'] ?? null, ['reply', 'human', 'none'], true) ? $data['action'] : null;
        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 200);

        $rules = app(ReplyClassifier::class)->classify($theirs)['category'];
        $declined = in_array($rules, ['not_interested', 'unsubscribe', 'out_of_office'], true);
        if ($action === 'human') {
            return ['action' => 'human', 'reason' => $reason ?: 'human', 'writer' => (string) $ai['provider']];
        }
        if ($action === 'none') {
            // a lead is lost if the AI wrongly reads interest as a "no": only a "no" the rules also see is left unanswered
            return $declined
                ? ['action' => 'none', 'reason' => $reason ?: 'none', 'writer' => (string) $ai['provider']]
                : ['action' => 'human', 'reason' => 'AI thinks no answer is needed (' . ($reason ?: 'none') . '), the rules do not: a person decides', 'writer' => (string) $ai['provider']];
        }
        if ($action === 'reply' && ($text = $this->safe((string) ($data['reply'] ?? ''), $lead, $link, $channel))) {
            return ['action' => 'reply', 'reply' => $text, 'reason' => $reason, 'writer' => (string) $ai['provider']];
        }
        // no AI answer, or one that broke the rules: the fixed safe answer, which only thanks them and offers the call
        if ($declined) {
            return ['action' => 'none', 'reason' => "rules read it as {$rules}", 'writer' => 'template'];
        }

        return ['action' => 'reply', 'reply' => $this->template($channel, $lead, $theirs, $link, $late), 'writer' => 'template',
            'reason' => $ai ? 'AI answer rejected: ' . $this->rejected : 'no AI answered'];
    }

    /** Why the last AI answer was rejected (shown in the dry run and stored with the answer). */
    protected string $rejected = '';

    /** The AI's answer, cleaned, or null when it breaks a rule ($this->rejected says which). */
    public function safe(string $text, Lead $lead, string $link, string $channel): ?string
    {
        $no = function (string $why) {
            $this->rejected = $why;

            return null;
        };
        $text = str_replace(["\r", '**', '__'], '', $text);
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{FEFF}]/u', ' ', $text); // odd spaces that glue words together
        // a Markdown link [pick a time](url) becomes plain text with the url
        $text = preg_replace('/\[([^\]]{1,80})\]\((https?:\/\/[^)\s]+)\)/', '$1: $2', $text);
        // our booking link as the AI wrote it (shortened, without or with changed tracking) becomes exactly our link
        if (($base = BookingLink::base()) && $link !== '') {
            $text = preg_replace('#(?:https?://)?' . preg_quote(preg_replace('#^https?://#', '', $base), '#') . '(?:\?[^\s)]*)?#i', $link, $text);
        }
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        if ($text === '') {
            return $no('empty');
        }
        if (mb_strlen($text) > ($channel === 'whatsapp' ? 700 : 1000)) {
            return $no('too long');
        }
        if (preg_match('/[\[\]{}]|lorem|\bTODO\b/i', $text)) {
            return $no('a placeholder in brackets');
        }
        // no price, money or promise
        if (preg_match('/[$€£₹]|\b(usd|aed|sar|pkr|qar|omr|kwd|gbp|eur|rs\.?|rupees?|dirhams?|riyals?|dollars?|discount|guarantee[ds]?|refund)\b/iu', $text, $m)) {
            return $no('money or a promise ("' . $m[0] . '")');
        }
        // no promise a person would have to keep (only the call is offered)
        if (preg_match("/\\b(we|i)\\s*(will|'ll|’ll|shall)\\s+(send|e-?mail|share|prepare|forward|get back|follow up|deliver|mail)|\\bwill be sending\\b/iu", $text, $m)) {
            return $no('a promise to send something ("' . $m[0] . '")');
        }
        // no link but ours
        $allowed = array_filter([$link, BookingLink::base(), config('leadpilot.agency.website')]);
        $withoutOurs = str_replace($allowed, '', $text);
        if (preg_match('#https?://\S+|www\.\S+|\b[a-z0-9-]+\.(com|net|org|io|co|pk|ae|sa)\b#i', $withoutOurs, $m)) {
            return $no('a link that is not ours (' . mb_substr($m[0], 0, 40) . ')');
        }
        // no price-like number: three or more digits, a percentage, "5k", "2,000"; small numbers ("3 fixes", "24 hours") are fine
        $plain = str_ireplace(array_filter([(string) $lead->company?->name]), '', $withoutOurs);
        if (preg_match('/\d{3,}|\d[\d,.]*\s*(%|percent|k\b|lakh|million)|\d,\d/iu', $plain, $m)) {
            return $no('a price-like number ("' . $m[0] . '")');
        }
        if ($link !== '' && !str_contains($text, $link)) {
            $text = rtrim($text) . "\n\n" . ($channel === 'whatsapp' ? "Pick a time here: {$link}" : "You can pick a time here: {$link}");
        }

        return $text;
    }

    /** The fixed safe answer: thanks, one line that fits what they asked, and the call. */
    public function template(string $channel, Lead $lead, string $theirs, string $link, bool $late): string
    {
        $company = $lead->company?->name;
        $first = $lead->contact_status === 'identified' ? trim((string) $lead->first_name) : '';
        $said = mb_strtolower($theirs);
        $lines = [($first !== '' ? "Hi {$first}, " : 'Hi, ') . ($late ? 'sorry for the slow reply, and thank you for your message!' : 'thank you for your reply!')];
        $lines[] = match (true) {
            (bool) preg_match('/\b(price|pricing|prices|cost|costs|charges|rates|quote|quotation|budget|how much|fee|fees)\b/u', $said) => 'The price depends on what ' . ($company ?: 'you') . ' needs. A short call is enough for me to send you an exact quote the same day.',
            (bool) preg_match('/\b(details|detail|more info|information|explain|portfolio|samples?|examples?)\b/u', $said) => 'Happy to explain. I can walk you through the report and show you examples of similar work.',
            default => 'I would be glad to walk you through the website report' . ($company ? " for {$company}" : '') . '.',
        };
        $lines[] = $link !== '' ? "You can pick a time for a 15-minute call here: {$link}" : 'When would suit you for a 15-minute call?';
        if ($channel === 'email') {
            $lines[] = (string) config('leadpilot.agency.sender_name');
        }

        return implode("\n\n", $lines);
    }

    protected function hold(string $channel, int $sourceId, Lead $lead, array $c, bool $dry, string $who, bool $record = true): array
    {
        if ($c['action'] === 'none') {
            return $this->record($channel, $sourceId, $lead, 'skipped', 'no answer needed: ' . $c['reason'], $dry, $who);
        }
        if (!$dry) {
            $assignee = $lead->assigned_user_id ?: User::where('role', 'admin')->where('is_active', true)->orderBy('id')->value('id');
            Task::firstOrCreate(['lead_id' => $lead->id, 'title' => 'Answer now: LeadPilot left this reply to you', 'status' => 'pending'], [
                'company_id' => $lead->company_id, 'assigned_to' => $assignee, 'priority' => 'high', 'due_date' => now()->toDateString(),
                'description' => 'Not answered automatically: ' . $c['reason'],
            ]);
        }
        if (!$record) {
            return ['held', "{$who} ({$channel}): left to a person: {$c['reason']}"];
        }

        return $this->record($channel, $sourceId, $lead, 'held', 'left to a person: ' . $c['reason'], $dry, $who);
    }

    protected function record(string $channel, int $sourceId, ?Lead $lead, string $status, ?string $reason, bool $dry, string $who, array $c = []): array
    {
        if (!$dry) {
            $row = AutoReply::firstOrNew(['channel' => $channel, 'source_id' => $sourceId]);
            $attempts = $row->exists ? $row->attempts + 1 : 1;
            $row->fill(['lead_id' => $lead?->id, 'status' => $status, 'attempts' => $attempts, 'reason' => $reason ? mb_substr($reason, 0, 300) : null,
                'body' => $c['reply'] ?? $row->body, 'writer' => $c['writer'] ?? $row->writer])->save();
            $row->touch();
            // refused every time: a person answers instead
            if ($status === 'failed' && $attempts >= self::MAX_ATTEMPTS && $lead) {
                $this->hold($channel, $sourceId, $lead, ['action' => 'human', 'reason' => "could not be sent after {$attempts} tries ({$reason})"], false, $who, false);
            }
        }

        return [in_array($status, ['sent', 'skipped', 'failed'], true) ? $status : 'held', "{$who} ({$channel}): " . ($reason ?? $status)];
    }

    protected function closeTasks(Lead $lead): void
    {
        Task::where('lead_id', $lead->id)->where('status', 'pending')
            ->where(fn ($q) => $q->where('title', 'like', '%answer now%')->orWhere('title', 'like', '%respond today%')->orWhere('title', 'like', '%review suggested response%'))
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /** Their text without quoted history, signatures' contact details, e-mail addresses or phone numbers. */
    protected function clean(string $text): string
    {
        $text = preg_split('/\n\s*(On .{5,80} wrote:|-{2,}\s*Original Message|From: )/i', $text)[0] ?? $text;
        $text = preg_replace('/^>.*$/m', '', $text);
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[phone]', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", (string) $text));
    }

    protected function who(Lead $lead): string
    {
        return $lead->company?->name ?? $lead->full_name ?? (string) $lead->email;
    }
}
