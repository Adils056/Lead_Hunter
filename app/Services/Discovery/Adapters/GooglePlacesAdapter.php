<?php

namespace App\Services\Discovery\Adapters;

use App\Models\Integration;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Support\Facades\Http;

/**
 * Local-business discovery via the official Google Places API (New) — Text Search.
 * https://developers.google.com/maps/documentation/places/web-service/text-search
 *
 * Collects only what Google returns: name, address, website, phone, rating, review count,
 * business status, primary type. No person data (Places has none) => leads are created as
 * 'needs_enrichment' until a public business email is found on the company's own website.
 *
 * Billing: the field mask decides the SKU. Cost per request is configured as an ESTIMATE in
 * config/leadpilot.php — check Google's current price list before relying on it.
 * Terms: Google Maps Platform terms restrict caching of Places content (place_id may be stored).
 * See docs/LEAD_HUNTING_ENGINE.md -> "Compliance".
 */
class GooglePlacesAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'google_places';
    protected string $sourceName = 'Google Places (Local Business Discovery)';
    protected string $providerType = 'local_business_directory';
    protected string $description = 'Finds local businesses by niche + city using the official Google Places API (New).';

    protected function apiKey(): ?string
    {
        $integration = Integration::where('provider', 'google_places')->first();
        if ($integration && $integration->is_active) {
            $key = $integration->getDecryptedCredentials()['api_key'] ?? null;
            if ($key) {
                return $key;
            }
        }

        return config('leadpilot.sources.google_places.api_key') ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey());
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'GOOGLE_PLACES_API_KEY not set.', 'details' => []];
        }
        $res = $this->search('coffee shop in Islamabad', null, 1);

        return [
            'success' => $res->successful(),
            'message' => $res->successful() ? 'Google Places API reachable.' : 'Google Places API error: HTTP ' . $res->status() . ' ' . $res->json('error.message'),
            'details' => ['status' => $res->status()],
        ];
    }

    protected function search(string $query, ?string $pageToken, int $pageSize)
    {
        $body = ['textQuery' => $query, 'pageSize' => max(1, min(20, $pageSize))];
        if ($pageToken) {
            $body['pageToken'] = $pageToken;
        }

        return Http::timeout(20)
            ->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey(),
                'X-Goog-FieldMask' => config('leadpilot.sources.google_places.field_mask') . ',nextPageToken',
            ])
            ->post('https://places.googleapis.com/v1/places:searchText', $body);
    }

    /**
     * Options: queries (string[]) | niches + cities + country, limit, min_rating (float),
     * min_reviews (int), website_requirement (any|has_website|no_website|weak_website), icp.
     */
    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = (int) ($options['limit'] ?? 20);
        $queries = $options['queries'] ?? [];
        $costPer = (int) config('leadpilot.sources.google_places.est_cost_cents_per_request', 4);
        $websiteReq = $options['website_requirement'] ?? 'any';
        $minRating = $options['min_rating'] ?? null;
        $minReviews = $options['min_reviews'] ?? null;

        if (!$queries) {
            $result->addError('No search queries. Add niches and cities to the ICP.');

            return;
        }

        $processed = 0;
        foreach ($queries as $query) {
            $pageToken = null;
            $pages = 0;
            do {
                $res = $this->search($query, $pageToken, min(20, $limit - $processed));
                $result->estimatedCostCents += $costPer;
                $pages++;

                if (!$res->successful()) {
                    $result->addError("Places query \"{$query}\" failed: HTTP {$res->status()} " . ($res->json('error.message') ?? ''));
                    break;
                }

                foreach ((array) $res->json('places', []) as $p) {
                    if ($processed >= $limit) {
                        break 3;
                    }
                    $result->totalFound++;

                    $website = $p['websiteUri'] ?? null;
                    $rating = $p['rating'] ?? null;
                    $reviews = $p['userRatingCount'] ?? null;
                    $status = $p['businessStatus'] ?? null;

                    // ICP filters (data-driven, nothing inferred)
                    if ($status && $status !== 'OPERATIONAL') {
                        $result->excludedCount++;
                        continue;
                    }
                    if ($websiteReq === 'has_website' && !$website) {
                        $result->excludedCount++;
                        continue;
                    }
                    if ($websiteReq === 'no_website' && $website) {
                        $result->excludedCount++;
                        continue;
                    }
                    if ($minRating !== null && $rating !== null && $rating < $minRating) {
                        $result->excludedCount++;
                        continue;
                    }
                    if ($minReviews !== null && ($reviews ?? 0) < $minReviews) {
                        $result->excludedCount++;
                        continue;
                    }

                    [$city, $country] = $this->cityCountry($p['addressComponents'] ?? []);
                    $processed++;

                    $up = $ingestor->upsertCompany([
                        'name' => $p['displayName']['text'] ?? '',
                        'website' => $website,
                        'industry' => $p['primaryTypeDisplayName']['text'] ?? ($options['industry'] ?? null),
                        'city' => $city,
                        'country' => $country,
                        'public_phone' => $p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? null,
                        'public_address' => $p['formattedAddress'] ?? null,
                        'external_id' => $p['id'] ?? null,
                        'google_rating' => $rating,
                        'google_review_count' => $reviews,
                        'business_status' => $status,
                        'source_url' => $p['googleMapsUri'] ?? null,
                        'confidence' => 90,
                    ], $result);

                    if (!$up['company']) {
                        continue;
                    }
                    $company = $up['company'];

                    $services = $website ? ['web_redesign'] : ['web_dev'];
                    if (!$website) {
                        $ingestor->addSignal($company, [
                            'signal_type' => 'website_issues',
                            'title' => 'No website listed on Google Business Profile',
                            'source_url' => $p['googleMapsUri'] ?? null,
                            'evidence_text' => sprintf(
                                'Google Places returned no websiteUri for this %s listing%s.',
                                $p['primaryTypeDisplayName']['text'] ?? 'business',
                                $reviews ? " ({$reviews} Google reviews, rating {$rating})" : ''
                            ),
                            'confidence' => 90,
                            'relevant_service' => 'web_dev',
                            'expiry_date' => now()->addDays(90)->toDateString(),
                            'metadata' => ['place_id' => $p['id'] ?? null, 'source' => 'google_places', 'evidence_key' => 'no_website'],
                        ], $result);
                    }

                    $lead = $ingestor->ensureLead($company, [], $result, $services, $p['googleMapsUri'] ?? null, $p['id'] ?? null);
                    $result->recordItem([
                        'company' => $company->name,
                        'website' => $website,
                        'phone' => $company->public_phone,
                        'rating' => $rating,
                        'reviews' => $reviews,
                        'lead_id' => $lead?->id,
                    ]);
                }

                $pageToken = $res->json('nextPageToken');
            } while ($pageToken && $processed < $limit && $pages < 3);
        }
    }

    protected function cityCountry(array $components): array
    {
        $city = $country = null;
        foreach ($components as $c) {
            $types = (array) ($c['types'] ?? []);
            if (!$city && (in_array('locality', $types) || in_array('postal_town', $types))) {
                $city = $c['longText'] ?? null;
            }
            if (in_array('country', $types)) {
                $country = $c['longText'] ?? null;
            }
        }

        return [$city, $country];
    }
}
