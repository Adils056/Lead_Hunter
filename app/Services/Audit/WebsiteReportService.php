<?php

namespace App\Services\Audit;

use App\Models\Company;
use App\Models\WebsiteReport;
use App\Services\Sales\LlmClient;

/**
 * Builds the client-facing website report: the business's own audit, its local competitors' audits, the benchmark,
 * the software it needs, and an AI reading of all of it. The AI sees only public facts from the audits and may only
 * restate them; a summary that brings in a number the facts do not contain is dropped.
 */
class WebsiteReportService
{
    public function __construct(protected WebsiteAuditService $audits, protected CompetitorBenchmark $bench)
    {
    }

    public function generate(Company $company, ?int $userId = null, int $auditSeconds = 25): WebsiteReport
    {
        @set_time_limit(120);
        $own = $company->latestWebsiteAudit;
        if ($company->website && (!$own || !$own->audited_at || $own->audited_at->lt(now()->subDays(30)))) {
            try {
                $this->audits->audit($company);
            } catch (\Throwable) {
            }
            $company->load('latestWebsiteAudit');
        }
        $competitors = $this->bench->competitors($company);
        $this->bench->auditMissing($competitors, $auditSeconds);
        $benchmark = $this->bench->build($company, $competitors);
        $solutions = $this->bench->solutions($company);
        [$ai, $provider] = $this->analyse($company, $benchmark, $solutions);

        return WebsiteReport::create(['company_id' => $company->id, 'benchmark' => $benchmark, 'solutions' => $solutions, 'ai' => $ai,
            'ai_provider' => $provider, 'created_by' => $userId]);
    }

    /** @return array{0: ?array, 1: ?string} */
    public function analyse(Company $company, array $benchmark, array $solutions): array
    {
        $audit = $company->latestWebsiteAudit;
        $facts = [
            'business' => $company->name, 'kind_of_business' => $company->industry_group ?: $company->industry, 'city' => $company->city, 'country' => $company->country,
            'website' => $company->website, 'technology' => array_slice((array) $company->tech_stack, 0, 8),
            'website_findings' => array_map(fn ($c) => ($c['label'] ?? '') . ': ' . ($c['evidence'] ?? ''), array_slice($audit?->issues() ?? [], 0, 10)),
            'scores_vs_local_competitors' => collect($benchmark['areas'])->map(fn ($a) => ['you' => $a['you'], 'competitor_average' => $a['average'], 'best_competitor' => $a['best']])->all(),
            'ahead_in' => $benchmark['leads_in'], 'behind_in' => $benchmark['behind_in'],
            'competitors_compared' => count($benchmark['competitors']),
            'typical_software_for_this_kind_of_business' => array_column($solutions, 'solution'),
        ];
        $res = app(LlmClient::class)->json(
            'You are a software consultant writing for a small business owner. Use ONLY the facts given: no new numbers, names, statistics or promises. '
            . 'Identify the kind of business, summarise in 2-3 plain sentences how its website compares with local competitors, and recommend up to 4 software '
            . 'solutions that would help it win customers from those competitors, each with a one-sentence reason tied to the facts. '
            . 'Return JSON {"category": string, "summary": string, "needs": [{"solution": string, "why": string}], "competitor_insight": string}.',
            json_encode($facts, JSON_UNESCAPED_UNICODE),
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['category', 'summary', 'needs', 'competitor_insight'], 'properties' => [
                'category' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'competitor_insight' => ['type' => 'string'],
                'needs' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['solution', 'why'],
                    'properties' => ['solution' => ['type' => 'string'], 'why' => ['type' => 'string']]]],
            ]],
            900, ['ollama']
        );
        $out = $res['data'] ?? null;
        if (!is_array($out) || !is_string($out['summary'] ?? null)) {
            return [null, null];
        }
        $known = json_encode($facts);
        $invented = fn (string $text) => collect(preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m) ? $m[0] : [])->contains(fn ($n) => !str_contains($known, $n));
        $clean = fn ($v, $len) => mb_substr(trim(strip_tags((string) $v)), 0, $len);
        $ai = [
            'category' => $clean($out['category'] ?? '', 80),
            'summary' => $invented((string) $out['summary']) ? null : $clean($out['summary'], 700),
            'competitor_insight' => $invented((string) ($out['competitor_insight'] ?? '')) ? null : $clean($out['competitor_insight'] ?? '', 400),
            'needs' => array_values(array_filter(array_map(fn ($n) => is_array($n) && !empty($n['solution'])
                ? ['solution' => $clean($n['solution'], 120), 'why' => $invented((string) ($n['why'] ?? '')) ? '' : $clean($n['why'] ?? '', 260)] : null, array_slice((array) ($out['needs'] ?? []), 0, 4)))),
        ];

        return [$ai, $res['provider'] ?? null];
    }
}
