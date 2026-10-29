<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\EligibilityGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Live 29 Oct: Charles Schwab, FedEx, UPS and Amazon Locker sat among the leads (imported before the chain list). */
class ChainCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_enterprise_and_chain_companies_are_archived_and_blocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $schwab = Company::create(['name' => 'Charles Schwab', 'domain' => 'schwab.com', 'website' => 'https://www.schwab.com', 'country' => 'United States']);
        $ups = Company::create(['name' => 'United Parcel Service', 'country' => 'United States']);
        $salon = Company::create(['name' => 'Shell Beauty Salon', 'domain' => 'shellbeauty.pk', 'country' => 'Pakistan']);
        $l1 = Lead::create(['company_id' => $schwab->id, 'full_name' => 'Schwab — general inbox', 'email' => 'info@schwab.com', 'lifecycle_stage' => 'qualified']);
        $l2 = Lead::create(['company_id' => $ups->id, 'full_name' => 'UPS', 'email' => 'x@ups.example', 'lifecycle_stage' => 'new']);
        $l3 = Lead::create(['company_id' => $salon->id, 'full_name' => 'Salon', 'email' => 'hi@shellbeauty.pk', 'lifecycle_stage' => 'new']);
        $camp = Campaign::create(['name' => 'Auto', 'service_offered' => 'web_redesign', 'created_by' => $admin->id, 'status' => 'running']);
        $draft = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $l1->id, 'status' => 'pending_approval', 'idempotency_key' => 'k1']);

        $this->assertArrayHasKey('chain_brand', app(EligibilityGateService::class)->evaluate($l1)->failedChecks, 'the send gate refuses it even before cleanup');

        (require base_path('database/migrations/2026_10_29_000005_archive_chain_brands.php'))->up();

        $this->assertSame(['archived', 'archived', 'prospect'], [$schwab->fresh()->status, $ups->fresh()->status, $salon->fresh()->status]);
        $this->assertSame(['not_a_fit', 'not_a_fit', 'new'], [$l1->fresh()->lifecycle_stage, $l2->fresh()->lifecycle_stage, $l3->fresh()->lifecycle_stage]);
        $this->assertSame('cancelled', $draft->fresh()->status);
    }
}
