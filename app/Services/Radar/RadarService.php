<?php

namespace App\Services\Radar;

use App\Models\BuyingSignal;
use App\Models\RadarItem;
use App\Support\Country;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Buyer's Radar: puts every buying signal on one list with its intent level, and watches public forums for people
 * asking for software work. Forum source: Hacker News through its official free search API (Algolia), in particular
 * the monthly "Seeking freelancer" thread, where buyers post the work they need. Only public posts are read; sellers
 * offering services are classified out.
 */
class RadarService
{
    /** Search phrases for buyers on Hacker News (stories and comments). */
    public const HN_QUERIES = ['looking for a developer', 'looking for an agency', 'need a developer', 'recommend an agency', 'looking for a freelancer',
        'need someone to build', 'hire a developer to build'];

    public function __construct(protected IntentClassifier $intent)
    {
    }

    /** Mirror one buying signal onto the radar (kept in step on every save). */
    public function mirror(BuyingSignal $s): ?RadarItem
    {
        if ($s->verification_status === 'dismissed') {
            RadarItem::where('buying_signal_id', $s->id)->delete();

            return null;
        }
        $c = $s->company;
        $r = $this->intent->classify((string) $s->signal_type, $s->title . ' ' . $s->evidence_text, $s->expiry_date);
        $item = RadarItem::firstOrNew(['platform' => self::platformFor($s), 'external_id' => 'signal:' . $s->id]);
        $item->fill([
            'buying_signal_id' => $s->id, 'company_id' => $s->company_id, 'title' => mb_substr($s->title, 0, 300),
            'excerpt' => mb_substr(trim(strip_tags((string) $s->evidence_text)), 0, 600), 'url' => $s->source_url,
            'country' => $c?->country, 'category' => $c?->industry_group, 'service' => $s->relevant_service,
            'intent_level' => $r['level'], 'intent_score' => $r['score'], 'reasons' => $r['reasons'],
            'posted_at' => $s->observed_date ? $s->observed_date->copy()->startOfDay() : $s->created_at,
        ]);
        if ($s->expiry_date && $s->expiry_date->isPast()) {
            $item->status = 'dismissed'; // a closed tender is no longer a buyer
        }
        $item->save();

        return $item;
    }

    public static function platformFor(BuyingSignal $s): string
    {
        $src = strtolower((string) ($s->metadata['source'] ?? ''));
        $host = strtolower((string) parse_url((string) $s->source_url, PHP_URL_HOST));

        return match (true) {
            str_starts_with($src, 'hn_') || str_contains($host, 'ycombinator') => 'Hacker News',
            str_contains($src, 'ocds') || $s->signal_type === 'procurement_rfp' => 'Public tenders',
            in_array($src, ['remoteok', 'himalayas', 'arbeitnow', 'remotive_public_api', 'ats_public_api'], true) => 'Job boards',
            $src === 'website_audit' || $s->signal_type === 'website_issues' => 'Website audit',
            $src === 'google_places' => 'Google Maps',
            default => 'Other signals',
        };
    }

    /** New buyer posts from Hacker News since $days ago. @return array{added: int, seen: int} */
    public function scanHackerNews(int $days = 7): array
    {
        $since = now()->subDays($days)->timestamp;
        $hits = [];
        // 1. the monthly "Freelancer? Seeking freelancer?" thread: top-level comments that start with SEEKING FREELANCER
        $thread = Http::timeout(15)->get('https://hn.algolia.com/api/v1/search_by_date', ['query' => 'Seeking freelancer', 'tags' => 'story,author_whoishiring', 'hitsPerPage' => 1])->json('hits.0');
        if (!empty($thread['objectID'])) {
            foreach ((array) Http::timeout(15)->get('https://hn.algolia.com/api/v1/search_by_date', ['tags' => "comment,story_{$thread['objectID']}", 'hitsPerPage' => 300,
                'numericFilters' => "created_at_i>{$since}"])->json('hits') as $h) {
                if (preg_match('/^\s*(<p>)?\s*seeking freelancer/i', (string) ($h['comment_text'] ?? '')) && (string) ($h['parent_id'] ?? '') === (string) $thread['objectID']) {
                    $hits[$h['objectID']] = $h + ['_kind' => 'seeking_freelancer'];
                }
            }
        }
        // 2. people asking in stories / comments
        foreach (self::HN_QUERIES as $q) {
            foreach ((array) Http::timeout(15)->get('https://hn.algolia.com/api/v1/search_by_date', ['query' => "\"{$q}\"", 'tags' => '(story,comment)', 'hitsPerPage' => 30,
                'numericFilters' => "created_at_i>{$since}"])->json('hits') as $h) {
                $hits[$h['objectID']] ??= $h + ['_kind' => 'forum_post'];
            }
        }

        $added = 0;
        foreach ($hits as $id => $h) {
            $text = html_entity_decode(strip_tags(str_replace(['<p>', '<br>'], "\n", (string) ($h['comment_text'] ?? $h['story_text'] ?? ''))), ENT_QUOTES | ENT_HTML5);
            $title = trim((string) ($h['title'] ?? '')) ?: Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 140);
            if ($title === '') {
                continue;
            }
            $r = $this->intent->classify($h['_kind'], $title . "\n" . $text);
            if ($r['level'] === 'normal') {
                continue; // a passing mention, or a seller: not worth a row
            }
            $item = RadarItem::firstOrNew(['platform' => 'Hacker News', 'external_id' => 'hn:' . $id]);
            $isNew = !$item->exists;
            $item->fill([
                'title' => mb_substr($title, 0, 300), 'excerpt' => mb_substr(trim($text), 0, 600), 'url' => "https://news.ycombinator.com/item?id={$id}",
                'author' => $h['author'] ?? null, 'country' => Country::fromText($title . ' ' . $text), 'service' => self::serviceFor($title . ' ' . $text),
                'category' => $h['_kind'] === 'seeking_freelancer' ? 'Seeking freelancer' : 'Forum request',
                'intent_level' => $r['level'], 'intent_score' => $r['score'], 'reasons' => $r['reasons'],
                'posted_at' => isset($h['created_at_i']) ? \Carbon\Carbon::createFromTimestamp($h['created_at_i']) : now(),
            ])->save();
            $added += $isNew ? 1 : 0;
        }

        return ['added' => $added, 'seen' => count($hits)];
    }

    /** The service a free-text request is about (best guess from its words; null when unclear). */
    public static function serviceFor(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(ios|android|mobile app|react native|flutter)\b/i', $text) => 'mobile_app',
            (bool) preg_match('/\b(shopify|woocommerce|e-?commerce|online store)\b/i', $text) => 'ecommerce',
            (bool) preg_match('/\b(crm|erp|inventory|pos)\b/i', $text) => 'erp_pos',
            (bool) preg_match('/\b(ai|llm|gpt|chatbot|automation)\b/i', $text) => 'ai_automation',
            (bool) preg_match('/\b(laravel|php)\b/i', $text) => 'laravel_php',
            (bool) preg_match('/\b(api|integration|integrate)\b/i', $text) => 'api_integration',
            (bool) preg_match('/\b(mvp|saas)\b/i', $text) => 'saas_mvp',
            (bool) preg_match('/\b(website|web site|landing page|wordpress|webflow)\b/i', $text) => 'web_dev',
            default => null,
        };
    }
}
