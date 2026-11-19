<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AutoReply;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappReply;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Prospects who reply get an answer within minutes: the AI reads the reply, the rules keep the answer safe. */
class AutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** what the fake Gemini answers next */
    protected array $ai = [];

    /** how many more times the fake WhatsApp gateway refuses */
    protected int $gatewayDown = 0;

    /** the gateway cannot send to this contact at all (a chat using a WhatsApp privacy id) */
    protected bool $lidContact = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'ismail@eziline.test', 'app.url' => 'https://hunter.example.com',
            'leadpilot.keys.gemini' => 'g-test', 'leadpilot.agency.booking_url' => 'https://calendly.com/ismail/30min', 'leadpilot.agency.sender_name' => 'Ismail',
            'leadpilot.whatsapp.ezimk' => ['url' => 'https://ezimk.com/api/create-message', 'appkey' => 'app-test', 'authkey' => 'auth-test'],
            'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false, 'reply_to_email' => 'ismail@eziline.test']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(array_shift($this->ai) ?? [])]]]]], 'usageMetadata' => ['totalTokenCount' => 50]]);
            }
            if (str_contains($request->url(), 'ezimk.com')) {
                if ($this->gatewayDown > 0) {
                    $this->gatewayDown--;

                    return Http::response(['message' => 'Trying to access array offset on value of type null'], 500);
                }
                if ($this->lidContact) {
                    return Http::response(['message' => 'Trying to access array offset on value of type bool', 'exception' => 'ErrorException'], 500);
                }

                return Http::response(['message_status' => 'Success'], 200);
            }

            return Http::response('', 404);
        });
    }

    protected function on(): void
    {
        app(AutonomyPolicyService::class)->set('reply.auto_respond', 'automatic');
    }

    protected function lead(string $name, string $stage = 'replied'): Lead
    {
        $c = Company::create(['name' => $name, 'domain' => strtolower(str_replace(' ', '', $name)) . '.example', 'country' => 'United Arab Emirates', 'city' => 'Dubai']);

        return Lead::create(['company_id' => $c->id, 'full_name' => 'Nadia Khan', 'first_name' => 'Nadia', 'email' => 'nadia@' . $c->domain,
            'contact_status' => 'identified', 'lifecycle_stage' => $stage, 'country' => 'United Arab Emirates']);
    }

    protected function emailReply(Lead $lead, string $body, string $category = 'interested', int $minutesAgo = 10): InboundReply
    {
        $camp = Campaign::firstOrCreate(['name' => 'Autopilot'], ['service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true]);
        CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'sequence_step' => 1, 'status' => 'replied', 'idempotency_key' => 'c' . $lead->id,
            'personalized_subject' => 'Your website', 'personalized_body' => 'Hi Nadia, I noticed your site has no mobile layout.', 'sent_at' => now()->subDays(3)]);

        return InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'ismail@eziline.test', 'subject' => 'Re: Your website',
            'message_id' => 'their-' . $lead->id . '@mail.example', 'body_text' => $body, 'category' => $category, 'received_at' => now()->subMinutes($minutesAgo)]);
    }

    protected function prospectMails(): array
    {
        return array_values(array_filter(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all(),
            fn ($m) => !in_array($this->admin->email, array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()), true)));
    }

    public function test_an_email_reply_is_read_by_the_ai_and_answered_in_the_thread(): void
    {
        $lead = $this->lead('Kre8tive Nails');
        $reply = $this->emailReply($lead, "Yes interested. Call me on +971 50 123 4567 or nadia.private@gmail.com\n\nOn Mon, Ismail wrote:\n> old text");
        $approval = app(ApprovalService::class)->request('meeting_slots', "meeting:{$reply->id}", ['title' => 'Offer times', 'summary' => 'x', 'payload' => ['subject' => 'Re', 'body' => 'x']], $reply);
        $link = 'https://calendly.com/ismail/30min?utm_source=leadpilot&utm_medium=email&utm_content=lp' . $lead->id;
        $this->ai = [['action' => 'reply', 'reply' => "Hi Nadia,\n\nThank you! Happy to show you the fixes for your website. Pick a time for a 15-minute call: {$link}\n\nIsmail", 'reason' => 'interested']];

        // off by default: nothing goes out, but a dry run shows what would be sent
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('Automatic answers are off')->assertSuccessful();
        $this->artisan('leadpilot:auto-reply', ['--dry-run' => true])->expectsOutputToContain('Would answer: Kre8tive Nails (e-mail, gemini)')->assertSuccessful();
        $this->assertCount(0, $this->prospectMails());
        $this->assertSame(0, AutoReply::count());

        // the AI never sees their e-mail address, phone number or our quoted text
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage') && str_contains($r->body(), '[phone]') && str_contains($r->body(), '[email]')
            && !str_contains($r->body(), '4567') && !str_contains($r->body(), 'nadia.private') && !str_contains($r->body(), 'old text'));

        $this->on();
        $this->ai = [['action' => 'reply', 'reply' => "Hi Nadia,\n\nThank you! Happy to show you the fixes for your website. Pick a time for a 15-minute call: {$link}\n\nIsmail", 'reason' => 'interested']];
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        $mails = $this->prospectMails();
        $this->assertCount(1, $mails);
        $m = $mails[0]->getOriginalMessage();
        $this->assertSame($lead->email, $m->getTo()[0]->getAddress());
        $this->assertSame('Re: Your website', $m->getSubject());
        $this->assertStringContainsString($reply->message_id, $m->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString($link, $m->getTextBody());
        $this->assertSame('sent', AutoReply::where('source_id', $reply->id)->value('status'));
        $this->assertTrue(EmailLog::where('lead_id', $lead->id)->where('direction', 'outbound')->exists());
        $this->assertSame('cancelled', $approval->fresh()->status, 'the meeting-times draft is not sent as a second answer');
        $this->artisan('leadpilot:waiting-digest', ['--dry-run' => true])->expectsOutputToContain('Nobody is waiting');

        // once only
        $this->artisan('leadpilot:auto-reply')->assertSuccessful();
        $this->assertCount(1, $this->prospectMails());
    }

    public function test_an_ai_answer_with_a_price_or_a_foreign_link_is_replaced_by_the_safe_answer(): void
    {
        $this->on();
        $lead = $this->lead('Sidekick Labs');
        $this->emailReply($lead, 'How much would a new website cost?', 'request_info');
        $this->ai = [['action' => 'reply', 'reply' => 'A new site costs $500 with 20% off this week. See https://cheap-sites.example', 'reason' => 'price']];

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        $body = $this->prospectMails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('$500', $body);
        $this->assertStringNotContainsString('cheap-sites', $body);
        $this->assertStringContainsString('The price depends on what Sidekick Labs needs', $body);
        $this->assertStringContainsString('https://calendly.com/ismail/30min', $body);
        $this->assertSame('template', AutoReply::value('writer'));
    }

    public function test_a_complaint_goes_to_a_person_and_a_no_or_a_stop_gets_no_answer(): void
    {
        $this->on();
        $angry = $this->lead('Angry Dental');
        $this->emailReply($angry, 'Who gave you my email? I will report you to my lawyer.', 'objection');
        $polite = $this->lead('Polite No');
        $this->emailReply($polite, 'Thanks, but we are not interested.', 'uncertain');
        $stop = $this->lead('Stop Spa');
        $this->emailReply($stop, 'unsubscribe', 'unsubscribe');
        $this->ai = [['action' => 'human', 'reply' => '', 'reason' => 'threatens legal action'], ['action' => 'none', 'reply' => '', 'reason' => 'they declined']];

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('Left to a person: Angry Dental (email): left to a person: threatens legal action')
            ->expectsOutputToContain('Skipped: Polite No (email): no answer needed: they declined')->assertSuccessful();
        $this->assertCount(0, $this->prospectMails());
        $this->assertSame(1, Task::where('lead_id', $angry->id)->where('title', 'Answer now: LeadPilot left this reply to you')->count());
        $this->assertSame(0, AutoReply::where('lead_id', $stop->id)->count(), 'an unsubscribe is never even read for an answer');
    }

    public function test_a_markdown_or_shortened_link_and_small_numbers_are_accepted_and_a_wrong_no_goes_to_a_person(): void
    {
        $this->on();
        $lead = $this->lead('New Crescent School');
        $this->emailReply($lead, 'Yes, can you share more about the 3 fixes?', 'request_info');
        $this->ai = [['action' => 'reply', 'reply' => "Hi Nadia,\n\n**Thanks!** The 3 fixes take about 24 hours to explain properly. [Pick a time](https://calendly.com/ismail/30min) for a 15-minute call.\n\nIsmail", 'reason' => 'info']];

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        $body = $this->prospectMails()[0]->getOriginalMessage()->getTextBody();
        $this->assertSame('gemini', AutoReply::value('writer'), 'the AI answer is used, not the fixed one');
        $this->assertStringContainsString('The 3 fixes take about 24 hours', $body);
        $this->assertStringContainsString('Pick a time: https://calendly.com/ismail/30min?utm_source=leadpilot&utm_medium=email&utm_content=lp' . $lead->id, $body);
        $this->assertStringNotContainsString('**', $body);
        $this->assertSame(1, substr_count($body, 'calendly.com'));

        // the AI reads interest as a "no": a person decides instead of nobody answering
        $other = $this->lead('Tacobay');
        $this->emailReply($other, 'Yes please call me tomorrow', 'interested');
        $this->ai = [['action' => 'none', 'reply' => '', 'reason' => 'not interested']];
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('Left to a person: Tacobay (email): left to a person: AI thinks no answer is needed')->assertSuccessful();
        $this->assertSame(1, Task::where('lead_id', $other->id)->where('title', 'Answer now: LeadPilot left this reply to you')->count());
    }

    public function test_a_dry_run_says_why_the_fixed_answer_was_used(): void
    {
        $lead = $this->lead('Sidekick Labs');
        $this->emailReply($lead, 'What would it cost?', 'request_info');
        $this->ai = [['action' => 'reply', 'reply' => 'It costs about 2,000 for a full site.', 'reason' => 'price']];
        $this->artisan('leadpilot:auto-reply', ['--dry-run' => true])->expectsOutputToContain('template, AI answer rejected: a price-like number')->assertSuccessful();

        // seen live: "We'll email you the details" is a promise nobody keeps; only the call is offered
        $this->ai = [['action' => 'reply', 'reply' => "Thanks for letting us know. We\u{2019}ll\u{200B}email you the details. Pick a time: https://calendly.com/ismail/30min", 'reason' => 'ok']];
        $this->artisan('leadpilot:auto-reply', ['--dry-run' => true])->expectsOutputToContain('AI answer rejected: a promise to send something')->assertSuccessful();
        $this->assertNull(app(\App\Services\Sales\AutoReplier::class)->safe('We will send the proposal tomorrow.', $lead, '', 'email'));
        $this->assertSame("Glad to help. I'm happy to share more on a call.", app(\App\Services\Sales\AutoReplier::class)->safe("Glad to help.\u{00A0}I'm happy to share more on a call.", $lead, '', 'email'));
    }

    public function test_without_the_ai_the_safe_answer_still_goes_out(): void
    {
        $this->on();
        config(['leadpilot.keys.gemini' => null]);
        $lead = $this->lead('Tacobay');
        $this->emailReply($lead, 'Yes please, send me more details', 'request_info');

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        $this->assertStringContainsString('Happy to explain', $this->prospectMails()[0]->getOriginalMessage()->getTextBody());
    }

    public function test_a_whatsapp_reply_gets_one_answer_after_their_last_message_and_none_after_stop(): void
    {
        $this->on();
        $lead = $this->lead('Sinag Dental', 'replied');
        $msg = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'to_number' => '971501234567', 'number_source' => 'mobile_number',
            'body' => 'Hi, I looked at your website and made you a free report.', 'status' => 'sent', 'sent_at' => now()->subDay()]);
        $first = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '971501234567', 'body' => 'Hello', 'action' => 'reply']);
        $second = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '971501234567', 'body' => 'Can you make our site work on phones?', 'action' => 'reply']);
        WhatsappInboundMessage::query()->update(['created_at' => now()->subMinutes(15)]);
        $this->ai = [['action' => 'reply', 'reply' => 'Hi! Yes, making your site work well on phones is exactly what we do. Pick a time for a 15-minute call: https://calendly.com/ismail/30min', 'reason' => 'interested']];

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage') && str_contains($r->body(), 'Hello') && str_contains($r->body(), 'work on phones'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ezimk.com') && collect($r->data())->pluck('contents', 'name')->get('to') === '971501234567'
            && str_contains((string) collect($r->data())->pluck('contents', 'name')->get('message'), 'work well on phones'));
        $this->assertSame(1, WhatsappReply::where('status', 'sent')->count());
        $this->assertSame(['skipped', 'sent'], [AutoReply::where('source_id', $first->id)->value('status'), AutoReply::where('source_id', $second->id)->value('status')]);
        $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertSee('work well on phones');

        // they write STOP: nothing more, ever
        $this->travel(2)->hours();
        $stop = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '971501234567', 'body' => 'stop', 'action' => 'opt_out']);
        $again = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '971501234567', 'body' => 'why?', 'action' => 'reply']);
        WhatsappInboundMessage::whereIn('id', [$stop->id, $again->id])->update(['created_at' => now()->subMinutes(15)]);
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('they asked us to stop')->assertSuccessful();
        $this->assertSame(1, WhatsappReply::count());
    }

    public function test_an_answer_the_gateway_refuses_is_tried_again_then_left_to_a_person(): void
    {
        $this->on();
        $lead = $this->lead('New Crescent School');
        $msg = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'to_number' => '971501234567', 'number_source' => 'mobile_number',
            'body' => 'Hi, here is your website report.', 'status' => 'sent', 'sent_at' => now()->subDay()]);
        $in = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '971501234567', 'body' => 'Thanks, useful report', 'action' => 'reply']);
        $in->forceFill(['created_at' => now()->subMinutes(15)])->save();
        $answer = ['action' => 'reply', 'reply' => 'Glad it helps! Pick a time for a 15-minute call: https://calendly.com/ismail/30min', 'reason' => 'ok'];

        // seen live on 19 Nov: the gateway refused two answers at the same minute; they used to be dropped for good
        $this->gatewayDown = 1;
        $this->ai = [$answer];
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('Not sent, tried again in 20 minutes: New Crescent School')->assertSuccessful();
        $this->assertSame(['failed', 1], [AutoReply::value('status'), AutoReply::value('attempts')]);
        $this->artisan('leadpilot:auto-reply')->assertSuccessful();
        $this->assertSame(1, AutoReply::value('attempts'), 'not again within 20 minutes');

        $this->travel(25)->minutes();
        $this->ai = [$answer];
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('1 answered')->assertSuccessful();
        $this->assertSame(['sent', 2], [AutoReply::value('status'), AutoReply::value('attempts')]);
        $this->assertSame(1, WhatsappReply::where('status', 'sent')->count());

        // refused three times: a person answers instead
        $other = $this->lead('Al-Rowad School');
        $msg2 = WhatsappMessage::create(['lead_id' => $other->id, 'company_id' => $other->company_id, 'to_number' => '971509876543', 'number_source' => 'mobile_number',
            'body' => 'Hi', 'status' => 'sent', 'sent_at' => now()->subDay()]);
        $in2 = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg2->id, 'lead_id' => $other->id, 'from_number' => '971509876543', 'body' => 'Interested', 'action' => 'reply']);
        $in2->forceFill(['created_at' => now()->subMinutes(15)])->save();
        $this->gatewayDown = 3;
        foreach (range(1, 3) as $i) {
            $this->ai = [$answer];
            $this->artisan('leadpilot:auto-reply')->assertSuccessful();
            $this->travel(25)->minutes();
        }
        $row = AutoReply::where('source_id', $in2->id)->firstOrFail();
        $this->assertSame(['failed', 3], [$row->status, $row->attempts]);
        $this->assertSame(1, Task::where('lead_id', $other->id)->where('title', 'Answer now: LeadPilot left this reply to you')->count());
        $this->artisan('leadpilot:auto-reply')->assertSuccessful();
        $this->assertSame(3, $row->fresh()->attempts, 'never a fourth try');
    }

    public function test_a_contact_the_gateway_can_never_reach_goes_to_a_person_at_once(): void
    {
        $this->on();
        $lead = $this->lead('New Crescent School');
        $msg = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $lead->company_id, 'to_number' => '966534232751', 'number_source' => 'mobile_number',
            'body' => 'Hi, here is your website report.', 'status' => 'sent', 'sent_at' => now()->subDay()]);
        $in = WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '966534232751', 'body' => 'Thank you for your kind interest', 'action' => 'reply']);
        $in->forceFill(['created_at' => now()->subMinutes(15)])->save();
        $this->lidContact = true;
        $this->ai = [['action' => 'reply', 'reply' => 'Glad to hear from you! Pick a time for a 15-minute call: https://calendly.com/ismail/30min', 'reason' => 'ok']];

        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('Left to a person: New Crescent School (whatsapp): left to a person: the WhatsApp gateway cannot send to this contact')->assertSuccessful();
        $row = AutoReply::firstOrFail();
        $this->assertSame(['held', 1], [$row->status, $row->attempts], 'no retries that would only delay the answer');
        $task = Task::where('lead_id', $lead->id)->where('title', 'Answer now: LeadPilot left this reply to you')->firstOrFail();
        $this->assertStringContainsString('answer from the phone', $task->description);
        $this->assertStringContainsString('Glad to hear from you!', $task->description, 'the ready answer is in the task, to copy');
    }

    public function test_answers_stop_after_the_limit_and_once_a_meeting_is_booked(): void
    {
        $this->on();
        config(['leadpilot.auto_reply.max_per_lead' => 1]);
        $lead = $this->lead('Al-Rowad School');
        $this->emailReply($lead, 'Interested, tell me more', 'interested', 120);
        $this->ai = [['action' => 'reply', 'reply' => 'Thanks! Pick a time: https://calendly.com/ismail/30min', 'reason' => 'ok']];
        $this->artisan('leadpilot:auto-reply')->assertSuccessful();
        $this->assertCount(1, $this->prospectMails());

        $this->travel(1)->hours();
        InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'And the price?',
            'category' => 'request_info', 'received_at' => now()->subMinutes(5)]);
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('automatic answers used up for this lead')->assertSuccessful();
        $this->assertCount(1, $this->prospectMails());

        $booked = $this->lead('Booked Clinic', 'meeting_booked');
        $this->emailReply($booked, 'See you Thursday', 'interested');
        $this->artisan('leadpilot:auto-reply')->expectsOutputToContain('lead is meeting booked')->assertSuccessful();
        $this->assertCount(1, $this->prospectMails());
    }
}
