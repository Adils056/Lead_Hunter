<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\User;
use App\Support\BookingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A booking on the Calendly link becomes a meeting on its lead, without anyone typing it in. */
class CalendlyBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected array $active = [];

    protected array $canceled = [];

    protected array $invitees = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'leadpilot.timezone' => 'Asia/Karachi', 'leadpilot.calendly.token' => 'cal-test', 'leadpilot.calendly.api' => 'https://api.calendly.com',
            'leadpilot.agency.booking_url' => 'https://calendly.com/ismail/30min']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/users/me')) {
                return Http::response(['resource' => ['uri' => 'https://api.calendly.com/users/U1', 'name' => 'Ismail', 'email' => 'ismail@eziline.test', 'scheduling_url' => 'https://calendly.com/ismail']]);
            }
            if (preg_match('#/scheduled_events/(\w+)/invitees#', $url, $m)) {
                return Http::response(['collection' => [$this->invitees[$m[1]]]]);
            }
            if (str_contains($url, '/scheduled_events')) {
                return Http::response(['collection' => $request['status'] === 'canceled' ? $this->canceled : $this->active, 'pagination' => ['next_page_token' => null]]);
            }

            return Http::response('', 404);
        });
    }

    protected function lead(string $name, string $email, string $stage = 'contacted'): Lead
    {
        $domain = substr(strrchr($email, '@'), 1);
        $c = Company::create(['name' => $name, 'domain' => in_array($domain, ['gmail.com'], true) ? strtolower(str_replace(' ', '', $name)) . '.example' : $domain, 'country' => 'United Arab Emirates']);

        return Lead::create(['company_id' => $c->id, 'full_name' => $name, 'email' => $email, 'contact_status' => 'generic_inbox', 'lifecycle_stage' => $stage, 'overall_score' => 70]);
    }

    protected function event(string $id, string $start, array $invitee): array
    {
        $this->invitees[$id] = $invitee + ['status' => 'active', 'questions_and_answers' => [], 'tracking' => []];

        return ['uri' => "https://api.calendly.com/scheduled_events/{$id}", 'name' => '30 Minute Meeting', 'status' => 'active',
            'start_time' => $start, 'end_time' => \Carbon\Carbon::parse($start)->addMinutes(30)->toIso8601ZuluString(),
            'location' => ['type' => 'google_conference', 'join_url' => "https://meet.google.com/{$id}"]];
    }

    public function test_a_calendly_booking_moves_its_lead_to_meeting_booked_and_stops_follow_ups(): void
    {
        $tacobay = $this->lead('Tacobay', 'info@tacobay.example', 'replied');
        $kre8 = $this->lead('Kre8tive Nails', 'hello@kre8tive.example', 'replied');
        $sinag = $this->lead('Sinag Dental', 'info@sinag.example');
        Task::create(['lead_id' => $tacobay->id, 'title' => 'Replied on WhatsApp: answer now', 'status' => 'pending', 'due_date' => now()->toDateString(), 'assigned_to' => $this->admin->id]);
        $camp = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true]);
        $follow = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $sinag->id, 'sequence_step' => 2, 'status' => 'approved', 'idempotency_key' => 'f1']);

        // the WhatsApp answer's link carries the lead, so a Gmail booking still finds Tacobay
        $link = BookingLink::for($tacobay);
        $this->assertStringContainsString('utm_content=lp' . $tacobay->id, $link);
        $this->active = [
            $this->event('E1', '2026-11-20T07:00:00Z', ['email' => 'owner.taco@gmail.com', 'name' => 'Omar', 'tracking' => ['utm_source' => 'leadpilot', 'utm_content' => 'lp' . $tacobay->id],
                'questions_and_answers' => [['question' => 'What should we cover?', 'answer' => 'New website']]]),
            $this->event('E2', '2026-11-21T08:00:00Z', ['email' => 'HELLO@kre8tive.example', 'name' => 'Nadia']),   // same address, other case
            $this->event('E3', '2026-11-21T09:00:00Z', ['email' => 'dr.ana@sinag.example', 'name' => 'Ana']),      // a colleague at the same company
            $this->event('E4', '2026-11-22T09:00:00Z', ['email' => 'someone@gmail.com', 'name' => 'Stranger']),    // no lead
        ];

        $this->artisan('leadpilot:calendly-sync', ['--dry-run' => true])->expectsOutputToContain('Meeting booked: Tacobay')->assertSuccessful();
        $this->assertSame(0, Meeting::count(), 'a dry run records nothing');

        $this->artisan('leadpilot:calendly-sync')->expectsOutputToContain('3 booked, 0 cancelled, 1 not matched')
            ->expectsOutputToContain('Not matched to a lead: Stranger <someone@gmail.com>')->assertSuccessful();
        $m = Meeting::where('lead_id', $tacobay->id)->firstOrFail();
        $this->assertSame(['calendly', 'scheduled', 'https://meet.google.com/E1'], [$m->source, $m->status, $m->meeting_url]);
        $this->assertStringContainsString('New website', $m->outcome_notes);
        $this->assertSame('meeting_booked', $tacobay->fresh()->lifecycle_stage);
        $this->assertSame('meeting_booked', $tacobay->fresh()->pipeline_state);
        $this->assertSame(1, Meeting::where('lead_id', $kre8->id)->count());
        $this->assertSame(1, Meeting::where('lead_id', $sinag->id)->count());
        $this->assertSame('cancelled', $follow->fresh()->status, 'no follow-up to someone who booked');
        $this->assertSame('completed', Task::where('lead_id', $tacobay->id)->where('title', 'like', '%answer now%')->value('status'));
        $this->assertSame(1, Task::where('lead_id', $tacobay->id)->where('title', 'Meeting Fri 20 Nov, 12:00: prepare')->count());
        $this->assertTrue(NotificationDelivery::where('dedupe_key', 'calendly-booked:https://api.calendly.com/scheduled_events/E1')->where('user_id', $this->admin->id)->exists());
        $this->assertSame(1, NotificationDelivery::where('dedupe_key', 'like', 'calendly-unmatched:%')->where('channel', 'database')->count());

        // the next sync records nothing twice and reports the stranger only once
        $this->artisan('leadpilot:calendly-sync')->expectsOutputToContain('0 booked, 0 cancelled, 1 not matched')->assertSuccessful();
        $this->assertSame(3, Meeting::count());
        $this->assertSame(1, NotificationDelivery::where('dedupe_key', 'like', 'calendly-unmatched:%')->where('channel', 'database')->count());

        // a cancellation on Calendly cancels the meeting and asks for a new time
        $this->canceled = [array_merge($this->active[0], ['status' => 'canceled', 'cancellation' => ['reason' => 'Travelling, next week please']])];
        $this->active = array_slice($this->active, 1);
        $this->artisan('leadpilot:calendly-sync')->expectsOutputToContain('Cancelled: Tacobay')->assertSuccessful();
        $this->assertSame('cancelled', $m->fresh()->status);
        $this->assertStringContainsString('Travelling, next week please', $m->fresh()->outcome_notes);
        $this->assertSame(1, Task::where('lead_id', $tacobay->id)->where('title', 'Meeting cancelled: answer now and offer a new time')->where('status', 'pending')->count());
        $this->assertSame(0, Task::where('lead_id', $tacobay->id)->where('title', 'like', 'Meeting %: prepare')->where('status', 'pending')->count());
    }

    public function test_without_a_token_nothing_is_called_and_check_says_why(): void
    {
        config(['leadpilot.calendly.token' => null]);
        $this->artisan('leadpilot:calendly-sync')->expectsOutputToContain('CALENDLY_TOKEN is not set')->assertSuccessful();
        $this->artisan('leadpilot:calendly-sync', ['--check' => true])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_check_confirms_the_token(): void
    {
        $this->artisan('leadpilot:calendly-sync', ['--check' => true])->expectsOutputToContain('Calendly connected: Ismail')->assertSuccessful();
    }
}
