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

        return $result;
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
