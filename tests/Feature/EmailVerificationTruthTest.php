<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\EmailVerification;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\CampaignDeliveryService;
use App\Services\EligibilityGateService;
use App\Services\EmailVerificationService;
use App\Services\Outreach\WinLadderService;
use App\Support\EmailStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 1 "verification truth" (audit P0-3, P1-5, P1-6): an MX lookup is never "verified"; the gate, ladder, campaigns
 * and dashboard read one EmailStatus vocabulary; the free probe runs first and Hunter only fills inconclusive results.
 */
class EmailVerificationTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mail.default' => 'array', 'app.url' => 'https://leads.eziline.example']);
        User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        OutreachSetting::current()->update(['mode' => 'live', 'require_verified_email' => true, 'allow_shared_inbox' => true, 'min_lead_score' => 65]);
    }

    /** Free verifier with a canned SMTP probe; Hunter optionally "configured". */
    protected function verifier(array $probe, bool $hunter = false): EmailVerificationService
    {
        return new class($probe, $hunter) extends EmailVerificationService {
            public function __construct(public array $probe, bool $hunter)
            {
                parent::__construct();
                $this->hunterApiKey = $hunter ? 'test-key' : null;
                $this->isHunterConfigured = $hunter;
            }

            public function mxHosts(string $domain): array { return ['mx1.' . $domain]; }

            public function smtpProbe(string $email, array $mxHosts): array { return $this->probe + ['code' => null, 'host' => $mxHosts[0], 'note' => 'test']; }
        };
    }

    protected function lead(string $status): Lead
    {
        $domain = 'clinic' . uniqid() . '.pk';
        $c = Company::create(['name' => 'Clinic ' . $domain, 'website' => "https://{$domain}", 'domain' => $domain, 'country' => 'Pakistan', 'city' => 'Lahore', 'industry' => 'Clinic',
            'source_provider' => 'osm_overpass', 'website_opportunity_score' => 70, 'website_audited_at' => now()->subDay(), 'public_email' => "info@{$domain}"]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_gap', 'title' => 'No mobile viewport', 'evidence_text' => 'Homepage has no viewport meta.', 'relevant_service' => 'web_redesign', 'confidence' => 85,
            'observed_date' => now()->subDay(), 'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit']]);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'final_url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'response_time_ms' => 900, 'opportunity_score' => 70,
            'checks' => [['key' => 'viewport', 'label' => 'Mobile viewport configured', 'status' => 'fail', 'value' => null, 'evidence' => 'No viewport meta tag.']],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport meta tag.']]], 'audited_at' => now()->subDay()]);

        return Lead::create(['company_id' => $c->id, 'first_name' => 'Info', 'last_name' => 'Inbox', 'email' => $c->public_email, 'email_verification_status' => $status,
            'contact_status' => 'generic_inbox', 'country' => 'Pakistan', 'overall_score' => 72, 'source_name' => 'OpenStreetMap (Overpass)',
            'lifecycle_stage' => 'qualified', 'pipeline_state' => 'qualified', 'score_explanation' => 'audit evidence']);
    }

    public function test_mx_only_is_not_sendable_until_the_owner_allows_it_and_gate_and_ladder_agree(): void
    {
        $gate = app(EligibilityGateService::class);
        $ladder = app(WinLadderService::class);
        $mxOnly = $this->lead(EmailStatus::MX_ONLY);
        $catchAll = $this->lead(EmailStatus::CATCH_ALL);
        $verified = $this->lead(EmailStatus::VERIFIED);

        $this->assertFalse(EmailStatus::isSendable(EmailStatus::MX_ONLY));
        $this->assertTrue(EmailStatus::isSendable(EmailStatus::CATCH_ALL), 'catch_all and risky are one vocabulary now; both pass');
        $this->assertTrue(EmailStatus::isSendable(EmailStatus::RISKY));

        $r = $gate->evaluate($mxOnly);
        $this->assertFalse($r->isEligible);
        $this->assertArrayHasKey('email_verification', $r->failedChecks);
        $this->assertTrue($gate->evaluate($catchAll)->isEligible, json_encode($gate->evaluate($catchAll)->failedChecks));
        $this->assertTrue($gate->evaluate($verified)->isEligible);
        $this->assertGreaterThan($ladder->evaluate($mxOnly)['points'], $ladder->evaluate($verified)['points'], 'MX-only earns no "verified deliverable" points');
        $this->assertFalse($mxOnly->hasSendableEmail());

        OutreachSetting::current()->update(['allow_mx_only' => true]);
        $this->assertTrue(EmailStatus::isSendable(EmailStatus::MX_ONLY));
        $this->assertTrue($gate->evaluate($mxOnly->fresh())->isEligible, json_encode($gate->evaluate($mxOnly->fresh())->failedChecks));
    }

    public function test_campaign_audience_uses_the_same_sendable_rule(): void
    {
        $leads = collect([EmailStatus::VERIFIED, EmailStatus::CATCH_ALL, EmailStatus::MX_ONLY, EmailStatus::UNVERIFIED])->mapWithKeys(fn ($s) => [$s => $this->lead($s)]);
        $template = EmailTemplate::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'service_category' => 'web_redesign', 'subject' => 'Hi {{company_name}}',
            'body_html' => '<p>Hi</p>', 'body_text' => 'Hi', 'is_active' => true]);
        $campaign = Campaign::create(['name' => 'PK clinics', 'service_offered' => 'Website redesign', 'target_countries' => ['Pakistan'], 'min_lead_score' => 70,
            'email_template_id' => $template->id, 'daily_limit' => 20, 'require_approval' => true, 'status' => 'draft', 'created_by' => User::first()->id]);

        app(CampaignDeliveryService::class)->buildCampaignDrafts($campaign);

        $included = CampaignRecipient::where('campaign_id', $campaign->id)->pluck('lead_id')->all();
        $this->assertEqualsCanonicalizing([$leads[EmailStatus::VERIFIED]->id, $leads[EmailStatus::CATCH_ALL]->id], $included);
    }

    public function test_hunter_mapping_reads_the_current_status_field(): void
    {
        $map = fn (array $d) => EmailVerificationService::mapHunter($d);
        $this->assertSame(EmailStatus::VERIFIED, $map(['status' => 'valid', 'result' => 'deliverable']));
        $this->assertSame(EmailStatus::INVALID, $map(['status' => 'invalid', 'result' => 'undeliverable']));
        $this->assertSame(EmailStatus::CATCH_ALL, $map(['status' => 'accept_all', 'result' => 'risky']));
        $this->assertSame(EmailStatus::INVALID, $map(['status' => 'disposable']));
        $this->assertSame(EmailStatus::VERIFIED, $map(['status' => 'webmail', 'smtp_check' => true]));
        $this->assertSame(EmailStatus::MX_ONLY, $map(['status' => 'webmail', 'smtp_check' => false]));
        $this->assertSame(EmailStatus::UNKNOWN, $map(['status' => 'unknown']));
        $this->assertSame(EmailStatus::VERIFIED, $map(['result' => 'deliverable']), 'deprecated field still understood');
        $this->assertSame(EmailStatus::UNKNOWN, $map([]));
    }

    public function test_waterfall_runs_the_free_probe_first_and_hunter_only_for_inconclusive_results_within_budget(): void
    {
        config(['leadpilot.budgets.global_daily' => 100, 'leadpilot.budgets.global_monthly' => 1000]);
        Http::fake(['api.hunter.io/*' => Http::response(['data' => ['status' => 'valid', 'result' => 'deliverable', 'score' => 92, 'smtp_check' => true, 'mx_records' => true]])]);

        // Free probe proves the mailbox: Hunter is never called.
        $v = $this->verifier(['result' => 'deliverable', 'code' => 250], hunter: true)->verifyEmail('owner@proven.pk');
        $this->assertSame([EmailStatus::VERIFIED, 'smtp_check'], [$v['status'], $v['provider']]);
        Http::assertNothingSent();

        // Port 25 blocked: free result is MX-only, Hunter fills the gap and its cost lands in the ledger.
        $v = $this->verifier(['result' => 'blocked'], hunter: true)->verifyEmail('owner@firm.pk');
        $this->assertSame([EmailStatus::VERIFIED, 'hunter', true], [$v['status'], $v['provider'], $v['has_smtp_check']]);
        Http::assertSentCount(1);
        $this->assertSame(EmailVerificationService::HUNTER_COST_CENTS, (int) UsageLog::where('provider', 'hunter')->value('cost_cents'));
        $steps = EmailVerification::where('email', 'owner@firm.pk')->value('provider_response')['waterfall'];
        $this->assertSame(['dns_check', 'hunter'], array_column($steps, 'provider'));
    }

    public function test_budget_zero_keeps_the_honest_mx_only_result_and_never_calls_hunter(): void
    {
        config(['leadpilot.budgets.global_daily' => 0, 'leadpilot.budgets.global_monthly' => 0]);
        Http::fake();
        $lead = $this->lead(EmailStatus::UNVERIFIED);

        $v = $this->verifier(['result' => 'blocked'], hunter: true)->verifyEmail($lead->email, $lead);

        $this->assertSame(EmailStatus::MX_ONLY, $v['status']);
        $this->assertSame(EmailStatus::MX_ONLY, $lead->fresh()->email_verification_status);
        Http::assertNothingSent();
        $steps = EmailVerification::where('email', $lead->email)->value('provider_response')['waterfall'];
        $this->assertSame('skipped', $steps[1]['status']);
    }

    public function test_inconclusive_results_are_cached_briefly_and_proven_ones_for_30_days(): void
    {
        $this->verifier(['result' => 'blocked'])->verifyEmail('a@slow.pk');
        $this->assertStringEndsWith('(cached)', $this->verifier(['result' => 'deliverable', 'code' => 250])->verifyEmail('a@slow.pk')['provider']);

        $this->travel(25)->hours();
        $this->assertSame(EmailStatus::VERIFIED, $this->verifier(['result' => 'deliverable', 'code' => 250])->verifyEmail('a@slow.pk')['status'], 'MX-only is re-probed after the window');

        $this->travel(10)->days();
        $this->assertStringEndsWith('(cached)', $this->verifier(['result' => 'blocked'])->verifyEmail('a@slow.pk')['provider']);
        $this->assertSame(EmailStatus::VERIFIED, $this->verifier(['result' => 'blocked'])->verifyEmail('a@slow.pk', fresh: false)['status']);
    }

    public function test_one_refusing_domain_does_not_mark_the_whole_network_as_port_25_blocked(): void
    {
        config(['leadpilot.smtp_probe' => true, 'leadpilot.email_verification.port25_canary' => null, 'leadpilot.email_verification.port25_block_threshold' => 3]);
        $svc = new class extends EmailVerificationService {
            public bool $canaryUp = true;

            protected function openSmtp(string $host) { return false; }

            protected function canConnect(string $host): bool { return $this->canaryUp; }
        };

        $this->assertSame('unreachable', $svc->smtpProbe('a@one.pk', ['mx.one.pk'])['result']);
        $this->assertSame('unreachable', $svc->smtpProbe('b@one.pk', ['mx.one.pk'])['result'], 'same domain twice is still one domain');
        $this->assertSame('unreachable', $svc->smtpProbe('a@two.pk', ['mx.two.pk'])['result']);
        $this->assertFalse(Cache::has('smtp_probe_port25_blocked'));
        $this->assertSame('blocked', $svc->smtpProbe('a@three.pk', ['mx.three.pk'])['result'], 'third distinct domain trips the flag');
        $this->assertTrue(Cache::has('smtp_probe_port25_blocked'));

        Cache::flush();
        config(['leadpilot.email_verification.port25_canary' => 'mx.known-good.example']);
        $this->assertSame('unreachable', $svc->smtpProbe('a@four.pk', ['mx.four.pk'])['result'], 'canary reachable: the domain is the problem, not the network');
        $svc->canaryUp = false;
        $this->assertSame('blocked', $svc->smtpProbe('a@five.pk', ['mx.five.pk'])['result'], 'canary refused too: network blocks port 25');
    }

    public function test_migration_relabels_mx_only_verifications_and_their_leads(): void
    {
        $migration = require database_path('migrations/2026_10_24_000001_add_mx_only_email_status.php');
        $migration->down();

        $mxLead = $this->lead('verified');
        $smtpLead = $this->lead('verified');
        $catchLead = $this->lead('risky');
        $row = fn (string $email, string $status, string $provider, bool $smtp) => DB::table('email_verifications')->insert([
            'email' => $email, 'status' => $status, 'provider' => $provider, 'has_smtp_check' => $smtp, 'has_mx_records' => true,
            'is_disposable' => false, 'is_free_mailbox' => false, 'credits_used' => 0, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row($mxLead->email, 'verified', 'dns_check', false);
        $row($smtpLead->email, 'verified', 'smtp_check', true);
        $row($catchLead->email, 'risky', 'smtp_check', true);

        $migration->up();

        $this->assertSame(EmailStatus::MX_ONLY, $mxLead->fresh()->email_verification_status);
        $this->assertSame(EmailStatus::VERIFIED, $smtpLead->fresh()->email_verification_status);
        $this->assertSame(EmailStatus::CATCH_ALL, $catchLead->fresh()->email_verification_status);
        $this->assertFalse((bool) OutreachSetting::current()->fresh()->allow_mx_only);
    }

    public function test_reverify_command_re_probes_mx_only_leads(): void
    {
        $lead = $this->lead(EmailStatus::MX_ONLY);
        $this->app->instance(EmailVerificationService::class, $this->verifier(['result' => 'deliverable', 'code' => 250]));

        $this->artisan('leadpilot:reverify-emails')->expectsOutputToContain('Re-verified 1 lead(s): Verified 1.')->assertExitCode(0);
        $this->assertSame(EmailStatus::VERIFIED, $lead->fresh()->email_verification_status);
    }

    public function test_autopilot_verifies_business_inbox_leads_too(): void
    {
        $inbox = $this->lead(EmailStatus::UNVERIFIED); // contact_status generic_inbox, as OSM/Overture leads are
        $this->app->instance(EmailVerificationService::class, $this->verifier(['result' => 'deliverable', 'code' => 250]));
        Http::fake();

        $out = app(\App\Services\Autopilot\AutopilotRunner::class)->run();

        $this->assertSame(1, $out['steps']['enrichment']['result']['verified']);
        $this->assertSame(EmailStatus::VERIFIED, $inbox->fresh()->email_verification_status);
    }

    public function test_one_failing_website_audit_does_not_stop_other_audits_or_email_checks(): void
    {
        $broken = $this->lead(EmailStatus::VERIFIED)->company;
        $other = $this->lead(EmailStatus::VERIFIED)->company;
        \App\Models\WebsiteAudit::query()->delete();
        $inbox = $this->lead(EmailStatus::UNVERIFIED);
        \App\Models\WebsiteAudit::where('company_id', $inbox->company_id)->update(['created_at' => now()]);
        $this->app->instance(EmailVerificationService::class, $this->verifier(['result' => 'deliverable', 'code' => 250]));
        $this->app->instance(\App\Services\Audit\WebsiteAuditService::class, new class($broken->id) extends \App\Services\Audit\WebsiteAuditService {
            public function __construct(public int $brokenId) {}

            public function audit(\App\Models\Company $company): \App\Models\WebsiteAudit
            {
                if ($company->id === $this->brokenId) {
                    throw new \RuntimeException('Data too long for column final_url');
                }

                return \App\Models\WebsiteAudit::create(['company_id' => $company->id, 'url' => $company->website, 'status' => 'completed', 'audited_at' => now()]);
            }
        });
        Http::fake();

        $out = app(\App\Services\Autopilot\AutopilotRunner::class)->run();

        $this->assertTrue($out['steps']['enrichment']['ok'], json_encode($out['steps']['enrichment']));
        $this->assertSame(1, $out['steps']['enrichment']['result']['audit_errors']);
        $this->assertDatabaseHas('website_audits', ['company_id' => $broken->id, 'status' => 'failed']);
        $this->assertDatabaseHas('website_audits', ['company_id' => $other->id, 'status' => 'completed']);
        $this->assertSame(EmailStatus::VERIFIED, $inbox->fresh()->email_verification_status, 'e-mail checks still run');
    }

    public function test_bot_protection_pages_are_recognised_and_never_audited_as_the_website(): void
    {
        $svc = app(\App\Services\Audit\WebsiteAuditService::class);
        $this->assertNotNull($svc->botWall('<html><title>Radware Captcha Page</title></html>', 'https://x.org/'));
        $this->assertNotNull($svc->botWall('<html><title>Just a moment...</title></html>', 'https://x.org/'));
        $this->assertNotNull($svc->botWall('<html><title>Home</title></html>', 'https://x.org/a?ssk=botmanager_support@radware.com'));
        $this->assertNull($svc->botWall('<html><title>Smile Dental Clinic | Lahore</title></html>', 'https://smile.pk/'));
    }

    public function test_dashboard_counts_only_proven_mailboxes_as_verified(): void
    {
        $this->lead(EmailStatus::VERIFIED);
        $this->lead(EmailStatus::MX_ONLY);
        $this->lead(EmailStatus::MX_ONLY);

        $this->actingAs(User::where('email', 'admin@eziline.test')->first())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('verifiedEmails', 1)
            ->assertViewHas('mxOnlyEmails', 2)
            ->assertSee('2 MX-only, unproven');
    }
}
