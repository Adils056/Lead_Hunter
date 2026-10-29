<?php

namespace App\Services\Discovery;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\DataProvenance;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Services\Audit\SafeFetcher;

/**
 * Single entry point for writing discovered prospects.
 *
 * Integrity rules enforced here (so no adapter can bypass them):
 *  1. Never invent a domain, email, phone, person, size or tech stack. Missing = null.
 *  2. Dedupe companies by external_id -> domain -> exact name.
 *  3. Apply ICP exclusion rules before anything is written.
 *  4. Record field-level provenance for every value actually obtained.
 *  5. If no real person is known, the lead is created with contact_status
 *     'needs_enrichment' (or 'generic_inbox' for info@-style public addresses),
 *     which the Eligibility Gate blocks from automated outreach.
 */
class ProspectIngestor
{
    /** Words that make "x.y@" a department, not a person ("info.sales@", "customer_service@"). */
    public const NOT_A_NAME = '/^(info|contact|hello|sales|admin|office|enquiries|enquiry|support|team|mail|hi|business|customer|service|services|accounts|billing|bookings?|reception|marketing|hr|jobs|careers|hiring|press|media|web|webmaster|noreply|no|reply|general|main|front|desk|the|and|co|ltd|inc|group|clinic|dental|law|legal)$/';

    public function __construct(
        protected string $source,          // machine source key, e.g. google_places
        protected string $sourceName,      // human name stored on leads.source_name
        protected ?IcpProfile $icp = null,
    ) {
    }

    /**
     * @param array $data company fields: name (required), website, domain, industry, country, city,
     *                    public_email, public_phone, public_address, description, size_range,
     *                    tech_stack, external_id, google_rating, google_review_count, business_status, source_url
     * @return array{company: ?Company, created: bool, excluded: ?string}
     */
    public function upsertCompany(array $data, DiscoveryResult $result): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $data['website'] = SafeFetcher::safeUrl($data['website'] ?? null);
        $data['source_url'] = SafeFetcher::safeUrl($data['source_url'] ?? null);
        if ($name === '') {
            return ['company' => null, 'created' => false, 'excluded' => 'Missing company name.'];
        }

        $domain = $data['domain'] ?? Company::normalizeDomain($data['website'] ?? null);
        if (!$domain && !empty($data['public_email'])) {
            $domain = Company::domainFromEmail($data['public_email']);
        }

        if (($reason = \App\Support\ChainBrand::reason($name, $domain))
            || ($this->icp && ($reason = $this->icp->exclusionReason($domain, $name, $data['description'] ?? null)))) {
            $result->excludedCount++;
            $result->recordItem(['company' => $name, 'excluded' => $reason]);

            return ['company' => null, 'created' => false, 'excluded' => $reason];
        }

        $company = null;
        if (!empty($data['external_id'])) {
            $company = Company::where('external_id', $data['external_id'])->first();
        }
        if (!$company && $domain) {
            $company = Company::where('domain', $domain)->first();
        }
        if (!$company) {
            // Name fallback only when it can't be two different branches (different place ids / cities).
            $company = Company::where('name', $name)
                ->when(!empty($data['external_id']), fn ($q) => $q->whereNull('external_id'))
                ->when(!empty($data['city']), fn ($q) => $q->where(fn ($w) => $w->whereNull('city')->orWhere('city', $data['city'])))
                ->first();
        }

