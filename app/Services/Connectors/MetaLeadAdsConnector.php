<?php

namespace App\Services\Connectors;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaLeadAdsConnector implements SocialConnectorInterface
{
    protected string $slug = 'meta_ads';
    protected string $name = 'Meta Lead Ads (Facebook & Instagram)';
    protected string $platform = 'meta';

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
        $integ = Integration::where('provider', 'meta_ads')->first();
        if (!$integ || !$integ->is_active) {
            return false;
        }
        $creds = $integ->getDecryptedCredentials();
        return !empty($creds['page_access_token']) || !empty($creds['app_secret']);
    }

    public function getCapabilities(): array
    {
        return [
            'supported' => [
                'Instant Lead Form Webhooks (Real-time voluntary prospect inquiries)',
                'Page message & voluntary business comment inquiries',
                'Aggregate campaign metrics (CPL, impressions, form completion rates)',
            ],
            'prohibited_by_policy' => [
                'Anonymous ad clicker deanonymization (Technically impossible and privacy violation)',
                'Personal email extraction from non-submitting visitors',
                'Private Facebook/Instagram activity surveillance',
            ],
            'webhook_listener_url' => url('/webhooks/meta_ads'),
        ];
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta Page Access Token or App Secret is not configured.',
                'details' => ['configured' => false],
            ];
        }

        $integ = Integration::where('provider', 'meta_ads')->first();
        $creds = $integ->getDecryptedCredentials();
        $token = $creds['page_access_token'] ?? '';

        try {
            $response = Http::timeout(10)->get('https://graph.facebook.com/v19.0/me', [
                'access_token' => $token,
                'fields' => 'id,name',
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connected successfully to Meta Graph API.',
                    'details' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Meta Graph API responded with error: ' . $response->body(),
                'details' => ['status' => $response->status()],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Meta connection failed: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }

    public function handleLeadWebhook(array $payload): ?Lead
    {
        // Extracts fields from authorized Meta Leadgen webhook payload
        $email = strtolower(trim($payload['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $fullName = trim($payload['full_name'] ?? $payload['name'] ?? 'Inbound Prospect');
        $companyName = trim($payload['company_name'] ?? $payload['company'] ?? 'Prospective Client');
        $phone = $payload['phone_number'] ?? null;
        $serviceRequested = $payload['service_needed'] ?? 'custom_software';

        $domain = Company::normalizeDomain($payload['website'] ?? null) ?: (Str::slug($companyName) . '.com');

        $company = Company::firstOrCreate(
            ['domain' => $domain],
            [
                'name' => $companyName,
                'website' => "https://{$domain}",
                'industry' => 'Corporate Services',
                'country' => $payload['country'] ?? 'Pakistan',
                'source' => 'Meta Lead Ads (Facebook/Instagram)',
            ]
        );

        $signal = BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'voluntary_inquiry',
            'title' => "Voluntary Inquiry via Meta Lead Form ({$serviceRequested})",
            'source_url' => 'https://facebook.com/leadgen',
            'evidence_text' => "Prospect voluntarily submitted B2B lead generation form requesting information on {$serviceRequested}.",
            'observed_date' => Carbon::now()->toDateString(),
            'confidence' => 95,
            'relevant_service' => $serviceRequested,
            'verification_status' => 'verified',
            'metadata' => [
                'ad_id' => $payload['ad_id'] ?? null,
                'form_id' => $payload['form_id'] ?? null,
                'platform' => 'meta_lead_ads',
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
                'job_title' => $payload['job_title'] ?? 'Business Lead',
                'country' => $payload['country'] ?? 'Pakistan',
                'source_name' => 'Meta Lead Ads (Facebook/Instagram)',
                'source_id' => (string) ($payload['leadgen_id'] ?? Str::uuid()),
                'relevant_services' => [$serviceRequested],
                'email_verification_status' => 'verified', // Voluntarily submitted
                'lifecycle_stage' => 'qualified',
            ]
        );

        return $lead;
    }
}
