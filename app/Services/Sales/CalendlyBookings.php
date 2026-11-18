<?php

namespace App\Services\Sales;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\User;
use App\Services\Autopilot\LeadStateService;
use App\Services\Autopilot\Notifier;
use App\Services\Integrations\CalendlyClient;
use App\Support\BookingLink;
use Carbon\Carbon;

/**
 * Bookings made on the Calendly link, recorded in LeadPilot without anyone typing them in: the meeting is created,
 * the lead moves to "meeting booked", its e-mails and follow-ups stop, the "answer now" tasks close, a "prepare" task
 * is added and the team is told at once. A cancelled booking marks the meeting cancelled and asks for a new time.
 * The booking is matched to its lead by the link's utm_content (WhatsApp answers carry the lead id), then by the
 * e-mail address, then by the company's own e-mail domain. A booking that matches no lead is reported once.
 */
class CalendlyBookings
{
    public const FREE_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
        'aol.com', 'proton.me', 'protonmail.com', 'yandex.com', 'mail.com', 'gmx.com', 'zoho.com', 'yahoo.co.uk', 'hotmail.co.uk'];

    public function __construct(protected CalendlyClient $calendly, protected Notifier $notifier)
    {
    }

    /** @return array{booked: array, cancelled: array, unmatched: array, error?: string, skipped?: string} */
    public function sync(bool $dry = false): array
    {
        $out = ['booked' => [], 'cancelled' => [], 'unmatched' => []];
        if (!$this->calendly->isConfigured()) {
            return $out + ['skipped' => 'CALENDLY_TOKEN is not set in .env'];
        }
        try {
            $user = (string) ($this->calendly->me()['uri'] ?? '');
            $since = now()->subDay();
            foreach ($this->calendly->events($user, $since, 'active') as $event) {
                if (!Meeting::where('external_ref', $event['uri'] ?? '')->exists()) {
                    $this->booked($event, $dry, $out);
                }
            }
            foreach ($this->calendly->events($user, $since, 'canceled') as $event) {
                $meeting = Meeting::where('external_ref', $event['uri'] ?? '')->where('status', 'scheduled')->first();
                if ($meeting) {
                    $this->cancelled($meeting, $event, $dry, $out);
                }
            }
        } catch (\Throwable $e) {
            return $out + ['error' => mb_substr($e->getMessage(), 0, 300)];
        }

        return $out;
    }

    protected function booked(array $event, bool $dry, array &$out): void
    {
        $invitee = collect($this->calendly->invitees($event['uri']))->firstWhere('status', 'active') ?? [];
        $email = mb_strtolower(trim((string) ($invitee['email'] ?? '')));
        $name = trim((string) ($invitee['name'] ?? ''));
        $start = Carbon::parse($event['start_time'])->utc();
        $when = $start->copy()->timezone(config('leadpilot.timezone'))->format('D d M, H:i');
        $lead = $this->match($invitee, $email);

        if (!$lead) {
            $key = 'calendly-unmatched:' . $event['uri'];
            $out['unmatched'][] = "{$name} <{$email}>, {$when}";
            if (!$dry && !NotificationDelivery::where('dedupe_key', $key)->exists()) {
                foreach (User::where('is_active', true)->where('role', 'admin')->get() as $u) {
                    $this->notifier->notifyUser($u, [
                        'title' => "Calendly booking from {$name}, not matched to a lead",
                        'priority' => 'high',
                        'what' => "{$name} <{$email}> booked {$event['name']} for {$when}. No lead has this e-mail address or domain.",
                        'why' => 'Add the meeting to the right lead so its follow-ups stop and the pipeline is right.',
                        'recommended' => 'Open the lead and add the meeting in Sales Pipeline',
                        'url' => route('crm.pipeline'),
                        'short' => "LeadPilot: Calendly booking from {$name} <{$email}> for {$when}, not matched to a lead.",
                        'urgent' => true,
                    ], $key);
                }
            }

            return;
        }

        $who = $lead->company?->name ?? $lead->full_name;
        $out['booked'][] = "{$who}: {$when}";
        if ($dry) {
            return;
        }
        $host = $lead->assigned_user_id ?: User::where('role', 'admin')->where('is_active', true)->orderBy('id')->value('id');
        $answers = collect((array) ($invitee['questions_and_answers'] ?? []))->map(fn ($q) => ($q['question'] ?? '') . ': ' . ($q['answer'] ?? ''))->implode("\n");
        $meeting = Meeting::create([
            'lead_id' => $lead->id, 'company_id' => $lead->company_id, 'host_user_id' => $host,
            'title' => ($event['name'] ?? 'Meeting') . ' with ' . ($name ?: $who),
            'meeting_url' => $event['location']['join_url'] ?? (filter_var($event['location']['location'] ?? null, FILTER_VALIDATE_URL) ?: null),
            'scheduled_start' => $start, 'scheduled_end' => isset($event['end_time']) ? Carbon::parse($event['end_time'])->utc() : null,
            'status' => 'scheduled', 'source' => 'calendly', 'external_ref' => $event['uri'],
            'outcome_notes' => trim("Booked on Calendly by {$name} <{$email}>.\n{$answers}"),
        ]);
        AuditLog::record('meeting_scheduled', $meeting, null, ['source' => 'calendly', 'invitee' => $email]);

        $lead->moveStage('meeting_booked');
        app(ReplyOutcomeService::class)->stopSequences($lead, 'Meeting booked on Calendly');
        Task::where('lead_id', $lead->id)->where('status', 'pending')
            ->where(fn ($q) => $q->where('title', 'like', '%answer now%')->orWhere('title', 'like', '%respond today%')->orWhere('title', 'like', '%follow up today%'))
            ->update(['status' => 'completed', 'completed_at' => now()]);
        Task::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'assigned_to' => $host, 'priority' => 'high', 'status' => 'pending',
            'title' => "Meeting {$when}: prepare", 'due_date' => $start->copy()->timezone(config('leadpilot.timezone'))->toDateString(),
            'description' => 'Booked on Calendly. Open their website report and pick the 3 fixes to show.']);
        app(LeadStateService::class)->refresh($lead->fresh());

        $this->tell($lead, [
            'title' => "Meeting booked: {$who}, {$when}",
            'what' => "{$name} <{$email}> booked {$event['name']} on Calendly for {$when} (your time).",
            'why' => 'LeadPilot moved the lead to Meeting booked and stopped its e-mails and follow-ups.',
            'recommended' => 'Prepare: open their website report and pick the 3 fixes to show',
            'short' => "LeadPilot: meeting booked! {$who}, {$when}. " . route('leads.show', $lead),
        ], 'calendly-booked:' . $event['uri']);
    }

    protected function cancelled(Meeting $meeting, array $event, bool $dry, array &$out): void
    {
        $lead = $meeting->lead;
        $who = $lead?->company?->name ?? $lead?->full_name ?? $meeting->title;
        $out['cancelled'][] = $who;
        if ($dry) {
            return;
        }
        $why = trim((string) ($event['cancellation']['reason'] ?? ''));
        $meeting->update(['status' => 'cancelled', 'outcome_notes' => trim($meeting->outcome_notes . "\nCancelled on Calendly" . ($why !== '' ? ": {$why}" : '.'))]);
        if ($lead) {
            Task::where('lead_id', $lead->id)->where('status', 'pending')->where('title', 'like', 'Meeting %: prepare')->update(['status' => 'completed', 'completed_at' => now()]);
            Task::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'assigned_to' => $meeting->host_user_id, 'priority' => 'high', 'status' => 'pending',
                'title' => 'Meeting cancelled: answer now and offer a new time', 'due_date' => now()->toDateString()]);
            app(LeadStateService::class)->refresh($lead->fresh());
            $this->tell($lead, [
                'title' => "Meeting cancelled: {$who}",
                'what' => "The Calendly booking was cancelled" . ($why !== '' ? ": \"{$why}\"" : '.'),
                'why' => 'A quick, friendly message offering another time often saves the meeting.',
                'recommended' => 'Offer a new time today',
                'short' => "LeadPilot: {$who} cancelled their meeting. Offer a new time today: " . route('leads.show', $lead),
            ], 'calendly-cancelled:' . $event['uri']);
        }
    }

    /** The lead a booking belongs to: the link's lead id, then the exact e-mail, then the company's own domain. */
    public function match(array $invitee, string $email): ?Lead
    {
        if ($id = BookingLink::leadId($invitee['tracking']['utm_content'] ?? null)) {
            if ($lead = Lead::find($id)) {
                return $lead;
            }
        }
        if ($email === '') {
            return null;
        }
        if ($lead = Lead::whereRaw('LOWER(email) = ?', [$email])->orderByDesc('overall_score')->first()) {
            return $lead;
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '' || in_array($domain, self::FREE_MAIL, true)) {
            return null;
        }

        return Lead::whereHas('company', fn ($q) => $q->where('domain', $domain)->orWhere('domain', 'www.' . $domain))
            ->orderByRaw("CASE WHEN lifecycle_stage IN ('replied','interested') THEN 0 ELSE 1 END")->orderByDesc('overall_score')->first();
    }

    protected function tell(Lead $lead, array $payload, string $key): void
    {
        $users = User::where('is_active', true)->where(fn ($q) => $q->where('role', 'admin')->when($lead->assigned_user_id, fn ($w) => $w->orWhere('id', $lead->assigned_user_id)))->get();
        foreach ($users as $u) {
            $this->notifier->notifyUser($u, $payload + ['priority' => 'high', 'url' => route('leads.show', $lead), 'urgent' => true], $key);
        }
    }
}
