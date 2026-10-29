<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\RadarItem;
use App\Models\User;
use App\Services\Radar\IntentClassifier;
use App\Services\Radar\RadarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner's list, 30 Oct 2026: Buyer's Radar — buying intent, hot / warm / normal, hot first, with filters. */
class OwnerRequestsP5Test extends TestCase
{
    use RefreshDatabase;

    public function test_intent_levels(): void
    {
        $c = new IntentClassifier();
        $this->assertSame('hot', $c->classify('forum_post', 'We are looking for an agency to build our booking website, budget $5k, need it by end of month')['level']);
        $this->assertSame('hot', $c->classify('procurement_rfp', 'Request for proposal: new council website', now()->addDays(20))['level']);
        $this->assertSame('warm', $c->classify('developer_hiring', 'Hiring a senior Laravel developer')['level'], 'hiring in-house is a need, not an ask for an agency');
        $this->assertSame('normal', $c->classify('website_issues', 'No viewport meta tag')['level']);
        $seller = $c->classify('forum_post', 'SEEKING WORK - I am a freelance developer, available for projects');
        $this->assertSame([5, 'normal'], [$seller['score'], $seller['level']]);
    }

    public function test_every_signal_is_on_the_radar_and_hn_buyers_are_found(): void
    {
        $co = Company::create(['name' => 'Leeds Council', 'country' => 'United Kingdom', 'industry' => 'Council']);
        $s = BuyingSignal::create(['company_id' => $co->id, 'signal_type' => 'procurement_rfp', 'title' => 'Tender: new council website', 'evidence_text' => 'Invitation to bid for a website rebuild',
            'observed_date' => now()->toDateString(), 'confidence' => 90, 'relevant_service' => 'web_dev', 'expiry_date' => now()->addDays(20)->toDateString(), 'metadata' => ['source' => 'contracts_finder_ocds']]);
        $item = RadarItem::where('buying_signal_id', $s->id)->firstOrFail();
        $this->assertSame(['Public tenders', 'hot', 'United Kingdom', 'Public Sector'], [$item->platform, $item->intent_level, $item->country, $item->category]);
        $s->update(['verification_status' => 'dismissed']);
        $this->assertSame(0, RadarItem::where('buying_signal_id', $s->id)->count(), 'dismissed signals leave the radar');

        Http::fake([
            'hn.algolia.com/api/v1/search_by_date?query=Seeking*' => Http::response(['hits' => [['objectID' => '900', 'title' => 'Ask HN: Freelancer? Seeking freelancer? (November 2026)']]]),
            'hn.algolia.com/api/v1/search_by_date?tags=comment*' => Http::response(['hits' => [
                ['objectID' => '901', 'parent_id' => 900, 'author' => 'acme_ceo', 'created_at_i' => now()->subDay()->timestamp,
                    'comment_text' => '<p>SEEKING FREELANCER | Remote | US-based startup<p>Need a Laravel developer to build our customer portal and integrate Stripe. Budget $8k, start ASAP.'],
                ['objectID' => '902', 'parent_id' => 900, 'author' => 'dev', 'created_at_i' => now()->subDay()->timestamp, 'comment_text' => 'SEEKING WORK | I am a freelance developer'],
            ]]),
            'hn.algolia.com/*' => Http::response(['hits' => [
                ['objectID' => '950', 'title' => 'Ask HN: Can anyone recommend an agency to build an online store in London?', 'author' => 'shopowner', 'created_at_i' => now()->subHours(5)->timestamp, 'story_text' => 'Small budget, how much should it cost?'],
            ]]),
        ]);
        $r = app(RadarService::class)->scanHackerNews(7);
        $this->assertSame(2, $r['added']);
        $hot = RadarItem::where('external_id', 'hn:901')->firstOrFail();
        $this->assertSame(['hot', 'United States', 'laravel_php', 'Seeking freelancer'], [$hot->intent_level, $hot->country, $hot->service, $hot->category]);
        $this->assertNull(RadarItem::where('external_id', 'hn:902')->first(), 'sellers are not buyers');
        $store = RadarItem::where('external_id', 'hn:950')->firstOrFail();
        $this->assertSame(['United Kingdom', 'ecommerce'], [$store->country, $store->service]);
        $this->assertSame(0, app(RadarService::class)->scanHackerNews(7)['added'], 'seen posts are not added twice');
    }

    public function test_radar_page_shows_hot_first_and_filters(): void
    {
        $user = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        foreach ([['a', 'warm', 60, 'Pakistan', 'Job boards'], ['b', 'hot', 85, 'United States', 'Hacker News'], ['c', 'normal', 20, 'Pakistan', 'Website audit'], ['d', 'hot', 75, 'Pakistan', 'Public tenders']] as [$id, $lvl, $sc, $country, $pf]) {
            RadarItem::create(['platform' => $pf, 'external_id' => $id, 'title' => "Item {$id}", 'intent_level' => $lvl, 'intent_score' => $sc, 'country' => $country, 'posted_at' => now()]);
        }
        $this->actingAs($user)->get(route('radar.index'))->assertOk()->assertSeeInOrder(['Item b', 'Item d', 'Item a', 'Item c']);
        $this->actingAs($user)->get(route('radar.index', ['country' => 'Pakistan', 'level' => 'hot']))->assertOk()
            ->assertViewHas('items', fn ($p) => $p->total() === 1 && $p->first()->external_id === 'd')
            ->assertViewHas('counts', fn ($c) => ($c['hot'] ?? 0) === 1 && ($c['warm'] ?? 0) === 1);
        $this->actingAs($user)->get(route('radar.index', ['platform' => 'Hacker News']))->assertViewHas('items', fn ($p) => $p->total() === 1);

        $d = RadarItem::where('external_id', 'd')->first();
        $this->actingAs($user)->post(route('radar.status', $d), ['status' => 'contacted'])->assertRedirect();
        $this->actingAs($user)->get(route('radar.index'))->assertDontSee('Item d');
        $this->actingAs($user)->get(route('radar.index', ['status' => 'contacted']))->assertSee('Item d');
    }
}
