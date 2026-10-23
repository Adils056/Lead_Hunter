<?php

namespace Database\Seeders;

use App\Models\IcpProfile;
use Illuminate\Database\Seeder;

/** Two starter Ideal Customer Profiles. Edit them in Lead Hunter before running a hunt. */
class IcpProfileSeeder extends Seeder
{
    public function run(): void
    {
        IcpProfile::firstOrCreate(['name' => 'Local clinics with weak websites — Islamabad/Rawalpindi'], [
            'description' => 'Dental and medical clinics whose websites fail mobile/CTA/SEO checks, or have no website.',
            'is_active' => true,
            'countries' => ['Pakistan'], 'cities' => ['Islamabad', 'Rawalpindi'],
            'industries' => ['Healthcare'], 'niches' => ['dental clinic', 'skin clinic', 'physiotherapy clinic'],
            'business_types' => ['local_business'], 'target_services' => ['web_redesign', 'web_dev', 'conversion_optimization', 'seo_technical'],
            'website_requirement' => 'any', 'contact_roles' => ['Owner', 'Practice Manager', 'Doctor'],
            'min_lead_score' => 50, 'min_google_rating' => 38, 'min_review_count' => 20,
            'sources' => ['overture_places', 'osm_overpass'], 'channels' => ['phone', 'email'], // free sources only: Overture (bundled DuckDB) then OSM; Google Places needs key + budget + approval
            'max_results_per_run' => 20, 'monthly_budget_cents' => 2000,
            'objective' => '4 discovery calls per month',
        ]);

        IcpProfile::firstOrCreate(['name' => 'UK public-sector web & software tenders'], [
            'description' => 'Open UK tenders for websites, portals, CRM/ERP and software development.',
            'is_active' => false,
            'countries' => ['United Kingdom'], 'business_types' => ['government'],
            'target_services' => ['web_redesign', 'custom_software', 'erp_pos', 'api_integration'],
            'keywords' => ['website', 'web application', 'portal', 'software development', 'crm', 'digital service'],
            'website_requirement' => 'any', 'min_lead_score' => 45,
            'sources' => ['uk_contracts_finder'], 'channels' => ['email'], 'max_results_per_run' => 25,
            'objective' => 'Respond to 2 qualified tenders per month',
        ]);
    }
}
