<?php

namespace App\Services\Outreach;

use Illuminate\Support\Facades\Http;

/**
 * Sends a WhatsApp message through the EZIMK gateway (form POST: appkey, authkey, to, message, optional file URL).
 * Returns the real outcome; the keys come from .env (EZIMK_APPKEY, EZIMK_AUTHKEY) and are never logged.
 */
class WhatsappGateway
{
    public function isConfigured(): bool
    {
        $c = (array) config('leadpilot.whatsapp.ezimk', []);

        return !empty($c['url']) && !empty($c['appkey']) && !empty($c['authkey']);
    }

    /**
     * @param string $to international digits, e.g. 923001234567
     * @return array{ok: bool, status: ?int, response: string}
     */
    public function send(string $to, string $message, ?string $fileUrl = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => null, 'response' => 'WhatsApp gateway is not configured (EZIMK_APPKEY / EZIMK_AUTHKEY).'];
        }
        $c = (array) config('leadpilot.whatsapp.ezimk');
        $form = ['appkey' => $c['appkey'], 'authkey' => $c['authkey'], 'to' => preg_replace('/\D/', '', $to), 'message' => $message];
        if ($fileUrl) {
            $form['file'] = $fileUrl;
        }
        try {
            $res = Http::timeout(30)->asMultipart()->acceptJson()->post($c['url'], $form);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'response' => mb_substr($e->getMessage(), 0, 500)];
        }
        $json = $res->json();
        // a 200 with {"status": false} or {"success": false} or an "error" field is a refusal, not a send
        $refused = is_array($json) && ((array_key_exists('status', $json) && in_array($json['status'], [false, 'false', 'error', 'failed'], true))
            || (array_key_exists('success', $json) && !$json['success']) || !empty($json['error']));

        return ['ok' => $res->successful() && !$refused, 'status' => $res->status(), 'response' => mb_substr((string) $res->body(), 0, 1000)];
    }
}
