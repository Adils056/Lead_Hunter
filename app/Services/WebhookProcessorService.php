<?php

namespace App\Services;

use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

class WebhookProcessorService
{
    protected AiResearchService $aiService;

    public function __construct(AiResearchService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Ingest and process a provider webhook event with strict deduplication.
     */
    public function processWebhook(string $provider, string $eventType, string $idempotencyKey, array $payload, ?string $signature = null): array
    {
        // 1. Idempotency Check
        $existing = WebhookEvent::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [
                'status' => 'duplicate',
                'message' => 'Event already processed idempotently.',
                'event_id' => $existing->id,
            ];
        }

        $event = WebhookEvent::create([
            'provider' => $provider,
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'signature' => $signature,
            'payload' => $payload,
            'is_processed' => false,
        ]);

        try {
            $this->handleEvent($eventType, $payload, $provider);
            $event->update([
                'is_processed' => true,
                'processed_at' => now(),
            ]);

            return [
                'status' => 'success',
                'event_id' => $event->id,
            ];
        } catch (\Exception $e) {
            $event->update([
                'error_message' => $e->getMessage(),
            ]);
            Log::error("Failed to process webhook event #{$event->id}: " . $e->getMessage());

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'event_id' => $event->id,
            ];
        }
    }

    protected function handleEvent(string $eventType, array $payload, string $provider = ''): void
    {
        // Route Social & Ad Platform Inbound Lead Webhooks (Phase 9)
        if ($provider === 'meta_ads' || str_contains($eventType, 'meta_lead')) {
            (new \App\Services\Connectors\MetaLeadAdsConnector())->handleLeadWebhook($payload);
            return;
        }

        if ($provider === 'google_ads' || str_contains($eventType, 'google_lead')) {
            (new \App\Services\Connectors\GoogleAdsLeadConnector())->handleLeadWebhook($payload);
            return;
        }

        if ($provider === 'tiktok_ads' || str_contains($eventType, 'tiktok_lead')) {
            (new \App\Services\Connectors\TikTokLeadAdsConnector())->handleLeadWebhook($payload);
            return;
        }

        $email = strtolower(trim($payload['email'] ?? $payload['recipient'] ?? ''));
        $messageId = $payload['message_id'] ?? null;

        $recipient = null;
        if ($messageId) {
            $recipient = CampaignRecipient::where('provider_message_id', $messageId)->first();
        }
        if (!$recipient && !empty($email)) {
            $recipient = CampaignRecipient::whereHas('lead', fn($q) => $q->where('email', $email))
                ->latest()
                ->first();
        }

        $lead = $recipient?->lead ?? ($email ? Lead::where('email', $email)->first() : null);

        switch (strtolower($eventType)) {
            case 'delivered':
                if ($recipient) {
                    $recipient->update([
                        'status' => 'delivered',
                        'delivered_at' => now(),
                    ]);
                }
                break;

            case 'bounce':
            case 'hard_bounce':
                if ($recipient) {
                    $recipient->update([
                        'status' => 'bounced',
                        'bounce_type' => 'hard',
                        'bounced_at' => now(),
                        'error_message' => $payload['reason'] ?? 'Hard bounce notification from provider',
                    ]);
                }
                if (!empty($email)) {
                    Suppression::addSuppression($email, 'hard_bounce', 'webhook');
                }
                if ($lead) {
                    $lead->update(['email_verification_status' => 'invalid']);
                }
                break;

            case 'complaint':
            case 'spam':
                if ($recipient) {
                    $recipient->update([
                        'status' => 'opted_out',
                        'error_message' => 'Spam complaint recorded',
                    ]);
                }
                if (!empty($email)) {
                    Suppression::addSuppression($email, 'complaint', 'webhook');
                }
                break;

            case 'unsubscribe':
                if (!empty($email)) {
                    Suppression::addSuppression($email, 'unsubscribe', 'webhook');
                }
                if ($recipient) {
                    $recipient->update(['status' => 'opted_out']);
                }
                if ($lead) {
                    $lead->update(['lifecycle_stage' => 'not_a_fit']);
                }
                break;

            case 'reply':
            case 'inbound_message':
                $body = $payload['body'] ?? $payload['text'] ?? '';
                $subject = $payload['subject'] ?? 'Re: Inquiry';
                $from = $email ?: ($payload['from'] ?? '');

                if ($lead) {
                    $messageId = isset($payload['message_id']) ? trim((string) $payload['message_id'], '<> ') : null;
                    if ($messageId && InboundReply::where('message_id', $messageId)->exists()) {
                        break; // idempotent
                    }
                    $reply = InboundReply::create([
                        'campaign_recipient_id' => $recipient?->id,
                        'lead_id' => $lead->id,
                        'from_email' => $from,
                        'to_email' => $payload['to'] ?? config('mail.from.address', 'sales@leadpilot.test'),
                        'subject' => $subject,
                        'body_text' => $body,
                        'message_id' => $messageId,
                        'source' => 'webhook',
                        'is_reviewed' => false,
                        'received_at' => now(),
                    ]);
                    // v4: one shared rule set for webhook and IMAP replies (stop sequences, suppression, approvals)
                    app(\App\Services\Sales\ReplyOutcomeService::class)->handle($reply);
                }
                break;
        }
    }
}
