<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NotificationDelivery;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Models\WhatsappMessage;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Outreach\WhatsappOutreach;
use App\Services\Sales\EngagementAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One WhatsApp per business through the EZIMK gateway, with the website report as a PDF; team alerts on WhatsApp. */
class WhatsappOutreachTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array', 'app.url' => 'https://hunter.example.com',
            'leadpilot.whatsapp.ezimk' => ['url' => 'https://ezimk.com/api/create-message', 'appkey' => 'app-test', 'authkey' => 'auth-test'],
            'leadpilot.whatsapp.outreach' => ['enabled' => true, 'daily_cap' => 10, 'per_run' => 3],
            'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        Http::fake(['ezimk.com/*' => Http::response(['message_status' => 'Success', 'data' => ['to' => '923001234567', 'status_code' => 200]], 200), '*' => Http::response('', 404)]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'phone' => '+923331112223']);
        OutreachSetting::current()->update(['mode' => 'live', 'allowed_countries' => ['Pakistan']]);
    }

    protected function clinic(string $name, array $statuses, ?string $phone = null, array $socials = []): Company
    {
        $slug = strtolower(str_replace(' ', '', $name));
        $c = Company::create(['name' => $name, 'website' => "https://{$slug}.example", 'domain' => "{$slug}.example", 'industry' => 'Dental clinic',
            'country' => 'Pakistan', 'city' => 'Lahore', 'google_review_count' => 20, 'public_phone' => $phone]);
        $checks = [];
        foreach ($statuses as $key => $status) {
            $checks[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'status' => $status, 'evidence' => "{$key} {$status}", 'evidence_keys' => $status === 'pass' ? [] : ["no_{$key}"]];
        }
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'checks' => $checks, 'opportunity_score' => 60,
            'contacts_found' => ['socials' => $socials], 'opportunities' => [], 'audited_at' => now()->subDay()]);
        $c->update(['website_audited_at' => now()->subDay(), 'website_opportunity_score' => 60]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_gap', 'title' => 'No mobile viewport', 'evidence_text' => 'Homepage has no viewport meta.',
            'relevant_service' => 'web_redesign', 'confidence' => 85, 'observed_date' => now()->subDay(), 'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit', 'evidence_key' => 'no_viewport']]);

        return $c->fresh();
    }

    protected function lead(Company $c, string $tier = 'B'): Lead
    {
        $l = Lead::create(['company_id' => $c->id, 'full_name' => "{$c->name} — general inbox", 'email' => 'info@' . $c->domain, 'contact_status' => 'generic_inbox',
            'email_verification_status' => 'verified', 'country' => 'Pakistan', 'overall_score' => 60, 'lifecycle_stage' => 'qualified']);
        DB::table('leads')->where('id', $l->id)->update(['win_tier' => $tier]);

        return $l->fresh();
    }

    protected function market(): array
    {
        $me = $this->clinic('Smile Dental', ['viewport' => 'fail', 'cta' => 'fail', 'https' => 'pass', 'title' => 'pass'], '0300 1234567');
        $this->clinic('Bright Teeth', ['viewport' => 'pass', 'cta' => 'pass', 'https' => 'pass', 'title' => 'pass']);

        return [$me, $this->lead($me)];
    }

    public function test_one_whatsapp_per_business_with_the_report_pdf(): void
    {
        [$me, $lead] = $this->market();
        $wa = app(WhatsappOutreach::class);

        $r = $wa->run();
        $this->assertSame(1, $r['sent'], json_encode($r));
        $msg = WhatsappMessage::firstOrFail();
        $this->assertSame(['923001234567', 'mobile_number', 'sent'], [$msg->to_number, $msg->number_source, $msg->status]);
        Http::assertSent(function ($req) {
            $f = collect($req->data())->pluck('contents', 'name');

            return str_contains($req->url(), 'ezimk.com/api/create-message') && $f['appkey'] === 'app-test' && $f['to'] === '923001234567'
                && str_ends_with($f['file'], '.pdf') && str_contains($f['message'], 'no viewport') && str_contains($f['message'], 'reply STOP')
                && str_contains($f['message'], 'comparing your website with 1 similar business in Lahore');
        });
        $this->assertNotNull($lead->fresh()->last_contacted_at);
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);

        // the attached PDF opens from the private link, and fetching it is not counted as a person viewing
        $this->get($msg->file_url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, $me->fresh()->latestWebsiteReport->view_count);

        // never a second message to the same business
        $this->assertSame(0, $wa->run()['sent']);
    }

    public function test_only_whatsapp_numbers_are_used_and_the_website_link_wins(): void
    {
        $landline = $this->clinic('Landline Dental', ['viewport' => 'fail', 'https' => 'pass'], '042 35761234');
        $linked = $this->clinic('Linked Dental', ['viewport' => 'fail', 'https' => 'pass'], '042 35769999', ['wa.me' => 'https://wa.me/923217654321']);
        $wa = app(WhatsappOutreach::class);

        $this->assertNull($wa->numberFor($this->lead($landline)), 'a landline cannot be on WhatsApp');
        $this->assertSame(['number' => '923217654321', 'source' => 'website_whatsapp_link'], $wa->numberFor($this->lead($linked)));
    }

    public function test_landlines_ahead_in_the_queue_do_not_hide_a_whatsapp_number_further_down(): void
    {
        [$me, $lead] = $this->market();
        // twelve higher-scored businesses that only list an office landline
        foreach (range(1, 12) as $i) {
            $l = $this->lead($this->clinic("Landline Clinic {$i}", ['viewport' => 'fail', 'https' => 'pass'], '042 3576' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)));
            $l->update(['overall_score' => 90]);
        }
        $r = app(WhatsappOutreach::class)->run(1);

        $this->assertSame(1, $r['sent'], json_encode($r));
        $this->assertSame($me->id, WhatsappMessage::first()->company_id);
        $this->assertSame(12, $r['funnel']['no_whatsapp_number']);
    }

    public function test_a_run_that_sends_nothing_says_why(): void
    {
        $this->lead($this->clinic('Landline Only', ['viewport' => 'fail', 'https' => 'pass'], '042 35761234'));

        $this->artisan('leadpilot:whatsapp-outreach', ['--limit' => 1])
            ->expectsOutputToContain('Why nothing was sent: checked 1, no whatsapp number 1')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_nothing_goes_out_when_off_in_dry_run_or_to_people_already_talking_to_us(): void
    {
        [, $lead] = $this->market();
        $wa = app(WhatsappOutreach::class);

        config(['leadpilot.whatsapp.outreach.enabled' => false]);
        $this->assertStringContainsString('WHATSAPP_OUTREACH=true', (string) $wa->run()['blocked']);
        config(['leadpilot.whatsapp.outreach.enabled' => true]);

        OutreachSetting::current()->update(['mode' => 'dry_run']);
        $this->assertNotNull($wa->run()['blocked']);
        OutreachSetting::current()->update(['mode' => 'live']);

        $lead->update(['lifecycle_stage' => 'interested']);
        $this->assertSame(0, $wa->run()['sent'], 'someone in a conversation gets answers, not cold messages');
        $lead->update(['lifecycle_stage' => 'qualified']);

        config(['leadpilot.whatsapp.outreach.daily_cap' => 0]);
        $this->assertStringContainsString('Daily WhatsApp cap', (string) $wa->run()['blocked']);
        Http::assertNothingSent();
    }

    public function test_approval_mode_waits_for_an_admin_then_sends(): void
    {
        $this->market();
        app(AutonomyPolicyService::class)->set('outreach.whatsapp', 'approval');

        $r = app(WhatsappOutreach::class)->run();
        $this->assertSame([0, 1], [$r['sent'], $r['queued_for_approval']]);
        Http::assertNothingSent();

        $req = ApprovalRequest::where('type', 'whatsapp_message')->firstOrFail();
        $done = app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $this->assertSame('executed', $done->status, (string) $done->execution_result);
        $this->assertSame('sent', WhatsappMessage::first()->status);
        Http::assertSentCount(1);
    }

    public function test_a_200_that_says_failed_is_not_counted_as_sent(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); // drop the "Success" fake from setUp
        Http::fake(['ezimk.com/*' => Http::response(['message_status' => 'Failed', 'message' => 'Number is not on WhatsApp'], 200)]);
        $res = app(\App\Services\Outreach\WhatsappGateway::class)->send('923001234567', 'hi');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('not on WhatsApp', $res['response']);
    }

    public function test_a_reply_on_whatsapp_stops_follow_ups_and_alerts_the_salesperson(): void
    {
        [, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();

        // a wrong token is not found, and nothing is recorded
        $this->post(str_replace(\App\Services\Outreach\WhatsappInbox::token(), 'wrong', $url), ['from' => '923001234567', 'message' => 'hi'])->assertNotFound();
        $this->get($url)->assertOk();

        // EZIMK-style nested JSON, number with the WhatsApp suffix
        $this->postJson($url, ['data' => ['id' => 'wamid-1', 'from' => '923001234567@s.whatsapp.net', 'fromMe' => false, 'message' => 'Yes please, what would the fixes cost?']])
            ->assertOk()->assertJson(['action' => 'reply']);
        $lead = $lead->fresh();
        $this->assertSame('replied', $lead->lifecycle_stage);
        $this->assertSame('Replied on WhatsApp: answer now', \App\Models\Task::where('lead_id', $lead->id)->value('title'));
        $this->actingAs($this->admin)->get(route('leads.show', $lead))->assertOk()->assertSee('Replied on WhatsApp')->assertSee('what would the fixes cost');
        Http::assertSent(fn ($req) => collect($req->data())->pluck('contents', 'name')->get('to') === '923331112223'
            && str_contains((string) collect($req->data())->pluck('contents', 'name')->get('message'), 'replied on WhatsApp'));

        // the gateway retrying the same message changes nothing
        $this->postJson($url, ['data' => ['id' => 'wamid-1', 'from' => '923001234567', 'message' => 'Yes please, what would the fixes cost?']]);
        $this->assertSame(1, \App\Models\WhatsappInboundMessage::count());
        // a field shared by every message (the device's id) is not mistaken for the message id
        $this->post($url, ['device' => ['id' => 'dev-1'], 'number' => '923339990000', 'from' => '923001234567', 'message' => 'Also, are you in Lahore?']);
        $this->post($url, ['device' => ['id' => 'dev-1'], 'number' => '923339990000', 'from' => '923001234567', 'message' => 'Call me after 5']);
        $this->assertSame(3, \App\Models\WhatsappInboundMessage::where('action', 'reply')->count());

        // a number we never messaged, or our own outgoing echo, is logged but not acted on
        $this->post($url, ['from' => '923119998887', 'message' => 'who is this?'])->assertJson(['action' => 'unmatched']);
        $this->postJson($url, ['data' => ['from' => '923001234567', 'fromMe' => true, 'message' => 'stop']])->assertJson(['action' => 'ignored']);
        $this->assertSame('replied', $lead->fresh()->lifecycle_stage);
    }

    public function test_stop_on_whatsapp_suppresses_the_business_everywhere(): void
    {
        [$me, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();

        $this->post(\App\Services\Outreach\WhatsappInbox::webhookUrl(), ['from' => '+92 300 1234567', 'message' => 'STOP'])->assertJson(['action' => 'opt_out']);

        $this->assertSame('opted_out', $lead->fresh()->lifecycle_stage);
        $this->assertTrue(\App\Models\Suppression::isSuppressed($lead->email));
        $this->assertTrue(\App\Services\Outreach\WhatsappInbox::optedOut('923001234567'));
        foreach (['stop', 'Please unsubscribe me', 'band karo', 'Remove me from your list'] as $t) {
            $this->assertTrue(\App\Services\Outreach\WhatsappInbox::isOptOut($t), $t);
        }
        foreach (['Yes, call me tomorrow', '', 'What does it cost?'] as $t) {
            $this->assertFalse(\App\Services\Outreach\WhatsappInbox::isOptOut($t), $t);
        }
    }

    public function test_an_automatic_whatsapp_business_greeting_is_not_a_reply(): void
    {
        [, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();
        $alerts = fn () => collect(Http::recorded())->filter(fn ($p) => collect($p[0]->data())->pluck('contents', 'name')->get('to') === '923331112223')->count();

        foreach (['Thank you for contacting Smile Dental! Please let us know how we can help you.',
            'أهلًا بك في مدارس بواكير الأهلية 🌷 للرد استفساركم تفضل بإرسال كلمة: ابدأ',
            'Dear Parents, Thank you for your interest in Capital School.'] as $greeting) {
            $this->post($url, ['from' => '923001234567', 'message' => $greeting])->assertJson(['action' => 'auto_reply']);
        }
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage, 'a machine answering is not a conversation');
        $this->assertSame(0, \App\Models\Task::where('lead_id', $lead->id)->count());
        $this->assertSame(0, $alerts());

        // a greeting that says where enquiries go (as Alton Fencing's did) is still a greeting, but the address it gives is followed up
        $this->post($url, ['from' => '923001234567', 'message' => 'Thank you for contact :*Smile Dental# Please let us know how can I help you ? Any inq. Pls fwd - enquiries@smiledental.example, 0321 7654321'])
            ->assertJson(['action' => 'auto_reply']);
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);
        $this->assertSame(0, $alerts());
        $this->assertNotNull(Lead::where('email', 'enquiries@smiledental.example')->value('last_contacted_at'), 'the enquiries address got the e-mail');
        $this->assertSame('923217654321', WhatsappMessage::where('number_source', 'given_in_reply')->value('to_number'));

        // a person who writes back after the greeting is a reply; an address to write to always is
        $this->post($url, ['from' => '923001234567', 'message' => 'j.altaher@ais.sch.sa'])->assertJson(['action' => 'reply']);
        $this->assertSame('replied', $lead->fresh()->lifecycle_stage);
        $this->assertSame(1, $alerts());
        foreach (['Thank you for your kind interest', 'Who is this?', 'Please call me at 0300 1234567'] as $human) {
            $this->assertFalse(\App\Services\Outreach\WhatsappInbox::isAutoReply($human), $human);
        }
    }

    public function test_greetings_counted_as_replies_before_the_fix_are_put_back(): void
    {
        [$me, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $sent = WhatsappMessage::firstOrFail();
        // as stored by the earlier version: a greeting recorded as a reply, the lead moved on, a task made
        \App\Models\WhatsappInboundMessage::create(['from_number' => '923001234567', 'body' => 'Thank you for contacting Smile Dental! Please let us know how we can help you.',
            'lead_id' => $lead->id, 'whatsapp_message_id' => $sent->id, 'action' => 'reply']);
        $lead->moveStage('replied');
        \App\Models\Task::create(['lead_id' => $lead->id, 'company_id' => $me->id, 'title' => 'Replied on WhatsApp: answer now', 'status' => 'pending', 'assigned_to' => $this->admin->id, 'priority' => 'high', 'due_date' => now()->toDateString()]);

        $this->artisan('leadpilot:whatsapp-reclassify', ['--dry-run' => true])->expectsOutputToContain('Would re-mark 1 message')->assertExitCode(0);
        $this->assertSame('replied', $lead->fresh()->lifecycle_stage);

        $this->artisan('leadpilot:whatsapp-reclassify')->expectsOutputToContain('Re-marked 1 message')->assertExitCode(0);
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);
        $this->assertSame('auto_reply', \App\Models\WhatsappInboundMessage::first()->action);
        $this->assertSame(0, \App\Models\Task::where('lead_id', $lead->id)->count());

        $wa = app(\App\Console\Commands\SalesReportCommand::class)->build(7, app(\App\Services\Outreach\WeeklyReview::class))['whatsapp'];
        $this->assertSame([1, 0, 1, 0], [$wa['sent']['all'], $wa['businesses_replied']['all'], $wa['auto_greetings']['all'], $wa['asked_to_stop']['all']]);
    }

    public function test_an_address_or_number_given_in_a_reply_is_contacted_at_once_and_only_once(): void
    {
        [$me, $lead] = $this->market();
        app(\App\Services\Audit\WebsiteReportService::class)->generate($me, null, 0, false);
        app(WhatsappOutreach::class)->run();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();
        $mails = fn () => collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all())
            ->filter(fn ($m) => collect($m->getOriginalMessage()->getTo())->contains(fn ($a) => $a->getAddress() === 'j.altaher@ais.sch.sa'))->values();

        $this->post($url, ['from' => '923001234567', 'message' => 'Please write to J.Altaher@ais.sch.sa or WhatsApp our manager 0321 7654321'])->assertJson(['action' => 'reply']);

        // the e-mail: sent to the address they gave, with the observation and the report, and logged so its reply is matched
        $this->assertCount(1, $mails());
        $sentMail = $mails()->first()->getOriginalMessage();
        $this->assertStringContainsString('as discussed on WhatsApp', $sentMail->getSubject());
        $this->assertStringContainsString('where we were given this address', $sentMail->getTextBody());
        $this->assertStringContainsString('/r/', $sentMail->getTextBody());
        $ref = Lead::where('email', 'j.altaher@ais.sch.sa')->firstOrFail();
        $this->assertSame([$me->id, 'replied', 'Given on WhatsApp'], [$ref->company_id, $ref->lifecycle_stage, $ref->source_name]);
        $this->assertNotNull($ref->last_contacted_at);
        $this->assertSame(1, \App\Models\EmailLog::where('recipient_email', 'j.altaher@ais.sch.sa')->whereNotNull('message_id')->count());

        // the number: one WhatsApp with the report PDF
        $wa = WhatsappMessage::where('number_source', 'given_in_reply')->firstOrFail();
        $this->assertSame(['923217654321', 'sent'], [$wa->to_number, $wa->status]);
        $this->assertStringEndsWith('.pdf', (string) $wa->file_url);
        $this->assertStringContainsString('shared this number with us on WhatsApp', $wa->body);

        // the salesperson's alert says what was already done
        Http::assertSent(fn ($req) => collect($req->data())->pluck('contents', 'name')->get('to') === '923331112223'
            && str_contains((string) collect($req->data())->pluck('contents', 'name')->get('message'), 'already followed up'));

        // writing the same address again does not send a second e-mail or WhatsApp
        $this->post($url, ['from' => '923001234567', 'message' => 'j.altaher@ais.sch.sa / 0321 7654321']);
        $this->assertCount(1, $mails());
        $this->assertSame(1, WhatsappMessage::where('number_source', 'given_in_reply')->count());
    }

    public function test_an_address_in_a_message_marked_as_a_real_reply_later_is_written_to(): void
    {
        [$me, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $msg = WhatsappMessage::firstOrFail();
        // seen live (Sinag Dental, 10 Nov): stored as a wrong number, later marked as a reply by a person
        $in = \App\Models\WhatsappInboundMessage::create(['whatsapp_message_id' => $msg->id, 'lead_id' => $lead->id, 'from_number' => '923001234567', 'action' => 'wrong_number',
            'body' => 'This clinic number is only reserved for clinic patients. For communications with our management, please send an email to sinagdental@gmail.com... Thank you']);
        $to = fn () => collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all())
            ->filter(fn ($m) => collect($m->getOriginalMessage()->getTo())->contains(fn ($a) => $a->getAddress() === 'sinagdental@gmail.com'))->values();
        $this->assertCount(0, $to());

        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'reply'])->assertRedirect()
            ->assertSessionHas('success', fn ($t) => str_contains($t, 'sinagdental@gmail.com: e-mailed with the report.'));
        $this->assertCount(1, $to());

        // the lead's own address, already reached on WhatsApp but never by e-mail, is still e-mailed (seen live: Sinag Dental)
        $other = $this->lead($this->clinic('Own Address Dental', ['viewport' => 'fail', 'https' => 'pass'], '0300 7654321'));
        $other->update(['email' => 'owner.dental@gmail.com', 'last_contacted_at' => now()->subWeek()]);
        $msg2 = WhatsappMessage::create(['lead_id' => $other->id, 'company_id' => $other->company_id, 'to_number' => '923007654321', 'number_source' => 'mobile_number', 'body' => 'Hi', 'status' => 'sent']);
        $in2 = \App\Models\WhatsappInboundMessage::create(['whatsapp_message_id' => $msg2->id, 'lead_id' => $other->id, 'from_number' => '923007654321', 'action' => 'reply',
            'body' => 'Please e-mail owner.dental@gmail.com']);
        $this->assertSame(['owner.dental@gmail.com: e-mailed with the report.'], app(\App\Services\Outreach\WhatsappReferral::class)->handle($in2, $other->fresh()));
        $this->assertSame(['owner.dental@gmail.com: already e-mailed before, not e-mailed again.'], app(\App\Services\Outreach\WhatsappReferral::class)->handle($in2, $other->fresh()));

        // marking it again (after an undo) does not e-mail twice
        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'auto_reply']);
        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'reply']);
        $this->assertCount(1, $to());
    }

    public function test_a_given_contact_is_left_to_people_when_policy_says_so_and_landlines_are_never_messaged(): void
    {
        $this->market();
        app(WhatsappOutreach::class)->run();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();

        $this->post($url, ['from' => '923001234567', 'message' => 'Call our office 042 35761234']);
        $this->assertSame(0, WhatsappMessage::where('number_source', 'given_in_reply')->count(), 'a landline is not on WhatsApp');

        app(AutonomyPolicyService::class)->set('reply.referral_contact', 'approval');
        $this->post($url, ['from' => '923001234567', 'message' => 'mail us at office@smiledental.example']);
        $this->assertSame(0, Lead::where('email', 'office@smiledental.example')->count());
        $this->assertSame(['emails' => ['a@b.co'], 'phones' => ['+92 300 1234567']], \App\Services\Outreach\WhatsappReferral::extract('write a@b.co. or +92 300 1234567'));

        // replies stored before this existed are followed up by the command, once
        app(AutonomyPolicyService::class)->set('reply.referral_contact', 'automatic');
        $this->artisan('leadpilot:whatsapp-referrals', ['--dry-run' => true])->expectsOutputToContain('office@smiledental.example: would be e-mailed with the report')->assertExitCode(0);
        $this->assertSame(0, Lead::where('email', 'office@smiledental.example')->count());
        $this->artisan('leadpilot:whatsapp-referrals')->expectsOutputToContain('office@smiledental.example: e-mailed')->assertExitCode(0);
        $this->artisan('leadpilot:whatsapp-referrals')->expectsOutputToContain('already e-mailed before')->assertExitCode(0);
    }

    public function test_away_messages_adverts_and_wrong_numbers_are_not_counted_as_replies(): void
    {
        [, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();

        // as seen on the server on 6 and 9 Nov
        $this->post($url, ['from' => '923001234567', 'message' => 'We’re unavailable right now, but will respond as soon as possible. Please contact between 12:00pm till 8pm'])->assertJson(['action' => 'auto_reply']);
        $this->post($url, ['from' => '923001234567', 'message' => '🌟STATE LIFE TAYYAB TAKAFUL ISLAMIC PLAN🌟 📌 Life Mein Backup Plan Zaroori Hai. 📌 Monthly saving plan with profit and life cover for the whole family'])->assertJson(['action' => 'auto_reply']);
        // the business's own sales script asking us for our details
        $this->post($url, ['from' => '923001234567', 'message' => 'Parsnol Information Sir. Good Name Contact Number Date of Birth Children Ages City Name Yearly Saving'])->assertJson(['action' => 'auto_reply']);
        $this->post($url, ['from' => '923001234567', 'message' => 'Sorry sir this number change'])->assertJson(['action' => 'wrong_number']);
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);
        $this->assertSame(0, \App\Models\Task::where('lead_id', $lead->id)->count());

        $wa = app(\App\Console\Commands\SalesReportCommand::class)->build(7, app(\App\Services\Outreach\WeeklyReview::class))['whatsapp'];
        $this->assertSame([0, 3, 1], [$wa['businesses_replied']['all'], $wa['auto_greetings']['all'], $wa['wrong_number']['all']]);

        // a new number given with the change is a reply, so the new number is followed up
        $this->assertFalse(\App\Services\Outreach\WhatsappInbox::isWrongNumber('Our number has changed, please contact 0321 1112223'));
        foreach (['Please share your name and contact number', 'Which city are you in? What would it cost?'] as $human) {
            $this->assertFalse(\App\Services\Outreach\WhatsappInbox::isAutoReply($human), $human);
        }
    }

    public function test_stored_away_messages_and_wrong_numbers_are_put_back(): void
    {
        [$me, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $sent = WhatsappMessage::firstOrFail();
        foreach (['We’re unavailable right now, but will respond as soon as possible.', 'Parsnol Information Sir. Good Name Contact Number Date of Birth Children Ages City Name Yearly Saving'] as $i => $body) {
            \App\Models\WhatsappInboundMessage::create(['from_number' => '923001234567', 'body' => $body, 'lead_id' => $lead->id, 'whatsapp_message_id' => $sent->id, 'action' => 'reply']);
        }
        $lead->moveStage('replied');

        $this->artisan('leadpilot:whatsapp-reclassify')->expectsOutputToContain('Re-marked 2 message')->assertExitCode(0);
        $this->assertSame(['auto_reply', 'auto_reply'], \App\Models\WhatsappInboundMessage::orderBy('id')->pluck('action')->all());
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);
    }

    public function test_the_whatsapp_screen_lists_conversations_and_a_person_can_correct_a_label(): void
    {
        [$me, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        $msg = WhatsappMessage::firstOrFail();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();
        $this->post($url, ['from' => '923001234567', 'message' => 'Thank you for contacting Smile Dental! Please let us know how we can help you.']);
        $this->post($url, ['from' => '923119998887', 'message' => 'Happy birthday Sabika']);

        $this->actingAs($this->admin)->get(route('whatsapp.index'))->assertOk()->assertSee('Smile Dental')->assertSee('Automatic')->assertSee('Real replies');
        $this->actingAs($this->admin)->get(route('whatsapp.index', ['tab' => 'replies']))->assertOk()->assertDontSee('Smile Dental');
        $this->actingAs($this->admin)->get(route('whatsapp.index', ['tab' => 'automatic']))->assertOk()->assertSee('Smile Dental');
        // a personal chat on the team's number is not stored (seen live: birthday wishes under "Other numbers")
        $this->assertSame(0, \App\Models\WhatsappInboundMessage::where('body', 'like', '%birthday%')->count());
        $this->actingAs($this->admin)->get(route('whatsapp.index', ['tab' => 'other']))->assertOk()->assertDontSee('Happy birthday')->assertSee('no longer stored');
        $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertOk()
            ->assertSee('no viewport')->assertSee('Website &amp; competitor report (PDF)', false)->assertSee('Mark as real reply')->assertSee('wa.me/923001234567');
        $this->actingAs($this->admin)->get(route('leads.show', $lead))->assertOk()->assertSee(route('whatsapp.show', $msg));

        // the filter took a person for a machine: one click puts it right
        $in = \App\Models\WhatsappInboundMessage::where('action', 'auto_reply')->firstOrFail();
        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'reply'])->assertRedirect();
        $this->assertSame(['reply', 'replied'], [$in->fresh()->action, $lead->fresh()->lifecycle_stage]);
        $this->assertSame(1, \App\Models\Task::where('lead_id', $lead->id)->where('title', 'Replied on WhatsApp: answer now')->count());
        $this->actingAs($this->admin)->get(route('whatsapp.index', ['tab' => 'replies']))->assertSee('Smile Dental');

        // and back again
        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'auto_reply'])->assertRedirect();
        $this->assertSame(['auto_reply', 'contacted'], [$in->fresh()->action, $lead->fresh()->lifecycle_stage]);
        $this->assertSame(0, \App\Models\Task::where('lead_id', $lead->id)->count());
        $this->actingAs($this->admin)->post(route('whatsapp.label', $in), ['action' => 'opt_out'])->assertSessionHasErrors('action');

        // a salesperson sees only the conversations of leads they may open
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $lead->update(['assigned_user_id' => $this->admin->id]);
        $this->actingAs($other)->get(route('whatsapp.show', $msg))->assertForbidden();
        $this->actingAs($other)->get(route('whatsapp.index'))->assertOk()->assertDontSee('Smile Dental');
        $this->actingAs($other)->post(route('whatsapp.label', $in), ['action' => 'reply'])->assertForbidden();
    }

    public function test_a_salesperson_answers_a_whatsapp_reply_from_the_screen_with_a_ready_answer(): void
    {
        [$me, $lead] = $this->market();
        config(['leadpilot.agency.booking_url' => 'https://cal.example/ismail']);
        app(WhatsappOutreach::class)->run();
        $msg = WhatsappMessage::firstOrFail();
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();

        // no answer box before they reply: only people who wrote to us are answered from here
        $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertOk()->assertDontSee('Send on WhatsApp');
        $this->actingAs($this->admin)->post(route('whatsapp.answer', $msg), ['body' => 'Hello there'])->assertRedirect();
        $this->assertSame(0, \App\Models\WhatsappReply::count());

        $this->post($url, ['from' => '923001234567', 'message' => 'How much would the fixes cost?'])->assertJson(['action' => 'reply']);
        $this->assertSame(1, \App\Models\Task::where('lead_id', $lead->id)->where('status', 'pending')->count());

        // the ready answer fits what they asked and offers the booking link
        $page = $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertOk()->assertSee('Send on WhatsApp');
        $page->assertSee('The price depends on what Smile Dental needs', false)->assertSee('https://cal.example/ismail');

        // the salesperson edits and sends it: it goes to their number, the task is done, and it shows in the thread
        $body = "Hi, thanks! A redesign starts with a short call.\n\nPick a time: https://cal.example/ismail";
        $this->actingAs($this->admin)->post(route('whatsapp.answer', $msg), ['body' => $body])->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn ($req) => collect($req->data())->pluck('contents', 'name')->get('to') === '923001234567'
            && collect($req->data())->pluck('contents', 'name')->get('message') === $body);
        $answer = \App\Models\WhatsappReply::firstOrFail();
        $this->assertSame(['sent', $this->admin->id, $lead->id], [$answer->status, $answer->user_id, $answer->lead_id]);
        $this->assertSame('completed', \App\Models\Task::where('lead_id', $lead->id)->value('status'));
        $this->artisan('leadpilot:waiting-digest', ['--dry-run' => true])->expectsOutputToContain('Nobody is waiting');
        $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertSee('A redesign starts with a short call.');

        // a double click does not send it twice, and the outreach caps do not count our answers
        $this->actingAs($this->admin)->post(route('whatsapp.answer', $msg), ['body' => $body])->assertSessionHas('error');
        $this->assertSame(1, \App\Models\WhatsappReply::count());
        $this->assertSame(1, app(WhatsappOutreach::class)->sentToday());

        // someone who may not open the lead cannot answer for it
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $lead->update(['assigned_user_id' => $this->admin->id]);
        $this->actingAs($other)->post(route('whatsapp.answer', $msg), ['body' => 'Hi'])->assertForbidden();

        // after STOP, nothing more goes out
        $this->post($url, ['from' => '923001234567', 'message' => 'STOP']);
        $this->actingAs($this->admin)->get(route('whatsapp.show', $msg))->assertSee('They asked us to stop')->assertDontSee('Send on WhatsApp');
        $this->actingAs($this->admin)->post(route('whatsapp.answer', $msg), ['body' => 'One more thing'])->assertSessionHas('error');
        $this->assertSame(1, \App\Models\WhatsappReply::count());
    }

    public function test_reliability_fixes_for_whatsapp(): void
    {
        [$me, $lead] = $this->market();
        // a second listing of the same business, same mobile: it must not get a second message
        $twin = $this->clinic('Smile Dental Twin', ['viewport' => 'fail', 'https' => 'pass'], '0300 1234567');
        $this->lead($twin);
        $r = app(WhatsappOutreach::class)->run();
        $this->assertSame(1, WhatsappMessage::where('to_number', '923001234567')->count(), json_encode($r));
        $url = \App\Services\Outreach\WhatsappInbox::webhookUrl();

        // EZIMK's copy of a message we sent is ours, not a prospect's
        $this->postJson($url, ['payload' => ['type' => 'MESSAGE_SENT', 'data' => ['from' => '923311111174', 'to' => '923001234567', 'message' => 'Hi, this is Ismail']], 'sender' => '923311111174'])
            ->assertJson(['action' => 'ignored']);
        // "don't stop" is not a stop request; a greeting phrase inside a real answer is a reply
        $this->assertFalse(\App\Services\Outreach\WhatsappInbox::isOptOut("Don't stop, send me more"));
        $this->post($url, ['from' => '923001234567', 'message' => 'Thank you for reaching out, yes we need a new website. Call me tomorrow'])->assertJson(['action' => 'reply']);
        $this->assertSame('replied', $lead->fresh()->lifecycle_stage);
    }

    public function test_a_whatsapp_lost_to_a_gateway_fault_is_retried_once(): void
    {
        $this->market();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['ezimk.com/*' => Http::sequence()->push(['error' => 'Request Failed'], 200)->push(['message_status' => 'Success'], 200)->push(['message_status' => 'Success'], 200)]);
        app(WhatsappOutreach::class)->run();
        $msg = WhatsappMessage::firstOrFail();
        $this->assertSame('failed', $msg->status);

        $this->artisan('leadpilot:retry-failed')->expectsOutputToContain('sent on retry')->assertSuccessful();
        $this->assertSame(['sent', 2], [$msg->fresh()->status, $msg->fresh()->attempts]);
        $this->artisan('leadpilot:retry-failed')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_numbers_in_the_wrong_format_are_put_right_or_skipped(): void
    {
        $P = \App\Support\Phone::class;
        // the live failures of 18 Nov: a local number in a wa.me link, a US link without its 1, a typo, a number too long
        $this->assertSame('923377775660', $P::fromWhatsappLink('03377775660', 'Pakistan'));
        $this->assertSame('15168002238', $P::fromWhatsappLink('5168002238', 'United States'));
        $this->assertSame('923217654321', $P::fromWhatsappLink('923217654321', 'Pakistan'));
        $this->assertSame('971501234567', $P::fromWhatsappLink('00971501234567', 'United Arab Emirates'));
        $this->assertNull($P::fromWhatsappLink('97180021776789', 'United Arab Emirates'));
        $this->assertFalse($P::plausible('97180021776789'));
        $this->assertFalse($P::inCountry('9966559353338', 'Saudi Arabia'));
        $this->assertTrue($P::plausible('966505309999'));

        $local = $this->clinic('Local Link Dental', ['viewport' => 'fail', 'https' => 'pass'], null, ['wa.me' => 'https://wa.me/03001387788']);
        $typo = $this->clinic('Typo Cafe', ['viewport' => 'fail', 'https' => 'pass'], null, ['wa.me' => 'https://wa.me/9966559353338']);
        $typo->update(['country' => 'Saudi Arabia']);
        $wa = app(WhatsappOutreach::class);
        $this->assertSame(['number' => '923001387788', 'source' => 'website_whatsapp_link'], $wa->numberFor($this->lead($local)));
        $l = $this->lead($typo);
        $l->update(['country' => 'Saudi Arabia', 'phone' => '+9966559353338']);
        $this->assertNull($wa->numberFor($l->fresh()), 'a number from another country is a typo, not a WhatsApp');
    }

    public function test_a_whatsapp_refused_for_its_number_format_is_resent_to_the_corrected_number(): void
    {
        $c = $this->clinic('Paramount Associates', ['viewport' => 'fail', 'https' => 'pass'], null, ['wa.me' => 'https://wa.me/03377775660']);
        $lead = $this->lead($c);
        $bad = WhatsappMessage::create(['lead_id' => $lead->id, 'company_id' => $c->id, 'to_number' => '03377775660', 'number_source' => 'website_whatsapp_link',
            'body' => 'Hi', 'status' => 'failed', 'attempts' => 2, 'provider_response' => '{"error":"Request Failed"}']);
        $bad->forceFill(['created_at' => now()->subDays(14)])->save();
        $junk = $this->clinic('A1 Properties', ['viewport' => 'fail', 'https' => 'pass'], null, ['wa.me' => 'https://wa.me/97180021776789']);
        $junkMsg = WhatsappMessage::create(['lead_id' => $this->lead($junk)->id, 'company_id' => $junk->id, 'to_number' => '97180021776789', 'number_source' => 'website_whatsapp_link',
            'body' => 'Hi', 'status' => 'failed', 'attempts' => 1, 'provider_response' => '{"error":"Request Failed"}']);

        // a number that cannot be made valid is left alone; the older message waits for --days
        $this->artisan('leadpilot:retry-failed')->expectsOutputToContain('not retried: no valid WhatsApp number')->assertSuccessful();
        Http::assertNothingSent();
        $this->artisan('leadpilot:retry-failed', ['--days' => 30])->expectsOutputToContain('sent on retry')->assertSuccessful();
        Http::assertSent(fn ($req) => collect($req->data())->pluck('contents', 'name')->get('to') === '923377775660');
        $this->assertSame(['sent', '923377775660', 3], [$bad->fresh()->status, $bad->fresh()->to_number, $bad->fresh()->attempts]);
        $this->assertSame('failed', $junkMsg->fresh()->status);

        // nothing more on the next run
        $this->artisan('leadpilot:retry-failed', ['--days' => 30])->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_an_approved_whatsapp_is_not_sent_to_someone_who_answered_meanwhile(): void
    {
        [, $lead] = $this->market();
        app(AutonomyPolicyService::class)->set('outreach.whatsapp', 'approval');
        app(WhatsappOutreach::class)->run();
        $lead->moveStage('replied'); // they answered our e-mail while the WhatsApp waited for approval

        $req = ApprovalRequest::where('type', 'whatsapp_message')->firstOrFail();
        $done = app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $this->assertSame('failed', $done->status);
        $this->assertSame('rejected', WhatsappMessage::first()->status);
        Http::assertNothingSent();
    }

    public function test_a_privacy_id_chat_is_read_by_its_phone_number_and_personal_chats_can_be_forgotten(): void
    {
        $inbox = app(\App\Services\Outreach\WhatsappInbox::class);
        // seen live (New Crescent, 19 Nov): the chat uses a WhatsApp privacy id, the number is in remoteJidAlt
        $lid = ['payload' => ['type' => 'MESSAGES_UPSERT', 'data' => [['key' => ['remoteJid' => '254674632474758@lid', 'remoteJidAlt' => '966534232751@s.whatsapp.net',
            'fromMe' => false, 'id' => 'ACE8', 'addressingMode' => 'lid'], 'message' => ['conversation' => 'Thank you for your kind interest']]]]];
        $this->assertSame('966534232751', $inbox->parse($lid)['from']);
        // a privacy id alone is never taken for a phone number
        unset($lid['payload']['data'][0]['key']['remoteJidAlt']);
        $this->assertNull($inbox->parse($lid)['from']);

        // rows stored before personal chats were left out are removed; conversations LeadPilot started are kept
        [, $lead] = $this->market();
        app(WhatsappOutreach::class)->run();
        \App\Models\WhatsappInboundMessage::create(['from_number' => '923215205714', 'body' => 'Happy birthday', 'action' => 'unmatched']);
        \App\Models\WhatsappInboundMessage::create(['from_number' => '923311111174', 'body' => 'see you tonight', 'action' => 'ignored']);
        $this->post(\App\Services\Outreach\WhatsappInbox::webhookUrl(), ['from' => '923001234567', 'message' => 'Yes, interested']);
        $this->artisan('leadpilot:whatsapp-forget-others', ['--dry-run' => true])->expectsOutputToContain('2 message(s) from other numbers would be removed')->assertSuccessful();
        $this->artisan('leadpilot:whatsapp-forget-others')->expectsOutputToContain('Removed 2')->assertSuccessful();
        $this->assertSame(['reply'], \App\Models\WhatsappInboundMessage::pluck('action')->all());
    }

    public function test_the_webhook_url_command_prints_the_url_to_paste_into_ezimk(): void
    {
        $this->artisan('leadpilot:whatsapp-webhook-url')->expectsOutputToContain('/webhooks/whatsapp/' . \App\Services\Outreach\WhatsappInbox::token())->assertExitCode(0);
    }

    public function test_team_alerts_go_to_whatsapp_through_the_same_gateway(): void
    {
        [$me, $lead] = $this->market();
        $report = app(\App\Services\Audit\WebsiteReportService::class)->generate($me, null, 0, false);
        $report->shareUrl();

        app(EngagementAlerts::class)->reportViewed($report->fresh());

        $this->assertSame('accepted_by_provider', NotificationDelivery::where('channel', 'whatsapp')->value('status'));
        Http::assertSent(fn ($req) => collect($req->data())->pluck('contents', 'name')->get('to') === '923331112223');
    }
}
