<?php

namespace App\Services\Outreach;

use App\Models\AuditLog;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Services\Audit\WebsiteReportService;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Sales\ConversionAssistantService;
use App\Support\CompanyName;
use App\Support\Phone;

/**
 * One WhatsApp message per business: the observation from its own website, the free website & competitor report as
 * a PDF, and a way to opt out. Sent only to a number the business itself shows for WhatsApp (a wa.me link on its
 * site) or a mobile number in a WhatsApp market, within the prospect's working hours, under a small daily cap, and
 * never to anyone already in a conversation, e-mailed in the last 30 days, a client, closed, or suppressed.
 * Autonomy policy "outreach.whatsapp": automatic = send, approval = a human approves each one, prohibited = off.
 */
class WhatsappOutreach
{
    /** @var array<int, array{number: string, source: string}|null> lead id => WhatsApp number, from candidates() */
    protected array $numbers = [];

    public function __construct(
        protected WhatsappGateway $gateway,
        protected AutonomyPolicyService $policy,
        protected ConversionAssistantService $assistant,
        protected SequenceEngine $sequence,
    ) {
    }

    /** Why nothing may be sent right now, or null. */
    public function blockReason(): ?string
    {
        $cfg = (array) config('leadpilot.whatsapp.outreach', []);
        $s = OutreachSetting::current();

        return match (true) {
            empty($cfg['enabled']) => 'WhatsApp outreach is off (WHATSAPP_OUTREACH=true in .env turns it on).',
            !$this->gateway->isConfigured() => 'WhatsApp gateway keys are missing (EZIMK_APPKEY, EZIMK_AUTHKEY).',
            $this->policy->isProhibited('outreach.whatsapp') => 'Autonomy policy: outreach.whatsapp is prohibited.',
            $s->isControlledPilot() => 'Controlled-pilot mode: no messages to prospects.',
            OutreachSetting::publicUrlIsLocal() => 'APP_URL is not public, so the report link and PDF would not open.',
            (bool) ($why = OutreachGuard::blockReason('outreach')) => $why,
            $this->sentToday() >= (int) ($cfg['daily_cap'] ?? 10) => 'Daily WhatsApp cap reached (' . (int) ($cfg['daily_cap'] ?? 10) . ').',
            default => null,
        };
    }

    public function sentToday(): int
    {
        return WhatsappMessage::whereIn('status', ['sent', 'pending_approval'])
            ->where('created_at', '>=', now(config('leadpilot.timezone'))->startOfDay()->utc())->count();
    }

    /** The WhatsApp number to use for a lead and where it came from, or null. */
    public function numberFor(Lead $lead): ?array
    {
        $c = $lead->company;
        $country = $lead->country ?: $c?->country;
        // 1. the business links its own WhatsApp on its website: an open invitation to message it there
        $link = $c?->latestCompletedWebsiteAudit?->contacts_found['socials']['wa.me'] ?? null;
        if ($link && preg_match('#wa\.me/\+?(\d{8,15})#', $link, $m)) {
            return ['number' => $m[1], 'source' => 'website_whatsapp_link'];
        }
        // 2. a mobile number in a market where businesses answer on WhatsApp (never a landline, never the US/UK office line)
        foreach ([$lead->phone, $c?->public_phone] as $raw) {
            $n = Phone::international($raw, $country);
            if ($n && Phone::whatsappHint($n, $country) === null) {
                return ['number' => $n, 'source' => 'mobile_number'];
            }
        }

        return null;
    }

    /**
     * Leads that may get their one WhatsApp, best first, each with a number that is on WhatsApp (in $this->numbers).
     * Many listed numbers are landlines or US/UK office lines, so a wide slice is read and filtered here; reading only
     * a few leads per message left runs with nothing to send. $stats counts what was read and how many had no number.
     */
    public function candidates(int $limit, array &$stats = [])
    {
        $allowed = OutreachSetting::current()->allowed_countries ?: [];
        $recentEmail = now()->subDays(30);

        $leads = Lead::query()->with(['company.latestCompletedWebsiteAudit', 'company.latestWebsiteReport'])
            ->whereNull('last_contacted_at')
            ->where(fn ($q) => $q->whereNull('lifecycle_stage')->orWhereNotIn('lifecycle_stage', array_merge(Lead::NO_COLD_EMAIL_STAGES, ['contacted'])))
            ->whereIn('win_tier', ['A', 'B', 'C'])
            ->when($allowed, fn ($q) => $q->where(fn ($w) => $w->whereIn('country', $allowed)->orWhereHas('company', fn ($c) => $c->whereIn('country', $allowed))))
            ->whereHas('company', fn ($c) => $c->where('status', '<>', 'archived')->whereNotNull('website_audited_at')
                ->whereDoesntHave('leads', fn ($l) => $l->whereNotNull('last_contacted_at')->where('last_contacted_at', '>=', $recentEmail))
                ->whereNotExists(fn ($w) => $w->selectRaw('1')->from('whatsapp_messages')->whereColumn('whatsapp_messages.company_id', 'companies.id')))
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereHas('company', fn ($c) => $c->whereNotNull('public_phone')
                ->orWhereHas('websiteAudits', fn ($a) => $a->whereNotNull('contacts_found->socials->wa.me'))))
            ->orderByRaw("CASE win_tier WHEN 'A' THEN 0 WHEN 'B' THEN 1 ELSE 2 END")->orderByDesc('overall_score')
            ->limit(max(300, $limit * 60))->get()
            ->unique('company_id')->values();

        $stats['checked'] = $leads->count();
        $withNumber = $leads->filter(function (Lead $lead) {
            $this->numbers[$lead->id] = $this->numberFor($lead);

            return $this->numbers[$lead->id] !== null;
        })->values();
        $stats['no_whatsapp_number'] = $stats['checked'] - $withNumber->count();

