<?php

namespace App\Services\Radar;

/**
 * Buying intent of a post or signal, 0-100, with the reasons that set it. Hot = someone is asking for this work now
 * (a request, a tender, a budget or price question); Warm = a clear need without an ask (hiring developers, new
 * funding, a tech change); Normal = a fit or a problem we noticed ourselves. Sellers ("for hire") are not buyers.
 */
class IntentClassifier
{
    /** Starting score by kind of sighting. */
    public const BASE = [
        'vendor_request' => 75, 'project_requirements' => 75, 'procurement_rfp' => 70, 'erp_crm_request' => 70, 'seeking_freelancer' => 70,
        'developer_hiring' => 55, 'mobile_hiring' => 55, 'ai_hiring' => 55, 'tech_stack_change' => 45, 'funding_announcement' => 40,
        'business_expansion' => 40, 'business_launch' => 45, 'forum_post' => 35, 'website_issues' => 25,
    ];

    protected const RULES = [
        // [regex, points, reason]
        ['/\b(looking for|need|seeking|searching for|want to hire|hiring)\s+(an?\s+|some\s+)?(web|app|mobile|software|php|laravel|shopify|wordpress|react|full.?stack|freelance)?\s*(developer|dev|agency|freelancer|company|team|studio|contractor|someone)\b/i', 20, 'Asks for a developer / agency'],
        ['/\b(need|want|looking for|build|rebuild|redesign|create)\s+(an?\s+|our\s+|my\s+|new\s+)*(website|web\s?site|web app|mobile app|app|e-?commerce store|online store|crm|erp|portal|mvp|booking system|software)\b/i', 15, 'Wants something built'],
        ['/\b(recommend|recommendation|suggest)\w*\b.{0,40}\b(agency|developer|freelancer|company)\b/i', 15, 'Asks for a recommendation'],
        ['/\b(rfp|rfq|request for (proposal|quotation)|tender|invitation to bid|itt)\b/i', 10, 'Formal request for proposals'],
        ['/(\$|£|€|usd|gbp|eur|aed|sar|pkr|rs\.?)\s?\d[\d,.]*\s?k?\b|\b\d[\d,.]*\s?(k|usd|dollars|pounds|euros)\b|\bbudget\b/i', 15, 'Mentions a budget'],
        ['/\b(how much|price|pricing|quote|quotation|cost|estimate|rates?)\b/i', 10, 'Asks about price'],
        ['/\b(asap|urgent|urgently|immediately|this week|by (monday|tuesday|wednesday|thursday|friday|end of (the )?month)|deadline)\b/i', 10, 'Urgent / has a deadline'],
    ];

    /** Offers of work (sellers), not requests: never a buyer. */
    protected const SELLER = '/\b(for hire|seeking work|available for (work|hire|projects)|i am a (freelance|developer)|hire me|my services|we offer|our services|portfolio:)\b|^\s*\[?for hire\]?/i';

    /** @return array{score: int, level: string, reasons: list<string>} */
    public function classify(string $kind, string $text, ?\DateTimeInterface $deadline = null): array
    {
        $score = self::BASE[$kind] ?? 30;
        $reasons = [];
        if (preg_match(self::SELLER, $text)) {
            return ['score' => 5, 'level' => 'normal', 'reasons' => ['Offers services (a seller, not a buyer)']];
        }
        foreach (self::RULES as $i => [$re, $points, $why]) {
            if ($i === 0 && str_ends_with($kind, '_hiring')) {
                continue; // a job post always "hires a developer": that is its kind, not an extra sign of buying from an agency
            }
            if (preg_match($re, $text)) {
                $score += $points;
                $reasons[] = $why;
            }
        }
        if ($deadline && $deadline > now() && $deadline < now()->addDays(45)) {
            $score += 5;
            $reasons[] = 'Closes within 45 days';
        }
        $score = max(0, min(100, $score));

        return ['score' => $score, 'level' => $score >= 70 ? 'hot' : ($score >= 45 ? 'warm' : 'normal'), 'reasons' => $reasons];
    }
}
