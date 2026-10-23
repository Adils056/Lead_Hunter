<?php

namespace App\Services\Mail;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\MailboxCursor;
use App\Models\Suppression;
use App\Models\SystemHeartbeat;
use App\Services\Autopilot\WorkflowControlService;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Sales\ReplyOutcomeService;

/**
 * Pulls new messages from the outreach mailbox over IMAP (read-only; BODY.PEEK keeps them unread),
 * matches them to our sent emails (In-Reply-To/References, then sender address), records replies and bounces,
 * and hands replies to ReplyOutcomeService. Idempotent via Message-ID + UID checkpoint.
 */
class ReplySyncService
{
    public function __construct(protected ?ImapTransport $transport = null)
    {
    }

    public function isConfigured(): bool
    {
        $c = config('leadpilot.imap');

        return (bool) ($c['enabled'] && $c['username'] && $c['password'] && $c['host']);
    }

    /** @return array{fetched:int, replies:int, bounces:int, ignored:int, error:?string} */
    public function sync(int $max = 50): array
    {
        $out = ['fetched' => 0, 'replies' => 0, 'bounces' => 0, 'ignored' => 0, 'error' => null];
        if (!$this->isConfigured()) {
            $out['error'] = 'IMAP not configured (set IMAP_ENABLED=true and IMAP_PASSWORD = Gmail App Password).';

            return $out;
        }
        if (app(WorkflowControlService::class)->isPaused('reply_sync')) {
            $out['error'] = 'reply_sync workflow paused';

            return $out;
        }
        $c = config('leadpilot.imap');
        $health = app(ProviderHealthService::class);
        $client = new ImapClient($this->transport ?? new SocketImapTransport());
        try {
            $client->connect($c['host'], (int) $c['port']);
            $client->login($c['username'], $c['password']);
            $box = $client->select($c['mailbox']);
            $cursor = MailboxCursor::firstOrCreate(['mailbox' => $c['username'] . '/' . $c['mailbox']], ['last_uid' => 0]);
            if ($cursor->uid_validity && $box['uidvalidity'] && $cursor->uid_validity !== $box['uidvalidity']) {
                $cursor->last_uid = 0; // mailbox was rebuilt: rescan (Message-ID dedupe prevents duplicates)
            }
            $uids = $cursor->last_uid > 0
                ? array_filter($client->uidSearch('UID ' . ($cursor->last_uid + 1) . ':*'), fn ($u) => $u > $cursor->last_uid)
                : $client->uidSearch('SINCE ' . now()->subDays(14)->format('d-M-Y'));
            sort($uids);
            $last = $cursor->last_uid;
            foreach (array_slice($uids, 0, $max) as $uid) {
                $raw = $client->fetchRaw($uid);
                $last = max($last, $uid);
                if ($raw !== null) {
                    $out['fetched']++;
                    try {
                        $kind = $this->ingest(MimeMessage::parse($raw));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Reply sync: message UID {$uid} skipped: " . $e->getMessage());
                        $kind = 'ignored';
                    }
                    $out[$kind] = ($out[$kind] ?? 0) + 1;
                }
                // checkpoint after EVERY message: one bad message can't block the mailbox
                $cursor->update(['last_uid' => $last, 'uid_validity' => $box['uidvalidity']]);
            }
            $cursor->update(['last_uid' => $last, 'uid_validity' => $box['uidvalidity'], 'last_synced_at' => now()]);
            $client->logout();
            $health->success('imap', true);
            SystemHeartbeat::updateOrCreate(['component' => 'reply_sync'], ['beat_at' => now(), 'meta' => $out]);
        } catch (ImapException $e) {
            $health->failure('imap', $e->getMessage(), $e->isAuth ? 401 : null);
            $out['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $health->failure('imap', $e->getMessage());
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /** @return string replies|bounces|ignored */
    public function ingest(MimeMessage $m): string
    {
        $msgId = $m->messageId();
        if ($msgId && InboundReply::where('message_id', $msgId)->exists()) {
            return 'ignored';
        }
        $ours = strtolower((string) config('mail.from.address'));
        $from = $m->fromEmail();
        if (!$from || $from === $ours) {
            return 'ignored';
        }

        if ($m->isBounce) {
            // Anti-spoofing: a bounce only counts if it references a Message-ID WE sent, and only that recipient is affected.
            $rec = $m->bouncedMessageId ? CampaignRecipient::where('rfc_message_id', $m->bouncedMessageId)->first() : null;
            if (!$rec || !$rec->lead) {
                return 'ignored';
            }
            $email = $rec->lead->email;
            if ($m->bouncedRecipient && $email && strtolower($m->bouncedRecipient) !== strtolower($email)) {
                return 'ignored'; // DSN names a different address than the one we sent to
            }
            $hard = $m->bounceStatus ? str_starts_with($m->bounceStatus, '5') : false; // unknown status = soft (no suppression)
            $rec->update(['status' => 'bounced', 'bounced_at' => now(), 'bounce_type' => $hard ? 'hard' : 'soft', 'error_message' => 'Bounce ' . ($m->bounceStatus ?? '(no status)')]);
            if ($hard && $email) {
                Suppression::addSuppression($email, 'hard_bounce', 'imap_bounce');
                $rec->lead->update(['email_verification_status' => 'invalid']);
            }

            return 'bounces';
        }

        $refs = $m->referencedIds();
        $rec = $refs ? CampaignRecipient::whereIn('rfc_message_id', $refs)->latest('id')->first() : null;
        $lead = $rec?->lead;
        if (!$lead) {
            // Sender-only match (no thread headers): only trust it when the sender was not shown to be forged.
            if ($m->senderAuthenticated() === false) {
                return 'ignored';
            }
            $lead = Lead::where('email', $from)->whereHas('campaignRecipients', fn ($q) => $q->whereNotNull('sent_at')->where('sent_at', '>=', now()->subDays(90)))->first();
            $rec = $lead ? CampaignRecipient::where('lead_id', $lead->id)->whereNotNull('sent_at')->latest('sent_at')->first() : null;
        }
        if (!$lead) {
            return 'ignored'; // not a reply to our outreach — we never process unrelated mail
        }

        $reply = InboundReply::create([
            'campaign_recipient_id' => $rec?->id,
            'lead_id' => $lead->id,
            'from_email' => $from,
            'to_email' => $ours,
            'subject' => mb_substr($m->header('subject') ?? '(no subject)', 0, 255),
            'body_text' => mb_substr($m->text, 0, 20000),
            'message_id' => $msgId,
            'source' => 'imap',
            'is_reviewed' => false,
            'received_at' => now(),
        ]);
        if ($m->isAutoSubmitted()) {
            // auto-replies (vacation responders etc.) are never treated as a human answer
            app(ReplyOutcomeService::class)->apply($reply, 'out_of_office', 90, 'Auto-Submitted header', app(\App\Services\Sales\ReplyClassifier::class)->classify($m->text));
        } else {
            app(ReplyOutcomeService::class)->handle($reply);
        }

        return 'replies';
    }
}
