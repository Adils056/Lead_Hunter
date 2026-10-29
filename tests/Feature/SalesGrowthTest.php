<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\DataProvenance;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\EmailVerificationService;
use App\Services\Enrichment\WebsiteFinder;
use App\Services\Outreach\WeeklyReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sales growth, 29 Oct 2026: website finder, more decision-maker mailboxes, the Monday review. */
class SalesGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadpilot.sources.pagespeed.enabled' => false, 'leadpilot.sources.website_crawler.dns_overrides' => [
            'zyqordental.co.uk' => ['93.184.216.34'], 'parkedzyqor.co.uk' => ['93.184.216.35'], 'vexlomarine.com' => ['93.184.216.36'],
            'quorvanbuild.com' => ['93.184.216.37'], 'plimtexhvac.com' => ['93.184.216.38'], 'stgeorgeszyq.co.uk' => ['93.184.216.39'],
        ]]);
    }

    protected function page(string $title, string $body = ''): string
    {
        return "<html><head><title>{$title}</title><meta name=\"viewport\" content=\"width=device-width\"></head><body><h1>{$title}</h1><p>{$body}</p>"
            . '<a href="/contact">Contact</a><p>' . str_repeat('We look after our customers with care and attention every single day. ', 20) . '</p></body></html>';
    }

    public function test_website_is_kept_only_with_proof_it_belongs_to_the_business(): void
    {
        Http::fake([
            'https://zyqordental.co.uk/*' => Http::response($this->page('Zyqor Dental | Family dentist'), 200),
            'https://parkedzyqor.co.uk/*' => Http::response('<html><head><title>parkedzyqor.co.uk</title></head><body>This domain is for sale. Buy this domain today. ' . str_repeat('x ', 50) . '</body></html>', 200),
            'https://vexlomarine.com/*' => Http::response($this->page('Vexlo Marine', 'Boat repairs in Miami, Florida'), 200),
            'https://quorvanbuild.com/*' => Http::response($this->page('Home', 'Builders'), 200),
            'https://plimtexhvac.com/*' => Http::response($this->page('Welcome', 'Call us on +92 42 3576 1234'), 200),
            'https://stgeorgeszyq.co.uk/*' => Http::response($this->page('Hosted by Fasthosts | stgeorgeszyq.co.uk'), 200),
            '*' => Http::response('', 404),
        ]);
        $finder = app(WebsiteFinder::class);

        $uk = Company::create(['name' => 'Zyqor Dental Ltd', 'country' => 'United Kingdom', 'city' => 'Leeds']);
        $r = $finder->find($uk);
        $this->assertSame(['found', 'https://zyqordental.co.uk'], [$r['outcome'], $r['website']], json_encode($r));

        $parked = Company::create(['name' => 'Parked Zyqor', 'country' => 'United Kingdom']);
        $this->assertSame('not_found', $finder->find($parked)['outcome'], 'a for-sale page is not the business');

        $elsewhere = Company::create(['name' => 'Vexlo Marine', 'country' => 'Australia', 'city' => 'Perth']);
        $this->assertSame('not_found', $finder->find($elsewhere)['outcome'], 'same name in Florida, not this Perth business');
        $here = Company::create(['name' => 'Vexlo Marine', 'country' => 'United States', 'city' => 'Miami']);
        $this->assertSame('found', $finder->find($here)['outcome'], 'the page names its city');

        $email = Company::create(['name' => 'QB Group', 'country' => 'United States']);
        Lead::create(['company_id' => $email->id, 'full_name' => 'x', 'email' => 'info@quorvanbuild.com']);
        $this->assertSame('https://quorvanbuild.com', $finder->find($email)['website'], 'the business already uses this domain for e-mail');

        $isp = Company::create(['name' => 'Corner Bakers', 'country' => 'United Kingdom']);
        Lead::create(['company_id' => $isp->id, 'full_name' => 'x', 'email' => 'cornerbakers@btmail.example']);
        Lead::create(['company_id' => $uk->id, 'full_name' => 'y', 'email' => 'zyqor@btmail.example']);
        $this->assertNotContains('btmail.example', array_column($finder->candidates($isp), 0), 'a mail provider shared by many companies is no one\'s website');

        $phone = Company::create(['name' => 'Plimtex HVAC', 'country' => 'Pakistan', 'public_phone' => '042-35761234']);
        $this->assertSame('found', $finder->find($phone)['outcome'], 'its phone number is on the page');

        // 29 Oct live: the host's placeholder named itself with the domain; a domain in the title is not the business
        $this->assertSame('not_found', $finder->find(Company::create(['name' => 'St Georges Zyq', 'country' => 'United Kingdom']))['outcome']);

        $urdu = Company::create(['name' => 'روياٹ لگژری ہوٹل', 'country' => 'Pakistan']);
        $this->assertSame('no_name', $finder->find($urdu)['outcome']);
    }

    public function test_find_websites_command_keeps_the_site_records_its_source_and_audits_it(): void
    {
        Http::fake(['https://zyqordental.co.uk/*' => Http::response($this->page('Zyqor Dental', 'Leeds dentist. Email hello@zyqordental.co.uk'), 200), '*' => Http::response('', 404)]);
        $c = Company::create(['name' => 'Zyqor Dental', 'country' => 'United Kingdom', 'city' => 'Leeds']);
        Lead::create(['company_id' => $c->id, 'full_name' => 'Zyqor Dental — contact unknown', 'contact_status' => 'needs_enrichment', 'overall_score' => 40]);

        $this->artisan('leadpilot:find-websites --limit=5 --seconds=60')->expectsOutputToContain('1 found and kept')->assertSuccessful();

        $c->refresh();
        $this->assertSame(['https://zyqordental.co.uk', 'zyqordental.co.uk'], [$c->website, $c->domain]);
        $this->assertTrue(DataProvenance::where('source', 'website_finder')->where('field', 'website')->exists());
        $this->assertSame(1, WebsiteAudit::where('company_id', $c->id)->count(), 'audited right away');
        $this->artisan('leadpilot:find-websites --limit=5 --seconds=60')->expectsOutputToContain('Tried 0')->assertSuccessful();
    }

    public function test_find_emails_tries_the_next_leader_when_the_first_has_no_mailbox(): void
    {
        $this->app->instance(EmailVerificationService::class, new class extends EmailVerificationService {
            public function mxHosts(string $domain): array
            {
                return ["mx.{$domain}"];
            }

            public function smtpProbe(string $email, array $mxHosts): array
            {
                return ['result' => $email === 'nadia.hussain@proof.test' ? 'deliverable' : 'undeliverable', 'code' => 250, 'host' => 'mx', 'note' => 'fake'];
            }
        });
        $c = Company::create(['name' => 'Proof Co', 'website' => 'https://proof.test', 'domain' => 'proof.test', 'country' => 'United States']);
        Lead::create(['company_id' => $c->id, 'full_name' => 'Proof Co — general inbox', 'email' => 'info@proof.test', 'contact_status' => 'generic_inbox']);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => 'https://proof.test', 'status' => 'completed', 'audited_at' => now(), 'contacts_found' => ['people' => [
            ['name' => 'Omar Qadir', 'first_name' => 'Omar', 'last_name' => 'Qadir', 'role' => 'Head of Sales', 'rank' => 60, 'email' => null, 'source_url' => 'https://proof.test/team'],
            ['name' => 'Zed Founder', 'first_name' => 'Zed', 'last_name' => 'Founder', 'role' => 'Founder & CEO', 'rank' => 95, 'email' => null, 'source_url' => 'https://proof.test/team'],
            ['name' => 'Nadia Hussain', 'first_name' => 'Nadia', 'last_name' => 'Hussain', 'role' => 'Managing Director', 'rank' => 90, 'email' => null, 'source_url' => 'https://proof.test/team'],
        ]]]);

        $this->artisan('leadpilot:find-emails --limit=5 --seconds=60')->expectsOutputToContain('nadia.hussain@proof.test')->assertSuccessful();

        $this->assertSame('Nadia', Lead::where('email', 'nadia.hussain@proof.test')->value('first_name'), 'founder had no mailbox; the MD (next by rank) did');
    }

    public function test_weekly_review_counts_real_outcomes_and_notifies_admins_once_a_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $camp = Campaign::create(['name' => 'A', 'service_offered' => 'web_redesign', 'created_by' => $admin->id, 'status' => 'running']);
        foreach (range(1, 3) as $i) {
            $lead = Lead::create(['full_name' => "L{$i}", 'email' => "p{$i}@x{$i}.example"]);
            $lead->forceFill(['win_segment' => 'hiring', 'win_tier' => $i === 3 ? 'A' : 'S'])->saveQuietly();
            CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => "k{$i}", 'sequence_step' => 1, 'sent_at' => now()->subDays(2)]);
        }
        InboundReply::create(['lead_id' => Lead::first()->id, 'from_email' => 'p1@x1.example', 'to_email' => 'me@x', 'subject' => 'Re', 'body_text' => 'Yes, call me', 'category' => 'interested', 'message_id' => 'm1', 'received_at' => now()->subDay()]);
        InboundReply::create(['lead_id' => Lead::find(2)->id, 'from_email' => 'p2@x2.example', 'to_email' => 'me@x', 'subject' => 'Auto', 'body_text' => 'Away', 'category' => 'out_of_office', 'message_id' => 'm2', 'received_at' => now()->subDay()]);

        $r = app(WeeklyReview::class)->build();
        $this->assertSame([3, 0, 1, 1], [$r['activity']['first_emails'], $r['activity']['follow_ups'], $r['activity']['replies'], $r['activity']['interested']], 'out-of-office is not a reply');
        $hiring = collect($r['segments'])->firstWhere('key', 'hiring');
        $this->assertSame(['too_early', 1], [$hiring['verdict'], $hiring['ready']]);
        $this->assertStringContainsString('Hiring', $r['focus']);

        $this->actingAs($admin)->get(route('autopilot.weekly'))->assertOk()->assertSee('Success rate by kind of lead')->assertSee('Focus this week');
        $this->artisan('leadpilot:weekly-review')->assertSuccessful();
        $this->artisan('leadpilot:weekly-review')->assertSuccessful();
        $this->assertSame(1, $admin->fresh()->notifications()->count(), 'one review per week');
    }

    public function test_urdu_and_arabic_names_take_the_english_name_the_business_uses_on_its_own_site(): void
    {
        $this->assertSame('Royaute Luxury Hotel', \App\Support\EnglishName::fromHtml('<title>Royaute Luxury Hotel | Best stay in Lahore</title>', 'www.royauteluxury.com'));
        $this->assertNull(\App\Support\EnglishName::fromHtml('<title>Best hotel in Lahore | Book now</title>', 'royauteluxury.com'), 'a slogan is not the name');
        $this->assertSame('Al Noor Bakers', \App\Support\EnglishName::fromHtml('<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Home"},{"@type":"Bakery","name":"Al Noor Bakers"}]}</script><title>Home</title>', 'alnoor.pk'));
        $this->assertSame('Zam Zam Sweets', \App\Support\EnglishName::fromHtml('<meta property="og:site_name" content="Zam Zam Sweets"><title>صفحہ اول</title>', 'zz.pk'));
        $this->assertNull(\App\Support\EnglishName::fromHtml('<meta property="og:site_name" content="زم زم سویٹس"><title>زم زم</title>', 'zz.pk'), 'still in Urdu: nothing');
        $this->assertNull(\App\Support\EnglishName::clean('royauteluxury.com'));

        Http::fake(['https://zyqordental.co.uk/*' => Http::response('<html><head><title>Zyqor Dental Care | Leeds</title></head><body>' . str_repeat('Smile. ', 80) . '<a href="/c">c</a></body></html>', 200), '*' => Http::response('', 404)]);
        $c = Company::create(['name' => 'زیقور ڈینٹل', 'website' => 'https://zyqordental.co.uk', 'country' => 'Pakistan']);
        $inbox = Lead::create(['company_id' => $c->id, 'full_name' => 'زیقور ڈینٹل — general inbox', 'email' => 'info@zyqordental.co.uk', 'contact_status' => 'generic_inbox']);
        $keep = Company::create(['name' => 'روياٹ لگژری ہوٹل', 'country' => 'Pakistan']);

        $this->artisan('leadpilot:english-names --dry-run')->expectsOutputToContain('→ Zyqor Dental Care')->assertSuccessful();
        $this->assertSame('زیقور ڈینٹل', $c->fresh()->name, 'dry run changes nothing');

        $this->artisan('leadpilot:english-names')->expectsOutputToContain('1 took the English name')->assertSuccessful();
        $this->assertSame(['Zyqor Dental Care', 'زیقور ڈینٹل'], [$c->fresh()->name, $c->fresh()->local_name]);
        $this->assertSame('Zyqor Dental Care — general inbox', $inbox->fresh()->full_name);
        $this->assertSame('روياٹ لگژری ہوٹل', $keep->fresh()->name, 'no website, no proof: the local name stays');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('companies.index', ['search' => 'زیقور']))->assertOk()->assertSee('Zyqor Dental Care');
    }
}
