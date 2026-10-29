<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\RadarItem;
use App\Models\User;
use App\Services\Discovery\FreshBusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner's list, 30 Oct 2026: newly established businesses and fresh domains. */
class OwnerRequestsP6Test extends TestCase
{
    use RefreshDatabase;

    public function test_new_uk_companies_in_served_industries_are_added_with_a_new_business_signal(): void
    {
        config(['leadpilot.keys.companies_house' => 'ch-key']);
        Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => [
            ['company_name' => 'BRIGHT SMILE DENTAL LTD', 'company_number' => '15000001', 'date_of_creation' => now()->subDays(5)->toDateString(), 'sic_codes' => ['86230'],
                'registered_office_address' => ['address_line_1' => '1 High Street', 'locality' => 'LEEDS', 'postal_code' => 'LS1 1AA']],
            ['company_name' => 'ACME HOLDINGS LTD', 'company_number' => '15000002', 'date_of_creation' => now()->subDays(3)->toDateString(), 'sic_codes' => ['68310']],
            ['company_name' => 'ODD SIC LTD', 'company_number' => '15000003', 'date_of_creation' => now()->subDays(3)->toDateString(), 'sic_codes' => ['01110']],
        ]])]);

        $r = app(FreshBusinessService::class)->importUkIncorporations(14, 100);

        $this->assertNull($r['error']);
        $this->assertSame(1, $r['companies'], 'holding companies and unrelated industries are skipped');
        $c = Company::where('external_id', 'ch:15000001')->firstOrFail();
        $this->assertSame(['Bright Smile Dental Ltd', 'Leeds', 'United Kingdom', 'Dental'], [$c->name, $c->city, $c->country, $c->industry_group]);
        $this->assertTrue($c->isNewBusiness());
        $this->assertSame(1, $c->leads()->count());
        $signal = BuyingSignal::where('company_id', $c->id)->where('signal_type', 'business_launch')->firstOrFail();
        $item = RadarItem::where('buying_signal_id', $signal->id)->firstOrFail();
        $this->assertSame(['Companies House (new companies)', 'warm'], [$item->platform, $item->intent_level]);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Basic ' . base64_encode('ch-key:')) && str_contains($req->url(), 'sic_codes=86230'));

        config(['leadpilot.keys.companies_house' => null]);
        $this->assertStringContainsString('No Companies House API key', app(FreshBusinessService::class)->importUkIncorporations()['error']);
    }

    public function test_fresh_domains_are_found_with_rdap_and_filterable(): void
    {
        Http::fake([
            'rdap.org/domain/newbakery.example' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => now()->subMonths(3)->toIso8601String()]]], 200),
            'rdap.org/domain/oldfirm.example' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2009-05-01T00:00:00Z']]], 200),
            '*' => Http::response('', 404),
        ]);
        $new = Company::create(['name' => 'New Bakery', 'domain' => 'newbakery.example', 'website' => 'https://newbakery.example', 'country' => 'Pakistan']);
        $old = Company::create(['name' => 'Old Firm', 'domain' => 'oldfirm.example', 'website' => 'https://oldfirm.example', 'country' => 'Pakistan']);
        $none = Company::create(['name' => 'No Rdap', 'domain' => 'shop.pk', 'website' => 'https://shop.pk', 'country' => 'Pakistan']);

        $r = app(FreshBusinessService::class)->checkDomainAges(10, 60);

        $this->assertSame(['checked' => 3, 'fresh' => 1], $r);
        $this->assertTrue($new->fresh()->isNewBusiness());
        $this->assertFalse($old->fresh()->isNewBusiness());
        $this->assertNull($none->fresh()->domain_registered_on, 'no RDAP for the registry: nothing claimed');
        $this->assertNotNull($none->fresh()->domain_checked_at, 'not asked again every night');
        $this->assertSame('Fresh domains', RadarItem::where('company_id', $new->id)->value('platform'));

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('companies.index', ['fresh' => 1]))->assertOk()->assertSee('New Bakery')->assertDontSee('Old Firm');
        $this->actingAs($admin)->get(route('companies.show', $new))->assertSee('New business');
    }
}
