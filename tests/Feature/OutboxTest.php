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

/** Sent & Replies page: who got an e-mail and what they answered. */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_emails_and_replies_are_listed_and_the_conversation_opens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['name' => 'Bloom Dental', 'website' => 'https://bloomdental.com', 'domain' => 'bloomdental.com']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Bloom Dental — general inbox', 'email' => 'hiring@bloomdental.com']);
        $quiet = Lead::create(['full_name' => 'Quiet Cafe', 'email' => 'hello@quietcafe.com']);
        $c = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'laravel_php', 'created_by' => $admin->id, 'status' => 'running']);
        $sent = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => 'a', 'sequence_step' => 1,
            'personalized_subject' => 'Your Laravel role', 'personalized_body' => "Hi team,<br>We saw your Laravel opening.", 'sent_at' => now()->subHour()]);
        CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $quiet->id, 'status' => 'sent', 'idempotency_key' => 'b', 'sequence_step' => 1,
            'personalized_subject' => 'Your menu page', 'sent_at' => now()->subHours(2)]);
        CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $quiet->id, 'status' => 'scheduled', 'idempotency_key' => 'c', 'personalized_subject' => 'Not sent yet']);
        InboundReply::create(['campaign_recipient_id' => $sent->id, 'lead_id' => $lead->id, 'from_email' => 'hiring@bloomdental.com', 'to_email' => 'leads@eziline.net',
            'subject' => 'Re: Your Laravel role', 'body_text' => 'Yes, can you share rates?', 'category' => 'interested', 'message_id' => 'm1', 'received_at' => now()]);

        $this->actingAs($admin)->get(route('outbox.index'))->assertOk()
            ->assertSee('hiring@bloomdental.com')->assertSee('Your menu page')->assertSee('Yes, can you share rates?')
            ->assertDontSee('Not sent yet')->assertSee('50%');
        $this->actingAs($admin)->get(route('outbox.index', ['tab' => 'replies']))->assertOk()
            ->assertSee('Interested')->assertDontSee('Your menu page');
        $this->actingAs($admin)->get(route('outbox.index', ['q' => 'quietcafe']))->assertOk()
            ->assertSee('Your menu page')->assertDontSee('Your Laravel role');
        $this->actingAs($admin)->get(route('outbox.show', $sent))->assertOk()
            ->assertSee('We saw your Laravel opening.')->assertSee('Yes, can you share rates?');
    }
}
