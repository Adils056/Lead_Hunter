<?php

namespace App\Services\Integrations;

use App\Models\ProviderHealth;
use App\Services\Autopilot\IncidentService;

/** Per-provider health + circuit breaker. Repeated auth failures raise a Level-3 incident. */
class ProviderHealthService
{
    public const BREAK_AFTER = 5;          // consecutive failures -> open circuit
    public const COOLDOWN_MINUTES = 60;

    public function row(string $provider): ProviderHealth
    {
        return ProviderHealth::firstOrCreate(['provider' => $provider], ['status' => 'unknown', 'consecutive_failures' => 0, 'auth_failures' => 0, 'total_calls' => 0, 'total_failures' => 0]);
    }

    public function isAvailable(string $provider): bool
    {
        $h = ProviderHealth::where('provider', $provider)->first();
        if (!$h) {
            return true;
        }
        if ($h->status === 'disabled' && $h->disabled_until && $h->disabled_until->isFuture()) {
            return false;
        }

        return true;
    }

    /** Strip secrets that providers/HTTP libraries echo back in error messages. */
    public static function redact(string $s): string
    {
        $s = preg_replace('/((?:api[_-]?key|key|token|access_token|password|secret)=)[^&\s"\']+/i', '$1[REDACTED]', $s);
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $s);
        $s = preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|AIza[0-9A-Za-z_\-]{20,})\b/', '[REDACTED]', $s);
        foreach (array_filter([config('leadpilot.keys.gemini'), config('leadpilot.keys.openai'), config('leadpilot.keys.hunter'), config('leadpilot.imap.password'), config('leadpilot.sources.google_places.api_key')]) as $secret) {
            if (strlen((string) $secret) >= 6) {
                $s = str_replace((string) $secret, '[REDACTED]', $s);
            }
        }

        return $s;
    }

    public function success(string $provider, bool $verified = false): void
    {
        $h = $this->row($provider);
        $h->fill([
            'status' => 'healthy', 'consecutive_failures' => 0, 'auth_failures' => 0,
            'total_calls' => $h->total_calls + 1, 'last_success_at' => now(), 'disabled_until' => null,
        ]);
        if ($verified) {
            $h->last_verified_at = now();
        }
        $h->save();
    }

    public function failure(string $provider, string $error, ?int $httpStatus = null): void
    {
        $h = $this->row($provider);
        $auth = in_array($httpStatus, [401, 403], true);
        $h->fill([
            'consecutive_failures' => $h->consecutive_failures + 1,
            'auth_failures' => $auth ? $h->auth_failures + 1 : $h->auth_failures,
            'total_calls' => $h->total_calls + 1,
            'total_failures' => $h->total_failures + 1,
            'last_failure_at' => now(),
            'last_error' => mb_substr(self::redact($error), 0, 1000),
            'status' => 'degraded',
        ]);
        if ($h->consecutive_failures >= self::BREAK_AFTER) {
            $h->status = 'disabled';
            $h->disabled_until = now()->addMinutes(self::COOLDOWN_MINUTES);
        }
        $h->save();

        if ($auth && $h->auth_failures >= (int) config('leadpilot.incidents.auth_failures_max', 3)) {
            $h->update(['status' => 'disabled', 'disabled_until' => now()->addDay()]);
            app(IncidentService::class)->raise('auth_failures', "Repeated authentication failures: {$provider}",
                "{$h->auth_failures} consecutive auth errors (HTTP {$httpStatus}). Provider disabled for 24h to avoid lockout. Last error: {$error}",
                null, ['provider' => $provider], "auth:{$provider}");
        }
    }
}
