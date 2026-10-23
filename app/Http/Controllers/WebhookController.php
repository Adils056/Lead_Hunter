<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\WebhookProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound provider webhooks (delivery events, unsubscribes, inbound replies, ad-platform leads).
 *
 * Security (Phase 0 hotfix, 23 Oct 2026): every request must carry an HMAC-SHA256 of the raw body, computed
 * with the provider's `webhook_secret` (Integrations) or LEADPILOT_WEBHOOK_SECRET. Unknown providers are 404,
 * missing configuration is 503 (fail closed), bad signatures are 401. Idempotency keys are scoped per provider
 * so one provider cannot pre-empt another's events.
 */
class WebhookController extends Controller
{
    public function __construct(protected WebhookProcessorService $webhookProcessor)
    {
    }

    public function handle(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, (array) config('leadpilot.webhooks.providers', []), true), 404);

        $secret = $this->secretFor($provider);
        if ($secret === '') {
            return response()->json(['status' => 'error', 'error' => 'Webhooks are not configured: set LEADPILOT_WEBHOOK_SECRET (or a webhook_secret on the provider integration).'], 503);
        }

        $given = (string) ($request->header('X-Webhook-Signature') ?? $request->header('X-Hub-Signature-256') ?? $request->header('Signature') ?? '');
        $given = strtolower(trim(preg_replace('/^sha256=/i', '', trim($given))));
        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);
        if ($given === '' || !hash_equals($expected, $given)) {
            Log::warning('Webhook rejected: invalid signature', ['provider' => $provider, 'ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'error' => 'Invalid webhook signature.'], 401);
        }

        $eventType = $request->input('event_type') ?? $request->input('type') ?? 'unknown';
        $idempotencyKey = $provider . ':' . ($request->input('idempotency_key')
            ?? $request->input('event_id')
            ?? $request->input('id')
            ?? ('hook_' . md5((string) $request->getContent())));

        $result = $this->webhookProcessor->processWebhook($provider, $eventType, $idempotencyKey, $request->all(), $given);

        return response()->json($result, $result['status'] === 'error' ? 400 : 200);
    }

    /** Provider-specific secret from the Integrations screen, else the global one. Empty = not configured. */
    protected function secretFor(string $provider): string
    {
        try {
            $integration = Integration::where('provider', $provider)->first();
            if ($integration && $integration->is_active) {
                $s = $integration->getDecryptedCredentials()['webhook_secret'] ?? null;
                if (is_string($s) && $s !== '') {
                    return $s;
                }
            }
        } catch (\Throwable) {
        }

        return (string) config('leadpilot.webhooks.secret', '');
    }
}
