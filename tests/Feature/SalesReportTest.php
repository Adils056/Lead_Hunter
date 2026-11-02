<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** leadpilot:sales-report — the owner's funnel and win rates, counts only. */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_funnel_follows_emailed_prospects_and_rates_are_shares_of_emailed(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $company = Company::create(['name' => 'Acme', 'website' => 'https://acme.example', 'domain' => 'acme.example', 'country' => 'Pakistan']);
        $campaign = Campaign::create(['name' => 'Test', 'service_offered' => 'Web development', 'daily_limit' => 10, 'status' => 'running', 'created_by' => $user->id]);
        $leads = collect(range(1, 4))->map(fn ($i) => Lead::create(['first_name' => "P{$i}", 'last_name' => 'X', 'email' => "p{$i}@acme.example", 'company_id' => $company->id]));
        Lead::create(['first_name' => 'Never', 'last_name' => 'Mailed', 'email' => 'n@acme.example', 'company_id' => $company->id, 'lifecycle_stage' => 'won']);

        foreach ($leads as $l) {
            CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $l->id, 'idempotency_key' => 'k' . $l->id, 'personalized_subject' => 'Hi', 'personalized_body' => 'Hi', 'status' => 'sent', 'sent_at' => now()->subDays(20), 'sequence_step' => 1]);
        }
        InboundReply::create(['lead_id' => $leads[0]->id, 'from_email' => $leads[0]->email, 'to_email' => 'x', 'message_id' => 'm1', 'subject' => 'Re', 'body_text' => 'Yes please', 'category' => 'interested', 'received_at' => now()]);
        InboundReply::create(['lead_id' => $leads[1]->id, 'from_email' => $leads[1]->email, 'to_email' => 'x', 'message_id' => 'm2', 'subject' => 'Re', 'body_text' => 'Away', 'category' => 'out_of_office', 'received_at' => now()]);
        $leads[0]->forceFill(['lifecycle_stage' => 'won'])->save();
        $leads[2]->forceFill(['lifecycle_stage' => 'lost'])->save();

        $this->artisan('leadpilot:sales-report --json')->assertSuccessful();
        $r = app(\App\Console\Commands\SalesReportCommand::class)->build(30, app(\App\Services\Outreach\WeeklyReview::class));

        $this->assertSame(4, $r['funnel']['emailed']['all']);
        $this->assertSame(1, $r['funnel']['replied']['all'], 'an out-of-office is not a reply');
        $this->assertSame([1, 1, 1], [$r['funnel']['meeting']['all'], $r['funnel']['proposal']['all'], $r['funnel']['won']['all']], 'a won lead also passed the meeting and proposal steps');
        $this->assertSame(25.0, $r['win_rates']['win_rate (won / emailed)']);
        $this->assertSame(50.0, $r['win_rates']['close_rate (won / won+lost)']);
        $this->assertSame(2, $r['win_rates']['won_any_channel (count)'], 'a deal won outside e-mail is counted separately');
        $this->assertSame(5, $r['found']['leads']['all']);
    }
}
