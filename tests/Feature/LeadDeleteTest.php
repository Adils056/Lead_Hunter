<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Deleting a lead: admin only, soft delete, nothing left to send, no re-contact of someone already e-mailed. */
class LeadDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_deletes_a_never_contacted_lead_and_it_can_be_imported_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $lead = Lead::create(['full_name' => 'Qa Tester', 'email' => 'qa.tester@example.com']);

        $this->actingAs($admin)->get(route('leads.show', $lead))->assertOk()->assertSee('Delete');
        $this->actingAs($admin)->delete(route('leads.destroy', $lead))
            ->assertRedirect(route('leads.index'))->assertSessionHas('success', 'Lead "Qa Tester" deleted.');

        $this->assertSoftDeleted($lead);
        $this->assertFalse(Suppression::isSuppressed('qa.tester@example.com'), 'never e-mailed: no do-not-contact entry');
        $this->actingAs($admin)->get(route('leads.index'))->assertOk()->assertDontSee('qa.tester@example.com');
        $this->actingAs($admin)->get(route('leads.show', $lead))->assertNotFound();

        $result = app(CsvImportService::class)->import([['name' => 'Qa Tester', 'email' => 'qa.tester@example.com']], ['full_name' => 'name', 'email' => 'email'], $admin->id);
        $this->assertSame(1, $result['imported'], 'a deleted test lead does not block a re-import');
    }

    public function test_only_admins_can_delete(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $lead = Lead::create(['full_name' => 'Kept Lead', 'email' => 'kept@example.com']);

        $this->actingAs($sales)->get(route('leads.show', $lead))->assertOk()->assertDontSee('bi-trash');
        $this->actingAs($sales)->delete(route('leads.destroy', $lead))->assertForbidden();
        $this->assertNotSoftDeleted($lead);
    }

    public function test_deleting_a_contacted_lead_cancels_pending_messages_and_suppresses_the_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $lead = Lead::create(['full_name' => 'Sara Khan', 'email' => 'sara@clinic.example']);
        $c = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'web_dev', 'created_by' => $admin->id, 'status' => 'running']);
        $sent = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => 's1', 'sequence_step' => 1,
            'personalized_subject' => 'First touch', 'sent_at' => now()->subDays(3)]);
        $followUp = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'pending_approval', 'idempotency_key' => 's2', 'sequence_step' => 2,
            'personalized_subject' => 'Follow up']);
        $req = app(ApprovalService::class)->request('follow_up', "follow:{$followUp->id}", ['title' => 'Approve follow-up', 'summary' => 's'], $followUp);

        $this->actingAs($admin)->delete(route('leads.destroy', $lead))->assertRedirect(route('leads.index'));

        $this->assertSoftDeleted($lead);
        $this->assertSame('cancelled', $followUp->fresh()->status);
        $this->assertSame('cancelled', $req->fresh()->status, 'the approval can no longer send it');
        $this->assertSame('sent', $sent->fresh()->status, 'history is kept');
        $this->assertTrue(Suppression::isSuppressed('sara@clinic.example'), 'already e-mailed: never re-contacted as a new lead');
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead_deleted']);

        // pages that list history still render with the lead gone
        $this->actingAs($admin)->get(route('campaigns.show', $c))->assertOk()->assertSee('Deleted lead');
        $this->actingAs($admin)->get(route('outbox.index'))->assertOk();
        $this->actingAs($admin)->get(route('outbox.show', $sent))->assertOk();
    }
}
