<?php

namespace App\Services\Autopilot;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActionRequired;
use Illuminate\Support\Facades\Mail;

/**
 * Sends human-action notifications on every configured channel and records the HONEST outcome:
 *   database -> 'stored' (in-app bell)
 *   mail     -> 'accepted_by_smtp' when the SMTP server accepted it, 'failed' otherwise.
 * We never record 'delivered' — SMTP acceptance is not inbox delivery.
 * Deduplicated per user+key within renotify window.
 */
class Notifier
{
    public function notifyRole(string $role, array $payload, string $dedupeKey): array
    {
        $users = User::where('is_active', true)->where('role', $role)->get();
        if ($users->isEmpty()) {
            $users = User::where('is_active', true)->where('role', 'admin')->get(); // nobody in that role -> admins
        }

        return $users->map(fn ($u) => $this->notifyUser($u, $payload, $dedupeKey))->all();
    }

    public function notifyUser(User $user, array $payload, string $dedupeKey, bool $force = false): array
    {
        $hours = (int) config('leadpilot.approvals.renotify_hours', 12);
        if (!$force && NotificationDelivery::where('user_id', $user->id)->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', now()->subHours($hours))->whereIn('status', ['stored', 'accepted_by_smtp', 'logged_only'])->exists()) {
            return ['user' => $user->id, 'skipped' => 'duplicate'];
        }

        $result = ['user' => $user->id];
        try {
            // SQA bug 7: a reminder replaces the earlier unread entry for the same approval instead of stacking a copy
            if (!empty($payload['approval_id'])) {
                $this->retire((int) $payload['approval_id'], $user, true);
            }
            $user->notify(new ActionRequired($payload));
            NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'database', 'dedupe_key' => $dedupeKey, 'status' => 'stored', 'subject_ref' => mb_substr($payload['title'] ?? '', 0, 120)]);
            $result['database'] = 'stored';
        } catch (\Throwable $e) {
            NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'database', 'dedupe_key' => $dedupeKey, 'status' => 'failed', 'error' => $e->getMessage()]);
            $result['database'] = 'failed';
        }

        if ($user->email) {
            try {
                Mail::to($user->email)->send(new \App\Mail\ActionRequiredMail($payload));
                // log/array drivers never leave the server: record that honestly
                $status = in_array(config('mail.default'), ['log', 'array'], true) ? 'logged_only' : 'accepted_by_smtp';
                NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'mail', 'dedupe_key' => $dedupeKey, 'status' => $status, 'subject_ref' => mb_substr($payload['title'] ?? '', 0, 120)]);
                $result['mail'] = $status;
            } catch (\Throwable $e) {
                NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'mail', 'dedupe_key' => $dedupeKey, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                $result['mail'] = 'failed: ' . mb_substr($e->getMessage(), 0, 120);
            }
        }
        if (!empty($payload['urgent'])) {
            $result += $this->instant($user, $payload, $dedupeKey);
        }

        return $result;
    }

    /**
     * Urgent alerts (a prospect replied, opened their report) also go where the salesperson sees them in minutes:
     * WhatsApp or SMS through Twilio to the user's phone, and/or a webhook (Make, Zapier, n8n, Slack...).
     * Each attempt is recorded with its real outcome; nothing configured = nothing sent, never an error.
     */
    protected function instant(User $user, array $payload, string $key): array
    {
        $out = [];
        $text = (string) ($payload['short'] ?? (($payload['title'] ?? 'LeadPilot alert') . (!empty($payload['url']) ? ' ' . $payload['url'] : '')));
        $cfg = (array) config('leadpilot.alerts', []);
        $record = function (string $channel, string $status, ?string $error = null) use ($user, $key, $payload, &$out) {
            NotificationDelivery::create(['user_id' => $user->id, 'channel' => $channel, 'dedupe_key' => $key, 'status' => $status,
                'error' => $error ? mb_substr($error, 0, 1000) : null, 'subject_ref' => mb_substr($payload['title'] ?? '', 0, 120)]);
            $out[$channel] = $status;
        };

        if (!empty($cfg['webhook_url'])) {
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(8)->acceptJson()->post($cfg['webhook_url'], [
                    'text' => $text, 'title' => $payload['title'] ?? null, 'url' => $payload['url'] ?? null,
                    'user_email' => $user->email, 'user_phone' => $user->phone, 'priority' => $payload['priority'] ?? 'high',
                ]);
                $res->successful() ? $record('webhook', 'delivered') : $record('webhook', 'failed', 'HTTP ' . $res->status());
            } catch (\Throwable $e) {
                $record('webhook', 'failed', $e->getMessage());
            }
        }

        // the team's own WhatsApp gateway (EZIMK), when its keys are set
        $gateway = app(\App\Services\Outreach\WhatsappGateway::class);
        $mobile = \App\Support\Phone::international($user->phone, null);
        if ($gateway->isConfigured()) {
            if (!$mobile) {
                $record('whatsapp', 'skipped', 'No phone in international format (+92…) on this user\'s profile.');
            } else {
                $res = $gateway->send($mobile, mb_substr($text, 0, 1500));
                $res['ok'] ? $record('whatsapp', 'accepted_by_provider') : $record('whatsapp', 'failed', 'HTTP ' . ($res['status'] ?? '-') . ' ' . $res['response']);
            }
        }

        $tw = (array) ($cfg['twilio'] ?? []);
        $phone = preg_replace('/[^\d+]/', '', (string) $user->phone);
        if (!empty($tw['sid']) && !empty($tw['token']) && !empty($tw['from'])) {
            $whatsapp = str_starts_with((string) $tw['from'], 'whatsapp:');
            $channel = $whatsapp ? 'whatsapp' : 'sms';
            if (!preg_match('/^\+\d{8,15}$/', $phone)) {
                $record($channel, 'skipped', 'No phone in international format (+92…) on this user\'s profile.');
            } else {
                try {
                    $res = \Illuminate\Support\Facades\Http::timeout(10)->asForm()->withBasicAuth($tw['sid'], $tw['token'])
                        ->post("https://api.twilio.com/2010-04-01/Accounts/{$tw['sid']}/Messages.json", [
                            'From' => $tw['from'], 'To' => ($whatsapp ? 'whatsapp:' : '') . $phone, 'Body' => mb_substr($text, 0, 1500),
                        ]);
                    $res->successful() ? $record($channel, 'accepted_by_provider') : $record($channel, 'failed', 'HTTP ' . $res->status() . ' ' . mb_substr($res->body(), 0, 300));
                } catch (\Throwable $e) {
                    $record($channel, 'failed', $e->getMessage());
                }
            }
        }

        return $out;
    }

    /** Clear the in-app entries of one approval: deleted when a reminder replaces them, marked read once it is decided. */
    public function retire(int $approvalId, ?User $only = null, bool $delete = false): void
    {
        $q = \Illuminate\Notifications\DatabaseNotification::whereNull('read_at')->where('type', ActionRequired::class)
            ->where('notifiable_type', (new User())->getMorphClass());
        if ($only) {
            $q->where('notifiable_id', $only->id);
        }
        // filtered in PHP: the data column is plain TEXT, so a JSON path query is not portable across MySQL/MariaDB/SQLite
        $mine = $q->get()->filter(fn ($n) => (int) ($n->data['approval_id'] ?? 0) === $approvalId);
        foreach ($mine as $n) {
            $delete ? $n->delete() : $n->markAsRead();
        }
    }
}
