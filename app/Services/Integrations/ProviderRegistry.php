<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\ProviderHealth;
use App\Services\Discovery\DiscoveryManager;

/**
 * Modular integration registry + provider selection.
 *
 * select($capability) returns providers ordered by: configured -> not circuit-broken -> within budget
 * -> config preference (free first). Callers try them in order and fall back on failure.
 * A provider is only reported "verified" after a real connectivity test succeeded (last_verified_at).
 */
class ProviderRegistry
{
    /** slug => [label, capability, paid(bool), est_cost_cents per call, docs, setup hint] */
    public const PROVIDERS = [
        'overture_places' => ['Overture Maps Places', 'local_discovery', false, 0, 'https://docs.overturemaps.org/guides/places/', 'No key. Bundled DuckDB CLI (tools/duckdb) reads the public release. Data CDLA-Permissive-2.0 / Apache-2.0.'],
        'osm_overpass' => ['OpenStreetMap Overpass', 'local_discovery', false, 0, 'https://wiki.openstreetmap.org/wiki/Overpass_API', 'No key. Data © OpenStreetMap contributors (ODbL).'],
        'google_places' => ['Google Places API (New)', 'local_discovery', true, 4, 'https://developers.google.com/maps/documentation/places/web-service/text-search', 'Set GOOGLE_PLACES_API_KEY; ~$35/1,000 after 1,000 free/month (Enterprise SKU).'],
        'uk_contracts_finder' => ['UK Contracts Finder', 'procurement', false, 0, 'https://www.contractsfinder.service.gov.uk/apidocumentation', 'No key.'],
        'uk_find_a_tender' => ['UK Find a Tender', 'procurement', false, 0, 'https://www.find-tender.service.gov.uk/apidocumentation/1.0/GET-ocdsReleasePackages', 'No key.'],
        'eu_ted' => ['EU TED (Tenders Electronic Daily)', 'procurement', false, 0, 'https://docs.ted.europa.eu/api/latest/index.html', 'No key for search.'],
        'ats_watchlist' => ['Company job boards (Greenhouse / Lever / Ashby / Workable)', 'hiring_signals', false, 0, 'https://developers.greenhouse.io/job-board.html', 'No key. Boards are found automatically by website audits; manual entries ("greenhouse:token") in an ICP also work.'],
        'hn_hiring' => ['Hacker News: Who is hiring', 'hiring_signals', false, 0, 'https://hn.algolia.com/api', 'No key. Monthly thread via the official Algolia HN API.'],
        'remoteok' => ['Remote OK jobs', 'hiring_signals', false, 0, 'https://remoteok.com/api', 'No key. Terms: link back to the job and credit Remote OK (kept on every signal).'],
        'himalayas' => ['Himalayas jobs', 'hiring_signals', false, 0, 'https://himalayas.app/api', 'No key. Remote jobs; the job link is kept on every signal.'],
        'arbeitnow' => ['Arbeitnow jobs', 'hiring_signals', false, 0, 'https://www.arbeitnow.com/blog/job-board-api', 'No key. Europe (mostly Germany); link back kept.'],
        'public_job_demand' => ['Remotive', 'hiring_signals', false, 0, 'https://github.com/remotive-com/remote-jobs-api', 'Disabled: terms forbid lead collection.'],
        'companies_house' => ['Companies House (new UK companies)', 'b2b_discovery', false, 0, 'https://developer.company-information.service.gov.uk/', 'Free API key (register, create an application, REST key). New companies in the last 14 days, by SIC code.'],
        'wikidata_orgs' => ['Wikidata organisations', 'b2b_discovery', false, 0, 'https://query.wikidata.org/', 'No key. CC0 data: companies by industry + country with official website.'],
        'apollo_orgs' => ['Apollo.io Organizations', 'b2b_discovery', true, 1, 'https://docs.apollo.io/', 'Add API key in Integrations.'],
        'dns_mx' => ['DNS/MX + SMTP mailbox check (built-in)', 'email_verification', false, 0, null, 'Built in, free. The mailbox probe needs outbound port 25 (LEADPILOT_SMTP_PROBE).'],
        'hunter' => ['Hunter Email Verifier', 'email_verification', true, 2, 'https://hunter.io/api-documentation/v2', 'HUNTER_API_KEY; 50 free credits/month (0.5 per verification).'],
        'pagespeed' => ['Google PageSpeed Insights', 'website_performance', false, 0, 'https://developers.google.com/speed/docs/insights/v5/get-started', 'Optional PAGESPEED_API_KEY; set PAGESPEED_ENABLED=true.'],
        'ollama' => ['Ollama (local LLM)', 'llm', false, 0, 'https://ollama.com/download', 'Free, no key, runs on this PC: install Ollama, `ollama pull qwen2.5:3b`. LEADPILOT_OLLAMA_URL/MODEL.'],
        'openai' => ['OpenAI', 'llm', true, 1, 'https://platform.openai.com/docs', 'OPENAI_API_KEY (no free tier).'],
        'gemini' => ['Google Gemini', 'llm', false, 0, 'https://ai.google.dev/gemini-api/docs/pricing', 'GEMINI_API_KEY (free tier on Flash-Lite; free-tier data may be used by Google — only public facts are sent).'],
        'smtp' => ['SMTP (e.g. Gmail)', 'email_sending', false, 0, 'https://support.google.com/mail/answer/22839', 'MAIL_* in .env. Personal Gmail ≈500/day.'],
        'imap' => ['IMAP reply sync (e.g. Gmail)', 'reply_sync', false, 0, 'https://support.google.com/accounts/answer/185833', 'IMAP_ENABLED=true + IMAP_PASSWORD (Gmail App Password, needs 2-Step Verification).'],
    ];

