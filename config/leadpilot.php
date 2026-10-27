<?php

/*
|--------------------------------------------------------------------------
| LeadPilot AI — Lead Hunting Engine configuration
|--------------------------------------------------------------------------
| Everything here is a default. Scoring weights/thresholds can be overridden
| by admins at runtime (Settings -> Lead Scoring), which writes to the
| scoring_settings table.
*/

return [

    'agency' => [
        'name' => env('LEADPILOT_AGENCY_NAME', 'Eziline Software House'),
        'sender_name' => env('LEADPILOT_SENDER_NAME', 'Ismail'),
        'website' => env('LEADPILOT_AGENCY_WEBSITE', 'https://eziline.com'),
        'booking_url' => env('LEADPILOT_BOOKING_URL'), // e.g. Calendly link; null = ask for a time instead
    ],

    /*
    | Service / offer catalog. 'impact' ranks offers when several apply
    | (e.g. a site that is not mobile-ready matters more than missing meta tags).
    | "triggers" are evidence keys produced by the
    | website audit, buying signals or ICP — the Conversion Assistant only
    | recommends an offer when at least one trigger is backed by evidence.
    */
    'services' => [
        'web_redesign' => [
            'impact' => 1.5, // business-impact multiplier used to rank offers
            'label' => 'Website Redesign',
            'pitch' => 'a faster, mobile-first site that turns visitors into enquiries',
            'triggers' => ['no_viewport', 'slow_response', 'slow_mobile_performance', 'outdated_copyright', 'no_https', 'legacy_jquery', 'large_page', 'outdated_cms_version'],
            'typical_value_usd' => [800, 5000],
        ],
        'web_dev' => [
            'impact' => 2.0, // business-impact multiplier used to rank offers
            'label' => 'New Website',
            'pitch' => 'a professional website so customers who search for you can find and contact you',
            'triggers' => ['no_website', 'website_unreachable'],
            'typical_value_usd' => [500, 3000],
        ],
        'conversion_optimization' => [
            'impact' => 1.2, // business-impact multiplier used to rank offers
            'label' => 'Conversion / Lead-Capture Fixes',
            'pitch' => 'clear calls-to-action and a working enquiry form on every key page',
            'triggers' => ['no_cta', 'no_contact_form', 'no_click_to_call'],
            'typical_value_usd' => [300, 1500],
        ],
        'seo_technical' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'Technical SEO',
            'pitch' => 'fixing the technical issues that stop Google from showing your pages',
            'triggers' => ['missing_title', 'missing_meta_description', 'missing_h1', 'multiple_h1', 'no_canonical', 'no_structured_data', 'no_og_tags', 'broken_links', 'robots_noindex'],
            'typical_value_usd' => [300, 2000],
        ],
        'accessibility' => [
            'impact' => 0.7, // business-impact multiplier used to rank offers
            'label' => 'Accessibility Improvements',
            'pitch' => 'making the site usable for every visitor (and lower legal risk)',
            'triggers' => ['missing_alt_text', 'missing_lang'],
            'typical_value_usd' => [300, 1500],
        ],
        'ecommerce' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'E-Commerce Development',
            'pitch' => 'an online store with payments and inventory that runs itself',
            'triggers' => ['ecommerce_signal', 'retail_no_store'],
            'typical_value_usd' => [1500, 10000],
        ],
        'custom_software' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'Custom Software',
            'pitch' => 'software built around how your team actually works',
            'triggers' => ['procurement_rfp', 'project_requirements', 'digital_transformation'],
            'typical_value_usd' => [5000, 50000],
        ],
        'laravel_php' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'Laravel / PHP Development',
            'pitch' => 'senior Laravel capacity without the hiring delay',
            'triggers' => ['developer_hiring', 'tech_laravel', 'tech_php'],
            'typical_value_usd' => [3000, 30000],
        ],
        'erp_pos' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'ERP / POS / CRM',
            'pitch' => 'one system for sales, stock and accounts instead of spreadsheets',
            'triggers' => ['erp_crm_request', 'multi_branch'],
            'typical_value_usd' => [3000, 25000],
        ],
        'api_integration' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'API & Integrations',
            'pitch' => 'connecting your tools so data stops being re-typed',
            'triggers' => ['tech_stack_change', 'integration_need'],
            'typical_value_usd' => [1000, 10000],
        ],
        'saas_mvp' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'SaaS / MVP Build',
            'pitch' => 'getting a working MVP in front of users quickly',
            'triggers' => ['funding_announcement', 'business_launch'],
            'typical_value_usd' => [8000, 60000],
        ],
        'mobile_app' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'Mobile App',
            'pitch' => 'an app your customers can book or order from',
            'triggers' => ['mobile_hiring'],
            'typical_value_usd' => [5000, 40000],
        ],
        'ai_automation' => [
            'impact' => 1.0, // business-impact multiplier used to rank offers
            'label' => 'AI & Workflow Automation',
            'pitch' => 'automating the repetitive admin work your team does every day',
            'triggers' => ['ai_hiring', 'automation_need'],
            'typical_value_usd' => [2000, 20000],
        ],
        'maintenance' => [
            'impact' => 0.6, // business-impact multiplier used to rank offers
            'label' => 'Website Care & Maintenance',
            'pitch' => 'keeping the site updated, secure and backed up every month',
            'triggers' => ['outdated_cms_version', 'mixed_content', 'broken_links'],
            'typical_value_usd' => [50, 300], // per month
        ],
    ],

    /*
    | Scoring: points per factor (must total 100). Each factor first produces a
    | 0..1 "strength" from evidence, then strength * weight = points.
    | THIS IS A HEURISTIC SCORE, not a conversion probability. See
    | ScoreCalibrationService for empirical validation once outcomes exist.
    */
    'scoring' => [
        'version' => '3.0',
        'weights' => [
            'explicit_request' => 22,   // RFP / tender / inbound enquiry
            'software_need' => 14,      // hiring developers, tech-stack change
            'website_gap' => 12,        // evidence from WebsiteAuditService
            'growth_initiative' => 7,   // funding, expansion, launch
            'icp_match' => 10,          // matches active Ideal Customer Profile
            'contact_role' => 9,        // decision-maker reachable
            'service_fit' => 10,        // maps to a service we sell
            'contact_verification' => 8,// verified/public contact + trusted source
            'data_completeness' => 4,   // website, phone, location, industry known
            'signal_freshness' => 4,    // recency of evidence
        ],
        'thresholds' => [
            'high' => 80,       // immediate outreach eligible
            'qualified' => 65,  // standard outreach eligible
            'nurture' => 45,    // manual review
        ],
        'calibration_min_outcomes' => 30, // minimum resolved leads before calibration is reported
    ],

    /*
    | Follow-up cadence (days after the previous touch). Kept short: industry
    | benchmark reports (see docs/COMPETITOR_RESEARCH.md) show most replies come
    | from the first email + early follow-ups, and long sequences raise spam risk.
    */
    'outreach' => [
        // Instantly 2026 benchmark: best results <80 words; 4-7 touches spaced 3-4 days; 42% of replies come from follow-ups.
        'max_words_first_email' => 80,
        'follow_up_days' => [3, 4, 4, 5],   // touches 2..5 => ~16-day sequence, stop on any reply
        'max_touches' => 5,
    ],

    'sources' => [
        'overture_places' => [
            // Free, no key: Overture Maps places GeoParquet is read from the public S3 bucket with the bundled DuckDB CLI.
            'duckdb_bin' => env('OVERTURE_DUCKDB_BIN', base_path('tools/duckdb/duckdb.exe')),
            'release' => env('OVERTURE_RELEASE', ''), // empty = latest from https://stac.overturemaps.org/catalog.json (cached 24 h)
            'fallback_release' => '2026-10-21.0', // only when the STAC catalog is unreachable (Overture removes releases after ~60 days)
            's3_prefix' => env('OVERTURE_S3_PREFIX', 's3://overturemaps-us-west-2/release'),
            'min_confidence' => (float) env('OVERTURE_MIN_CONFIDENCE', 0.6), // Overture existence score 0-1 (relative, not calibrated)
            'bbox_padding' => 0.02, // degrees added around the Nominatim bounding box of a city
            'extract_timeout' => (int) env('OVERTURE_EXTRACT_TIMEOUT', 600), // seconds for the one-off city extract (~45 s observed)
        ],
        'google_places' => [
            'api_key' => env('GOOGLE_PLACES_API_KEY'),
            // Field mask controls BILLING SKU. Keep to what we need.
            'field_mask' => 'places.id,places.displayName,places.formattedAddress,places.websiteUri,places.nationalPhoneNumber,places.internationalPhoneNumber,places.rating,places.userRatingCount,places.businessStatus,places.primaryTypeDisplayName,places.googleMapsUri,places.addressComponents',
            // websiteUri/phone/rating trigger the Text Search ENTERPRISE SKU: $35 per 1,000 after 1,000 free/month
            // (Google price list checked 2026-10-22). 3.5c rounded up. Verify before budgeting.
            'est_cost_cents_per_request' => 4,
            // Google Maps Platform terms: only place_id may be stored indefinitely. Places-sourced fields
            // older than this are purged by `php artisan leadpilot:places-purge` unless re-verified elsewhere.
            'retention_days' => (int) env('GOOGLE_PLACES_RETENTION_DAYS', 30),
        ],
        'ollama' => [
            // Free local LLM (https://ollama.com): no key, nothing leaves this PC. Install Ollama, run `ollama pull qwen2.5:3b`.
            'enabled' => (bool) env('LEADPILOT_OLLAMA_ENABLED', true),
            'url' => rtrim((string) env('LEADPILOT_OLLAMA_URL', 'http://localhost:11434'), '/'),
            'model' => env('LEADPILOT_OLLAMA_MODEL', 'qwen2.5:3b'),
            'timeout' => (int) env('LEADPILOT_OLLAMA_TIMEOUT', 120),
            // Drafts are guarded (facts, numbers, CTA, placeholders) so a small local model can only reword or be rejected.
            // Company research is open-ended and small models fabricate there: off unless the owner opts in (needs >= 7b to be useful).
            'research' => (bool) env('LEADPILOT_OLLAMA_RESEARCH', false),
        ],
        'pagespeed' => [
            'api_key' => env('PAGESPEED_API_KEY'),
            'enabled' => env('PAGESPEED_ENABLED', false),
        ],
        'remotive' => [
            // Remotive's API terms prohibit using listings to collect leads. Keep false unless you have permission.
            'enabled' => env('LEADPILOT_REMOTIVE_ENABLED', false),
        ],
        'website_crawler' => [
            'user_agent' => env('LEADPILOT_CRAWLER_UA', 'LeadPilotBot/1.0 (+business website audit; contact: admin)'),
            'timeout' => 15,
            'max_internal_links_checked' => 15,
            'contact_paths' => ['/contact', '/contact-us', '/about', '/about-us'],
        ],
    ],

    // Free-mail domains: never treat as a company domain
    // Free mailbox-existence check (the alternative to Hunter): SMTP RCPT TO on the domain's MX, no email is sent.
    // Needs outbound TCP 25; when the network blocks it (most home ISPs) the check reports "not probed" and falls back to MX.
    'smtp_probe' => (bool) env('LEADPILOT_SMTP_PROBE', true),

    // Verification waterfall: cache -> free SMTP probe -> Hunter (only for MX-only/unknown results, within budget).
    'email_verification' => [
        'hunter_escalation' => (bool) env('LEADPILOT_HUNTER_ESCALATION', true),
        // mx_only / unknown results are re-checked after this many hours (proven results are cached 30 days).
        'inconclusive_cache_hours' => (int) env('LEADPILOT_EMAIL_RECHECK_HOURS', 24),
        // Port 25 counts as blocked only when this known-good MX also refuses (e.g. gmail-smtp-in.l.google.com) ...
        'port25_canary' => env('LEADPILOT_SMTP_CANARY'),
        // ... or, with no canary, after this many distinct domains refused within an hour.
        'port25_block_threshold' => (int) env('LEADPILOT_PORT25_BLOCK_THRESHOLD', 3),
    ],

    // Inbound webhooks: HMAC-SHA256 of the raw body with this secret (or the provider integration's webhook_secret).
    // No secret configured = every webhook is rejected with 503 (fail closed).
    'webhooks' => [
        'secret' => env('LEADPILOT_WEBHOOK_SECRET'),
        'providers' => ['resend', 'sendgrid', 'mailgun', 'postmark', 'ses', 'meta_ads', 'google_ads', 'tiktok_ads'],
    ],

    // Self-service registration. New accounts get `default_role` (sales: can work leads, cannot change outreach
    // settings). The very first account of an empty installation becomes admin so a fresh server can be set up.
    'registration' => [
        'enabled' => (bool) env('LEADPILOT_ALLOW_REGISTRATION', true),
        // Private sign-up link: when set, /register opens only as /register?key=<this value> and the login page shows
        // no "Create an account" link, so only people the owner sends the link to can create an account.
        'key' => env('LEADPILOT_REGISTRATION_KEY') ?: null,
        'default_role' => env('LEADPILOT_REGISTRATION_ROLE', 'sales'),
    ],

    'free_mail_domains' => [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'aol.com',
        'proton.me', 'protonmail.com', 'gmx.com', 'yandex.com', 'mail.com', 'msn.com', 'ymail.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | v4 Autopilot
    |--------------------------------------------------------------------------
    */
    'timezone' => env('LEADPILOT_TIMEZONE', 'Asia/Karachi'),

    // Default autonomy policy (admins can change per action in Autopilot -> Autonomy policy).
    // automatic = runs without asking; approval = pauses for a human; prohibited = never runs.
    'autonomy_defaults' => [
        'discovery.free' => 'automatic',          // keyless sources (OSM, tenders, ATS)
        'discovery.paid' => 'approval',           // Google Places / Apollo calls beyond budget-approved
        'enrichment.audit' => 'automatic',        // website audits
        'enrichment.verify_email' => 'automatic', // free DNS/MX; Hunter only within budget
        'scoring.run' => 'automatic',
        'drafting.ai' => 'automatic',             // LLM polish (counts toward AI budget)
        'outreach.first_touch' => 'approval',     // SAFE DEFAULT: a human approves each first email
        'outreach.follow_up' => 'automatic',      // follow-ups of an approved thread, stop on any reply
        'reply.classify' => 'automatic',
        'reply.auto_respond' => 'prohibited',     // replies to humans are always reviewed
        'crm.stage_update' => 'automatic',
        'meeting.propose_slots' => 'approval',
        'proposal.send' => 'approval',            // never automatic: commercial commitment
        'integration.enable' => 'approval',
        'spend.over_budget' => 'prohibited',
    ],

    'approvals' => [
        'due_hours' => ['critical' => 2, 'high' => 8, 'normal' => 24, 'low' => 72],
        // Escalation chain by level (role names). Level 0 = assigned user/role.
        'escalation_chain' => ['sales', 'admin'],
        'renotify_hours' => 12,
        'reply_confidence_threshold' => 85, // below this a classification is routed to a human
    ],

    'incidents' => [
        'bounce_rate_max' => 0.05,          // hard bounces / sends over last N
        'complaint_rate_max' => 0.003,      // Gmail bulk-sender guidance: keep spam rate < 0.3%
        'window_sends' => 50,
        'auth_failures_max' => 3,
        'heartbeat_stale_minutes' => 15,
        'notification_failures_max' => 5,
    ],

    // Free-first budgets in US cents (0 = paid calls not allowed). Override in Autopilot -> Budgets.
    'budgets' => [
        'global_daily' => (int) env('LEADPILOT_BUDGET_DAILY_CENTS', 0),
        'global_monthly' => (int) env('LEADPILOT_BUDGET_MONTHLY_CENTS', 0),
    ],

    // Provider catalogue for the registry (capability => providers in preference order: free first).
    'providers' => [
        'local_discovery' => ['overture_places', 'osm_overpass', 'google_places'], // Overture has the most contact data; all free-first
        'procurement' => ['uk_contracts_finder', 'uk_find_a_tender', 'eu_ted'],
        'hiring_signals' => ['ats_watchlist', 'hn_hiring', 'public_job_demand'],
        'b2b_discovery' => ['wikidata_orgs', 'apollo_orgs'], // free keyless Wikidata first; Apollo only with a key + budget
        'email_verification' => ['dns_mx', 'hunter'],
        'website_performance' => ['pagespeed'],
        'llm' => ['ollama', 'openai'], // local Ollama (free) before paid OpenAI; keyed Gemini free tier is tried first by LlmClient
        'email_sending' => ['smtp'],
        'reply_sync' => ['imap'],
    ],

    'autopilot' => [
        'discovery_runs_per_cycle' => 2,
        'audits_per_cycle' => 10,
        // Time budgets (seconds) so one cycle stays well under shared-hosting process limits; whatever is left
        // over is picked up 15 minutes later. lock_seconds frees the cycle lock if the host killed a run.
        'audit_seconds' => (int) env('LEADPILOT_AUDIT_SECONDS', 150),
        'verify_seconds' => (int) env('LEADPILOT_VERIFY_SECONDS', 90),
        'lock_seconds' => (int) env('LEADPILOT_CYCLE_LOCK_SECONDS', 900),
        'drafts_per_cycle' => 10,
        'max_first_touch_per_day' => (int) env('LEADPILOT_MAX_FIRST_TOUCH_PER_DAY', 25),
        // Warm-up: for the first `days` after outreach went live, first touches are capped at `cap` per day.
        'ramp' => ['days' => (int) env('LEADPILOT_RAMP_DAYS', 3), 'cap' => (int) env('LEADPILOT_RAMP_CAP', 10)],
        'sending_hours' => [9, 17],         // local time, Mon-Fri
        'sending_days' => [1, 2, 3, 4, 5],
    ],

    // Auto Market: the autopilot builds its own Ideal Customer Profiles (country x segment) so nobody has to type
    // targets in. The allocator then learns which country/segment/source combinations produce qualified leads.
    // Countries = where B2B cold email is lawful without prior consent (EU/GDPR and Canada/CASL deliberately excluded).
    'auto_market' => [
        'enabled' => (bool) env('LEADPILOT_AUTO_MARKET', true),
        'countries' => [
            'Pakistan' => ['Karachi', 'Lahore', 'Islamabad', 'Rawalpindi', 'Faisalabad'],
            'United States' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Dallas'],
            'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Glasgow'],
            'United Arab Emirates' => ['Dubai', 'Abu Dhabi', 'Sharjah'],
            'Saudi Arabia' => ['Riyadh', 'Jeddah', 'Dammam'],
            'Australia' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth'],
        ],
        // niches are OSM/Wikidata search words; services are keys of `services` above.
        'segments' => [
            'clinics' => ['label' => 'Clinics & healthcare', 'niches' => ['dentist', 'clinic', 'physio', 'skin'], 'services' => ['web_redesign', 'seo_technical'], 'type' => 'local_business'],
            'beauty' => ['label' => 'Salons, beauty & gyms', 'niches' => ['salon', 'beauty', 'gym'], 'services' => ['web_redesign', 'conversion_optimization'], 'type' => 'local_business'],
            'food' => ['label' => 'Restaurants & cafes', 'niches' => ['restaurant', 'cafe', 'bakery'], 'services' => ['web_dev', 'ecommerce'], 'type' => 'local_business'],
            // 'hospitality' (hotels & travel) removed 26 Oct 2026: on live data it yielded chain branches and
            // reservations@ inboxes, not owners who buy a website. Its profiles are switched off on the next sync.
            'property' => ['label' => 'Real estate & construction', 'niches' => ['real estate', 'property', 'construction'], 'services' => ['web_redesign', 'custom_software'], 'type' => 'local_business'],
            'professional' => ['label' => 'Law, accounting & architects', 'niches' => ['lawyer', 'accountant', 'architect', 'insurance'], 'services' => ['web_redesign', 'seo_technical'], 'type' => 'local_business'],
            'education' => ['label' => 'Schools & academies', 'niches' => ['school', 'college', 'academy'], 'services' => ['web_dev', 'custom_software'], 'type' => 'local_business'],
            'retail' => ['label' => 'Retail & showrooms', 'niches' => ['furniture', 'clothes', 'jewel', 'electronics', 'car'], 'services' => ['ecommerce', 'erp_pos'], 'type' => 'local_business'],
            'b2b' => ['label' => 'Companies (B2B)', 'niches' => ['software company', 'it company', 'marketing agency', 'logistics'], 'services' => ['custom_software', 'laravel_php'], 'type' => 'b2b'],
            'tenders' => ['label' => 'Public tenders', 'niches' => [], 'services' => ['web_dev', 'custom_software'], 'type' => 'government', 'countries' => ['United Kingdom']],
            // One profile across all approved countries: companies announcing developer hiring (HN "Who is hiring",
            // and job boards found on audited websites). Hiring is the strongest free buying signal we can see.
            'hiring' => ['label' => 'Companies hiring developers', 'keywords' => ['php', 'laravel', 'web developer', 'full stack'], 'services' => ['laravel_php', 'custom_software'],
                'type' => 'b2b', 'global' => true, 'sources' => ['hn_hiring', 'ats_watchlist']],
        ],
    ],

    // API keys read via config so `php artisan config:cache` keeps working
    'keys' => [
        'gemini' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'openai' => env('OPENAI_API_KEY'),
        'hunter' => env('HUNTER_API_KEY'),
    ],

    'imap' => [
        'host' => env('IMAP_HOST', 'imap.gmail.com'),
        'port' => (int) env('IMAP_PORT', 993),
        'username' => env('IMAP_USERNAME', env('MAIL_USERNAME')),
        'password' => env('IMAP_PASSWORD'), // Gmail: an App Password; never your normal password
        'mailbox' => env('IMAP_MAILBOX', 'INBOX'),
        'enabled' => env('IMAP_ENABLED', false),
    ],

    'meetings' => [
        'duration_minutes' => 30,
        'working_hours' => [10, 17],
        'working_days' => [1, 2, 3, 4, 5],
        'slots_to_offer' => 3,
    ],

    'osm' => [
        'overpass_url' => env('OSM_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        // Comma-separated list to replace the default mirror order (community mirror, OSM_OVERPASS_URL, main server).
        'overpass_mirrors' => env('OSM_OVERPASS_MIRRORS', ''),
        'http_timeout' => (int) env('OSM_HTTP_TIMEOUT', 120), // seconds; the public server often needs 30-60 s per city query
        'retry_pause_seconds' => (int) env('OSM_RETRY_PAUSE', 5),
        'nominatim_url' => env('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        'contact_email' => env('OSM_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')), // required by Nominatim policy
    ],
];
