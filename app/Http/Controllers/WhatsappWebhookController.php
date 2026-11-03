<?php

namespace App\Http\Controllers;

use App\Services\Outreach\WhatsappInbox;
use Illuminate\Http\Request;

/**
 * EZIMK posts every incoming WhatsApp message here (device → Webhook Url). EZIMK cannot sign its requests, so the
 * URL carries a secret token instead; a wrong token is a plain 404. GET answers "ok" for the gateway's URL check.
 */
class WhatsappWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, WhatsappInbox $inbox)
    {
        abort_unless(hash_equals(WhatsappInbox::token(), $token), 404);
        if ($request->isMethod('get')) {
            return response()->json(['ok' => true]);
        }
        $payload = $request->all() ?: (json_decode((string) $request->getContent(), true) ?: []);
        $in = $inbox->handle((array) $payload);

        return response()->json(['ok' => true, 'action' => $in->action]);
    }
}
