<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\Outreach\SequenceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live 29 Oct: Starbridge got a first email at recruiting@ and, a day later, a founder draft for justin@ was waiting.
 * One conversation per company: the founder email waits, the shared-inbox follow-ups stop.
 */
class OneConversationPerCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $camp = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'laravel_php', 'created_by' => $admin->id, 'status' => 'running', 'is_autopilot' => true]);
        $co = Company::create(['name' => 'Starbridge', 'domain' => 'starbridge.test', 'country' => 'United States']);
        $inbox = Lead::create(['company_id' => $co->id, 'full_name' => 'Starbridge — general inbox', 'email' => 'hello@starbridge.test', 'contact_status' => 'generic_inbox', 'email_verification_status' => 'verified']);
        $founder = Lead::create(['company_id' => $co->id, 'first_name' => 'Justin', 'last_name' => 'Wenig', 'job_title' => 'Founder', 'email' => 'justin@starbridge.test', 'contact_status' => 'identified', 'email_verification_status' => 'verified']);
        $first = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $inbox->id, 'status' => 'sent', 'sequence_step' => 1, 'idempotency_key' => 'a', 'sent_at' => now()->subDays(2)]);

        return [$camp, $inbox, $founder, $first];
    }

    protected function conflict(CampaignRecipient $rec): ?string
    {
        $m = new \ReflectionMethod(SequenceEngine::class, 'companyConflict');

        return $m->invoke(app(SequenceEngine::class), $rec->fresh(['lead']));
    }

    public function test_founder_first_email_waits_until_the_company_gap_has_passed(): void
    {
        [$camp, , $founder, $first] = $this->fixture();
        $draft = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $founder->id, 'status' => 'approved', 'sequence_step' => 1, 'idempotency_key' => 'b']);

        $this->assertStringContainsString('deferred — company emailed', (string) $this->conflict($draft));
        $this->assertSame('approved', $draft->fresh()->status, 'rescheduled, not dropped');
        $this->assertSame($first->sent_at->copy()->addDays(10)->toDateString(), $draft->fresh()->scheduled_at->toDateString());

        $first->update(['sent_at' => now()->subDays(11)]);
        $this->assertNull($this->conflict($draft), 'after the gap the founder email may go');
    }

    public function test_shared_inbox_follow_up_stops_once_a_named_decision_maker_is_known(): void
    {
        [$camp, $inbox, , $first] = $this->fixture();
        $follow = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $inbox->id, 'status' => 'approved', 'sequence_step' => 2, 'parent_recipient_id' => $first->id, 'idempotency_key' => 'c']);

        $this->assertStringContainsString('cancelled', (string) $this->conflict($follow));
        $this->assertSame('cancelled', $follow->fresh()->status);
    }

    public function test_a_deferred_queued_email_is_picked_up_again(): void
    {
        [$camp, , $founder] = $this->fixture();
        $queued = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $founder->id, 'status' => 'queued', 'sequence_step' => 1, 'idempotency_key' => 'q', 'scheduled_at' => now()->subMinute()]);

        app(SequenceEngine::class)->dispatch($queued, false);

        $this->assertNotSame('queued', $queued->fresh()->status, 'quota-deferred work used to stay queued forever');
    }
}
