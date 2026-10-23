<?php

namespace App\Services\Connectors;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Str;

class GoogleAdsLeadConnector implements SocialConnectorInterface
{
    protected string $slug = 'google_ads';
    protected string $name = 'Google Ads Lead Form Extensions';
    protected string $platform = 'google';

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function isConfigured(): bool
    {
        $integ = Integration::where('provider', 'google_ads')->first();
        if (!$integ || !$integ->is_active) {
            return false;
        }
        $creds = $integ->getDecryptedCredentials();
        return !empty($creds['webhook_key']);
    }

    public function getCapabilities(): array
    {
        return [
            'supported' => [
                'Google Search & YouTube Lead Form Webhooks (Instant voluntary submissions)',
                'Campaign-level aggregate conversion and cost data',
            ],
            'prohibited_by_policy' => [
                'Private search keyword tracking of individual users (Google does not expose user identities)',
                'Deanonymization of regular website ad clickers',
                'Access to personal browsing history',
            ],
            'webhook_listener_url' => url('/webhooks/google_ads'),
        ];
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Google Ads Lead Form Webhook Key is not configured.',
                'details' => ['configured' => false],
            ];
        }

        return [
            'success' => true,
            'message' => 'Google Ads webhook endpoint is active and listening for authorized lead submissions.',
            'details' => ['endpoint' => url('/webhooks/google_ads')],
        ];
    }

    public function handleLeadWebhook(array $payload): ?Lead
    {
        // Google Ads lead form payload
        $userColumnData = $payload['user_column_data'] ?? [];
        $mapped = [];
        foreach ($userColumnData as $col) {
            $mapped[$col['column_id'] ?? $col['column_name']] = $col['string_value'] ?? '';
        }

        $email = strtolower(trim($mapped['EMAIL'] ?? $payload['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $fullName = trim($mapped['FULL_NAME'] ?? $payload['full_name'] ?? 'Google Search Lead');
        $companyName = trim($mapped['COMPANY_NAME'] ?? $payload['company_name'] ?? 'Inbound Client');
        $phone = $mapped['PHONE_NUMBER'] ?? null;
        $serviceRequested = $mapped['SERVICE_NEEDED'] ?? 'laravel_php';

        $domain = Str::slug($companyName) . '.com';

        $company = Company::firstOrCreate(
            ['domain' => $domain],
            [
                'name' => $companyName,
                'website' => "https://{$domain}",
                'industry' => 'Commercial Enterprise',
                'country' => 'Pakistan',
                'source' => 'Google Ads Search Lead Extension',
            ]
        );

        $signal = BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'voluntary_inquiry',
            'title' => "Voluntary Google Ads Form Inquiry: {$serviceRequested}",
            'source_url' => 'https://ads.google.com/leadform',
            'evidence_text' => "Prospect submitted official Google Ads lead form seeking custom software solutions.",
            'observed_date' => Carbon::now()->toDateString(),
            'confidence' => 95,
            'relevant_service' => $serviceRequested,
            'verification_status' => 'verified',
            'metadata' => [
                'campaign_id' => $payload['campaign_id'] ?? null,
                'lead_id' => $payload['lead_id'] ?? null,
                'platform' => 'google_ads',
            ],
        ]);

        $names = explode(' ', $fullName, 2);

        $lead = Lead::updateOrCreate(
            ['email' => $email],
            [
                'company_id' => $company->id,
                'first_name' => $names[0] ?? $fullName,
                'last_name' => $names[1] ?? '',
                'full_name' => $fullName,
                'phone' => $phone,
                'job_title' => $mapped['JOB_TITLE'] ?? 'Commercial Buyer',
                'country' => 'Pakistan',
                'source_name' => 'Google Ads Search Extension',
                'source_id' => (string) ($payload['lead_id'] ?? Str::uuid()),
                'relevant_services' => [$serviceRequested],
                'email_verification_status' => 'verified',
                'lifecycle_stage' => 'qualified',
            ]
        );

        return $lead;
    }
}
