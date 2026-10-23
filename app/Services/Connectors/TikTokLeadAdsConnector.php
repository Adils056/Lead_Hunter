<?php

namespace App\Services\Connectors;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TikTokLeadAdsConnector implements SocialConnectorInterface
{
    protected string $slug = 'tiktok_ads';
    protected string $name = 'TikTok Instant Lead Forms';
    protected string $platform = 'tiktok';

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
        $integ = Integration::where('provider', 'tiktok_ads')->first();
        if (!$integ || !$integ->is_active) {
            return false;
        }
        $creds = $integ->getDecryptedCredentials();
        return !empty($creds['app_id']);
    }

    public function getCapabilities(): array
    {
        return [
            'supported' => [
                'TikTok Instant Form Webhook Ingestion (Voluntary inquiries)',
                'Campaign-level performance tracking',
            ],
            'prohibited_by_policy' => [
                'Extraction of personal profile data without voluntary form submission',
                'Tracking personal video viewing history',
            ],
            'webhook_listener_url' => url('/webhooks/tiktok_ads'),
        ];
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'TikTok Ads App ID / Secret is not configured.',
                'details' => ['configured' => false],
            ];
        }

        return [
            'success' => true,
            'message' => 'TikTok Ads webhook listener endpoint is configured and active.',
            'details' => ['endpoint' => url('/webhooks/tiktok_ads')],
        ];
    }

    public function handleLeadWebhook(array $payload): ?Lead
    {
        $email = strtolower(trim($payload['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $fullName = trim($payload['name'] ?? 'TikTok Prospect');
        $companyName = trim($payload['company_name'] ?? 'Mobile/E-Commerce Brand');
        $phone = $payload['phone'] ?? null;
        $serviceRequested = $payload['service_needed'] ?? 'ecommerce';

        $domain = Str::slug($companyName) . '.com';

        $company = Company::firstOrCreate(
            ['domain' => $domain],
            [
                'name' => $companyName,
                'website' => "https://{$domain}",
                'industry' => 'Consumer Tech / E-Commerce',
                'country' => 'Pakistan',
                'source' => 'TikTok Instant Form Ads',
            ]
        );

        $signal = BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'voluntary_inquiry',
            'title' => "Voluntary TikTok Instant Form Lead: {$serviceRequested}",
            'source_url' => 'https://ads.tiktok.com/leadgen',
            'evidence_text' => "Prospect submitted official TikTok Lead Generation instant form requesting digital solutions.",
            'observed_date' => Carbon::now()->toDateString(),
            'confidence' => 95,
            'relevant_service' => $serviceRequested,
            'verification_status' => 'verified',
            'metadata' => [
                'advertiser_id' => $payload['advertiser_id'] ?? null,
                'platform' => 'tiktok_lead_ads',
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
                'job_title' => 'Founder / Brand Manager',
                'country' => 'Pakistan',
                'source_name' => 'TikTok Instant Form Ads',
                'source_id' => (string) ($payload['lead_id'] ?? Str::uuid()),
                'relevant_services' => [$serviceRequested],
                'email_verification_status' => 'verified',
                'lifecycle_stage' => 'qualified',
            ]
        );

        return $lead;
    }
}
