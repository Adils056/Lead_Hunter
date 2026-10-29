<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Services\Audit\WebsiteAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Live case 29 Oct (rklawgroup.com): a 163-byte forwarding page was audited as if it were the website. */
class WebsiteShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadpilot.sources.pagespeed.enabled' => false, 'leadpilot.sources.website_crawler.dns_overrides' => [
            'shellfirm.test' => ['93.184.216.34'], 'www.realfirm.test' => ['93.184.216.34'], 'jsonly.test' => ['93.184.216.34'], 'parkedlaw.test' => ['93.184.216.34'],
        ]]);
    }

    public function test_a_forwarding_page_is_followed_to_the_real_site(): void
    {
        $real = '<html><head><title>Real Firm LLP</title><meta name="viewport" content="width=device-width"></head><body><h1>Real Firm</h1>'
            . '<a href="/our-team">Our team</a><p>' . str_repeat('Trusted counsel for families and businesses. ', 10) . '</p></body></html>';
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'shellfirm.test*' => Http::response('<html><head><script>window.location.href="https://www.realfirm.test/";</script></head></html>', 200, ['Content-Type' => 'text/html']),
            'www.realfirm.test/our-team*' => Http::response('<html><body><h3>Maria Lopez</h3><p>Managing Partner</p><a href="mailto:mlopez@realfirm.test">Email</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'www.realfirm.test*' => Http::response($real, 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'Real Firm', 'website' => 'http://shellfirm.test', 'domain' => 'shellfirm.test', 'country' => 'United States']);

        $audit = app(WebsiteAuditService::class)->audit($c);
        $checks = collect($audit->checks)->keyBy('key');

        $this->assertSame('completed', $audit->status);
        $this->assertSame('https://www.realfirm.test/', $audit->final_url);
        $this->assertSame('pass', $checks['viewport']['status'], 'the real page is audited, not the forwarding stub');
        $this->assertContains('https://www.realfirm.test/our-team', $audit->contacts_found['pages']);
        $this->assertDatabaseHas('leads', ['email' => 'mlopez@realfirm.test', 'contact_status' => 'identified']);
    }

    public function test_an_empty_javascript_only_page_claims_nothing_and_clears_old_claims(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'jsonly.test*' => Http::response('<html><head><script src="/app.js"></script></head><body><div id="root"></div></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'JS Only', 'website' => 'https://jsonly.test', 'domain' => 'jsonly.test', 'country' => 'Pakistan', 'website_opportunity_score' => 80]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_issues', 'title' => 'old false audit', 'evidence_text' => 'No H1', 'relevant_service' => 'web_redesign', 'confidence' => 80,
            'observed_date' => now()->subDays(3), 'metadata' => ['source' => 'website_audit']]);

        $audit = app(WebsiteAuditService::class)->audit($c);

        $this->assertSame('no_content', $audit->status);
        $this->assertSame(0, $audit->opportunity_score);
        $this->assertSame(0, (int) $c->fresh()->website_opportunity_score);
        $this->assertSame(0, BuyingSignal::where('company_id', $c->id)->where('signal_type', 'website_issues')->count());
    }

    public function test_reaudit_shells_command_redoes_old_tiny_page_audits(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'jsonly.test*' => Http::response('<html><body><div id="root"></div></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'JS Only', 'website' => 'https://jsonly.test', 'domain' => 'jsonly.test', 'country' => 'Pakistan', 'website_opportunity_score' => 70]);
        \App\Models\WebsiteAudit::create(['company_id' => $c->id, 'url' => 'https://jsonly.test', 'status' => 'completed', 'page_size_bytes' => 163, 'opportunity_score' => 70, 'audited_at' => now()->subDay()]);

        $this->artisan('leadpilot:reaudit-shells')->expectsOutputToContain('163 bytes → no_content')->assertExitCode(0);
        $this->assertSame(0, (int) $c->fresh()->website_opportunity_score);
        $this->artisan('leadpilot:reaudit-shells')->expectsOutputToContain('Re-audited 0 of 1')->assertExitCode(0);
    }

    public function test_hosting_default_page_is_not_audited_as_the_business_site(): void
    {
        // live: rklawgroup.com forwards to /cgi-sys/defaultwebpage.cgi (cPanel "site not set up yet")
        $default = '<html><head><title>Default Web Site Page</title></head><body><h1>Sorry!</h1><p>If you are the owner of this website, please contact your hosting provider.</p>'
            . str_repeat('<p>cPanel hosting information and links.</p>', 30) . '<a href="https://cpanel.net">cPanel</a></body></html>';
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'parkedlaw.test/cgi-sys/*' => Http::response($default, 200, ['Content-Type' => 'text/html']),
            'parkedlaw.test*' => Http::response('<html><head><meta http-equiv="refresh" content="0;url=/cgi-sys/defaultwebpage.cgi"></head></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'Parked Law', 'website' => 'http://parkedlaw.test', 'domain' => 'parkedlaw.test', 'country' => 'United States', 'website_opportunity_score' => 26]);

        $audit = app(WebsiteAuditService::class)->audit($c);

        $this->assertSame('no_content', $audit->status);
        $this->assertStringContainsString('default page', $audit->checks[0]['evidence']);
        $this->assertSame(0, (int) $c->fresh()->website_opportunity_score);

        // the shell re-audit does not pick a company whose latest audit already came from the current code
        \App\Models\WebsiteAudit::where('id', $audit->id)->update(['status' => 'completed', 'page_size_bytes' => 300]);
        $this->artisan('leadpilot:reaudit-shells')->expectsOutputToContain('Re-audited 0 of 1')->assertExitCode(0);
    }
}
