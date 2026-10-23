<?php

namespace Database\Seeders;

use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Deal;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadScore;
use App\Models\LeadSource;
use App\Models\Meeting;
use App\Models\Suppression;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $sales1 = User::where('email', 'sales@leadpilot.test')->first() ?? $admin;
        $sales2 = User::where('email', 'sarah@leadpilot.test')->first() ?? $admin;
        $sourceSearch = LeadSource::where('slug', 'search-api')->first();
        $sourceHiring = LeadSource::where('slug', 'tech-hiring-signals')->first();
        $sourceCrawler = LeadSource::where('slug', 'rfp-website-crawler')->first();
        $sourceManual = LeadSource::where('slug', 'manual-research')->first();

        // 1. Companies across Pakistan, USA, UK, Canada, Australia, Germany
        $companiesData = [
            [
                'name' => 'FinTrack Global Ltd',
                'website' => 'https://fintrack.example.com',
                'domain' => 'fintrack.example.com',
                'industry' => 'Financial Technology',
                'country' => 'United Kingdom',
                'city' => 'London',
                'size_range' => '51-200',
                'description' => 'Multi-currency corporate treasury management platform seeking Laravel backend architects to scale transaction ingestion throughput.',
                'public_email' => 'contact@fintrack.example.com',
                'public_phone' => '+44 20 7123 4567',
                'tech_stack' => ['PHP 8.2', 'Laravel', 'Vue.js', 'PostgreSQL', 'Redis', 'AWS'],
                'source_provider' => 'tech-hiring-signals',
                'source_url' => 'https://jobs.example.com/posting/fintrack-sr-laravel-lead',
                'discovery_date' => now()->subDays(12),
                'status' => 'prospect',
                'assigned_user_id' => $sales1->id,
                'ai_research' => [
                    'business_summary' => 'FinTech enterprise platform handling payment reconciliation and automated FX hedging.',
                    'primary_needs' => ['Database query optimization', 'High-throughput payment gateway API scaling', 'Custom compliance reporting'],
                    'recommended_agency_service' => 'Laravel and PHP development',
                    'intent_level' => 'High',
                    'suggested_angle' => 'Highlight experience with transactional consistency, queue-based transaction processing, and PCI compliance.',
                ],
                'ai_researched_at' => now()->subDays(10),
            ],
            [
                'name' => 'Apex Logistics Networks',
                'website' => 'https://apexlogistics.example.pk',
                'domain' => 'apexlogistics.example.pk',
                'industry' => 'Logistics & Supply Chain',
                'country' => 'Pakistan',
                'city' => 'Lahore',
                'size_range' => '201-500',
                'description' => 'Nationwide freight and warehousing operator transitioning from legacy spreadsheet operations to an integrated ERP and dispatch tracking portal.',
                'public_email' => 'ops@apexlogistics.example.pk',
                'public_phone' => '+92 42 3578 9012',
                'tech_stack' => ['Legacy PHP', 'MySQL', 'Bootstrap', 'REST APIs'],
                'source_provider' => 'rfp-website-crawler',
                'source_url' => 'https://procurement.example.pk/rfp-custom-erp-apex',
                'discovery_date' => now()->subDays(20),
                'status' => 'prospect',
                'assigned_user_id' => $sales2->id,
                'ai_research' => [
                    'business_summary' => 'Tier-1 domestic freight carrier managing 400+ fleet vehicles across 6 regional transit hubs.',
                    'primary_needs' => ['Custom ERP development', 'GPS tracking API integration', 'Driver mobile application backend'],
                    'recommended_agency_service' => 'ERP and CRM development',
                    'intent_level' => 'High',
                    'suggested_angle' => 'Reference proven experience building multi-warehouse inventory systems and mobile-ready dispatch workflows.',
                ],
                'ai_researched_at' => now()->subDays(18),
            ],
            [
                'name' => 'Kura Health Diagnostics',
                'website' => 'https://kurahealth.example.com',
                'domain' => 'kurahealth.example.com',
                'industry' => 'Healthcare & MedTech',
                'country' => 'United States',
                'city' => 'Austin, TX',
                'size_range' => '11-50',
                'description' => 'Telehealth screening provider building secure patient appointment portal and HIPAA-compliant doctor diagnostic dashboard.',
                'public_email' => 'support@kurahealth.example.com',
                'public_phone' => '+1 (512) 555-0199',
                'tech_stack' => ['Laravel 10', 'React', 'MySQL', 'Docker', 'Stripe'],
                'source_provider' => 'search-api',
                'source_url' => 'https://search.example.com/intent/telehealth-custom-software',
                'discovery_date' => now()->subDays(8),
                'status' => 'prospect',
                'assigned_user_id' => $sales1->id,
                'ai_research' => [
                    'business_summary' => 'Rapidly growing clinical lab providing at-home biomarker testing kits with online results tracking.',
                    'primary_needs' => ['Patient portal security audit', 'EHR API integration', 'Automated recurring billing'],
                    'recommended_agency_service' => 'Custom software development',
                    'intent_level' => 'Medium-High',
                    'suggested_angle' => 'Emphasize secure API tokenization, HIPAA architectural guidelines, and automated diagnostic report generation.',
                ],
                'ai_researched_at' => now()->subDays(6),
            ],
            [
                'name' => 'Nordic Gear Online',
                'website' => 'https://nordicgear.example.de',
                'domain' => 'nordicgear.example.de',
                'industry' => 'E-Commerce & Retail',
                'country' => 'Germany',
                'city' => 'Munich',
                'size_range' => '11-50',
                'description' => 'Outdoor sporting apparel retailer needing custom Magento-to-Shopify headless migration and ERP inventory connector.',
                'public_email' => 'info@nordicgear.example.de',
                'public_phone' => '+49 89 2444 0123',
                'tech_stack' => ['Shopify Plus', 'Vue.js', 'PHP Middleware', 'MySQL'],
                'source_provider' => 'manual-research',
                'source_url' => 'https://nordicgear.example.de/press/expansion-2026',
                'discovery_date' => now()->subDays(15),
                'status' => 'prospect',
                'assigned_user_id' => $sales2->id,
                'ai_research' => [
                    'business_summary' => 'Direct-to-consumer brand expanding into pan-European distribution with multilingual store requirements.',
                    'primary_needs' => ['Headless storefront performance', 'Multi-currency checkout', 'Automated warehouse API connector'],
                    'recommended_agency_service' => 'E-commerce development',
                    'intent_level' => 'Medium',
                    'suggested_angle' => 'Focus on sub-second mobile page loads and automated returns management.',
                ],
                'ai_researched_at' => now()->subDays(14),
            ],
            [
                'name' => 'Pacific Cloud Solutions',
                'website' => 'https://pacificcloud.example.com.au',
                'domain' => 'pacificcloud.example.com.au',
                'industry' => 'SaaS & Enterprise Cloud',
                'country' => 'Australia',
                'city' => 'Sydney',
                'size_range' => '51-200',
                'description' => 'B2B infrastructure management tool seeking full-stack engineers for continuous feature releases and customer integration pipelines.',
                'public_email' => 'hello@pacificcloud.example.com.au',
                'public_phone' => '+61 2 8000 1234',
                'tech_stack' => ['PHP', 'Laravel', 'TailwindCSS', 'Alpine.js', 'PostgreSQL'],
                'source_provider' => 'tech-hiring-signals',
                'source_url' => 'https://jobs.example.com.au/posting/pacificcloud-laravel',
                'discovery_date' => now()->subDays(5),
                'status' => 'prospect',
                'assigned_user_id' => $sales1->id,
                'ai_research' => [
                    'business_summary' => 'Enterprise monitoring and cost governance platform for multi-cloud deployments.',
                    'primary_needs' => ['SaaS multi-tenancy audit', 'Microservice API integrations', 'Queue performance tuning'],
                    'recommended_agency_service' => 'SaaS product development',
                    'intent_level' => 'High',
                    'suggested_angle' => 'Highlight expertise with high-frequency telemetry ingestion, queue concurrency, and Livewire/Alpine dashboards.',
                ],
                'ai_researched_at' => now()->subDays(4),
            ],
            [
                'name' => 'Maple Leaf Retailers Co',
                'website' => 'https://mapleleafretail.example.ca',
                'domain' => 'mapleleafretail.example.ca',
                'industry' => 'Retail Systems',
                'country' => 'Canada',
                'city' => 'Toronto',
                'size_range' => '201-500',
                'description' => 'Multi-location Canadian retail chain evaluating modern POS software integration and loyalty management API.',
                'public_email' => 'inquiries@mapleleafretail.example.ca',
                'public_phone' => '+1 (416) 555-0182',
                'tech_stack' => ['PHP', 'MySQL', 'Node.js', 'Square POS API'],
                'source_provider' => 'search-api',
                'source_url' => 'https://mapleleafretail.example.ca/careers',
                'discovery_date' => now()->subDays(25),
                'status' => 'prospect',
                'assigned_user_id' => $sales2->id,
                'ai_research' => [
                    'business_summary' => 'Retail cooperative operating 35 storefronts across Ontario and Quebec.',
                    'primary_needs' => ['Omnichannel loyalty API', 'Inventory synchronization between POS and website'],
                    'recommended_agency_service' => 'API development and integration',
                    'intent_level' => 'Medium',
                    'suggested_angle' => 'Propose unified inventory reconciliation system with real-time stock checks.',
                ],
                'ai_researched_at' => now()->subDays(22),
            ],
        ];

        $createdCompanies = [];
        foreach ($companiesData as $cData) {
            $createdCompanies[$cData['domain']] = Company::create($cData);
        }

        // 2. Buying Signals
        $signalsData = [
            [
                'company_domain' => 'fintrack.example.com',
                'signal_type' => 'developer_hiring',
                'title' => 'Hiring Lead Laravel Architect & Backend Team',
                'source_url' => 'https://jobs.example.com/posting/fintrack-sr-laravel-lead',
                'evidence_text' => 'FinTrack is urgently seeking 3 Senior Laravel Engineers to rewrite our core settlement engine from monolithic scripts into a microservice-ready modular architecture.',
                'observed_date' => now()->subDays(11)->toDateString(),
                'confidence' => 92,
                'relevant_service' => 'Laravel and PHP development',
                'verification_status' => 'verified',
            ],
            [
                'company_domain' => 'fintrack.example.com',
                'signal_type' => 'funding_announcement',
                'title' => 'Closed $4.5M Series A Funding Round',
                'source_url' => 'https://techcrunch.example.com/fintrack-series-a',
                'evidence_text' => 'FinTrack announced the close of their $4.5M Series A round led by NorthStar Ventures, earmarked for engineering talent and European expansion.',
                'observed_date' => now()->subDays(28)->toDateString(),
                'confidence' => 95,
                'relevant_service' => 'Custom software development',
                'verification_status' => 'verified',
            ],
            [
                'company_domain' => 'apexlogistics.example.pk',
                'signal_type' => 'erp_crm_request',
                'title' => 'Public Tender: Fleet Dispatch & Warehousing ERP System',
                'source_url' => 'https://procurement.example.pk/rfp-custom-erp-apex',
                'evidence_text' => 'Apex Logistics Networks invites sealed proposals from qualified software development agencies for the turnkey delivery of an Enterprise Resource Planning system.',
                'observed_date' => now()->subDays(19)->toDateString(),
                'confidence' => 98,
                'relevant_service' => 'ERP and CRM development',
                'verification_status' => 'verified',
            ],
            [
                'company_domain' => 'kurahealth.example.com',
                'signal_type' => 'project_requirements',
                'title' => 'Public Request for HealthTech Software Development Partner',
                'source_url' => 'https://kurahealth.example.com/partners/rfq-patient-portal',
                'evidence_text' => 'Seeking an external software agency experienced in HIPAA-compliant web applications and REST API integrations for patient report deliveries.',
                'observed_date' => now()->subDays(7)->toDateString(),
                'confidence' => 88,
                'relevant_service' => 'Custom software development',
                'verification_status' => 'verified',
            ],
            [
                'company_domain' => 'pacificcloud.example.com.au',
                'signal_type' => 'developer_hiring',
                'title' => 'Open Position: Senior Full-Stack PHP/Laravel Engineer',
                'source_url' => 'https://jobs.example.com.au/posting/pacificcloud-laravel',
                'evidence_text' => 'Looking for senior Laravel engineers to accelerate product delivery on our cloud cost governance SaaS platform.',
                'observed_date' => now()->subDays(5)->toDateString(),
                'confidence' => 90,
                'relevant_service' => 'SaaS product development',
                'verification_status' => 'verified',
            ],
            [
                'company_domain' => 'nordicgear.example.de',
                'signal_type' => 'business_expansion',
                'title' => 'European Storefront Modernization Initiative',
                'source_url' => 'https://nordicgear.example.de/press/expansion-2026',
                'evidence_text' => 'Nordic Gear announces rollout of localized storefronts for UK, France, and Benelux with consolidated backend inventory synchronization.',
                'observed_date' => now()->subDays(14)->toDateString(),
                'confidence' => 82,
                'relevant_service' => 'E-commerce development',
                'verification_status' => 'verified',
            ],
        ];

        foreach ($signalsData as $sig) {
            $company = $createdCompanies[$sig['company_domain']] ?? null;
            if ($company) {
                unset($sig['company_domain']);
                $sig['company_id'] = $company->id;
                BuyingSignal::create($sig);
            }
        }

        // 3. Leads (Contacts) with 100-Point Transparent Scoring
        $leadsData = [
            [
                'company_domain' => 'fintrack.example.com',
                'first_name' => 'Edward',
                'last_name' => 'Sterling',
                'full_name' => 'Edward Sterling',
                'job_title' => 'Chief Technology Officer',
                'email' => 'edward.sterling@fintrack.example.com',
                'email_verification_status' => 'verified',
                'phone' => '+44 20 7987 1122',
                'profile_url' => 'https://linkedin.example.com/in/edwardsterling-cto',
                'lead_source_id' => $sourceHiring?->id,
                'source_name' => 'Hiring Signals & Job Feeds',
                'country' => 'United Kingdom',
                'industry' => 'Financial Technology',
                'relevant_services' => ['Laravel and PHP development', 'API development and integration'],
                'lifecycle_stage' => 'qualified',
                'intent_score' => 28,      // out of 30 (Explicit hiring + Series A)
                'service_fit_score' => 20,  // out of 20 (Laravel matches core stack)
                'contact_confidence_score' => 15, // out of 15 (Verified corporate email)
                'overall_score' => 88,     // High priority
                'score_explanation' => 'Strong buying intent: urgent hiring signal for Laravel developers combined with recently announced $4.5M Series A funding. Validated corporate email address and executive decision-maker role.',
                'score_breakdown' => [
                    'explicit_request' => 25,
                    'technical_hiring' => 20,
                    'verified_growth_evidence' => 15,
                    'decision_maker_identified' => 15,
                    'service_relevance' => 10,
                    'contact_verification' => 3,
                ],
                'assigned_user_id' => $sales1->id,
                'notes' => 'Reached out via tailored Laravel architecture audit.',
                'next_action_date' => now()->addDays(2)->toDateString(),
                'next_action_note' => 'Follow up on technical architecture discussion',
            ],
            [
                'company_domain' => 'apexlogistics.example.pk',
                'first_name' => 'Tariq',
                'last_name' => 'Mehmood',
                'full_name' => 'Tariq Mehmood',
                'job_title' => 'Head of Supply Chain & IT',
                'email' => 'tariq.mehmood@apexlogistics.example.pk',
                'email_verification_status' => 'verified',
                'phone' => '+92 300 845 1199',
                'profile_url' => 'https://linkedin.example.com/in/tariq-mehmood-supplychain',
                'lead_source_id' => $sourceCrawler?->id,
                'source_name' => 'Public Website & RFP Scanner',
                'country' => 'Pakistan',
                'industry' => 'Logistics & Supply Chain',
                'relevant_services' => ['ERP and CRM development', 'Database design, optimization, and system integrations'],
                'lifecycle_stage' => 'meeting_booked',
                'intent_score' => 30,      // out of 30 (Published tender)
                'service_fit_score' => 20,
                'contact_confidence_score' => 15,
                'overall_score' => 94,     // High priority
                'score_explanation' => 'Maximum intent signal: published competitive RFP for full ERP migration. Key stakeholder identified and directly reachable.',
                'score_breakdown' => [
                    'explicit_request' => 30,
                    'technical_hiring' => 18,
                    'verified_growth_evidence' => 13,
                    'decision_maker_identified' => 15,
                    'service_relevance' => 10,
                    'contact_verification' => 8,
                ],
                'assigned_user_id' => $sales2->id,
                'notes' => 'Discovery demo scheduled for upcoming Monday.',
                'last_contacted_at' => now()->subDays(1),
                'next_action_date' => now()->addDays(3)->toDateString(),
                'next_action_note' => 'Conduct Zoom product demonstration and architecture walkthrough',
            ],
            [
                'company_domain' => 'kurahealth.example.com',
                'first_name' => 'Jessica',
                'last_name' => 'Miller',
                'full_name' => 'Jessica Miller',
                'job_title' => 'VP of Digital Health',
                'email' => 'jmiller@kurahealth.example.com',
                'email_verification_status' => 'verified',
                'phone' => '+1 (512) 555-0144',
                'profile_url' => 'https://linkedin.example.com/in/jessica-miller-health',
                'lead_source_id' => $sourceSearch?->id,
                'source_name' => 'Search Engine API (Google/Bing)',
                'country' => 'United States',
                'industry' => 'Healthcare & MedTech',
                'relevant_services' => ['Custom software development', 'SaaS product development'],
                'lifecycle_stage' => 'proposal_sent',
                'intent_score' => 26,
                'service_fit_score' => 18,
                'contact_confidence_score' => 15,
                'overall_score' => 84,
                'score_explanation' => 'Confirmed requirement for patient portal rebuild with strict HIPAA compliance needs.',
                'score_breakdown' => [
                    'explicit_request' => 25,
                    'technical_hiring' => 15,
                    'verified_growth_evidence' => 12,
                    'decision_maker_identified' => 14,
                    'service_relevance' => 10,
                    'contact_verification' => 8,
                ],
                'assigned_user_id' => $sales1->id,
                'notes' => 'Sent initial technical proposal for portal MVP.',
                'last_contacted_at' => now()->subDays(3),
                'next_action_date' => now()->addDays(1)->toDateString(),
                'next_action_note' => 'Follow up on pricing and sprint delivery milestones',
            ],
            [
                'company_domain' => 'pacificcloud.example.com.au',
                'first_name' => 'Liam',
                'last_name' => 'Davies',
                'full_name' => 'Liam Davies',
                'job_title' => 'Director of Engineering',
                'email' => 'liam.davies@pacificcloud.example.com.au',
                'email_verification_status' => 'verified',
                'phone' => '+61 2 8091 4455',
                'profile_url' => 'https://linkedin.example.com/in/liam-davies-eng',
                'lead_source_id' => $sourceHiring?->id,
                'source_name' => 'Hiring Signals & Job Feeds',
                'country' => 'Australia',
                'industry' => 'SaaS & Enterprise Cloud',
                'relevant_services' => ['SaaS product development', 'Laravel and PHP development'],
                'lifecycle_stage' => 'ready_for_review',
                'intent_score' => 24,
                'service_fit_score' => 19,
                'contact_confidence_score' => 14,
                'overall_score' => 79,
                'score_explanation' => 'Active developer recruitment. Perfect match for Laravel engineering outsourcing.',
                'score_breakdown' => [
                    'explicit_request' => 20,
                    'technical_hiring' => 20,
                    'verified_growth_evidence' => 12,
                    'decision_maker_identified' => 12,
                    'service_relevance' => 10,
                    'contact_verification' => 5,
                ],
                'assigned_user_id' => $sales1->id,
                'notes' => 'Draft personalized introduction prepared; pending admin review.',
                'next_action_date' => now()->addDays(2)->toDateString(),
                'next_action_note' => 'Review and submit outreach draft',
            ],
            [
                'company_domain' => 'nordicgear.example.de',
                'first_name' => 'Felix',
                'last_name' => 'Weber',
                'full_name' => 'Felix Weber',
                'job_title' => 'E-Commerce Manager',
                'email' => 'felix.weber@nordicgear.example.de',
                'email_verification_status' => 'risky', // Example of risky verification status
                'phone' => '+49 89 2444 8877',
                'profile_url' => 'https://linkedin.example.com/in/felixweber-ecom',
                'lead_source_id' => $sourceManual?->id,
                'source_name' => 'Manual Research & Referrals',
                'country' => 'Germany',
                'industry' => 'E-Commerce & Retail',
                'relevant_services' => ['E-commerce development'],
                'lifecycle_stage' => 'researching',
                'intent_score' => 18,
                'service_fit_score' => 16,
                'contact_confidence_score' => 8,
                'overall_score' => 58,
                'score_explanation' => 'Store expansion public signal, but email verification flagged catch-all server; requires manual confirmation before emailing.',
                'score_breakdown' => [
                    'explicit_request' => 15,
                    'technical_hiring' => 10,
                    'verified_growth_evidence' => 12,
                    'decision_maker_identified' => 11,
                    'service_relevance' => 8,
                    'contact_verification' => 2,
                ],
                'assigned_user_id' => $sales2->id,
                'notes' => 'Need to verify direct inbox before queueing outreach.',
                'next_action_date' => now()->addDays(4)->toDateString(),
                'next_action_note' => 'Attempt phone verification or direct connect via sales',
            ],
            [
                'company_domain' => 'mapleleafretail.example.ca',
                'first_name' => 'Claire',
                'last_name' => 'Bouchard',
                'full_name' => 'Claire Bouchard',
                'job_title' => 'Chief Information Officer',
                'email' => 'claire.b@mapleleafretail.example.ca',
                'email_verification_status' => 'verified',
                'phone' => '+1 (416) 555-0199',
                'profile_url' => 'https://linkedin.example.com/in/claire-bouchard-cio',
                'lead_source_id' => $sourceSearch?->id,
                'source_name' => 'Search Engine API (Google/Bing)',
                'country' => 'Canada',
                'industry' => 'Retail Systems',
                'relevant_services' => ['API development and integration'],
                'lifecycle_stage' => 'new',
                'intent_score' => 16,
                'service_fit_score' => 15,
                'contact_confidence_score' => 14,
                'overall_score' => 63,
                'score_explanation' => 'Verified corporate contact; POS modernization evaluation underway.',
                'score_breakdown' => [
                    'explicit_request' => 14,
                    'technical_hiring' => 12,
                    'verified_growth_evidence' => 12,
                    'decision_maker_identified' => 13,
                    'service_relevance' => 8,
                    'contact_verification' => 4,
                ],
                'assigned_user_id' => $sales2->id,
                'notes' => 'Discovered via POS search intent.',
                'next_action_date' => now()->addDays(3)->toDateString(),
                'next_action_note' => 'Generate AI research summary',
            ],
        ];

        $createdLeads = [];
        foreach ($leadsData as $lData) {
            $company = $createdCompanies[$lData['company_domain']] ?? null;
            if ($company) {
                unset($lData['company_domain']);
                $lData['company_id'] = $company->id;
                $lData['discovery_date'] = now()->subDays(rand(3, 20));
                $lData['last_verified_at'] = now()->subDays(rand(1, 5));
                $lData['scored_at'] = now()->subDays(rand(1, 4));

                $lead = Lead::create($lData);
                $createdLeads[$lead->email] = $lead;

                // Create LeadScore history entry
                LeadScore::create([
                    'lead_id' => $lead->id,
                    'company_id' => $company->id,
                    'scoring_version' => '1.0',
                    'intent_score' => $lead->intent_score,
                    'service_fit_score' => $lead->service_fit_score,
                    'contact_confidence_score' => $lead->contact_confidence_score,
                    'overall_score' => $lead->overall_score,
                    'priority_level' => $lead->priority_badge,
                    'reasoning' => $lead->score_explanation,
                    'breakdown' => $lead->score_breakdown,
                    'evaluated_by' => $admin?->id,
                    'evaluated_at' => now()->subDays(2),
                ]);
            }
        }

        // 4. Suppressions (Demonstrating do-not-contact enforcement)
        Suppression::create([
            'email' => 'optedout.contact@unsubscribed-corp.com',
            'domain' => 'unsubscribed-corp.com',
            'reason' => 'unsubscribe',
            'source' => 'unsubscribe_link',
            'notes' => 'Requested opt-out via direct email link on 2026-10-18',
            'added_by' => $admin?->id,
        ]);
        Suppression::create([
            'email' => 'bounced.test@badserver.com',
            'domain' => 'badserver.com',
            'reason' => 'hard_bounce',
            'source' => 'webhook',
            'notes' => '550 5.1.1 User unknown - auto suppressed to protect sender reputation',
            'added_by' => null,
        ]);

        // 5. Campaigns & Outreach Workflow
        $template = EmailTemplate::where('slug', 'laravel-php-development')->first();
        if ($template) {
            $campaign = Campaign::create([
                'name' => 'Q4 Enterprise Laravel Scaling — UK & US',
                'service_offered' => 'Laravel and PHP development',
                'target_countries' => ['United Kingdom', 'United States'],
                'target_industries' => ['Financial Technology', 'Healthcare & MedTech', 'SaaS & Enterprise Cloud'],
                'min_lead_score' => 75,
                'eligible_sources' => ['tech-hiring-signals', 'search-api'],
                'contact_rules' => ['require_verified_email' => true, 'exclude_unsubscribed' => true],
                'daily_limit' => 30,
                'sent_today' => 3,
                'last_sent_date' => now()->toDateString(),
                'schedule_config' => ['days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], 'start_time' => '09:00', 'end_time' => '17:00'],
                'timezone' => 'Europe/London',
                'email_template_id' => $template->id,
                'follow_up_config' => ['enabled' => true, 'days_delay' => 3, 'max_followups' => 1],
                'require_approval' => true,
                'status' => 'approved',
                'emergency_stopped' => false,
                'created_by' => $sales1->id,
                'approved_by' => $admin->id,
                'approved_at' => now()->subDays(2),
            ]);

            // Add Campaign Recipient for Edward Sterling
            $edward = $createdLeads['edward.sterling@fintrack.example.com'] ?? null;
            if ($edward) {
                CampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'lead_id' => $edward->id,
                    'status' => 'sent',
                    'idempotency_key' => 'cmp_' . $campaign->id . '_lead_' . $edward->id,
                    'personalized_subject' => "Scaling FinTrack Global Ltd's backend engineering with dedicated Laravel talent",
                    'personalized_body' => "Hi Edward,\n\nI noticed that FinTrack Global Ltd operates in the Financial Technology space and is currently scaling your software infrastructure. We specialize in enterprise Laravel and PHP application architecture...",
                    'scheduled_at' => now()->subDay(),
                    'sent_at' => now()->subDay(),
                    'delivered_at' => now()->subDay(),
                    'provider_message_id' => 'msg_' . Str::random(16),
                ]);
            }
        }

        // 6. CRM Tasks, Meetings, and Deals
        $tariq = $createdLeads['tariq.mehmood@apexlogistics.example.pk'] ?? null;
        $apex = $createdCompanies['apexlogistics.example.pk'] ?? null;
        if ($tariq && $apex) {
            Task::create([
                'lead_id' => $tariq->id,
                'company_id' => $apex->id,
                'assigned_to' => $sales2->id,
                'title' => 'Prepare ERP dispatch architecture slide deck for Tariq',
                'description' => 'Review multi-transit hub tracking diagrams and custom driver assignment logic for the upcoming demo.',
                'due_date' => now()->addDays(2)->toDateString(),
                'priority' => 'high',
                'status' => 'in_progress',
            ]);

            Meeting::create([
                'lead_id' => $tariq->id,
                'company_id' => $apex->id,
                'host_user_id' => $sales2->id,
                'title' => 'Apex Logistics — Turnkey ERP Solution Demo',
                'meeting_url' => 'https://meet.google.com/xyz-leadpilot-demo',
                'scheduled_start' => now()->addDays(3)->setHour(14)->setMinute(0),
                'scheduled_end' => now()->addDays(3)->setHour(15)->setMinute(0),
                'status' => 'scheduled',
                'outcome_notes' => 'Agenda: 1. Dispatch board demo, 2. Mobile driver app wireframes, 3. Proposed milestone budget.',
            ]);

            Deal::create([
                'lead_id' => $tariq->id,
                'company_id' => $apex->id,
                'owner_id' => $sales2->id,
                'title' => 'Apex Logistics ERP & Dispatch Mobile App',
                'value' => 38000.00,
                'currency' => 'USD',
                'stage' => 'discovery',
                'probability' => 45,
                'expected_close_date' => now()->addDays(30),
                'notes' => 'Turnkey contract spanning custom Laravel backend, MySQL warehouse schema, and React Native mobile driver portal.',
            ]);
        }

        $jessica = $createdLeads['jmiller@kurahealth.example.com'] ?? null;
        $kura = $createdCompanies['kurahealth.example.com'] ?? null;
        if ($jessica && $kura) {
            Deal::create([
                'lead_id' => $jessica->id,
                'company_id' => $kura->id,
                'owner_id' => $sales1->id,
                'title' => 'Kura Health Patient Screening Portal Rebuild',
                'value' => 24500.00,
                'currency' => 'USD',
                'stage' => 'proposal_sent',
                'probability' => 70,
                'expected_close_date' => now()->addDays(14),
                'notes' => 'Includes HIPAA audit preparation, patient portal Vue.js frontend, and encrypted lab report PDF generation.',
            ]);
        }
    }
}
