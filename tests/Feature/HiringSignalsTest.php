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
