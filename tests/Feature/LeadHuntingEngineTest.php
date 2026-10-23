<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\DataProvenance;
use App\Models\DiscoveryRun;
use App\Models\EmailLog;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Discovery\Adapters\GooglePlacesAdapter;
use App\Services\Hunting\IcpSearchConfigBuilder;
use App\Services\Hunting\LeadHuntService;
use App\Services\LeadScoringService;
use App\Services\Sales\ConversionAssistantService;
use App\Services\Sales\ReplyClassifier;
use App\Services\Sales\ScoreCalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadHuntingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'leadpilot.sources.google_places.api_key' => 'test-key',
            'leadpilot.sources.pagespeed.enabled' => false,
            'leadpilot.agency.booking_url' => null,
            // v4 is free-first: these v3 tests exercise Google Places, so allow paid discovery with a budget
            'leadpilot.autonomy_defaults' => array_merge(config('leadpilot.autonomy_defaults'), ['discovery.paid' => 'automatic']),
            'leadpilot.budgets.global_daily' => 10000,
            'leadpilot.budgets.global_monthly' => 100000,
            // Fake hosts resolve to a public documentation-range-like address so the SSRF guard lets them through
            'leadpilot.sources.website_crawler.dns_overrides' => [
                'smiledental.test' => ['93.184.216.34'], 'blocked.test' => ['93.184.216.34'], 'glue.test' => ['93.184.216.34'],
                'evil.test' => ['93.184.216.34'], 'agency-footer.test' => ['93.184.216.34'], 'botblock.test' => ['93.184.216.34'],
            ],
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    protected function placesPayload(): array
    {
        return ['places' => [
            [
                'id' => 'place-aaa', 'displayName' => ['text' => 'Smile Dental Clinic'],
                'formattedAddress' => '12 Mall Rd, Lahore, Pakistan', 'websiteUri' => 'https://smiledental.test/',
                'internationalPhoneNumber' => '+92 42 1234567', 'rating' => 4.6, 'userRatingCount' => 210,
                'businessStatus' => 'OPERATIONAL', 'primaryTypeDisplayName' => ['text' => 'Dentist'],
                'googleMapsUri' => 'https://maps.google.com/?cid=1',
                'addressComponents' => [['longText' => 'Lahore', 'types' => ['locality']], ['longText' => 'Pakistan', 'types' => ['country']]],
            ],
            [
                'id' => 'place-bbb', 'displayName' => ['text' => 'Gulberg Family Dentist'],
                'formattedAddress' => 'Gulberg, Lahore', 'nationalPhoneNumber' => '042 7654321', 'rating' => 4.2,
                'userRatingCount' => 85, 'businessStatus' => 'OPERATIONAL', 'primaryTypeDisplayName' => ['text' => 'Dentist'],
                'googleMapsUri' => 'https://maps.google.com/?cid=2',
                'addressComponents' => [['longText' => 'Lahore', 'types' => ['locality']], ['longText' => 'Pakistan', 'types' => ['country']]],
            ],
            [
                'id' => 'place-ccc', 'displayName' => ['text' => 'Closed Dental'], 'businessStatus' => 'CLOSED_PERMANENTLY',
            ],
            [
                'id' => 'place-ddd', 'displayName' => ['text' => 'Competitor Dental'], 'websiteUri' => 'https://competitor.test',
                'businessStatus' => 'OPERATIONAL', 'rating' => 4.9, 'userRatingCount' => 500,
            ],
        ]];
    }

    protected function weakSiteHtml(): string
    {
        return <<<'HTML'
<html><head><title>Smile</title>
<script src="/js/jquery-1.11.3.min.js"></script>
<meta name="generator" content="WordPress 5.2.1">
</head><body>
<h1>Welcome</h1><h1>Our clinic</h1>
<img src="a.jpg"><img src="b.jpg"><img src="c.jpg" alt="team">
<p>Visit us. Email <a href="mailto:info@smiledental.test">info@smiledental.test</a></p>
<a href="/services">Services</a> <a href="/broken-page">Old page</a>
<footer>© 2019 Smile Dental. All rights reserved.</footer>
</body></html>
HTML;
    }

    protected function fakeWeb(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->placesPayload(), 200),
            'smiledental.test/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/\n", 200),
            'smiledental.test/broken-page' => Http::response('nope', 404),
            'smiledental.test/services' => Http::response('<html>ok</html>', 200),
            'smiledental.test/contact*' => Http::response('<html><body><form><input type="email" name="email"><textarea name="message"></textarea></form><a href="tel:+92421234567">Call</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'smiledental.test/about*' => Http::response('', 404),
            'smiledental.test*' => Http::response($this->weakSiteHtml(), 200, ['Content-Type' => 'text/html', 'X-Powered-By' => 'PHP/7.2.1']),
            'blocked.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200),
            'blocked.test*' => Http::response('<html>secret</html>', 200),
            '*' => Http::response('', 404),
        ]);
    }

    protected function icp(array $over = []): IcpProfile
    {
        return IcpProfile::create(array_merge([
            'name' => 'Lahore dentists',
            'countries' => ['Pakistan'], 'cities' => ['Lahore'], 'niches' => ['dentist'], 'industries' => ['Dentist'],
            'website_requirement' => 'any', 'sources' => ['google_places'], 'exclude_domains' => ['competitor.test'],
            'contact_roles' => ['Owner'], 'target_services' => ['web_redesign'], 'max_results_per_run' => 10,
            'min_lead_score' => 40, 'is_active' => true,
        ], $over));
    }

    // ------------------------------------------------------------------ ICP builder

    public function test_icp_builder_turns_profile_into_source_configs(): void
    {
        $cfg = app(IcpSearchConfigBuilder::class)->build($this->icp(['min_google_rating' => 40, 'website_requirement' => 'weak_website']));

        $this->assertSame(['dentist in Lahore, Pakistan'], $cfg['google_places']['queries']);
        $this->assertEquals(4.0, $cfg['google_places']['min_rating']);
        $this->assertSame('has_website', $cfg['google_places']['website_requirement']);
    }

    // ------------------------------------------------------------------ Google Places

    public function test_google_places_ingests_without_fabrication_and_applies_icp_rules(): void
    {
        $this->fakeWeb();
        $icp = $this->icp();
        $result = (new GooglePlacesAdapter())->discover(['queries' => ['dentist in Lahore'], 'limit' => 10, 'icp' => $icp]);

        $this->assertSame(2, $result->newCompaniesCount);
        $this->assertSame(2, $result->excludedCount, 'closed business + excluded competitor domain');
        $this->assertDatabaseMissing('companies', ['name' => 'Competitor Dental']);
        $this->assertGreaterThan(0, $result->estimatedCostCents);

        $noSite = Company::where('name', 'Gulberg Family Dentist')->first();
        $this->assertNull($noSite->website);
        $this->assertNull($noSite->domain);
        $this->assertDatabaseHas('buying_signals', ['company_id' => $noSite->id, 'signal_type' => 'website_issues', 'relevant_service' => 'web_dev']);

        $lead = Lead::where('company_id', $noSite->id)->first();
        $this->assertNull($lead->email, 'No email may be invented');
        $this->assertSame('needs_enrichment', $lead->contact_status);
        $this->assertSame($icp->id, $lead->icp_profile_id);
        $this->assertNull($lead->first_name);

        $this->assertDatabaseHas('data_provenance', ['subject_id' => $noSite->id, 'field' => 'public_phone', 'source' => 'google_places']);

        // Idempotent on place_id
        $again = (new GooglePlacesAdapter())->discover(['queries' => ['dentist in Lahore'], 'limit' => 10, 'icp' => $icp]);
        $this->assertSame(0, $again->newCompaniesCount);
        $this->assertSame(0, $again->newLeadsCount);
    }

    public function test_google_places_website_requirement_filter(): void
    {
        $this->fakeWeb();
        $r = (new GooglePlacesAdapter())->discover(['queries' => ['dentist'], 'website_requirement' => 'no_website']);
        $this->assertSame(1, $r->newCompaniesCount);
        $this->assertDatabaseHas('companies', ['name' => 'Gulberg Family Dentist']);
    }

    // ------------------------------------------------------------------ Website audit

    public function test_website_audit_records_only_real_checks_and_finds_public_contacts(): void
    {
        $this->fakeWeb();
        $company = Company::create(['name' => 'Smile Dental Clinic', 'website' => 'https://smiledental.test/', 'domain' => 'smiledental.test']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Smile Dental Clinic — contact unknown', 'contact_status' => 'needs_enrichment']);

        $audit = app(WebsiteAuditService::class)->audit($company);
        $checks = collect($audit->checks)->keyBy('key');

        $this->assertSame('completed', $audit->status);
        $this->assertSame('fail', $checks['viewport']['status']);
        $this->assertSame('fail', $checks['meta_description']['status']);
        $this->assertSame('warn', $checks['h1']['status']);
        $this->assertSame('warn', $checks['copyright']['status']);
        $this->assertSame('warn', $checks['legacy_tech']['status']);
        $this->assertSame('fail', $checks['broken_links']['status']);
        $this->assertStringContainsString('/broken-page', $checks['broken_links']['evidence']);
        $this->assertSame('pass', $checks['contact_form']['status'], 'form found on /contact page');
        $this->assertSame('pass', $checks['https']['status']);

        $tech = collect($audit->technologies)->pluck('name')->all();
        $this->assertContains('WordPress', $tech);
        $this->assertContains('jQuery', $tech);

        $this->assertGreaterThanOrEqual(30, $audit->opportunity_score);
        $this->assertSame('web_redesign', $audit->opportunities[0]['service']);

        $company->refresh();
        $this->assertSame('info@smiledental.test', $company->public_email);
        $this->assertNotNull($company->website_audited_at);

        $lead->refresh();
        $this->assertSame('info@smiledental.test', $lead->email);
        $this->assertSame('generic_inbox', $lead->contact_status);
        $this->assertDatabaseHas('data_provenance', ['subject_id' => $lead->id, 'field' => 'email', 'source' => 'website_crawl']);
        $this->assertDatabaseHas('buying_signals', ['company_id' => $company->id, 'signal_type' => 'website_issues']);

        // Re-audit replaces (not duplicates) the audit-derived signal
        app(WebsiteAuditService::class)->audit($company);
        $this->assertSame(1, BuyingSignal::where('company_id', $company->id)->where('signal_type', 'website_issues')->count());
    }

    public function test_website_audit_respects_robots_txt(): void
    {
        $this->fakeWeb();
        $company = Company::create(['name' => 'Private Co', 'website' => 'https://blocked.test']);
        $audit = app(WebsiteAuditService::class)->audit($company);

        $this->assertSame('blocked_by_robots', $audit->status);
        Http::assertNotSent(fn ($req) => $req->url() === 'https://blocked.test');
    }

    public function test_website_audit_refuses_internal_hosts(): void
    {
        Http::fake();
        $company = Company::create(['name' => 'Internal', 'website' => 'http://127.0.0.1/admin']);
        $audit = app(WebsiteAuditService::class)->audit($company);

        $this->assertSame('failed', $audit->status);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ Scoring

    public function test_scoring_is_configurable_explainable_and_labelled_heuristic(): void
    {
        $company = Company::create(['name' => 'Acme', 'country' => 'Pakistan', 'city' => 'Lahore', 'industry' => 'Dentist', 'website' => 'https://acme.test']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Ali Raza', 'first_name' => 'Ali', 'job_title' => 'Owner', 'email' => 'ali@acme.test', 'contact_status' => 'identified', 'country' => 'Pakistan']);

        app(LeadScoringService::class)->evaluateLead($lead);
        $bd = $lead->fresh()->score_breakdown;

        $this->assertSame('heuristic', $bd['score_type']);
        $this->assertNull($bd['scores']['website_gap'], 'Unaudited website is not evaluated, not scored as zero');
        $this->assertContains('website_gap', $bd['excluded_factors']);
        $this->assertContains('Website not audited yet — run a website audit.', $bd['missing_information']);
        $this->assertArrayHasKey('confidence', $bd);
        $this->assertArrayHasKey('suggested_next_action', $bd);
        $this->assertArrayHasKey('data_freshness', $bd);

        // Admin weights change the outcome
        $before = $lead->fresh()->overall_score;
        $w = config('leadpilot.scoring.weights');
        $w['contact_role'] += $w['explicit_request'];
        $w['explicit_request'] = 0;
        ScoringSetting::create(['weights' => $w, 'thresholds' => config('leadpilot.scoring.thresholds')]);
        app(LeadScoringService::class)->evaluateLead($lead->fresh());
        $this->assertGreaterThan($before, $lead->fresh()->overall_score);
    }

    public function test_icp_match_factor_uses_only_known_data(): void
    {
        $icp = $this->icp();
        $company = Company::create(['name' => 'Smile', 'country' => 'Pakistan', 'city' => 'Lahore', 'industry' => 'Dentist']);
        $lead = Lead::create(['company_id' => $company->id, 'icp_profile_id' => $icp->id, 'full_name' => 'Smile — contact unknown', 'contact_status' => 'needs_enrichment', 'country' => 'Pakistan']);

        [$strength, $detail] = app(LeadScoringService::class)->icpMatch($icp, $lead);
        $this->assertEquals(1.0, $strength);
        $this->assertStringContainsString('3/3', $detail);

        $company->update(['city' => 'Karachi']);
        [$strength2] = app(LeadScoringService::class)->icpMatch($icp, $lead->fresh());
        $this->assertLessThan(1.0, $strength2);
    }

    public function test_settings_reject_weights_not_totalling_100(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $w = config('leadpilot.scoring.weights');
        $w['explicit_request'] += 5;
        $this->actingAs($admin)->put(route('settings.scoring.update'), ['weights' => $w, 'thresholds' => ['high' => 80, 'qualified' => 65, 'nurture' => 45]])
            ->assertSessionHas('error');
        $this->assertSame(0, ScoringSetting::count());
    }

    public function test_calibration_reports_insufficient_data_then_auc(): void
    {
        $svc = app(ScoreCalibrationService::class);
        $this->assertFalse($svc->report()['sufficient']);
        $this->assertSame(1.0, $svc->auc([['score' => 90, 'y' => 1], ['score' => 20, 'y' => 0]]));
        $this->assertSame(0.5, $svc->auc([['score' => 50, 'y' => 1], ['score' => 50, 'y' => 0]]));

        config(['leadpilot.scoring.calibration_min_outcomes' => 10]);
        foreach (range(1, 12) as $i) {
            $good = $i <= 6;
            Lead::create(['full_name' => "L{$i}", 'overall_score' => $good ? 85 : 30, 'lifecycle_stage' => $good ? 'meeting_booked' : 'lost', 'last_contacted_at' => now()->subDays(30)]);
        }
        $r = $svc->report();
        $this->assertTrue($r['sufficient']);
        $this->assertSame(1.0, $r['auc']);
        $this->assertStringContainsString('predictive', $r['verdict']);
    }

    // ------------------------------------------------------------------ Reply classifier

    public function test_reply_classifier_covers_six_categories_and_negation(): void
    {
        $c = new ReplyClassifier();
        $cases = [
            'Sounds interesting — can we schedule a call on Tuesday at 3pm?' => 'interested',
            'We are not interested, thanks.' => 'not_interested',
            "I'm not the right person. Please contact our IT manager sara@acme.com" => 'referral',
            'Honestly this is too expensive for us this year.' => 'objection',
            'I am out of office until 5 November with limited access to email.' => 'out_of_office',
            'Please remove me from your list.' => 'unsubscribe',
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, $c->classify($text)['category'], $text);
        }
        $this->assertSame('meeting_request', $c->classify('Sounds interesting — can we schedule a call on Tuesday?')['subtype']);
        $this->assertSame('sara@acme.com', $c->classify("Not my area. Please contact our IT manager sara@acme.com")['referred_email']);
        $this->assertSame('price', $c->classify('Too expensive.')['objection_type']);

        // Quoted original email must not drive the result
        $quoted = "No thanks.\n\nOn Mon, 3 Nov 2026, Ismail wrote:\n> Are you interested in a call on Tuesday?";
        $this->assertSame('not_interested', $c->classify($quoted)['category']);
    }

    public function test_webhook_negative_reply_no_longer_marked_positive(): void
    {
        $lead = Lead::create(['full_name' => 'Tom', 'email' => 'tom@x.test', 'lifecycle_stage' => 'contacted']);
        $r = app(\App\Services\AiResearchService::class)->classifyInboundReply('Thanks but we are not interested in this.');
        $this->assertSame('negative', $r['classification']);
    }

    // ------------------------------------------------------------------ Conversion assistant

    public function test_assistant_drafts_short_evidence_only_email_without_inventing_a_person(): void
    {
        $this->fakeWeb();
        $company = Company::create(['name' => 'Smile Dental Clinic', 'website' => 'https://smiledental.test/', 'domain' => 'smiledental.test']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Smile Dental Clinic — contact unknown', 'contact_status' => 'needs_enrichment']);
        app(WebsiteAuditService::class)->audit($company);

        $out = app(ConversionAssistantService::class)->draftFirstEmail($lead->fresh(), false);

        $this->assertTrue($out['ok']);
        $this->assertStringStartsWith('Hi Smile Dental Clinic team,', $out['draft']['body']);
        $this->assertStringContainsString('viewport', $out['draft']['body']);
        $this->assertLessThanOrEqual(80, $out['draft']['word_count']);
        $this->assertSame('Website Redesign', $out['offer']['label']);
        $this->assertNotEmpty($out['facts_used']);
    }

    public function test_assistant_refuses_generic_email_without_evidence(): void
    {
        $lead = Lead::create(['company_id' => Company::create(['name' => 'Blank'])->id, 'full_name' => 'X', 'email' => 'x@blank.test']);
        $out = app(ConversionAssistantService::class)->draftFirstEmail($lead, false);
        $this->assertFalse($out['ok']);
    }

    public function test_ai_polish_rejected_when_it_invents_numbers(): void
    {
        $this->fakeWeb();
        \App\Models\Integration::updateOrCreate(['provider' => 'openai'], ['name' => 'OpenAI', 'is_active' => false]);
        config(['app.env' => 'testing']);
        putenv('OPENAI_API_KEY=sk-test');
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['subject' => 'Hi', 'body' => 'Hi Smile Dental Clinic team, we grew clients revenue 300% last year.'])]]]], 200),
            'places.googleapis.com/*' => Http::response($this->placesPayload(), 200),
            'smiledental.test/robots.txt' => Http::response('', 404),
            'smiledental.test*' => Http::response($this->weakSiteHtml(), 200),
            '*' => Http::response('', 404),
        ]);
        $company = Company::create(['name' => 'Smile Dental Clinic', 'website' => 'https://smiledental.test/', 'domain' => 'smiledental.test']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Smile', 'contact_status' => 'needs_enrichment']);
        app(WebsiteAuditService::class)->audit($company);

        $out = app(ConversionAssistantService::class)->draftFirstEmail($lead->fresh(), true);
        putenv('OPENAI_API_KEY');

        $this->assertSame('template', $out['draft']['generator'], 'AI output with invented "300" must be rejected');
        $this->assertStringNotContainsString('300%', $out['draft']['body']);
    }

    public function test_follow_up_respects_cadence_and_stops_on_reply(): void
    {
        $lead = Lead::create(['full_name' => 'Ali', 'first_name' => 'Ali', 'email' => 'ali@a.test', 'contact_status' => 'identified']);
        EmailLog::create(['lead_id' => $lead->id, 'direction' => 'outbound', 'sender_email' => 's@x.test', 'recipient_email' => 'ali@a.test', 'subject' => 'Hello', 'status' => 'sent', 'occurred_at' => now()->subDays(4)]);

        $f = app(ConversionAssistantService::class)->suggestFollowUp($lead);
        $this->assertSame('send_now', $f['action']);
        $this->assertSame(2, $f['touch']);

        \App\Models\InboundReply::create(['lead_id' => $lead->id, 'from_email' => 'ali@a.test', 'to_email' => 's@x.test', 'subject' => 'Re', 'body_text' => 'Not interested', 'classification' => 'negative']);
        $this->assertSame('respond', app(ConversionAssistantService::class)->suggestFollowUp($lead)['action']);
    }

    // ------------------------------------------------------------------ End-to-end hunt + UI

    public function test_end_to_end_hunt_discovers_audits_scores_and_logs_runs(): void
    {
        $this->fakeWeb();
        $icp = $this->icp();
        $runs = app(LeadHuntService::class)->hunt($icp);

        $this->assertCount(1, $runs);
        $this->assertSame('completed', $runs[0]->status);
        $this->assertSame(2, $runs[0]->new_leads);
        $this->assertSame(1, WebsiteAudit::count(), 'Only the company with a website is audited');
        $this->assertSame('completed', WebsiteAudit::first()->status);
        foreach (Lead::all() as $lead) {
            $this->assertNotNull($lead->scored_at);
            $this->assertNotNull($lead->score_breakdown['scores']['icp_match']);
        }
        $this->assertNotNull($icp->fresh()->last_run_at);
        $this->assertSame(1, DiscoveryRun::count());
    }

    public function test_pages_render_and_admin_gating(): void
    {
        $this->fakeWeb();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $icp = $this->icp();
        app(LeadHuntService::class)->hunt($icp);
        $lead = Lead::whereNotNull('email')->first() ?? Lead::first();
        $audit = WebsiteAudit::first();

        $this->actingAs($admin);
        $this->get(route('hunting.index'))->assertOk()->assertSee('Lead Hunter');
        $this->get(route('hunting.create'))->assertOk();
        $this->get(route('hunting.show', $icp))->assertOk()->assertSee('dentist in Lahore, Pakistan');
        $this->get(route('audits.show', $audit))->assertOk()->assertSee('Checks performed');
        $this->get(route('assistant.show', $lead))->assertOk()->assertSee('What to offer');
        $this->get(route('settings.scoring'))->assertOk()->assertSee('Does the score predict outcomes?');
        $this->get(route('leads.show', $lead))->assertOk()->assertSee('Heuristic score');
        $this->post(route('assistant.classify', $lead), ['reply' => 'Please remove me'])->assertRedirect();
        $this->get(route('assistant.show', $lead))->assertSee('unsubscribe');

        $this->post(route('hunting.store'), ['name' => 'New ICP', 'website_requirement' => 'any', 'min_lead_score' => 50, 'max_results_per_run' => 10, 'cities' => 'Islamabad, Rawalpindi', 'is_active' => 1])
            ->assertRedirect();
        $this->assertSame(['Islamabad', 'Rawalpindi'], IcpProfile::where('name', 'New ICP')->first()->cities);

        $this->actingAs($sales);
        $this->get(route('hunting.index'))->assertOk();
        $this->get(route('hunting.create'))->assertForbidden();
        $this->post(route('hunting.hunt', $icp))->assertForbidden();
        $this->get(route('settings.scoring'))->assertForbidden();
    }

    public function test_places_purge_removes_expired_google_content_but_keeps_place_id(): void
    {
        $this->fakeWeb();
        (new GooglePlacesAdapter())->discover(['queries' => ['dentist'], 'limit' => 2]);
        DataProvenance::query()->update(['collected_at' => now()->subDays(40)]);

        $this->artisan('leadpilot:places-purge')->assertSuccessful();

        $c = Company::where('external_id', 'place-bbb')->first();
        $this->assertNotNull($c, 'place_id retained');
        $this->assertNull($c->public_phone);
        $this->assertNull($c->google_rating);
        $this->assertSame('Gulberg Family Dentist', $c->name);
    }

    public function test_regressions_email_gluing_mailto_cta_and_local_icp_scoring(): void
    {
        Http::fake([
            'glue.test/robots.txt' => Http::response('', 404),
            'glue.test*' => Http::response('<html><body><h1>Welcome</h1><p>clinic</p><a href="mailto:info@glue.test">info@glue.test</a><span>services</span></body></html>', 200),
            '*' => Http::response('', 404),
        ]);
        $company = Company::create(['name' => 'Glue Clinic', 'website' => 'https://glue.test']);
        $audit = app(WebsiteAuditService::class)->audit($company);

        $emails = collect($audit->contacts_found['emails'])->pluck('value')->all();
        $this->assertSame(['info@glue.test'], $emails, 'Adjacent text must not be glued onto the address');
        $cta = collect($audit->checks)->firstWhere('key', 'cta');
        $this->assertSame('warn', $cta['status'], 'A bare mailto link is not a clear CTA');
        $this->assertStringNotContainsString('Mobile viewport configured', $audit->summary, 'Summary lists problems, not check names');

        // Local-only ICP: RFP and developer-hiring factors are not applicable
        $icp = $this->icp(['business_types' => ['local_business']]);
        $lead = Lead::create(['company_id' => $company->id, 'icp_profile_id' => $icp->id, 'full_name' => 'Glue', 'email' => 'info@glue.test', 'contact_status' => 'generic_inbox']);
        app(LeadScoringService::class)->evaluateLead($lead);
        $bd = $lead->fresh()->score_breakdown;
        $this->assertNull($bd['scores']['explicit_request']);
        $this->assertNull($bd['scores']['software_need']);
        $this->assertStringNotContainsString('Send personalised', $bd['suggested_next_action'] ?? '', 'Low/nurture leads must not be told to send');
    }

    // ------------------------------------------------------------------ Security & review regressions

    public function test_ssrf_guard_blocks_internal_targets_and_redirects(): void
    {
        $f = new \App\Services\Audit\SafeFetcher('test');
        foreach (['http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://0x7f000001/', 'http://100.64.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://user:pw@example.com/', 'ftp://example.com/', 'http://no-such-host.invalid/', 'http://example.com:22/'] as $u) {
            $this->assertNotNull($f->blockReason($u), "should block {$u}");
        }
        $this->assertFalse(\App\Services\Audit\SafeFetcher::sameOrigin('https://example.com@127.0.0.1/', 'https://example.com'));
        $this->assertFalse(\App\Services\Audit\SafeFetcher::sameOrigin('https://example.com.attacker.tld/', 'https://example.com'));
        $this->assertNull(\App\Services\Audit\SafeFetcher::safeUrl('javascript:alert(1)'));

        // Redirect to cloud metadata is refused and nothing internal is fetched
        Http::fake([
            'evil.test/robots.txt' => Http::response('', 404),
            'evil.test*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('<title>SECRET</title>', 200),
            '*' => Http::response('', 404),
        ]);
        $audit = app(WebsiteAuditService::class)->audit(Company::create(['name' => 'Evil', 'website' => 'https://evil.test']));
        $this->assertNotSame('completed', $audit->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_robots_group_with_versioned_user_agent_is_honoured_and_agency_email_ignored(): void
    {
        config(['leadpilot.sources.website_crawler.user_agent' => 'LeadPilotBot/1.0 (+audit)']);
        Http::fake([
            'botblock.test/robots.txt' => Http::response("User-agent: LeadPilotBot/1.0\nDisallow: /\n", 200),
            'agency-footer.test/robots.txt' => Http::response('', 404),
            'agency-footer.test*' => Http::response('<html><body><footer>Site by <a href="mailto:hello@someagency.com">hello@someagency.com</a></footer></body></html>', 200),
            '*' => Http::response('', 404),
        ]);
        $this->assertSame('blocked_by_robots', app(WebsiteAuditService::class)->audit(Company::create(['name' => 'B', 'website' => 'https://botblock.test']))->status);

        $c = Company::create(['name' => 'Footer Co', 'website' => 'https://agency-footer.test', 'domain' => 'agency-footer.test']);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'F', 'contact_status' => 'needs_enrichment']);
        app(WebsiteAuditService::class)->audit($c);
        $this->assertNull($c->fresh()->public_email);
        $this->assertNull($lead->fresh()->email);
    }

    public function test_eligibility_gate_blocks_unidentified_and_tender_contacts(): void
    {
        Http::fake(['www.contractsfinder.service.gov.uk/*' => Http::response(['releases' => [[
            'ocid' => 'ocds-1', 'id' => 'ocds-1-1', 'buyer' => ['name' => 'Council X'],
            'parties' => [['name' => 'Council X', 'roles' => ['buyer'], 'contactPoint' => ['name' => 'Procurement Team', 'email' => 'bids@councilx.gov.uk']]],
            'tender' => ['title' => 'Interpreting and translation services', 'description' => 'Language services', 'classification' => ['id' => '79530000']],
        ], [
            'ocid' => 'ocds-2', 'id' => 'ocds-2-1', 'buyer' => ['name' => 'Council Y'],
            'parties' => [['name' => 'Council Y', 'roles' => ['buyer'], 'contactPoint' => ['name' => 'Procurement Team', 'email' => 'bids@councily.gov.uk']]],
            'tender' => ['title' => 'New website build', 'description' => 'CMS website', 'classification' => ['id' => '72413000']],
        ]]], 200)]);
        $r = (new \App\Services\Discovery\Adapters\UkContractsFinderAdapter())->discover(['keywords' => ['erp', 'website']]);
        $this->assertSame(1, $r->newCompaniesCount, '"erp" must not match "interpreting"');
        $lead = Lead::where('email', 'bids@councily.gov.uk')->first();
        $this->assertNull($lead->first_name, '"Procurement Team" is not a person');
        $this->assertSame('generic_inbox', $lead->contact_status);

        $gate = app(\App\Services\EligibilityGateService::class)->evaluate($lead);
        $this->assertArrayHasKey('contact_identity', $gate->failedChecks);
    }

    public function test_dedupe_keeps_branches_and_recreates_expired_signals(): void
    {
        $res = new \App\Services\Discovery\DiscoveryResult('t', 'T');
        $ing = new \App\Services\Discovery\ProspectIngestor('google_places', 'Places');
        $a = $ing->upsertCompany(['name' => 'Smile Dental', 'external_id' => 'p1', 'city' => 'Lahore'], $res)['company'];
        $b = $ing->upsertCompany(['name' => 'Smile Dental', 'external_id' => 'p2', 'city' => 'Karachi'], $res)['company'];
        $this->assertNotSame($a->id, $b->id);

        $sig = ['signal_type' => 'website_issues', 'title' => 'No website', 'evidence_text' => 'x', 'relevant_service' => 'web_dev'];
        $ing->addSignal($a, $sig + ['expiry_date' => now()->subDay()->toDateString()], $res);
        $this->assertNotNull($ing->addSignal($a, $sig + ['expiry_date' => now()->addDays(30)->toDateString()], $res));
    }

    public function test_classifier_review_cases_and_out_of_office_pauses_follow_up(): void
    {
        $c = new ReplyClassifier();
        $this->assertNotSame('unsubscribe', $c->classify("Please don't unsubscribe me, I just have a question.")['category']);
        $this->assertSame('interested', $c->classify('Sounds good. Our budget is around 2k, can we schedule a call Tuesday?')['category']);
        $this->assertSame('interested', $c->classify("Yes, I'm interested. What's your budget range?")['category']);
        $this->assertNotSame('out_of_office', $c->classify('I will be back to you on this.')['category']);
        $this->assertSame('interested', $c->classify("We'd love to chat")['category']);
        $this->assertSame('objection', $c->classify("We're interested but it's too expensive.")['category']);

        $lead = Lead::create(['full_name' => 'Ali', 'first_name' => 'Ali', 'email' => 'ali@a.test', 'contact_status' => 'identified']);
        EmailLog::create(['lead_id' => $lead->id, 'direction' => 'outbound', 'sender_email' => 's@x.test', 'recipient_email' => 'ali@a.test', 'subject' => 'Hello', 'status' => 'sent', 'occurred_at' => now()->subDays(4)]);
        \App\Models\InboundReply::create(['lead_id' => $lead->id, 'from_email' => 'ali@a.test', 'to_email' => 's@x.test', 'subject' => 'Auto', 'body_text' => 'Out of office until ' . now()->addDays(10)->format('j F'), 'classification' => 'out_of_office', 'received_at' => now()]);
        $f = app(ConversionAssistantService::class)->suggestFollowUp($lead);
        $this->assertSame('scheduled', $f['action']);
        $this->assertTrue(\Carbon\Carbon::parse($f['due_at'])->gte(now()->addDays(10)->startOfDay()));
    }

    public function test_sales_rep_cannot_open_assistant_for_someone_elses_lead(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $lead = Lead::create(['full_name' => 'X', 'assigned_user_id' => $other->id]);
        $this->actingAs($sales)->get(route('assistant.show', $lead))->assertForbidden();
        $this->actingAs($sales)->post(route('assistant.classify', $lead), ['reply' => 'hi'])->assertForbidden();
    }
}
