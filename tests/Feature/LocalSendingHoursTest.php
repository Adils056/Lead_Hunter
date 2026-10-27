<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\Outreach\SequenceEngine;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every e-mail leaves inside the prospect's own working hours: their country's zone and working days. */
class LocalSendingHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function lead(string $country, ?string $city = null): Lead
    {
        $c = Company::create(['name' => "Co {$country} {$city}", 'country' => $country, 'city' => $city]);

        return Lead::create(['company_id' => $c->id, 'full_name' => 'x', 'email' => uniqid() . '@example.com', 'country' => $country]);
    }

    public function test_each_country_gets_mail_in_its_own_working_hours_and_days(): void
    {
        config(['leadpilot.autopilot.sending_hours' => [9, 17], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5]]);
        $engine = app(SequenceEngine::class);
        $pk = $this->lead('Pakistan');
        $uk = $this->lead('United Kingdom');
        $ny = $this->lead('United States', 'New York');
        $sf = $this->lead('United States', 'San Francisco');

        $this->travelTo(Carbon::parse('2026-10-27 10:00', 'Asia/Karachi')); // Tuesday: 05:00 London, 01:00 New York
        $this->assertTrue($engine->inSendingWindow(null, $pk));
        $this->assertFalse($engine->inSendingWindow(null, $uk));
        $this->assertFalse($engine->inSendingWindow(null, $ny));

        $next = $engine->nextSendTime(null, $uk);
        $this->assertTrue($next->between(Carbon::parse('2026-10-27 09:00', 'Europe/London'), Carbon::parse('2026-10-27 09:40', 'Europe/London')), $next->toIso8601String());
        $this->assertSame('America/Los_Angeles', LocalTime::forLead($sf)[0]);
        $this->assertSame(9, $engine->nextSendTime(null, $sf)->timezone('America/Los_Angeles')->hour);

        $sa = $this->lead('Saudi Arabia');
        $ae = $this->lead('United Arab Emirates');
        $this->travelTo(Carbon::parse('2026-11-01 10:00', 'Asia/Riyadh')); // Sunday
        $this->assertTrue($engine->inSendingWindow(null, $sa), 'Sunday is a working day in Saudi Arabia');
        $this->assertFalse($engine->inSendingWindow(null, $ae), 'the UAE weekend is Saturday-Sunday');
        $this->travelTo(Carbon::parse('2026-10-30 10:00', 'Asia/Riyadh')); // Friday
        $this->assertFalse($engine->inSendingWindow(null, $sa));
    }

    public function test_hiring_post_location_sets_the_us_zone_when_the_company_has_no_city(): void
    {
        $lead = $this->lead('United States');
        BuyingSignal::create(['company_id' => $lead->company_id, 'signal_type' => 'developer_hiring', 'title' => 'Hiring', 'confidence' => 85,
            'source_url' => 'https://news.ycombinator.com/item?id=1', 'evidence_text' => 'x', 'relevant_service' => 'laravel_php', 'observed_date' => now()->toDateString(),
            'metadata' => ['location' => 'Senior Laravel developer | Austin, TX | ONSITE']]);

        $this->assertSame('America/Chicago', LocalTime::forLead($lead->fresh())[0]);
    }

    public function test_due_mail_waits_for_the_prospects_morning_instead_of_leaving_at_night(): void
    {
        config(['leadpilot.autopilot.sending_hours' => [9, 17], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5]]);
        $this->travelTo(Carbon::parse('2026-10-27 10:00', 'Asia/Karachi')); // 05:00 in London
        $admin = User::factory()->create(['role' => 'admin']);
        $campaign = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'laravel_php', 'created_by' => $admin->id, 'status' => 'running', 'is_autopilot' => true]);
        $rec = CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $this->lead('United Kingdom')->id, 'status' => 'approved',
            'idempotency_key' => 'uk1', 'sequence_step' => 1, 'personalized_subject' => 'Hi', 'personalized_body' => 'Body', 'scheduled_at' => now()->subMinute()]);

        $out = app(SequenceEngine::class)->dispatchDue();

        $this->assertSame(0, $out['sent']);
        $this->assertTrue($out['outside_window']);
        $this->assertSame('approved', $rec->fresh()->status, 'kept for 09:00 London time');
    }
}