        $fields = array_filter([
            'name' => $name,
            'website' => $data['website'] ?? null,
            'domain' => $domain,
            'industry' => $data['industry'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'size_range' => $data['size_range'] ?? null,
            'description' => $data['description'] ?? null,
            'public_email' => $data['public_email'] ?? null,
            'public_phone' => $data['public_phone'] ?? null,
            'public_address' => $data['public_address'] ?? null,
            'tech_stack' => $data['tech_stack'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'google_rating' => $data['google_rating'] ?? null,
            'google_review_count' => $data['google_review_count'] ?? null,
            'business_status' => $data['business_status'] ?? null,
            'source_url' => $data['source_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $created = false;
        if (!$company) {
            $company = Company::create($fields + [
                'source_provider' => $this->source,
                'discovery_date' => now(),
                'status' => 'prospect',
            ]);
            $created = true;
            $result->newCompaniesCount++;
            $toRecord = $fields;
        } else {
            // Only fill blanks; never overwrite data a human or better source already set.
            $fill = [];
            foreach ($fields as $k => $v) {
                if (empty($company->{$k})) {
                    $fill[$k] = $v;
                }
            }
            if ($fill) {
                $company->update($fill);
            }
            $result->duplicatesCount++;
            $toRecord = $fill;
        }

        unset($toRecord['name']);
        DataProvenance::record($company, $toRecord, $this->source, $data['source_url'] ?? null, 'api', (int) ($data['confidence'] ?? 85));

        return ['company' => $company, 'created' => $created, 'excluded' => null];
    }

    /**
     * Create the lead row for a company. Never fabricates a person.
     *
     * @param array $person optional: first_name, last_name, job_title, email, phone, profile_url, confidence
     */
    public function ensureLead(Company $company, array $person, DiscoveryResult $result, array $services = [], ?string $sourceUrl = null, ?string $sourceId = null): ?Lead
    {
        $email = isset($person['email']) ? strtolower(trim($person['email'])) : null;
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }
        $hasPerson = !empty($person['first_name']) || !empty($person['last_name']);

        $existing = $email
            ? Lead::where('email', $email)->first()
            : Lead::where('company_id', $company->id)->first();
        if ($existing) {
            return null;
        }

        $local = $email ? strtolower(strstr($email, '@', true)) : null;
        // "marc.ripley@" / "jane_doe@" name a person even when the source gave no name (SQA bug 6): use it, never guess more
        if (!($person['first_name'] ?? null) && empty($person['is_tender_contact']) && $local
            && preg_match('/^([a-z]{2,20})[._]([a-z]{2,20})$/', $local, $nm) && !preg_match(self::NOT_A_NAME, $nm[1]) && !preg_match(self::NOT_A_NAME, $nm[2])) {
            $person['first_name'] = ucfirst($nm[1]);
            $person['last_name'] = ucfirst($nm[2]);
            $hasPerson = true;
        }
        $isGeneric = $local && preg_match('/^(info|contact|hello|sales|admin|office|enquiries|enquiry|support|team|mail|hi|business)$/', $local);

        $status = match (true) {
            !empty($person['is_tender_contact']) && $email => 'generic_inbox', // published for tender questions, not cold outreach
            $hasPerson && $email && !$isGeneric => 'identified',
            (bool) $email => 'generic_inbox',
            default => 'needs_enrichment',
        };

        $lead = Lead::create([
            'company_id' => $company->id,
            'icp_profile_id' => $this->icp?->id,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            // Honest label when no person is known — visible in UI, never sent as a greeting.
            'full_name' => $hasPerson ? null : ($company->name . ' — ' . ($email ? 'general inbox' : 'contact unknown')),
            'job_title' => $person['job_title'] ?? null,
            'email' => $email,
            'phone' => $person['phone'] ?? $company->public_phone,
            'profile_url' => $person['profile_url'] ?? null,
            'contact_status' => $status,
            'country' => $company->country,
            'industry' => $company->industry,
            'source_name' => $this->sourceName,
            'source_url' => SafeFetcher::safeUrl($sourceUrl),
            'discovery_date' => now(),
            'relevant_services' => array_values(array_unique($services)) ?: null,
            'email_verification_status' => 'unverified',
            'lifecycle_stage' => 'new',
        ]);
        DataProvenance::record($lead, array_filter([
            'email' => $email,
            'job_title' => $person['job_title'] ?? null,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            // phone copied from the company record keeps the company's source (needed for Places retention purge)
            'phone' => $person['phone'] ?? $company->public_phone,
        ]), $this->source, SafeFetcher::safeUrl($sourceUrl), 'api', (int) ($person['confidence'] ?? 80));

        $result->newLeadsCount++;
        $result->newLeadIds[] = $lead->id;

        return $lead;
    }

    /** Idempotent signal creation (by company + source_url, or company + title). */
    public function addSignal(Company $company, array $signal, DiscoveryResult $result): ?BuyingSignal
    {
        $signal['source_url'] = SafeFetcher::safeUrl($signal['source_url'] ?? null);
        $exists = BuyingSignal::where('company_id', $company->id)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->where(function ($q) use ($signal) {
                if (!empty($signal['source_url'])) {
                    $q->where('source_url', $signal['source_url']);
                }
                $q->orWhere('title', $signal['title']);
            })->exists();
        if ($exists) {
            return null;
        }

        $created = BuyingSignal::create($signal + [
            'company_id' => $company->id,
            'observed_date' => now()->toDateString(),
            'confidence' => 70,
            'verification_status' => 'verified',
        ]);
        $result->newSignalsCount++;

        return $created;
    }
}
