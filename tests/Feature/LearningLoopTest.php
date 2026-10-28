<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\IcpProfile;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\SearchArm;
use App\Models\User;
use App\Services\Autopilot\SearchAllocator;
use App\Services\Hunting\AutoMarketService;
use App\Services\Sales\ReplyOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The search learns from real answers: silence costs a segment searches, interest clones it ("lookalike"). */
class LearningLoopTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected IcpProfile $icp;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        OutreachSetting::current()->update(['allowed_countries' => ['United Kingdom', 'Pakistan']]);
        $this->icp = IcpProfile::create(['name' => 'Auto · Dental clinics · United Kingdom', 'is_active' => true, 'countries' => ['United Kingdom'], 'cities' => ['London'],
            'niches' => ['dentist'], 'business_types' => ['local_business'], 'target_services' => ['web_redesign'], 'sources' => ['osm_overpass'], 'channels' => ['email']]);
        $this->campaign = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true]);
    }

    protected function emailed(string $name, string $city, int $daysAgo, string $country = 'United Kingdom'): Lead
    {
        $c = Company::create(['name' => $name, 'city' => $city, 'country' => $country, 'industry' => 'Dentist', 'source_provider' => 'osm_overpass', 'website' => 'https://' . \Illuminate\Support\Str::slug($name) . '.test']);
        $lead = Lead::create(['company_id' => $c->id, 'icp_profile_id' => $this->icp->id, 'full_name' => "{$name} — general inbox", 'email' => 'info@' . \Illuminate\Support\Str::slug($name) . '.test', 'country' => $country]);
        CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'lead_id' => $lead->id, 'status' => 'sent', 'sequence_step' => 1, 'idempotency_key' => "k{$lead->id}",
            'personalized_subject' => 'Hi', 'sent_at' => now()->subDays($daysAgo)]);

        return $lead;
    }

    protected function reply(Lead $lead, string $category): void
    {
        $r = InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'leads@eziline.net', 'subject' => 'Re: Hi', 'body_text' => 'Sounds good, tell me more.',
            'message_id' => uniqid(), 'received_at' => now()]);
        app(ReplyOutcomeService::class)->apply($r, $category, 90, 'test');
    }

    public function test_prospects_silent_for_two_weeks_count_against_their_segment(): void
    {
        $this->emailed('Old Silent', 'London', 20);
        $this->emailed('Another Silent', 'London', 15);
        $this->reply($this->emailed('Old Replied', 'London', 20), 'not_interested');
        $this->emailed('Too Recent', 'London', 3);

        $this->assertSame(["{$this->icp->id}|osm_overpass" => 2], app(SearchAllocator::class)->ignoredByArm());
    }

    public function test_interest_creates_a_lookalike_profile_that_grows_by_city_and_survives_auto_market(): void
    {
        $this->reply($this->emailed('Bright Smile', 'Bristol', 2), 'interested');

        $look = IcpProfile::where('auto_key', 'lookalike:dentist:united-kingdom')->firstOrFail();
        $this->assertTrue($look->is_active);
        $this->assertSame(['United Kingdom'], $look->countries);
        $this->assertSame(['dentist'], $look->niches);
        $this->assertSame('Bristol', $look->cities[0], 'starts where the interested prospect is');
        $this->assertContains('Manchester', $look->cities, 'then the other approved cities of that country');
        $this->assertSame(1, SearchArm::where('icp_profile_id', $look->id)->where('source_slug', 'osm_overpass')->value('positive'));

        $this->reply($this->emailed('Leeds Dental', 'Leeds', 2), 'request_info');
        $look->refresh();
        $this->assertSame('Leeds', $look->cities[0]);
        $this->assertSame(1, IcpProfile::where('auto_key', 'like', 'lookalike:%')->count(), 'same kind + country: one profile');
        $this->assertSame(2, SearchArm::where('icp_profile_id', $look->id)->where('source_slug', 'osm_overpass')->value('positive'));

        app(AutoMarketService::class)->sync();
        $this->assertTrue($look->fresh()->is_active, 'Auto Market never switches lookalikes off');
    }

    public function test_no_lookalike_outside_approved_countries_or_on_a_decline(): void
    {
        $this->reply($this->emailed('Paris Dent', 'Paris', 2, 'France'), 'interested');
        $this->reply($this->emailed('No Thanks Dental', 'York', 2), 'not_interested');

        $this->assertSame(0, IcpProfile::where('auto_key', 'like', 'lookalike:%')->count());
    }

    public function test_performance_page_ranks_segments_by_real_answers(): void
    {
        $this->reply($this->emailed('Bright Smile', 'Bristol', 2), 'interested');
        $this->emailed('Quiet Dental', 'Bath', 2);

        $this->actingAs($this->admin)->get(route('autopilot.performance'))->assertOk()
            ->assertSee('Winning segments')->assertSee('Auto · Dental clinics · United Kingdom')->assertSee('50%')->assertSee('too early');
    }
}
