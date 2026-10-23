<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Hunting Engine (v3):
 *  - icp_profiles        Ideal Customer Profiles -> reusable search configurations
 *  - discovery_runs      Audit log of every hunt (which ICP, which sources, results, errors)
 *  - website_audits      Evidence-based website/business audits (only checks actually performed)
 *  - data_provenance     Field-level provenance: where each value came from and when
 *  - scoring_settings    Admin-configurable scoring weights and thresholds
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icp_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();

            // Geography
            $table->json('countries')->nullable();
            $table->json('regions')->nullable();
            $table->json('cities')->nullable();

            // Firmographics
            $table->json('industries')->nullable();
            $table->json('niches')->nullable();             // e.g. "dental clinics", "real estate agencies"
            $table->json('company_sizes')->nullable();      // e.g. ["1-10","11-50"]
            $table->json('business_types')->nullable();     // local_business, b2b, saas, ecommerce, agency, nonprofit, government

            // Offer & need
            $table->json('target_services')->nullable();    // keys from config('leadpilot.services')
            $table->string('website_requirement', 30)->default('any'); // any, has_website, no_website, weak_website
            $table->json('tech_include')->nullable();       // e.g. ["WordPress","jQuery"]
            $table->json('tech_exclude')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('keywords')->nullable();           // free-form discovery keywords
            $table->string('min_purchase_ability', 20)->nullable(); // low, medium, high (heuristic)

            // Contacts & quality
            $table->json('contact_roles')->nullable();
            $table->unsignedTinyInteger('min_lead_score')->default(50);
            $table->unsignedTinyInteger('min_google_rating')->nullable();      // x10, e.g. 35 = 3.5
            $table->unsignedInteger('min_review_count')->nullable();

            // Exclusions
            $table->json('exclude_domains')->nullable();
            $table->json('exclude_keywords')->nullable();
            $table->boolean('exclude_existing_customers')->default(true);

            // Channels / campaign
            $table->json('sources')->nullable();            // discovery adapters to use
            $table->json('channels')->nullable();           // email, phone, linkedin_manual, whatsapp_manual
            $table->unsignedInteger('max_results_per_run')->default(25);
            $table->unsignedInteger('monthly_budget_cents')->nullable();
            $table->string('objective')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('icp_profile_id')->nullable()->constrained('icp_profiles')->nullOnDelete();
            $table->string('source_slug', 100)->index();
            $table->string('status', 30)->default('running')->index(); // running, completed, failed, skipped
            $table->json('options')->nullable();
            $table->unsignedInteger('total_found')->default(0);
            $table->unsignedInteger('new_companies')->default(0);
            $table->unsignedInteger('new_leads')->default(0);
            $table->unsignedInteger('new_signals')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('excluded')->default(0);
            $table->json('errors')->nullable();
            $table->unsignedInteger('estimated_cost_cents')->default(0);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('website_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('final_url', 500)->nullable();
            $table->string('status', 30)->default('completed')->index(); // completed, blocked_by_robots, unreachable, failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('page_size_bytes')->nullable();
            $table->unsignedTinyInteger('opportunity_score')->default(0); // 0-100: higher = more fixable gaps
            $table->json('checks')->nullable();            // every check performed: key, label, status, evidence, value
            $table->json('technologies')->nullable();      // detected tech with evidence
            $table->json('contacts_found')->nullable();    // public business emails/phones/socials found on site
            $table->json('opportunities')->nullable();     // service opportunities mapped from failed checks
            $table->json('pagespeed')->nullable();         // Google PageSpeed Insights (only if run)
            $table->text('summary')->nullable();
            $table->timestamp('audited_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('data_provenance', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');                       // Company / Lead
            $table->string('field', 100)->index();
            $table->text('value')->nullable();
            $table->string('source', 100)->index();          // google_places, website_crawl, remotive_api, manual, ...
            $table->string('source_url', 500)->nullable();
            $table->string('method', 50)->nullable();        // api, crawl, user_input, import, inferred
            $table->unsignedTinyInteger('confidence')->default(80);
            $table->timestamp('collected_at')->index();
            $table->timestamps();
        });

        Schema::create('scoring_settings', function (Blueprint $table) {
            $table->id();
            $table->json('weights');
            $table->json('thresholds');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('icp_profile_id')->nullable()->after('company_id')->constrained('icp_profiles')->nullOnDelete();
            // contact_status makes "we have a company but no verified person" explicit instead of inventing one
            $table->string('contact_status', 30)->default('identified')->after('email_verification_status')->index(); // identified, generic_inbox, needs_enrichment
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('source_provider')->index(); // e.g. Google place_id
            $table->decimal('google_rating', 2, 1)->nullable()->after('external_id');
            $table->unsignedInteger('google_review_count')->nullable()->after('google_rating');
            $table->string('business_status', 40)->nullable()->after('google_review_count');
            $table->unsignedTinyInteger('website_opportunity_score')->nullable()->after('business_status');
            $table->timestamp('website_audited_at')->nullable()->after('website_opportunity_score');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'google_rating', 'google_review_count', 'business_status', 'website_opportunity_score', 'website_audited_at']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('icp_profile_id');
            $table->dropColumn('contact_status');
        });
        Schema::dropIfExists('scoring_settings');
        Schema::dropIfExists('data_provenance');
        Schema::dropIfExists('website_audits');
        Schema::dropIfExists('discovery_runs');
        Schema::dropIfExists('icp_profiles');
    }
};
