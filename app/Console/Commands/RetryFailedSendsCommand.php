<?php

namespace App\Console\Commands;

use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Outreach\SequenceEngine;
use App\Services\Outreach\WhatsappInbox;
use App\Services\Outreach\WhatsappOutreach;
use Illuminate\Console\Command;

/**
 * One more try for messages that failed because of a passing fault on our side, not because of the prospect:
 * the mail server timing out or refusing the connection, the WhatsApp gateway answering "Request Failed", an empty
 * reply or a timeout. Seen live: 13 e-mails (SMTP timeouts) and about 10 WhatsApp messages lost that way in a week,
 * and since every business gets one WhatsApp only, a failed one was never sent at all.
 * Each message is retried once, within 7 days (--days), through the normal sending path (eligibility gate, caps, the
 * prospect's working hours, suppression and opt-outs all apply again).
 * A WhatsApp refused because its number was in the wrong format (wa.me/0300…, no country code) is sent once more to
 * the corrected number; one whose number cannot be made valid is left alone.
 */
class RetryFailedSendsCommand extends Command
{
    protected $signature = 'leadpilot:retry-failed {--dry-run : List what would be retried} {--days=7 : How far back to look}';

    protected $description = 'Retry, once, e-mails and WhatsApp messages that failed because of a passing mail-server or gateway fault';

    public const EMAIL_TRANSIENT = '/timed out|connection (refused|reset|could not be established|lost)|unable to connect|could not connect|network is unreachable|expected response code "?4\d\d|temporar(y|ily)|try again later|too many connections/i';

    public const WHATSAPP_TRANSIENT = '/request failed|curl error (6|7|28|35|52|56)|timed out|empty reply|connection (refused|reset)|server error|bad gateway|service unavailable|"status_code":\s*5\d\d|trying to access array offset/i';

    public const RETRIED = '[retried] ';

    public function handle(SequenceEngine $sequence, WhatsappOutreach $wa): int
    {
        $dry = (bool) $this->option('dry-run');
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $rows = [];

        // e-mail: back to "approved"; the dispatcher sends it in the prospect's next working hours, after the gate
        foreach (CampaignRecipient::with('lead')->where('status', 'failed')->where('updated_at', '>=', $since)->get() as $rec) {
            $err = (string) $rec->error_message;
            if (str_starts_with($err, self::RETRIED) || !preg_match(self::EMAIL_TRANSIENT, $err)) {
                continue;
            }
            $lead = $rec->lead;
            if (!$lead || in_array($lead->lifecycle_stage, Lead::NO_COLD_EMAIL_STAGES, true) || Suppression::isSuppressed($lead->email)) {
                continue;
            }
            if (!$dry) {
                $rec->update(['status' => 'approved', 'scheduled_at' => now(), 'error_message' => self::RETRIED . mb_substr($err, 0, 400)]);
            }
            $rows[] = ['e-mail', $lead->company?->name ?? '–', mb_substr($err, 0, 60), $dry ? 'would retry' : 'queued again'];
        }

        // WhatsApp: send again now if it is allowed now (caps, outreach switch, their working hours)
        $budget = (int) config('leadpilot.whatsapp.outreach.per_run', 3);
        foreach (WhatsappMessage::with('lead.company.latestCompletedWebsiteAudit')->where('status', 'failed')->where('attempts', '<', 3)->where('created_at', '>=', $since)->oldest('id')->get() as $msg) {
            $lead = $msg->lead;
            // a number in the wrong format (wa.me/0300…, a missing country code) was refused by the gateway: with the
            // number put right, it gets one more try even after its earlier retry
            $fixed = $lead ? $wa->numberFor($lead) : null;
            $renumbered = $fixed && $fixed['number'] !== $msg->to_number && in_array($msg->number_source, ['website_whatsapp_link', 'mobile_number'], true);
            if (!$renumbered && ($msg->attempts >= 2 || !preg_match(self::WHATSAPP_TRANSIENT, (string) $msg->provider_response))) {
                continue;
            }
            $target = $renumbered ? $fixed['number'] : $msg->to_number;
            $tail = substr($target, -10);
            $reason = match (true) {
                !$lead => 'lead deleted',
                !$fixed && in_array($msg->number_source, ['website_whatsapp_link', 'mobile_number'], true) => 'no valid WhatsApp number',
                in_array($lead->lifecycle_stage, Lead::NO_COLD_EMAIL_STAGES, true) => 'already in a conversation',
                WhatsappInbox::optedOut($target) => 'asked to stop',
                WhatsappMessage::where('to_number', 'like', "%{$tail}")->whereKeyNot($msg->id)->whereIn('status', ['sent', 'pending_approval'])->exists() => 'number already messaged',
                default => null,
            };
            if ($reason) {
                $rows[] = ['WhatsApp', $lead?->company?->name ?? '–', '', "not retried: {$reason}"];
                if (!$dry) {
                    $msg->update(['attempts' => $renumbered ? 3 : 2]); // looked at once, not again every hour
                }
                continue;
            }
            if ($budget <= 0 || ($why = $wa->blockReason()) || !$sequence->inSendingWindow(null, $lead)) {
                $rows[] = ['WhatsApp', $lead->company?->name ?? '–', mb_substr((string) $msg->provider_response, 0, 60), 'later: ' . ($why ?? ($budget <= 0 ? 'this run is full' : 'outside their working hours'))];
                continue;
            }
            if ($dry) {
                $rows[] = ['WhatsApp', $lead->company?->name ?? '–', mb_substr((string) $msg->provider_response, 0, 60), 'would retry'];
                continue;
            }
            $budget--;
            $msg->update(['attempts' => $msg->attempts + 1] + ($renumbered ? ['to_number' => $fixed['number'], 'number_source' => $fixed['source']] : []));
            $ok = $wa->deliver($msg);
            $rows[] = ['WhatsApp', $lead->company?->name ?? '–', mb_substr((string) $msg->provider_response, 0, 60), $ok ? 'sent on retry' : 'failed again'];
        }

        $this->table(['Channel', 'Business', 'Error', 'Result'], $rows);
        $this->info(count($rows) . ' failed message(s) looked at.');

        return 0;
    }
}
