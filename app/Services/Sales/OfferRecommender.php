<?php

namespace App\Services\Sales;

use App\Models\Lead;

/**
 * Recommends which service to offer, ONLY from evidence on record:
 * website-audit evidence keys, buying-signal types/services, and declared relevant services.
 * Every recommendation carries the evidence that justifies it; no evidence => no recommendation.
 */
class OfferRecommender
{
    /** @return array<int, array{service:string,label:string,pitch:string,score:float,evidence:array,typical_value_usd:array}> */
    public function recommend(Lead $lead, int $max = 3): array
    {
        $catalog = config('leadpilot.services');
        $company = $lead->company;
        $cand = [];
        $add = function (string $svc, float $w, string $evidence) use (&$cand, $catalog) {
            if (!isset($catalog[$svc])) {
                return;
            }
            $cand[$svc] ??= ['service' => $svc, 'label' => $catalog[$svc]['label'], 'pitch' => $catalog[$svc]['pitch'], 'score' => 0, 'evidence' => [], 'typical_value_usd' => $catalog[$svc]['typical_value_usd']];
            $cand[$svc]['score'] += $w;
            if (!in_array($evidence, $cand[$svc]['evidence'], true)) {
                $cand[$svc]['evidence'][] = $evidence;
            }
        };

        // 1. Website audit opportunities (strongest: directly observed)
        $audit = $company?->latestWebsiteAudit;
        if ($audit && $audit->status !== 'blocked_by_robots') {
            foreach ((array) $audit->opportunities as $o) {
                $add($o['service'], min(10, $o['weight'] / 3), $o['evidence'][0] ?? 'Website audit finding');
            }
        }

        // 2. Buying signals
        if ($company) {
            foreach ($company->buyingSignals()->active()->get() as $s) {
                foreach ($catalog as $svc => $def) {
                    if (in_array($s->signal_type, $def['triggers'], true) || ($s->metadata['evidence_key'] ?? null) && in_array($s->metadata['evidence_key'], $def['triggers'], true)) {
                        $add($svc, $s->confidence / 15, "{$s->title}");
                    }
                }
                if ($s->relevant_service && isset($catalog[$s->relevant_service])) {
                    $add($s->relevant_service, $s->confidence / 20, "{$s->title}");
                }
            }
        }

        // 3. Declared relevance (weakest — recorded by a user or source)
        foreach ((array) $lead->relevant_services as $svc) {
            $add((string) $svc, 2, 'Listed as relevant service on the lead record');
        }

        usort($cand, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values($cand), 0, $max);
    }
}
