<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Search Engine API (Google/Bing)',
                'slug' => 'search-api',
                'provider_type' => 'search_api',
                'description' => 'Targeted search engine queries identifying companies requesting web and software development services.',
                'config' => ['query_template' => 'software development agency needed site:*.com OR site:*.co.uk', 'rate_limit_per_min' => 30],
                'is_active' => true,
                'status' => 'idle',
                'sync_frequency' => 'daily',
                'total_discovered' => 24,
            ],
            [
                'name' => 'Hunter.io Discovery & Verification',
                'slug' => 'hunter-enricher',
                'provider_type' => 'hunter',
                'description' => 'Direct B2B email search and verification connector.',
                'config' => ['confidence_threshold' => 80],
                'is_active' => false,
                'status' => 'needs_credentials',
                'sync_frequency' => 'manual',
                'total_discovered' => 18,
            ],
            [
                'name' => 'Apollo B2B Enrichment',
                'slug' => 'apollo-connector',
                'provider_type' => 'apollo',
                'description' => 'B2B company and contact enrichment adapter.',
                'config' => ['enrich_technologies' => true],
                'is_active' => false,
                'status' => 'needs_credentials',
                'sync_frequency' => 'manual',
                'total_discovered' => 12,
            ],
            [
                'name' => 'Hiring Signals & Job Feeds',
                'slug' => 'tech-hiring-signals',
                'provider_type' => 'job_board',
                'description' => 'Public job postings for PHP, Laravel, React, and Full-Stack engineers indicating immediate software development needs.',
                'config' => ['keywords' => ['Laravel Developer', 'Full Stack Engineer', 'ERP Specialist', 'Custom CRM']],
                'is_active' => true,
                'status' => 'idle',
                'sync_frequency' => 'daily',
                'total_discovered' => 35,
            ],
            [
                'name' => 'Public Website & RFP Scanner',
                'slug' => 'rfp-website-crawler',
                'provider_type' => 'website_crawler',
                'description' => 'Permitted public procurement notices and agency tender announcements.',
                'config' => ['respect_robots_txt' => true, 'max_depth' => 2],
                'is_active' => true,
                'status' => 'idle',
                'sync_frequency' => 'weekly',
                'total_discovered' => 16,
            ],
            [
                'name' => 'CSV Bulk Importer',
                'slug' => 'csv-importer',
                'provider_type' => 'csv_import',
                'description' => 'Internal lead lists uploaded via structured CSV with column mapping.',
                'config' => [],
                'is_active' => true,
                'status' => 'idle',
                'sync_frequency' => 'manual',
                'total_discovered' => 45,
            ],
            [
                'name' => 'Manual Research & Referrals',
                'slug' => 'manual-research',
                'provider_type' => 'manual',
                'description' => 'Manually verified sales prospects researched by the business development team.',
                'config' => [],
                'is_active' => true,
                'status' => 'idle',
                'sync_frequency' => 'manual',
                'total_discovered' => 28,
            ],
        ];

        foreach ($sources as $source) {
            LeadSource::firstOrCreate(['slug' => $source['slug']], $source);
        }
    }
}
