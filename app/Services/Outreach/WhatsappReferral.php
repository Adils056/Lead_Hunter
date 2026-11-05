<?php

namespace App\Services\Outreach;

use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Sales\ConversionAssistantService;
use App\Support\CompanyName;
use App\Support\Phone;

/**
 * A business that answers our WhatsApp with an e-mail address or another number ("write to j.altaher@ais.sch.sa")
 * is telling us where to continue. LeadPilot does that at once: one e-mail to the address, or one WhatsApp to the
 * number, with the observation and the free website report. Each address or number is contacted once, never if it
 * is suppressed or opted out, and only while sending is allowed (live mode, no emergency stop).
 * Autonomy policy "reply.referral_contact": automatic = send; anything else = leave it to the salesperson,
 * who already has the alert with the message.
 */
class WhatsappReferral
{
    public function __construct(
        protected AutonomyPolicyService $policy,
        protected ConversionAssistantService $assistant,
        protected OutreachMailer $mailer,
    ) {
    }

    /** E-mail addresses and phone numbers written in a message (at most two of each). */
    public static function extract(?string $text): array
    {
        $text = (string) $text;
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i', $text, $e);
        preg_match_all('/\+?\d[\d\s().-]{7,18}\d/', $text, $p);

        return [
            'emails' => array_slice(array_values(array_unique(array_map(fn ($x) => strtolower(rtrim($x, '.')), $e[0]))), 0, 2),
            'phones' => array_slice(array_values(array_unique(array_filter(array_map('trim', $p[0]), fn ($x) => strlen(preg_replace('/\D/', '', $x)) >= 9))), 0, 2),
        ];
    }

    /** Contact what the prospect gave us. Returns one line per address or number: what was done, or why not. */
    public function handle(WhatsappInboundMessage $in, Lead $lead): array
    {
        $found = self::extract($in->body);
        if (!$found['emails'] && !$found['phones']) {
            return [];
        }
        if (!$this->policy->isAutomatic('reply.referral_contact')) {
            return ['Left for the salesperson (autonomy policy reply.referral_contact is not automatic).'];
        }
        $out = [];
        foreach ($found['emails'] as $email) {
            $out[] = $this->email($email, $lead, $in);
        }
        foreach ($found['phones'] as $raw) {
            $out[] = $this->whatsapp($raw, $lead, $in);
        }

        return $out;
    }

    protected function email(string $email, Lead $lead, WhatsappInboundMessage $in): string
    {
        $ours = array_filter([strtolower((string) config('mail.from.address')), strtolower((string) \App\Models\OutreachSetting::current()->reply_to_email)]);
        if (in_array($email, $ours, true)) {
            return "{$email}: our own address, skipped.";
        }
        $c = $lead->company;
        if (Suppression::isSuppressed($email, $c?->domain)) {
            return "{$email}: suppressed, not contacted.";
        }
        $existing = Lead::where('email', $email)->first();
        if ($existing && ($existing->last_contacted_at || EmailLog::where('recipient_email', $email)->where('direction', 'outbound')->exists())) {
            return "{$email}: already contacted before, not e-mailed again.";
        }
        $to = $existing ?? Lead::create([
            'company_id' => $lead->company_id, 'icp_profile_id' => $lead->icp_profile_id,
            'full_name' => ($c?->name ?? 'Company') . ' — address given on WhatsApp', 'email' => $email, 'contact_status' => 'generic_inbox',
            'source_name' => 'Given on WhatsApp', 'country' => $lead->country, 'industry' => $lead->industry,
            'notes' => "Given by +{$in->from_number} in reply to our WhatsApp (message #{$in->id}). They asked us to write here: not a cold contact.",
        ]);
        // they invited this e-mail; keep it out of cold sequences
        $to->forceFill(['lifecycle_stage' => 'replied'])->save();

        $subject = (CompanyName::forEmail($c?->name) ?? 'Your website') . ': the website report, as discussed on WhatsApp';
        $body = $this->emailBody($lead);
        try {
            $messageId = $this->mailer->sendToLead($to, $subject, $body);
        } catch (\Throwable $e) {
            return "{$email}: not sent ({$e->getMessage()}).";
        }
        EmailLog::create(['lead_id' => $to->id, 'direction' => 'outbound', 'provider' => config('mail.default', 'smtp'), 'message_id' => $messageId,
            'sender_email' => (string) (config('mail.from.address') ?: 'leadpilot@localhost'), 'recipient_email' => $email, 'subject' => $subject, 'body_snippet' => mb_substr($body, 0, 200),
            'status' => 'sent', 'occurred_at' => now(), 'event_metadata' => ['via' => 'whatsapp_referral', 'inbound_id' => $in->id]]);
        $to->forceFill(['last_contacted_at' => now()])->save();
        AuditLog::record('whatsapp_referral_email', $to, null, ['to' => $email, 'inbound_id' => $in->id, 'message_id' => $messageId]);

        return "{$email}: e-mailed with the report.";
    }

