<?php

namespace App\Http\Controllers;

use App\Services\WebhookProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    protected WebhookProcessorService $webhookProcessor;

    public function __construct(WebhookProcessorService $webhookProcessor)
    {
        $this->webhookProcessor = $webhookProcessor;
    }

    /**
     * Inbound email webhook endpoint.
     * Accessible by email delivery providers (e.g. Resend, SendGrid, Mailgun) or internal simulation.
     */
    public function handle(Request $request, string $provider)
    {
        $signature = $request->header('X-Webhook-Signature') ?? $request->header('Signature');
        $eventType = $request->input('event_type') ?? $request->input('type') ?? 'unknown';
        $idempotencyKey = $request->input('idempotency_key') 
            ?? $request->input('event_id') 
            ?? $request->input('id') 
            ?? ('hook_' . md5(json_encode($request->all())));

        $payload = $request->all();

        $result = $this->webhookProcessor->processWebhook(
            $provider,
            $eventType,
            $idempotencyKey,
            $payload,
            $signature
        );

        $statusCode = ($result['status'] === 'error') ? 400 : 200;

        return response()->json($result, $statusCode);
    }
}
