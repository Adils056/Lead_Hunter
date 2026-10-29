<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\IcpProfile;
use App\Services\Discovery\Adapters\ArbeitnowAdapter;
use App\Services\Discovery\Adapters\HimalayasAdapter;
use App\Services\Discovery\Adapters\RemoteOkAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Free job-board feeds: developer roles at companies in approved countries -> company + hiring signal with link back. */
class JobFeedsTest extends TestCase
{
    use RefreshDatabase;

    public function test_remoteok_jobs_become_hiring_companies_with_attribution(): void
    {
        Http::fake(['remoteok.com/api' => Http::response([
            ['legal' => 'API Terms of Service: link back to Remote OK'],
            ['id' => '101', 'company' => 'Paperloop', 'position' => 'Senior Laravel Developer', 'tags' => ['php', 'laravel'], 'location' => 'USA',
                'description' => 'Join us at https://paperloop.io/careers to build our PHP platform.', 'apply_url' => 'https://paperloop.io/jobs/1', 'url' => 'https://remoteok.com/remote-jobs/101', 'date' => '2026-10-27T10:00:00+00:00'],
            ['id' => '102', 'company' => 'Sales Co', 'position' => 'Account Executive', 'tags' => ['sales'], 'location' => 'USA', 'description' => '', 'url' => 'https://remoteok.com/remote-jobs/102'],
            ['id' => '103', 'company' => 'Agencyless', 'position' => 'React Developer', 'tags' => ['react'], 'location' => 'USA', 'description' => 'No agencies please.', 'url' => 'https://remoteok.com/remote-jobs/103'],
            ['id' => '104', 'company' => 'Elsewhere GmbH', 'position' => 'PHP Developer', 'tags' => ['php'], 'location' => 'Brazil', 'description' => '', 'url' => 'https://remoteok.com/remote-jobs/104'],
        ])]);

        $r = (new RemoteOkAdapter())->discover(['countries' => ['United States', 'United Kingdom'], 'limit' => 10]);

        $this->assertSame(1, $r->newCompaniesCount, json_encode($r->toArray()));
        $c = Company::where('name', 'Paperloop')->firstOrFail();
        $this->assertSame(['https://paperloop.io', 'United States'], [$c->website, $c->country]);
        $sig = BuyingSignal::where('company_id', $c->id)->firstOrFail();
        $this->assertSame(['developer_hiring', 'https://remoteok.com/remote-jobs/101'], [$sig->signal_type, $sig->source_url], 'link back to the job on Remote OK');
        $this->assertStringContainsString('Remote OK', $sig->title);
        $this->assertSame(0, Company::whereIn('name', ['Sales Co', 'Agencyless', 'Elsewhere GmbH'])->count());
    }

    public function test_himalayas_and_arbeitnow_feeds_parse(): void
    {
        Http::fake([
            'himalayas.app/*' => Http::response(['jobs' => [
                ['guid' => 'https://himalayas.app/companies/brightcart/jobs/full-stack', 'companyName' => 'Brightcart', 'title' => 'Full Stack Engineer', 'categories' => ['Software Development'],
                    'excerpt' => 'Shopify and Laravel stack', 'locationRestrictions' => ['United Kingdom'], 'applicationLink' => 'https://jobs.lever.co/brightcart/1', 'pubDate' => 1761700000],
            ]]),
            'www.arbeitnow.com/*' => Http::response(['data' => [
                ['slug' => 'php-dev-berlin', 'company_name' => 'Stadtwerk Digital', 'title' => 'PHP Backend Developer (m/w/d)', 'tags' => ['PHP'], 'location' => 'Berlin',
                    'remote' => false, 'url' => 'https://www.arbeitnow.com/jobs/companies/stadtwerk/php-dev-berlin', 'description' => '<p>Laravel</p>', 'created_at' => 1761700000],
            ]]),
        ]);

        (new HimalayasAdapter())->discover(['countries' => ['United Kingdom', 'Germany'], 'limit' => 5]);
        (new ArbeitnowAdapter())->discover(['countries' => ['United Kingdom', 'Germany'], 'limit' => 5]);

        $bright = Company::where('name', 'Brightcart')->firstOrFail();
        $this->assertNull($bright->website, 'a Lever apply link is not the company site');
        $this->assertSame('lever:brightcart', $bright->ats_board, 'but its job board is remembered for the watchlist');
        $this->assertSame('Germany', Company::where('name', 'Stadtwerk Digital')->value('country'));
    }

    public function test_the_hiring_profile_gets_the_new_feeds(): void
    {
        $icp = IcpProfile::create(['name' => 'Auto · hiring', 'auto_key' => 'global:hiring', 'sources' => ['hn_hiring', 'ats_watchlist'], 'is_active' => true]);
        (require base_path('database/migrations/2026_10_29_000009_add_job_feeds_to_hiring_icp.php'))->up();

        $this->assertSame(['hn_hiring', 'ats_watchlist', 'remoteok', 'himalayas', 'arbeitnow'], $icp->fresh()->sources);
    }
}
