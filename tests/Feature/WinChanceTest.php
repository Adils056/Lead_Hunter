<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\User;
use App\Services\Outreach\WinChance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Success rate per lead: benchmark per evidence kind, adjusted for reach, learning from own replies. */
class WinChanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stronger_evidence_and_better_reach_give_a_higher_success_rate(): void
    {
        $c = app(WinChance::class);
        $founder = $c->estimate(['segment' => 'hiring', 'decision_maker' => true, 'named' => true, 'email_status' => 'verified', 'evidence_age_days' => 5]);
        $request = $c->estimate(['segment' => 'request', 'named' => true, 'email_status' => 'verified', 'evidence_age_days' => 5]);
        $inbox = $c->estimate(['segment' => 'audit_low', 'shared_inbox' => true, 'email_status' => 'verified', 'evidence_age_days' => 40]);
        $unsure = $c->estimate(['segment' => 'hiring', 'decision_maker' => true, 'named' => true, 'email_status' => 'mx_only', 'evidence_age_days' => 5]);

        $this->assertSame(5.9, $founder['interested']);   // 3.5 % benchmark × 1.4 owner × 1.2 fresh
        $this->assertGreaterThan($founder['interested'], $request['interested']);
        $this->assertLessThan(1, $inbox['interested']);
        $this->assertLessThan($founder['interested'], $unsure['interested']);
        $this->assertSame('benchmark', $founder['basis']);
        $this->assertLessThan($founder['interested'], $founder['deal']);
    }

    public function test_own_results_pull_the_rate_toward_reality(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $camp = Campaign::create(['name' => 'Auto', 'service_offered' => 'laravel_php', 'created_by' => $admin->id, 'status' => 'running']);
        foreach (range(1, 40) as $i) {
            $lead = Lead::create(['full_name' => "F{$i}", 'email' => "f{$i}@co{$i}.example"]);
            $lead->forceFill(['win_segment' => 'hiring'])->saveQuietly(); // set by the ladder, not mass-assignable
            CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => "k{$i}", 'sent_at' => now()->subDays(20)]);
            if ($i <= 8) { // 20 % interested — far above the 3.5 % benchmark
                InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'me@x', 'subject' => 'Re', 'body_text' => 'Yes', 'category' => 'interested', 'message_id' => "m{$i}", 'received_at' => now()->subDays(18)]);
            }
        }
        $recent = Lead::create(['full_name' => 'New', 'email' => 'new@n.example']);
        $recent->forceFill(['win_segment' => 'hiring'])->saveQuietly();
        CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $recent->id, 'status' => 'sent', 'idempotency_key' => 'k-new', 'sent_at' => now()->subDay()]);

        [$reply, $interested, $basis] = (new WinChance())->rates('hiring');

        $this->assertEqualsWithDelta((30 * 0.035 + 8) / 70, $interested, 0.0001, 'a send from yesterday does not count as "no reply" yet');
        $this->assertSame('your data: 40 sent, 8 replied, 8 interested', $basis);
    }

    public function test_win_ladder_shows_the_success_rate_and_expected_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $lead = Lead::create(['first_name' => 'Ann', 'last_name' => 'Lee', 'job_title' => 'Founder', 'email' => 'ann@lee.example', 'email_verification_status' => 'verified', 'contact_status' => 'identified', 'overall_score' => 50]);
        app(\App\Services\Outreach\WinLadderService::class)->refresh($lead);
        $lead->refresh();

        $this->assertNotNull($lead->win_interested_chance);
        $this->assertSame('none', $lead->win_segment);
        $html = $this->actingAs($admin)->get(route('leads.win-ladder', ['tier' => $lead->win_tier]))->assertOk()->getContent();
        $this->assertStringContainsString('Success rate', $html);
        $this->assertStringContainsString('If all 1 lead(s) on this rung get the full sequence', $html);
        $this->artisan('leadpilot:win-ladder --tier=' . $lead->win_tier . ' --no-refresh')->expectsOutputToContain('Expected interested')->assertExitCode(0);
    }
}
