<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryRun;
use App\Models\ProviderHealth;
use App\Models\SearchArm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live status of every discovery source, built only from what the database records:
 * the adapter's configuration, the provider health row (written after every real call),
 * the last discovery run, the last 7 days of runs, the companies that came from the source,
 * and whether the learning loop has rested the source. Nothing here calls the network.
 */
class SourceStatusService
{
    public function __construct(protected DiscoveryManager $discovery)
    {
    }

    /** @return array<string, array> keyed by source slug */
    public function all(): array
    {
        $slugs = array_keys($this->discovery->getAdapters());
        $health = ProviderHealth::whereIn('provider', $slugs)->get()->keyBy('provider');
        $lastRuns = DiscoveryRun::whereIn('source_slug', $slugs)->orderByDesc('id')->get()->unique('source_slug')->keyBy('source_slug');
        $week = DiscoveryRun::whereIn('source_slug', $slugs)->where('created_at', '>=', now()->subDays(7))
            ->select('source_slug', DB::raw('count(*) as runs'), DB::raw('sum(total_found) as found'), DB::raw('sum(new_leads) as new_leads'), DB::raw("sum(case when status = 'completed' then 1 else 0 end) as completed"))
            ->groupBy('source_slug')->get()->keyBy('source_slug');
        $companies = Schema::hasColumn('companies', 'source_provider')
            ? DB::table('companies')->whereNull('deleted_at')->whereIn('source_provider', $slugs)->select('source_provider', DB::raw('count(*) as n'))->groupBy('source_provider')->pluck('n', 'source_provider')
            : collect();
        $rests = Schema::hasTable('search_arms')
            ? SearchArm::whereIn('source_slug', $slugs)->whereNotNull('exhausted_until')->where('exhausted_until', '>', now())->orderByDesc('exhausted_until')->get()->unique('source_slug')->keyBy('source_slug')
            : collect();

        $out = [];
        foreach ($this->discovery->getAdapters() as $slug => $adapter) {
            $out[$slug] = $this->describe(
                $slug,
                $adapter->getSourceName(),
                $adapter->isConfigured(),
                $health->get($slug),
                $lastRuns->get($slug),
                $week->get($slug),
                (int) ($companies[$slug] ?? 0),
                $rests->get($slug),
            );
        }

        return $out;
    }

    protected function describe(string $slug, string $name, bool $configured, ?ProviderHealth $h, ?DiscoveryRun $run, $week, int $companies, ?SearchArm $rest): array
    {
        $now = now();
        $down = $h && $h->status === 'disabled' && $h->disabled_until && $h->disabled_until->isFuture();

        // One state, decided in priority order: configuration → circuit breaker → last real result → rest → never used.
        if (!$configured) {
            [$state, $label, $class] = ['not_configured', 'not configured', 'bg-secondary'];
        } elseif ($down) {
            [$state, $label, $class] = ['down', 'down · retry ' . $h->disabled_until->diffForHumans(), 'bg-danger'];
        } elseif ($run && $run->status === 'failed') {
            [$state, $label, $class] = ['failed', 'last run failed', 'bg-danger'];
        } elseif ($h && $h->status === 'degraded') {
            [$state, $label, $class] = ['degraded', 'errors · retrying', 'bg-warning text-dark'];
        } elseif ($rest) {
            [$state, $label, $class] = ['rested', 'rested until ' . $rest->exhausted_until->timezone(config('app.timezone'))->format('d M H:i'), 'bg-info text-dark'];
        } elseif ($run && $run->status === 'completed') {
            [$state, $label, $class] = ['live', 'live · ' . ($run->finished_at ?? $run->created_at)->diffForHumans(), 'bg-success'];
        } elseif ($h && $h->last_verified_at) {
            [$state, $label, $class] = ['verified', 'reachable · verified ' . $h->last_verified_at->diffForHumans(), 'bg-success'];
        } else {
            [$state, $label, $class] = ['ready', 'ready · never run', 'bg-light text-dark border'];
        }

        $detail = [];
        if ($run) {
            $when = ($run->finished_at ?? $run->created_at);
            $detail[] = 'last run ' . $when->diffForHumans() . ' (' . $run->status . ')';
            if ($run->status !== 'skipped') {
                $detail[] = $run->total_found . ' found';
                $detail[] = $run->new_leads . ' new';
                if ($run->duplicates) {
                    $detail[] = $run->duplicates . ' known';
                }
            }
        }
        if ($week && (int) $week->runs > 0) {
            $detail[] = '7 d: ' . (int) $week->runs . ' runs, ' . (int) $week->found . ' found, ' . (int) $week->new_leads . ' new';
        }
        if ($companies > 0) {
            $detail[] = $companies . ' compan' . ($companies === 1 ? 'y' : 'ies') . ' in database';
        }

        $error = null;
        if ($run && $run->errors) {
            $error = implode(' | ', (array) $run->errors);
        } elseif ($h && $h->status !== 'healthy' && $h->last_error) {
            $error = $h->last_error;
        }
        if (!$configured) {
            $error = null; // the badge already says so
        }
        if ($error !== null) {
            $error = mb_substr(\App\Services\Integrations\ProviderHealthService::redact($error), 0, 220);
        }
        // A completed run that logged an error (e.g. one mirror timed out, the retry succeeded) is a note, not a failure.
        $errorLevel = ($run && $run->status === "completed" && !in_array($state, ["down", "degraded", "failed"], true)) ? "warning" : "danger";

        return [
            'slug' => $slug,
            'name' => $name,
            'configured' => $configured,
            'state' => $state,
            'label' => $label,
            'class' => $class,
            'detail' => implode(' · ', $detail),
            'error' => $error,
            'error_level' => $error !== null ? $errorLevel : null,
            'health' => $h ? [
                'status' => $h->status,
                'last_success_at' => $h->last_success_at?->toIso8601String(),
                'last_failure_at' => $h->last_failure_at?->toIso8601String(),
                'total_calls' => (int) $h->total_calls,
                'total_failures' => (int) $h->total_failures,
            ] : null,
            'last_run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'at' => ($run->finished_at ?? $run->created_at)->toIso8601String(),
                'found' => (int) $run->total_found,
                'new_leads' => (int) $run->new_leads,
                'duplicates' => (int) $run->duplicates,
            ] : null,
            'companies' => $companies,
            'rested_until' => $rest?->exhausted_until?->toIso8601String(),
            'as_of' => $now->toIso8601String(),
        ];
    }
}
