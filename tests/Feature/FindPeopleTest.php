<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bulk "find people": follows the homepage's own team links (e.g. /our-attorneys), never guesses an address. */
class FindPeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_people_follows_team_links_and_adds_named_contacts(): void
    {
        config(['leadpilot.sources.pagespeed.enabled' => false, 'leadpilot.sources.website_crawler.dns_overrides' => ['rklaw.test' => ['93.184.216.34'], 'shop.test' => ['93.184.216.34']]]);
        $home = '<html><head><title>RK Law</title></head><body><nav><a href="/practice-areas">Practice</a><a href="/our-attorneys/">Our Attorneys</a></nav><h1>RK Law Group</h1></body></html>';
        $attorneys = '<html><body><div><h3>Rachel Klein</h3><p>Managing Partner</p><a href="mailto:rklein@rklaw.test">Email</a></div>'
            . '<div><h3>David Ortiz</h3><p>Attorney</p></div></body></html>';
        Http::fake([
            'rklaw.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'rklaw.test/our-attorneys*' => Http::response($attorneys, 200, ['Content-Type' => 'text/html']),
            'rklaw.test/' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            'rklaw.test' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            'shop.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'shop.test*' => Http::response('<html><body><h1>Shop</h1><a href="mailto:info@shop.test">mail</a></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $law = Company::create(['name' => 'RK Law Group', 'website' => 'https://rklaw.test/', 'domain' => 'rklaw.test', 'country' => 'United States']);
        Lead::create(['company_id' => $law->id, 'full_name' => 'RK Law Group — general inbox', 'email' => 'info@rklaw.test', 'contact_status' => 'generic_inbox', 'overall_score' => 70]);
        $shop = Company::create(['name' => 'Corner Shop', 'website' => 'https://shop.test/', 'domain' => 'shop.test', 'country' => 'Pakistan']);
        Lead::create(['company_id' => $shop->id, 'full_name' => 'Corner Shop — general inbox', 'email' => 'info@shop.test', 'contact_status' => 'generic_inbox', 'overall_score' => 50]);

        $this->artisan('leadpilot:find-people')->expectsOutputToContain('Rachel Klein (Managing Partner) rklein@rklaw.test')
            ->expectsOutputToContain('Re-audited 2 of 2 companies: 1 list decision-makers, 1 new named contact(s)')->assertExitCode(0);

        $rachel = Lead::where('email', 'rklein@rklaw.test')->firstOrFail();
        $this->assertSame(['Rachel', 'Klein', 'identified'], [$rachel->first_name, $rachel->last_name, $rachel->contact_status]);
        $this->assertSame(0, Lead::where('email', 'like', 'david%')->orWhere('email', 'like', 'dortiz%')->count(), 'no guessed address for David');
        $this->assertContains('https://rklaw.test/our-attorneys/', $law->latestWebsiteAudit->contacts_found['pages']);

        // already read with the people step: the next run skips both
        $this->artisan('leadpilot:find-people')->expectsOutputToContain('Re-audited 0 of 0 companies')->assertExitCode(0);
    }

    public function test_team_page_on_https_www_is_read_when_the_homepage_is_plain_http(): void
    {
        // live case (RK Law Group): website stored as http://, its menu links to https://www. pages
        config(['leadpilot.sources.pagespeed.enabled' => false, 'leadpilot.sources.website_crawler.dns_overrides' => ['mixfirm.test' => ['93.184.216.34'], 'www.mixfirm.test' => ['93.184.216.34']]]);
        Http::fake([
            '*mixfirm.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'www.mixfirm.test/meet-the-team*' => Http::response('<html><body><h3>Nadia Hussain</h3><p>Founder &amp; CEO</p><a href="mailto:nadia@mixfirm.test">mail</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'mixfirm.test*' => Http::response('<html><body><a href="https://www.mixfirm.test/meet-the-team/">Meet the team</a><a href="https://www.mixfirm.test/">Home</a></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'Mix Firm', 'website' => 'http://mixfirm.test', 'domain' => 'mixfirm.test', 'country' => 'Pakistan']);

        $audit = app(\App\Services\Audit\WebsiteAuditService::class)->audit($c);

        $this->assertContains('https://www.mixfirm.test/meet-the-team/', $audit->contacts_found['pages']);
        $this->assertSame('nadia@mixfirm.test', $audit->contacts_found['people'][0]['email']);
        $this->assertDatabaseHas('leads', ['email' => 'nadia@mixfirm.test', 'contact_status' => 'identified', 'first_name' => 'Nadia']);
    }

    public function test_investors_and_quoted_customers_are_not_taken_as_the_companys_people(): void
    {
        // live case (Tolmo, Relevant Healthcare): backers and customers listed with their own company after the role
        $html = '<body><p>Pierre Betouin, Cofounder &amp; CEO</p><p>Renaud Deraison, Founder, Tenable</p><p>Olivier Pomel, Co-founder &amp; CEO, Datadog</p>'
            . '<p>Lindsay Farrell, CEO, Open Door Family Medical Centers</p><p>Jacob Hodes, Co-founder, advisor</p><p>Sam Lee, CTO at Tolmo</p></body>';
        $health = '<body><p>Holly Preston, COO, West Cecil Health Center</p><p>Brandon Hamilton, CEO, Co-founder</p><p>Ann Roe, CTO at Relevant</p></body>';
        $this->assertSame(['Brandon Hamilton', 'Ann Roe'], array_column((new \App\Services\Audit\PeopleExtractor())->extract($health, 'https://relevant.test', [], 'Relevant Healthcare'), 'name'),
            'a shared generic word ("health") does not make another organisation this company');
        $names = array_column((new \App\Services\Audit\PeopleExtractor())->extract($html, 'https://tolmo.test', [], 'Tolmo'), 'name');
        sort($names);

        $this->assertSame(['Jacob Hodes', 'Pierre Betouin', 'Sam Lee'], $names);
    }
}