        return $withNumber;
    }

    /** The message text, or null when there is no observation to open with (no evidence, no message). */
    public function compose(Lead $lead, string $reportUrl, int $competitors): ?string
    {
        $facts = $this->assistant->facts($lead);
        if (!$facts) {
            return null;
        }
        $c = $lead->company;
        $agency = config('leadpilot.agency');
        $template = WhatsappTemplate::where('purpose', 'first_contact')->where('is_active', true)->first();
        $name = $lead->contact_status === 'identified' && $lead->first_name ? $lead->first_name : ($lead->attentionGreetingName() ?? null);
        $opening = $template ? trim($template->render($lead))
            : 'Hi' . ($name ? " {$name}" : '') . ", this is {$agency['sender_name']} from {$agency['name']}. I looked at "
                . (CompanyName::forEmail($c?->name) ?? 'your business') . "'s website and noticed {$facts[0]['text']}.";
        $place = $c?->city ? "in {$c->city}" : 'nearby';

        return $opening . "\n\nI made you a free report comparing your website with {$competitors} similar "
            . ($competitors === 1 ? 'business' : 'businesses') . " {$place} (PDF attached, also here: {$reportUrl}).\n\n"
            . "Happy to walk you through the top 3 fixes if useful.\n\nIf you'd rather not hear from us, reply STOP.";
    }

    /** One pass: send (or queue for approval) up to $limit messages. Returns a summary. */
    public function run(?int $limit = null): array
    {
        $limit ??= (int) config('leadpilot.whatsapp.outreach.per_run', 3);
        $out = ['sent' => 0, 'queued_for_approval' => 0, 'failed' => 0, 'skipped' => [], 'blocked' => null,
            'funnel' => ['checked' => 0, 'no_whatsapp_number' => 0, 'outside_hours' => 0, 'suppressed' => 0, 'email_in_progress' => 0, 'no_shareable_report' => 0, 'no_observation' => 0]];
        if ($why = $this->blockReason()) {
            $out['blocked'] = $why;

            return $out;
        }
        $reportsBuilt = 0;
        $stats = [];
        $candidates = $this->candidates($limit, $stats);
        $out['funnel'] = array_merge($out['funnel'], $stats);
        foreach ($candidates as $lead) {
            if ($out['sent'] + $out['queued_for_approval'] >= $limit || $this->blockReason()) {
                break;
            }
            $c = $lead->company;
            $num = $this->numbers[$lead->id];
            if (!$this->sequence->inSendingWindow(null, $lead)) {
                $out['funnel']['outside_hours']++;
                $out['skipped'][] = "{$c->name}: outside their working hours";
                continue;
            }
            if (($lead->email && Suppression::isSuppressed($lead->email, $c->domain)) || ($c->domain && Suppression::isSuppressed(null, $c->domain))
                || WhatsappInbox::optedOut($num['number'])) {
                $out['funnel']['suppressed']++;
                continue;
            }
            if (CampaignRecipient::whereHas('lead', fn ($q) => $q->where('company_id', $c->id))->whereIn('status', ['pending_approval', 'approved', 'queued', 'scheduled'])->exists()) {
                $out['funnel']['email_in_progress']++;
                continue; // an e-mail is already on its way to this business: one conversation at a time
            }
            $report = $c->latestWebsiteReport;
            if ((!$report || !$report->isShareable()) && $reportsBuilt < 2) {
                $reportsBuilt++;
                try {
                    $report = app(WebsiteReportService::class)->generate($c, null, 10, false);
                } catch (\Throwable) {
                    $report = null;
                }
            }
            if (!$report || !$report->isShareable()) {
                $out['funnel']['no_shareable_report']++;
                continue; // the report is the point of the message
            }
            $url = $report->shareUrl();
            $body = $this->compose($lead, $url, $report->competitorCount());
            if (!$body) {
                $out['funnel']['no_observation']++;
                continue;
            }
            $msg = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $c->id, 'to_number' => $num['number'], 'number_source' => $num['source'],
                'body' => $body, 'file_url' => route('reports.public.pdf', $report->share_token), 'status' => 'pending_approval']);

            if (!$this->policy->isAutomatic('outreach.whatsapp')) {
                app(ApprovalService::class)->request('whatsapp_message', "whatsapp:{$msg->id}", [
                    'title' => 'Send a WhatsApp to ' . $c->name, 'summary' => mb_substr($body, 0, 400), 'priority' => 'normal',
                    'payload' => ['to' => $num['number'], 'body' => $body], 'evidence' => ['number_from' => $num['source'], 'report' => $url],
                ], $msg);
                $out['queued_for_approval']++;
                continue;
            }
            $this->deliver($msg) ? $out['sent']++ : $out['failed']++;
        }

        return $out;
    }

    /** Send a stored message; marks the lead contacted on success. */
    public function deliver(WhatsappMessage $msg, ?string $body = null): bool
    {
        $res = $this->gateway->send($msg->to_number, $body ?? $msg->body, $msg->file_url);
        $msg->update(['status' => $res['ok'] ? 'sent' : 'failed', 'provider_response' => $res['response'], 'sent_at' => $res['ok'] ? now() : null] + ($body ? ['body' => $body] : []));
        if ($res['ok'] && $msg->lead) {
            $msg->lead->update(['last_contacted_at' => now()]);
            $msg->lead->moveStage('contacted');
            AuditLog::record('whatsapp_sent', $msg->lead, null, ['to' => $msg->to_number, 'source' => $msg->number_source]);
        }

        return $res['ok'];
    }
}
