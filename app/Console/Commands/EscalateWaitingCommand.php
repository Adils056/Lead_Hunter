<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Autopilot\Notifier;
use App\Services\Sales\WaitingList;
use Illuminate\Console\Command;

/**
 * A prospect who replied and has had no answer for 24 hours is escalated, once, to the people named in
 * LEADPILOT_ESCALATE_TO (for example the CEO), or to every admin when nobody is named. One message per run lists every
 * reply that crossed the line since the last run, on WhatsApp and e-mail. Seen live: an interested reply waited 11 days.
 * Report opens are not escalated (they are in the morning list); only people who wrote to us are.
 */
class EscalateWaitingCommand extends Command
{
    protected $signature = 'leadpilot:escalate-waiting {--hours= : Hours without an answer before escalating} {--dry-run : List what would be escalated}';

    protected $description = 'Escalate, once, every reply left without an answer for 24 hours';

    public function handle(Notifier $notifier, WaitingList $waiting): int
    {
        $hours = max(1, (int) ($this->option('hours') ?: config('leadpilot.alerts.escalate_after_hours', 24)));
        $due = $waiting->items()
            ->filter(fn ($i) => $i['kind'] === 'reply' && $i['since']->lte(now()->subHours($hours)))
            ->reject(fn ($i) => NotificationDelivery::where('channel', 'escalation')->where('dedupe_key', 'escalation:' . $i['key'])->exists())
            ->values();
        if ($due->isEmpty()) {
            $this->info("No reply has waited more than {$hours} hours without an escalation.");

            return 0;
        }

        $list = $due->take(10)->map(fn ($i) => WaitingList::line($i))->implode("\n") . ($due->count() > 10 ? "\n…and " . ($due->count() - 10) . ' more' : '');
        $n = $due->count();
        $payload = [
            'title' => "{$n} " . ($n === 1 ? 'prospect has' : 'prospects have') . " waited over {$hours} hours for our answer",
            'priority' => 'high',
            'what' => $list,
            'why' => 'They wrote to us and nobody has answered. Replies answered the same day turn into meetings far more often.',
            'recommended' => 'Make sure someone answers each of them today',
            'url' => route('whatsapp.index', ['tab' => 'replies']),
            'short' => "LeadPilot escalation: {$n} prospect(s) waiting over {$hours}h for an answer. " . WaitingList::line($due->first()) . '. ' . route('whatsapp.index', ['tab' => 'replies']),
        ];
        $this->line($payload['title']);
        $this->line($list);
        if ($this->option('dry-run')) {
            return 0;
        }

        $users = $this->recipients();
        foreach ($users as $u) {
            $notifier->notifyUser($u, $payload + ['urgent' => true], 'escalation-run:' . now()->format('YmdHi'));
        }
        // each reply is escalated once, however long it then waits (the morning list keeps showing it)
        foreach ($due as $i) {
            NotificationDelivery::create(['user_id' => null, 'channel' => 'escalation', 'dedupe_key' => 'escalation:' . $i['key'], 'status' => 'stored',
                'subject_ref' => mb_substr(WaitingList::line($i), 0, 120)]);
        }
        $this->info('Escalated to ' . $users->pluck('name')->implode(', ') . '.');

        return 0;
    }

    /** The people named in LEADPILOT_ESCALATE_TO (e-mail addresses of users), or every active admin. */
    protected function recipients(): \Illuminate\Support\Collection
    {
        $emails = array_filter(array_map('trim', explode(',', (string) config('leadpilot.alerts.escalate_to'))));
        $named = $emails ? User::where('is_active', true)->whereIn('email', $emails)->get() : collect();

        return $named->isNotEmpty() ? $named : User::where('is_active', true)->where('role', 'admin')->get();
    }
}