    protected function whatsapp(string $raw, Lead $lead, WhatsappInboundMessage $in): string
    {
        $c = $lead->company;
        $number = Phone::international($raw, $lead->country ?: $c?->country);
        if (!$number) {
            return "{$raw}: could not read the number (no country code), left for the salesperson.";
        }
        if (substr($number, -10) === substr((string) $in->from_number, -10)) {
            return "+{$number}: the number already talking to us, skipped.";
        }
        if (Phone::whatsappHint($number, $lead->country ?: $c?->country) !== null) {
            return "+{$number}: a landline, so not on WhatsApp; left for the salesperson to call.";
        }
        $tail = substr($number, -10);
        if (WhatsappInbox::optedOut($number) || WhatsappMessage::where('to_number', 'like', "%{$tail}")->whereIn('status', ['sent', 'pending_approval'])->exists()) {
            return "+{$number}: already messaged or opted out, skipped.";
        }
        $wa = app(WhatsappOutreach::class);
        if (($why = $wa->blockReason()) && !str_starts_with($why, 'Daily WhatsApp cap')) {
            return "+{$number}: not sent ({$why}).";
        }
        $report = $c?->latestWebsiteReport;
        $shareable = $report && $report->isShareable() && !\App\Models\OutreachSetting::publicUrlIsLocal();
        $msg = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'to_number' => $number, 'number_source' => 'given_in_reply',
            'body' => $this->whatsappBody($lead, $shareable ? $report : null), 'file_url' => $shareable ? route('reports.public.pdf', $report->share_token) : null,
            'status' => 'pending_approval']);

        return $wa->deliver($msg) ? "+{$number}: sent a WhatsApp" . ($shareable ? ' with the report PDF.' : '.') : "+{$number}: the gateway refused it.";
    }

    protected function emailBody(Lead $lead): string
    {
        $agency = config('leadpilot.agency');
        $c = $lead->company;
        $fact = $this->assistant->facts($lead)[0]['text'] ?? null;
        $name = CompanyName::forEmail($c?->name) ?? 'your business';

        return "Hello,\n\nThank you for your reply on WhatsApp, where we were given this address to write to.\n\n"
            . "I'm {$agency['sender_name']} from {$agency['name']}. I looked at {$name}'s website" . ($fact ? " and noticed {$fact}." : '.') . "\n\n"
            . (($line = $this->assistant->reportLine($lead)) ? $line . "\n\n" : '')
            . "Would a 15-minute call this week be useful? I can walk you through the top 3 fixes.\n\n"
            . "Best regards,\n{$agency['sender_name']}\n{$agency['name']}" . (!empty($agency['website']) ? "\n{$agency['website']}" : '');
    }

    protected function whatsappBody(Lead $lead, $report): string
    {
        $agency = config('leadpilot.agency');
        $c = $lead->company;
        $name = CompanyName::forEmail($c?->name) ?? 'your business';
        $fact = $this->assistant->facts($lead)[0]['text'] ?? null;
        $place = $c?->city ? "in {$c->city}" : 'nearby';

        return "Hi, this is {$agency['sender_name']} from {$agency['name']}. {$name} shared this number with us on WhatsApp."
            . ($fact ? " I looked at the website and noticed {$fact}." : '')
            . ($report ? "\n\nI made a free report comparing it with {$report->competitorCount()} similar " . ($report->competitorCount() === 1 ? 'business' : 'businesses') . " {$place} (PDF attached, also here: {$report->shareUrl()})." : '')
            . "\n\nHappy to walk you through the top 3 fixes if useful.\n\nIf you'd rather not hear from us, reply STOP.";
    }
}
