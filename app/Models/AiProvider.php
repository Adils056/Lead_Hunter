<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One AI model LeadPilot may call, tried in `priority` order (lowest first). Free tiers carry a daily request
 * limit; a model is skipped once it reaches it, while it cools down after a rate-limit / server error, or when off.
 */
class AiProvider extends Model
{
    /** Known services: kind + base URL, so adding one needs only a model name and a key. */
    public const PRESETS = [
        'groq' => ['label' => 'Groq', 'kind' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1'],
        'gemini' => ['label' => 'Google Gemini', 'kind' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
        'openrouter' => ['label' => 'OpenRouter', 'kind' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1'],
        'cerebras' => ['label' => 'Cerebras', 'kind' => 'openai', 'base_url' => 'https://api.cerebras.ai/v1'],
        'mistral' => ['label' => 'Mistral', 'kind' => 'openai', 'base_url' => 'https://api.mistral.ai/v1'],
        'deepseek' => ['label' => 'DeepSeek', 'kind' => 'openai', 'base_url' => 'https://api.deepseek.com/v1'],
        'openai' => ['label' => 'OpenAI', 'kind' => 'openai', 'base_url' => 'https://api.openai.com/v1'],
        'custom' => ['label' => 'Other (OpenAI-compatible)', 'kind' => 'openai', 'base_url' => null],
    ];

    /** The owner's list (30 Oct 2026): priority, preset, model, daily limit. Added without keys and switched off until a key is set. */
    public const RECOMMENDED = [
        [10, 'groq', 'Groq', 'openai/gpt-oss-120b', 1000],
        [20, 'gemini', 'Google Gemini', 'gemini-3.6-flash', 20],
        [30, 'openrouter', 'OpenRouter', 'nex-agi/nex-n2.5-pro:free', 50],
        [40, 'cerebras', 'Cerebras', 'gpt-oss-120b', 500],
        [50, 'mistral', 'Mistral', 'mistral-small-2603', 2000],
        [90, 'gemini', 'Google Gemini / gemma-4-31b-it', 'gemma-4-31b-it', 14400],
        [95, 'gemini', 'Google Gemini / gemma-4-26b-a4b-it', 'gemma-4-26b-a4b-it', 14400],
        [99, 'deepseek', 'DeepSeek Flash (paid)', 'deepseek-flash', null],
        [100, 'groq', 'Groq / openai/gpt-oss-20b', 'openai/gpt-oss-20b', 1000],
        [105, 'groq', 'Groq / qwen/qwen3.8-27b', 'qwen/qwen3.8-27b', 1000],
        [110, 'groq', 'Groq / qwen/qwen3.6-27b', 'qwen/qwen3.6-27b', 1000],
        [115, 'gemini', 'Google Gemini / gemini-3.5-flash', 'gemini-3.5-flash', 20],
        [120, 'gemini', 'Google Gemini / gemini-3.1-flash-lite', 'gemini-3.1-flash-lite', 500],
        [125, 'gemini', 'Google Gemini / gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 500],
    ];

    protected $fillable = ['priority', 'name', 'kind', 'base_url', 'model', 'api_key', 'daily_limit', 'used_today', 'used_on', 'is_active',
        'cooldown_until', 'last_status', 'last_error', 'last_used_at'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'is_active' => 'boolean', 'used_on' => 'date', 'cooldown_until' => 'datetime', 'last_used_at' => 'datetime'];
    }

    /** Requests used on the business day (the counter starts again each morning, Asia/Karachi by default). */
    public function usedToday(): int
    {
        return $this->used_on && $this->used_on->toDateString() === self::today() ? (int) $this->used_today : 0;
    }

    public static function today(): string
    {
        return now(config('leadpilot.timezone', 'Asia/Karachi'))->toDateString();
    }

    /** ready | off | no_key | limit | cooling | error */
    public function state(): string
    {
        return match (true) {
            !$this->api_key => 'no_key',
            !$this->is_active => 'off',
            $this->daily_limit !== null && $this->usedToday() >= $this->daily_limit => 'limit',
            $this->cooldown_until && $this->cooldown_until->isFuture() => $this->last_status === 'error' ? 'error' : 'cooling',
            default => 'ready',
        };
    }

    public function maskedKey(): string
    {
        $k = (string) $this->api_key;

        return $k === '' ? '—' : '••••••' . mb_substr($k, -4);
    }

    public function countUse(): void
    {
        $this->forceFill(['used_today' => $this->usedToday() + 1, 'used_on' => self::today(), 'last_used_at' => now()])->save();
    }
}
