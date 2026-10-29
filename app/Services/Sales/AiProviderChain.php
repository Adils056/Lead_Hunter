<?php

namespace App\Services\Sales;

use App\Models\AiProvider;
use App\Services\Integrations\CostLedger;
use Illuminate\Support\Facades\Http;

/**
 * Tries the owner's AI models in priority order and returns the first good JSON answer. A model at its daily limit,
 * switched off, or cooling down is skipped: a rate limit / server error rests it 10 minutes, a bad key or unknown
 * model 1 hour (shown as "Error" with the reason). Every call is counted against the model's daily limit.
 */
class AiProviderChain
{
    /** @return array{data: array, provider: string}|null */
    public function json(string $system, string $user, array $schema, int $maxTokens = 600): ?array
    {
        foreach (AiProvider::where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $p) {
            if ($p->state() !== 'ready') {
                continue;
            }
            $data = $this->tryOne($p, $system, $user, $schema, $maxTokens);
            if ($data !== null) {
                return ['data' => $data, 'provider' => $p->name];
            }
        }

        return null;
    }

    /** One small round trip to check the key and model, for the Test button. */
    public function test(AiProvider $p): array
    {
        $t = microtime(true);
        $data = $this->tryOne($p, 'You check that an API works.', 'Reply with {"ok": true}.', ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']], 300);
        $p->refresh();

        return ['ok' => $data !== null, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => $data !== null ? 'Answered correctly.' : ($p->last_error ?: 'No usable answer.')];
    }

    protected function tryOne(AiProvider $p, string $system, string $user, array $schema, int $maxTokens): ?array
    {
        $p->countUse(); // a refused or failed call still counts: providers count requests, not successes
        try {
            $text = $p->kind === 'gemini' ? $this->gemini($p, $system, $user, $maxTokens) : $this->openai($p, $system, $user, $schema, $maxTokens);
            $data = self::parse($text);
            if ($data === null) {
                $p->forceFill(['last_status' => 'bad_answer', 'last_error' => 'The answer was not valid JSON: ' . mb_substr((string) $text, 0, 120)])->save();

                return null;
            }
            $p->forceFill(['last_status' => 'ok', 'last_error' => null, 'cooldown_until' => null])->save();
            app(CostLedger::class)->record('ai:' . mb_substr($p->name, 0, 40), 'llm', 0, true, ['model' => $p->model, 'note' => $p->daily_limit ? 'free tier' : 'paid, not priced here']);

            return $data;
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $hard = in_array($code, [400, 401, 403, 404], true); // bad key, unknown model: will not fix itself in minutes
            $p->forceFill(['last_status' => $hard ? 'error' : 'cooling', 'last_error' => mb_substr($e->getMessage(), 0, 300),
                'cooldown_until' => now()->addMinutes($hard ? 60 : 10)])->save();

            return null;
        }
    }

    protected function openai(AiProvider $p, string $system, string $user, array $schema, int $maxTokens): string
    {
        $base = rtrim((string) $p->base_url, '/');
        $res = Http::timeout(45)->withToken((string) $p->api_key)->withHeaders(['HTTP-Referer' => (string) config('app.url'), 'X-Title' => 'LeadPilot'])
            ->post("{$base}/chat/completions", [
                'model' => $p->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system . "\n\nReply with one JSON object only, no prose, matching this JSON schema:\n" . json_encode($schema)],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => max($maxTokens, 300),
                'temperature' => 0.4,
            ]);
        if (!$res->successful()) {
            throw new \RuntimeException("{$p->name} HTTP {$res->status()}: " . mb_substr($res->body(), 0, 200), $res->status());
        }

        return (string) $res->json('choices.0.message.content');
    }

    protected function gemini(AiProvider $p, string $system, string $user, int $maxTokens): string
    {
        $base = rtrim((string) ($p->base_url ?: AiProvider::PRESETS['gemini']['base_url']), '/');
        // the key travels in a header, never in the URL (URLs end up in logs and error messages)
        $res = Http::timeout(45)->withHeaders(['x-goog-api-key' => (string) $p->api_key])->post("{$base}/models/{$p->model}:generateContent", [
            'systemInstruction' => ['parts' => [['text' => $system . "\n\nReply with one JSON object only."]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => max($maxTokens, 300), 'temperature' => 0.4],
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException("{$p->name} HTTP {$res->status()}: " . mb_substr($res->body(), 0, 200), $res->status());
        }

        return (string) $res->json('candidates.0.content.parts.0.text');
    }

    /** JSON object from a model answer, tolerating ```json fences or a sentence around it. */
    public static function parse(?string $text): ?array
    {
        $t = trim((string) $text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
        $data = json_decode($t, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $t, $m)) {
            $data = json_decode($m[0], true);
        }

        return is_array($data) && $data !== [] ? $data : null;
    }
}
