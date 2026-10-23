<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IcpProfile extends Model
{
    use SoftDeletes;

    public const ARRAY_FIELDS = [
        'countries', 'regions', 'cities', 'industries', 'niches', 'company_sizes', 'business_types',
        'target_services', 'tech_include', 'tech_exclude', 'pain_points', 'keywords', 'contact_roles',
        'exclude_domains', 'exclude_keywords', 'sources', 'channels',
    ];

    public const BUSINESS_TYPES = [
        'local_business' => 'Local business (shop, clinic, restaurant, services)',
        'b2b' => 'B2B company',
        'saas' => 'SaaS / software company',
        'ecommerce' => 'E-commerce / retail',
        'agency' => 'Agency (marketing, design)',
        'nonprofit' => 'Non-profit / education',
        'government' => 'Government / public sector',
    ];

    public const WEBSITE_REQUIREMENTS = [
        'any' => 'Any',
        'has_website' => 'Must have a website',
        'no_website' => 'No website (new-site prospects)',
        'weak_website' => 'Website with audit gaps',
    ];

    protected $fillable = [
        'name', 'description', 'is_active',
        'countries', 'regions', 'cities', 'industries', 'niches', 'company_sizes', 'business_types',
        'target_services', 'website_requirement', 'tech_include', 'tech_exclude', 'pain_points', 'keywords',
        'min_purchase_ability', 'contact_roles', 'min_lead_score', 'min_google_rating', 'min_review_count',
        'exclude_domains', 'exclude_keywords', 'exclude_existing_customers',
        'sources', 'channels', 'max_results_per_run', 'monthly_budget_cents', 'objective',
        'last_run_at', 'created_by',
    ];

    protected function casts(): array
    {
        $casts = array_fill_keys(self::ARRAY_FIELDS, 'array');

        return $casts + [
            'is_active' => 'boolean',
            'exclude_existing_customers' => 'boolean',
            'last_run_at' => 'datetime',
            'min_lead_score' => 'integer',
            'max_results_per_run' => 'integer',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function list(string $field): array
    {
        return array_values(array_filter(array_map('trim', (array) ($this->{$field} ?? [])), fn ($v) => $v !== ''));
    }

    /**
     * Does a company satisfy this ICP's hard exclusion rules?
     * Returns null when OK, otherwise the human-readable reason.
     */
    public function exclusionReason(?string $domain, string $name, ?string $text = null): ?string
    {
        $domain = strtolower((string) $domain);
        foreach ($this->list('exclude_domains') as $ex) {
            $ex = strtolower(preg_replace('#^https?://(www\.)?#', '', $ex));
            if ($domain && ($domain === $ex || str_ends_with($domain, '.' . $ex))) {
                return "Domain {$domain} is on the exclusion list.";
            }
        }
        $hay = strtolower($name . ' ' . $text);
        foreach ($this->list('exclude_keywords') as $kw) {
            if ($kw !== '' && str_contains($hay, strtolower($kw))) {
                return "Matched exclusion keyword \"{$kw}\".";
            }
        }
        if ($this->exclude_existing_customers && $domain) {
            if (Company::where('domain', $domain)->where('status', 'customer')->exists()) {
                return 'Existing customer.';
            }
        }

        return null;
    }
}