    public function __construct(protected ?DiscoveryManager $discovery = null)
    {
    }

    public function definition(string $slug): ?array
    {
        $d = self::PROVIDERS[$slug] ?? null;

        return $d ? ['slug' => $slug, 'label' => $d[0], 'capability' => $d[1], 'paid' => $d[2], 'est_cost_cents' => $d[3], 'docs' => $d[4], 'setup' => $d[5]] : null;
    }

    public function isConfigured(string $slug): bool
    {
        return match ($slug) {
            'osm_overpass', 'wikidata_orgs', 'uk_contracts_finder', 'uk_find_a_tender', 'eu_ted', 'ats_watchlist', 'hn_hiring', 'dns_mx' => true,
            'remoteok', 'himalayas', 'arbeitnow' => (bool) config("leadpilot.sources.job_feeds.{$slug}", true),
            'ollama' => $this->ollamaReachable(),
            'overture_places' => is_file((string) config('leadpilot.sources.overture_places.duckdb_bin')),
            'public_job_demand' => (bool) config('leadpilot.sources.remotive.enabled'),
            'google_places' => (bool) (config('leadpilot.sources.google_places.api_key') ?: $this->integrationKey('google_places')),
            'apollo_orgs' => (bool) $this->integrationKey('apollo'),
            'companies_house' => (bool) (config('leadpilot.keys.companies_house') ?: $this->integrationKey('companies_house')),
            'hunter' => (bool) (config('leadpilot.keys.hunter') ?: $this->integrationKey('hunter')),
            'pagespeed' => (bool) config('leadpilot.sources.pagespeed.enabled'),
            'openai' => (bool) (config('leadpilot.keys.openai') ?: $this->integrationKey('openai')),
            'gemini' => (bool) $this->geminiKey(),
            'smtp' => config('mail.default') === 'smtp' ? (bool) (config('mail.mailers.smtp.host') && config('mail.mailers.smtp.username')) : !in_array(config('mail.default'), ['log', 'array'], true),
            'imap' => (bool) (config('leadpilot.imap.enabled') && config('leadpilot.imap.password') && config('leadpilot.imap.username')),
            default => false,
        };
    }

    /** Ollama counts as configured when enabled and its local API answers (checked at most every 10 minutes). */
    public function ollamaReachable(): bool
    {
        if (!config('leadpilot.sources.ollama.enabled')) {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\Cache::remember('ollama_reachable', now()->addMinutes(10), function () {
            try {
                return \Illuminate\Support\Facades\Http::timeout(2)->connectTimeout(2)->get(config('leadpilot.sources.ollama.url') . '/api/tags')->successful();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** Gemini key from .env or from Settings → Integrations (SQA bug 14: every key can be set in the app). */
    public function geminiKey(): ?string
    {
        return config('leadpilot.keys.gemini') ?: $this->integrationKey('gemini');
    }

    public function integrationKeyFor(string $provider): ?string
    {
        return $this->integrationKey($provider);
    }

    protected function integrationKey(string $provider): ?string
    {
        try {
            $i = Integration::where('provider', $provider)->first();

            return ($i && $i->is_active) ? ($i->getDecryptedCredentials()['api_key'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ordered candidates for a capability: configured, not disabled, budget-permitting. */
    public function select(string $capability, ?BudgetGuard $budget = null): array
    {
        $budget ??= app(BudgetGuard::class);
        $out = [];
        foreach ((array) config("leadpilot.providers.{$capability}", []) as $slug) {
            $def = $this->definition($slug);
            if (!$def || !$this->isConfigured($slug) || !app(ProviderHealthService::class)->isAvailable($slug)) {
                continue;
            }
            if ($def['paid'] && $def['est_cost_cents'] > 0 && !$budget->allows($slug, $def['est_cost_cents'])) {
                continue;
            }
            $out[] = $slug;
        }

        return $out;
    }

    /** Status rows for the setup/health dashboard. */
    public function statusReport(): array
    {
        $rows = [];
        $health = ProviderHealth::all()->keyBy('provider');
        foreach (array_keys(self::PROVIDERS) as $slug) {
            $def = $this->definition($slug);
            $h = $health[$slug] ?? null;
            $configured = $this->isConfigured($slug);
            $rows[$slug] = $def + [
                'configured' => $configured,
                'status' => !$configured ? 'unconfigured' : ($h->status ?? 'unknown'),
                'verified_at' => $h?->last_verified_at,
                'last_error' => $h?->last_error,
                'disabled_until' => $h?->disabled_until,
                'calls' => $h->total_calls ?? 0,
                'failures' => $h->total_failures ?? 0,
            ];
        }

        return $rows;
    }
}
