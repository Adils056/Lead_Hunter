<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Services\Discovery\Adapters\GreenhouseLeverWatchlistAdapter;
use App\Services\Discovery\Adapters\HnWhoIsHiringAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Free hiring signals: HN "Who is hiring" and job boards auto-discovered on audited websites. */
class HiringSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hn_post_parsing_takes_company_site_board_email_and_approved_country(): void
    {
        $p = HnWhoIsHiringAdapter::parsePost('Acme Clinics | Senior PHP/Laravel Developer | London, UK | REMOTE (UK) | <a href="https:&#x2F;&#x2F;www.acmeclinics.co.uk&#x2F;">https://www.acmeclinics.co.uk</a><p>We need help with our booking platform. Apply: <a href="https://jobs.lever.co/acmeclinics">jobs</a> or email jobs@acmeclinics.co.uk');

        $this->assertSame('Acme Clinics', $p['company']);
        $this->assertSame('https://acmeclinics.co.uk', $p['website']);
        $this->assertSame('lever:acmeclinics', $p['ats_board']);
        $this->assertSame('jobs@acmeclinics.co.uk', $p['email']);
        $this->assertSame('United Kingdom', $p['country']);
        $this->assertNull(HnWhoIsHiringAdapter::parsePost('Foo | Rust engineer | Berlin, Germany | ONSITE')['country'], 'unapproved country is not guessed');
    }

    public function test_hn_website_is_the_business_mail_domain_or_a_link_with_the_company_name_never_a_video_or_article(): void
    {
        $video = HnWhoIsHiringAdapter::parsePost('Charge Robotics | Software engineer | Oakland, CA | ONSITE | <a href="https://youtu.be/abc">demo</a> email max@chargerobotics.com');
        $this->assertSame('https://chargerobotics.com', $video['website']);

        $article = HnWhoIsHiringAdapter::parsePost('Paramark | Full stack engineer | San Francisco | <a href="https://www.mercurynews.com/2026/paramark">press</a> <a href="https://jobs.gem.com/paramark">apply</a>');
        $this->assertNull($article['website']);

        $named = HnWhoIsHiringAdapter::parsePost('Curai | Backend engineer | Remote (US) | <a href="https://github.com/curai">code</a> <a href="https://www.curaihealth.com/careers">careers</a>');
        $this->assertSame('https://curaihealth.com', $named['website']);
    }

    public function test_hn_adapter_ingests_relevant_posts_and_skips_agency_refusals_and_other_countries(): void
    {
        Http::fake([
            'hn.algolia.com/api/v1/search_by_date*' => Http::response(['hits' => [['objectID' => '999', 'title' => 'Ask HN: Who is hiring? (October 2026)']]]),
            'hn.algolia.com/api/v1/items/999' => Http::response(['children' => [
                ['id' => 1, 'created_at' => '2026-10-01T15:00:00Z', 'text' => 'Bloom Dental | Laravel developer | Austin, TX | ONSITE | <a href="https://bloomdental.com">bloomdental.com</a><p>Contact hiring@bloomdental.com'],
                ['id' => 2, 'text' => 'Big Corp | Full stack engineer | New York, NY | No agencies or recruiters please'],
                ['id' => 3, 'text' => 'Kraut GmbH | PHP developer | Munich, Germany | ONSITE'],
                ['id' => 4, 'text' => 'Fin Co | Rust and Go infra engineer | London, UK'], // not a role we serve
            ]]),
        ]);

        $r = (new HnWhoIsHiringAdapter())->discover(['countries' => ['United States', 'United Kingdom'], 'limit' => 10]);

        $this->assertSame(1, $r->newCompaniesCount, json_encode($r->errors));
        $c = Company::where('external_id', 'hn:1')->firstOrFail();
        $this->assertSame(['Bloom Dental', 'https://bloomdental.com', 'United States'], [$c->name, $c->website, $c->country]);
        $this->assertSame('developer_hiring', BuyingSignal::where('company_id', $c->id)->value('signal_type'));
        $this->assertSame('hiring@bloomdental.com', Lead::where('company_id', $c->id)->value('email'));
        $this->assertSame(2, $r->excludedCount, 'agency refusal and Germany are skipped');
    }

    public function test_seeking_freelancer_posts_become_explicit_requests_named_after_the_posters_site(): void
    {
        Http::fake([
            'hn.algolia.com/api/v1/search_by_date*' => Http::response(['hits' => [
                ['objectID' => '999', 'title' => 'Ask HN: Who is hiring? (October 2026)'],
                ['objectID' => '998', 'title' => 'Ask HN: Freelancer? Seeking freelancer? (October 2026)'],
            ]]),
            'hn.algolia.com/api/v1/items/999' => Http::response(['children' => []]),
            'hn.algolia.com/api/v1/items/998' => Http::response(['children' => [
                ['id' => 7, 'text' => 'SEEKING FREELANCER | London, UK | Remote<p>We need a Laravel developer to rebuild our booking app. Contact tom@harbourclinic.co.uk'],
                ['id' => 8, 'text' => 'SEEKING WORK | Lahore, Pakistan | Laravel and PHP developer, me@gmail.com'], // a freelancer, not a buyer
            ]]),
        ]);

        $r = (new HnWhoIsHiringAdapter())->discover(['countries' => ['United Kingdom', 'Pakistan'], 'limit' => 10]);

        $this->assertSame(1, $r->newCompaniesCount, json_encode($r->errors));
        $c = Company::where('external_id', 'hn:7')->firstOrFail();
        $this->assertSame(['Harbourclinic', 'https://harbourclinic.co.uk'], [$c->name, $c->website]);
        $this->assertSame('vendor_request', BuyingSignal::where('company_id', $c->id)->value('signal_type'));
    }

    public function test_a_company_asking_for_developers_scores_into_email_now(): void
    {
        $this->artisan('leadpilot:go-autonomous', ['--live' => true]);
        $c = Company::create(['name' => 'Bloom Dental', 'website' => 'https://bloomdental.com', 'domain' => 'bloomdental.com', 'country' => 'United States']);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'developer_hiring', 'title' => 'Hiring developers (Hacker News)', 'confidence' => 85,
            'source_url' => 'https://news.ycombinator.com/item?id=1', 'evidence_text' => 'Senior Laravel developer, Austin TX', 'relevant_service' => 'laravel_php',
            'observed_date' => now()->toDateString(), 'expiry_date' => now()->addDays(45)->toDateString()]);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'Bloom Dental — general inbox', 'email' => 'hello@bloomdental.com', 'country' => 'United States',
            'contact_status' => 'generic_inbox', 'email_verification_status' => 'verified', 'source_name' => 'Hacker News: Who is hiring']);

        app(\App\Services\LeadScoringService::class)->evaluateLead($lead->fresh());
        $lead->refresh();
        $rung = app(\App\Services\Outreach\WinLadderService::class)->evaluate($lead);

        $this->assertGreaterThanOrEqual(\App\Models\OutreachSetting::current()->min_lead_score, $lead->overall_score, 'missing RFP / growth news is not counted against a hiring company');
        $this->assertSame('A', $rung['tier'], json_encode($rung['blockers']));
        $lead->update(['email' => 'hiring@bloomdental.com']);
        $this->assertArrayHasKey('role_inbox', app(\App\Services\EligibilityGateService::class)->evaluate($lead->fresh(), null, null, false)->failedChecks,
            'a job-applications inbox is not someone who buys services');
    }

    public function test_job_board_found_on_a_website_is_checked_by_the_hiring_source(): void
    {
        $this->assertSame('greenhouse:acme', GreenhouseLeverWatchlistAdapter::boardFromUrl('https://boards.greenhouse.io/acme/jobs/123'));
        $this->assertSame('ashby:acme.io', GreenhouseLeverWatchlistAdapter::boardFromUrl('https://jobs.ashbyhq.com/acme.io'));
        $this->assertSame('workable:acme', GreenhouseLeverWatchlistAdapter::boardFromUrl('https://apply.workable.com/acme/'));
        $this->assertNull(GreenhouseLeverWatchlistAdapter::boardFromUrl('https://acme.com/careers'));

        $c = Company::create(['name' => 'Acme Health', 'website' => 'https://acmehealth.com', 'domain' => 'acmehealth.com', 'country' => 'United States', 'ats_board' => 'ashby:acmehealth']);
        Http::fake(['api.ashbyhq.com/posting-api/job-board/acmehealth' => Http::response(['jobs' => [
            ['title' => 'Senior Laravel Engineer', 'jobUrl' => 'https://jobs.ashbyhq.com/acmehealth/1'],
            ['title' => 'Office Manager', 'jobUrl' => 'https://jobs.ashbyhq.com/acmehealth/2'],
        ]])]);

        $r = (new GreenhouseLeverWatchlistAdapter())->discover(['auto' => true, 'limit' => 5]);

        $this->assertSame([], $r->errors);
        $signal = BuyingSignal::where('company_id', $c->id)->firstOrFail();
        $this->assertSame('developer_hiring', $signal->signal_type);
        $this->assertStringContainsString('Senior Laravel Engineer', $signal->evidence_text);
        $this->assertStringNotContainsString('Office Manager', $signal->evidence_text);
        $this->assertNotNull($c->fresh()->ats_checked_at);
        $this->assertSame(1, Company::count(), 'the known company is used, not duplicated');
    }
}
