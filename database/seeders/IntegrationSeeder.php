<?php

namespace Database\Seeders;

use App\Models\Integration;
use Illuminate\Database\Seeder;

class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $integrations = [
            [
                'provider' => 'openai',
                'name' => 'OpenAI API (GPT-4o / GPT-4o-mini)',
                'description' => 'Automated company business analysis, buying intent extraction, structured JSON research synthesis, and inbound reply sentiment classification.',
                'settings' => [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.2,
                    'max_tokens' => 1200,
                    'timeout_seconds' => 30,
                    'monthly_spend_cap_usd' => 50.00,
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 5000,
            ],
            [
                'provider' => 'companies_house',
                'name' => 'Companies House — New UK Companies (free)',
                'description' => 'Free government API key (developer.company-information.service.gov.uk → create application → REST key). Finds UK businesses incorporated in the last 14 days in the industries you serve.',
                'settings' => [],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'gemini',
                'name' => 'Google Gemini — Draft Writing (free tier)',
                'description' => 'Optional LLM for polishing outreach drafts and the Sales Assistant. Flash-Lite free tier; only public business facts are sent.',
                'settings' => [],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'google_places',
                'name' => 'Google Places API (New) — Local Business Discovery',
                'description' => 'Official Google Maps Platform Text Search for local-business discovery. websiteUri/phone/rating trigger the Enterprise SKU (~$35 per 1,000 requests after 1,000 free/month, checked Oct 2026). Only place_id may be stored long-term; run leadpilot:places-purge daily.',
                'settings' => ['retention_days' => 30],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 2000,
            ],
            [
                'provider' => 'hunter',
                'name' => 'Hunter.io Email Discovery & Verification',
                'description' => 'Discovers direct business email addresses by domain and verifies deliverability (checks MX, SMTP response, catch-all detection).',
                'settings' => [
                    'confidence_score_min' => 75,
                    'check_disposable' => true,
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 3000,
            ],
            [
                'provider' => 'apollo',
                'name' => 'Apollo.io B2B Enrichment',
                'description' => 'Fetches verified company firmographics, headcount ranges, technologies used, and executive decision-maker listings.',
                'settings' => [
                    'include_social_links' => true,
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 4500,
            ],
            [
                'provider' => 'smtp',
                'name' => 'SMTP / Transactional Email Provider',
                'description' => 'Queued delivery of approved outbound sales intelligence campaigns and individual prospect follow-ups with throttle management.',
                'settings' => [
                    'host' => '127.0.0.1',
                    'port' => 2525,
                    'encryption' => 'tls',
                    'from_email' => 'sales@leadpilot.test',
                    'from_name' => 'LeadPilot Outreach',
                ],
                'is_active' => true, // Default active for log/local sending
                'status' => 'connected',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'resend',
                'name' => 'Resend / SendGrid Webhook Events',
                'description' => 'Receives real-time delivery webhooks: deliveries, hard bounces, spam complaints, and inbound customer replies.',
                'settings' => [
                    'verify_signatures' => true,
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'meta_ads',
                'name' => 'Meta Lead Ads (Facebook & Instagram)',
                'description' => 'Ingests authorized instant lead forms and voluntary prospect inquiries from Facebook and Instagram campaigns via webhook.',
                'settings' => [
                    'webhook_endpoint' => '/webhooks/meta_ads',
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'google_ads',
                'name' => 'Google Ads Lead Form Extensions',
                'description' => 'Ingests voluntary high-intent business inquiries submitted via Google Search and YouTube lead form extensions.',
                'settings' => [
                    'webhook_endpoint' => '/webhooks/google_ads',
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
            [
                'provider' => 'tiktok_ads',
                'name' => 'TikTok Instant Lead Forms',
                'description' => 'Ingests voluntary mobile inquiries submitted through official TikTok business lead generation instant forms.',
                'settings' => [
                    'webhook_endpoint' => '/webhooks/tiktok_ads',
                ],
                'is_active' => false,
                'status' => 'pending_credentials',
                'monthly_spend_limit_cents' => 0,
            ],
        ];

        foreach ($integrations as $integ) {
            Integration::firstOrCreate(['provider' => $integ['provider']], $integ);
        }
    }
}
