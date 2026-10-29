<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use App\Support\OutreachMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SQA report 29 Oct 2026, round 2: one set of numbers, working filters, clean contacts. */
class SqaRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_bugs_8_9_10_every_page_shows_the_same_outreach_numbers(): void
    {
        $camp = Campaign::create(['name' => 'Auto', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running']);
        foreach (range(1, 4) as $i) {
            $lead = Lead::create(['full_name' => "L{$i}", 'email' => "p{$i}@x{$i}.example", 'overall_score' => 50 + 5 * $i]);
            CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'status' => $i === 4 ? 'bounced' : 'sent', 'idempotency_key' => "k{$i}",
                'sent_at' => now()->subDay(), 'bounced_at' => $i === 4 ? now() : null]);
            if ($i === 1) {
                InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'me@x', 'subject' => 'Re', 'body_text' => 'Away', 'category' => 'out_of_office', 'message_id' => 'm1', 'received_at' => now()]);
            }
            if ($i === 2) {
                InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'me@x', 'subject' => 'Re', 'body_text' => 'Yes', 'category' => 'interested', 'message_id' => 'm2', 'received_at' => now()]);
                InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'me@x', 'subject' => 'Re', 'body_text' => 'Also', 'category' => 'request_info', 'message_id' => 'm3', 'received_at' => now()]);
            }
        }

        $m = OutreachMetrics::summary();
        $this->assertSame([4, 3, 1, 1, 1, 25.0, 75.0], [$m['sent'], $m['delivered'], $m['bounced'], $m['replied'], $m['positive'], $m['reply_rate'], $m['delivery_rate']],
            'auto-replies are not replies; two answers from one prospect are one reply; rates never exceed 100%');

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertViewHas('replyRate', 25.0)->assertViewHas('emailsDelivered', 3)->assertViewHas('qualifiedLeads', 2); // scores 65 and 70; 60 is below the 65 threshold
        $this->actingAs($this->admin)->get(route('analytics.index'))->assertOk()->assertViewHas('replyRate', 25.0)->assertViewHas('delivered', 3);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertOk()
            ->assertViewHas('campaigns', fn ($c) => $c->first()->delivered_count === 3 && $c->first()->replied_count === 1);
        $this->actingAs($this->admin)->get(route('outbox.index'))->assertOk()->assertViewHas('stats', fn ($s) => $s['rate'] === 25.0 && $s['replied'] === 1);
    }

    public function test_bug11_stage_follows_a_falling_score_and_bug12_service_filter_uses_the_stored_key(): void
    {
        $lead = Lead::create(['full_name' => 'Fallen', 'email' => 'a@fallen.example', 'lifecycle_stage' => 'qualified', 'relevant_services' => ['web_dev']]);
        app(\App\Services\LeadScoringService::class)->evaluateLead($lead->fresh());
        $this->assertSame('new', $lead->fresh()->lifecycle_stage, 'scored below the threshold: no longer "Qualified"');

        Lead::create(['full_name' => 'Other', 'email' => 'b@other.example', 'relevant_services' => ['seo_technical']]);
        $this->actingAs($this->admin)->get(route('dashboard', ['service' => 'web_dev']))->assertOk()
            ->assertViewHas('totalContacts', 1)
            ->assertSee('<option value="web_dev" selected>', false);
    }

    public function test_bug14_the_integrations_page_lists_providers_with_a_key_field(): void
    {
        Integration::query()->delete(); // a fresh server: no provider rows
        $this->actingAs($this->admin)->get(route('settings.integrations'))->assertOk()->assertSee('name="api_key"', false)->assertSee('OpenAI');
        $this->assertGreaterThan(3, Integration::count());
        $this->assertSame(0, Integration::whereIn('provider', ['openai', 'hunter', 'apollo', 'google_places'])->where('is_active', true)->count(), 'no paid provider is switched on without a key');
    }

    public function test_bug6_first_dot_last_addresses_are_people_and_bug22_chains_and_category_names_are_skipped(): void
    {
        $ing = new ProspectIngestor('test', 'Test');
        $r = new DiscoveryResult('test', 'Test');

        $c = $ing->upsertCompany(['name' => 'Growth Co', 'website' => 'https://growthco.example'], $r)['company'];
        $person = $ing->ensureLead($c, ['email' => 'marc.ripley@growthco.example'], $r);
        $this->assertSame(['identified', 'Marc', 'Ripley'], [$person->contact_status, $person->first_name, $person->last_name]);
        $c2 = $ing->upsertCompany(['name' => 'Dept Co', 'website' => 'https://deptco.example'], $r)['company'];
        $this->assertSame('generic_inbox', $ing->ensureLead($c2, ['email' => 'info.sales@deptco.example'], $r)->contact_status);

        $this->assertNull($ing->upsertCompany(['name' => 'Allstate Insurance: Jane Doe', 'website' => 'https://agents.allstate.com/jane-doe'], $r)['company']);
        $this->assertNull($ing->upsertCompany(['name' => 'State Farm', 'website' => 'https://janedoe-insurance.example'], $r)['company']);
        $this->assertNull($ing->upsertCompany(['name' => 'Bakery', 'website' => 'https://somebakery.example'], $r)['company']);
        $this->assertNotNull($ing->upsertCompany(['name' => 'Bakery Bliss', 'website' => 'https://bakerybliss.example'], $r)['company']);
    }
}
