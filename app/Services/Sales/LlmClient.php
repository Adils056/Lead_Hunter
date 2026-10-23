<?php

namespace App\Services\Sales;

use App\Services\Integrations\BudgetGuard;
use App\Services\Integrations\CostLedger;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Provider-agnostic JSON completion with fallback: free Gemini first (when keyed), then local Ollama (free, keyless),
 * then OpenAI within budget.
 * Callers must only pass PUBLIC business facts — Gemini's free tier may use inputs to improve Google products.
 */
class LlmClient
{
    /** @return array{data: array, provider: string}|null */
    public function json(string $system, string $user, array $schema, int $maxTokens = 600): ?array
    {
        $registry = app(ProviderRegistry::class);
        $candidates = array_values(array_unique(array_merge(
            $registry->isConfigured('gemini') ? ['gemini'] : [],
            $registry->select('llm'),
        )));
        foreach ($candidates as $p) {
            if (!app(ProviderHealthService::class)->isAvailable($p)) {
                continue;
            }
            try {
                $data = match ($p) {
                    'gemini' => $this->gemini($system, $user, $schema, $maxTokens),
                    'ollama' => $this->ollama($system, $user, $schema, $maxTokens),
                    default => $this->openai($system, $user, $schema, $maxTokens),
                };
                if ($data !== null) {
                    app(ProviderHealthService::class)->success($p, true);

                    return ['data' => $data, 'provider' => $p];
                }
            } catch (\Throwable $e) {
                app(ProviderHealthService::class)->failure($p, $e->getMessage(), (int) $e->getCode() ?: null);
            }
        }

        return null;
    }

    protected function gemini(string $system, string $user, array $schema, int $maxTokens): ?array
    {
        $model = config('leadpilot.keys.gemini_model', 'gemini-2.5-flash-lite');
        // key in a header, never in the URL (URLs end up in error messages/logs)
        $res = Http::timeout(30)->withHeaders(['x-goog-api-key' => (string) config('leadpilot.keys.gemini')])->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens, 'temperature' => 0.4],
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException('Gemini HTTP ' . $res->status() . ' ' . mb_substr($res->body(), 0, 200), $res->status());
        }
        app(CostLedger::class)->record('gemini', 'llm', 0, true, ['tokens' => (int) $res->json('usageMetadata.totalTokenCount', 0), 'note' => 'free tier assumed']);

        return json_decode((string) $res->json('candidates.0.content.parts.0.text'), true) ?: null;
    }

    /** Local Ollama chat API with JSON-schema constrained output (Ollama >= 0.5). Nothing leaves this machine. */
    protected function ollama(string $system, string $user, array $schema, int $maxTokens): ?array
    {
        $cfg = config('leadpilot.sources.ollama');
        $res = Http::timeout((int) ($cfg['timeout'] ?? 120))->post($cfg['url'] . '/api/chat', [
            'model' => $cfg['model'],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'stream' => false,
            'format' => $schema,
            'options' => ['temperature' => 0.4, 'num_predict' => $maxTokens],
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException('Ollama HTTP ' . $res->status() . ' ' . mb_substr($res->body(), 0, 200), $res->status());
        }
        app(CostLedger::class)->record('ollama', 'llm', 0, false, ['tokens' => (int) $res->json('eval_count', 0), 'model' => $cfg['model'], 'note' => 'local, free']);

        return json_decode((string) $res->json('message.content'), true) ?: null;
    }

    protected function openai(string $system, string $user, array $schema, int $maxTokens): ?array
    {
        $ai = app(\App\Services\AiResearchService::class);
        $key = (fn () => $this->apiKey)->call($ai);
        $model = (fn () => $this->model)->call($ai);
        if (!app(BudgetGuard::class)->allows('openai', 1)) {
            return null;
        }
        $res = Http::timeout(30)->withToken($key)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'out', 'strict' => true, 'schema' => $schema]],
            'max_tokens' => $maxTokens,
            'temperature' => 0.4,
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException('OpenAI HTTP ' . $res->status() . ' ' . mb_substr($res->body(), 0, 200), $res->status());
        }
        $tokens = (int) $res->json('usage.total_tokens', 0);
        app(CostLedger::class)->record('openai', 'llm', max(1, (int) ceil($tokens / 1000 * 0.06)), true, ['tokens' => $tokens]);

        return json_decode((string) $res->json('choices.0.message.content'), true) ?: null;
    }
}
