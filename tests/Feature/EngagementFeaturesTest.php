<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\NotificationDelivery;
use App\Models\OutreachSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Audit\WebsiteReportService;
use App\Services\LeadScoringService;
use App\Services\Sales\ConversionAssistantService;
use App\Services\Sales\ReplyClassifier;
use App\Services\Sales\ReplyOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Free report link in the first e-mail, instant alerts when a prospect engages, and info@ addressed to the owner. */
class EngagementFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'app.url' => 'https://hunter.example.com']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'phone' => '+92 300 1234567']);
        OutreachSetting::current()->update(['mode' => 'live']);
    }

    protected function clinic(string $name, array $statuses): Company
    {
        $slug = strtolower(str_replace(' ', '', $name));
        $c = Company::create(['name' => $name, 'website' => "https://{$slug}.example", 'domain' => "{$slug}.example", 'industry' => 'Dental clinic',
            'country' => 'Pakistan', 'city' => 'Lahore', 'google_review_count' => 20]);
        $checks = [];
        foreach ($statuses as $key => $status) {
            $checks[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'status' => $status, 'evidence' => "{$key} {$status}", 'evidence_keys' => $status === 'pass' ? [] : ["no_{$key}"]];
        }
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'checks' => $checks, 'opportunity_score' => 60,
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport meta tag.']]], 'audited_at' => now()->subDay()]);
        $c->update(['website_audited_at' => now()->subDay(), 'website_opportunity_score' => 60]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_gap', 'title' => 'No mobile viewport', 'evidence_text' => 'Homepage has no viewport meta.',
            'relevant_service' => 'web_redesign', 'confidence' => 85, 'observed_date' => now()->subDay(), 'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit', 'evidence_key' => 'no_viewport']]);

        return $c->fresh();
    }

    public function test_the_report_link_goes_in_the_email_and_a_real_view_alerts_the_salesperson(): void
    {
        config(['leadpilot.alerts.webhook_url' => 'https://hooks.example.com/leadpilot']);
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200), '*' => Http::response('', 404)]);
        $me = $this->clinic('Smile Dental', ['viewport' => 'fail', 'cta' => 'fail', 'https' => 'pass', 'title' => 'pass']);
        $this->clinic('Bright Teeth', ['viewport' => 'pass', 'cta' => 'pass', 'https' => 'pass', 'title' => 'pass']);
        $lead = Lead::create(['company_id' => $me->id, 'first_name' => 'Ayesha', 'last_name' => 'Khan', 'job_title' => 'Owner', 'email' => 'ayesha@smiledental.example',
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'country' => 'Pakistan', 'overall_score' => 75]);

        $report = app(WebsiteReportService::class)->generate($me, null, 0, false);
        $this->assertTrue($report->isShareable());
        $line = app(ConversionAssistantService::class)->reportLine($lead->fresh());
        $this->assertStringContainsString('comparing your website with 1 similar business in Lahore', (string) $line);
        $url = $report->fresh()->shareUrl();
        $this->assertStringContainsString($url, $line);
        $draft = app(ConversionAssistantService::class)->draftFirstEmail($lead->fresh(), false);
        $this->assertTrue($draft['ok'], json_encode($draft));
        $this->assertStringContainsString($url, $draft['draft']['body'], 'the link is in the first e-mail');

        $page = $this->get($url)->assertOk()->assertSee('Smile Dental')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('/seen', $page->getContent(), 'the page reports a real view with a script');

        // a mail security scanner opening the link is not a person
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Barracuda Sentinel scanner)'])->post($url . '/seen')->assertNoContent();
        $this->assertSame(0, $report->fresh()->view_count);
        $this->assertSame(0, Task::count());

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'])->post($url . '/seen')->assertNoContent();
        $this->assertSame(1, $report->fresh()->view_count);
        $this->assertSame('Opened their website report: follow up today', Task::where('lead_id', $lead->id)->value('title'));
        $this->assertSame('delivered', NotificationDelivery::where('channel', 'webhook')->value('status'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'hooks.example.com') && str_contains($r['text'], 'just opened their website report'));

        // a second view counts, but does not alert again
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->post($url . '/seen');
        $this->assertSame(2, $report->fresh()->view_count);
        $this->assertSame(1, NotificationDelivery::where('channel', 'webhook')->count());

        // rebuilding keeps the link that was already sent working, now showing the new report
        $token = $report->fresh()->share_token;
        $new = app(WebsiteReportService::class)->generate($me->fresh(), null, 0, false);
        $this->assertSame($token, $new->share_token);
        $this->assertSame(2, $new->view_count);
        $this->get($url)->assertOk();
    }

    public function test_a_reply_is_sent_to_the_salesperson_on_whatsapp_at_once(): void
    {
        config(['leadpilot.alerts.twilio' => ['sid' => 'AC123', 'token' => 'tok', 'from' => 'whatsapp:+14155238886']]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), '*' => Http::response('', 404)]);
        $lead = Lead::create(['full_name' => 'Omar Ali', 'first_name' => 'Omar', 'email' => 'omar@acme.example', 'contact_status' => 'identified']);
        $reply = fn (string $id) => InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'leads@eziline.test', 'subject' => 'Re: hi',
            'body_text' => 'Yes, can you share prices?', 'message_id' => $id, 'received_at' => now()]);

        $this->mock(ReplyClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(['category' => 'interested', 'confidence' => 95, 'reasoning' => 'yes', 'subtype' => null], ['category' => 'not_interested', 'confidence' => 95, 'reasoning' => 'no', 'subtype' => null]));
        app(ReplyOutcomeService::class)->handle($reply('m1'));

        $this->assertSame('accepted_by_provider', NotificationDelivery::where('channel', 'whatsapp')->value('status'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Accounts/AC123/Messages.json') && $r['To'] === 'whatsapp:+923001234567' && str_contains($r['Body'], 'Omar replied (interested)'));

        // a plain "no" is not worth interrupting anyone for
        app(ReplyOutcomeService::class)->handle($reply('m2'));
        $this->assertSame(1, NotificationDelivery::where('channel', 'whatsapp')->count());

        // a user without a phone in +country format is skipped, with the reason recorded
        $this->admin->update(['phone' => '0300-1234567']);
        $lead->update(['lifecycle_stage' => 'contacted']);
        $this->mock(ReplyClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(['category' => 'request_info', 'confidence' => 95, 'reasoning' => 'q', 'subtype' => null]));
        app(ReplyOutcomeService::class)->handle($reply('m3'));
        $this->assertSame('skipped', NotificationDelivery::where('channel', 'whatsapp')->latest('id')->value('status'));
    }

    public function test_a_shared_inbox_is_addressed_to_the_owner_named_on_the_website(): void
    {
        $co = $this->clinic('Care Dental', ['viewport' => 'fail', 'cta' => 'fail', 'https' => 'pass', 'title' => 'pass']);
        $lead = Lead::create(['company_id' => $co->id, 'full_name' => 'Care Dental — general inbox', 'email' => 'info@caredental.example', 'contact_status' => 'generic_inbox',
            'email_verification_status' => 'verified', 'country' => 'Pakistan', 'source_name' => 'OpenStreetMap']);
        $scoring = app(LeadScoringService::class);
        $scoring->evaluateLead($lead->fresh());
        $before = $lead->fresh()->overall_score;

        // from the audit already on file: the site names the owner but publishes no address for her
        $co->latestCompletedWebsiteAudit->update(['contacts_found' => ['people' => [
            ['name' => 'Dr. Sara Malik', 'first_name' => 'Sara', 'last_name' => 'Malik', 'role' => 'Owner', 'rank' => 100, 'email' => null],
            ['name' => 'Bilal Raza', 'first_name' => 'Bilal', 'last_name' => 'Raza', 'role' => 'Receptionist', 'rank' => 10, 'email' => null],
        ]]]);
        $this->artisan('leadpilot:address-inboxes')->expectsOutputToContain('Addressed 1 shared-inbox lead')->assertExitCode(0);

        $lead = $lead->fresh();
        $this->assertSame(['Dr. Sara Malik', 'Owner'], [$lead->attention_name, $lead->attention_role]);
        $this->assertSame('Dr Malik', $lead->attentionGreetingName());
        $this->assertGreaterThan($before, $lead->overall_score, 'knowing who reads it raises the score');
        $this->assertStringContainsString('Decision-maker known by name (Dr. Sara Malik, Owner)', (string) json_encode($lead->score_explanation));

        $draft = app(ConversionAssistantService::class)->draftFirstEmail($lead, false);
        $this->assertTrue($draft['ok'], json_encode($draft));
        $this->assertStringStartsWith('Hi Dr Malik,', $draft['draft']['body']);
        $this->assertStringStartsWith('For Dr. Sara Malik: ', $draft['draft']['subject']);
        $this->assertSame(0, Lead::where('email', 'like', 'sara%')->count(), 'her own address is never guessed');

        // a plain name greets by first name
        $other = Lead::create(['full_name' => 'X inbox', 'email' => 'info@x.example', 'contact_status' => 'generic_inbox', 'attention_name' => 'Imran Qureshi']);
        $this->assertSame('Imran', $other->attentionGreetingName());
        $this->assertSame(0, WebsiteAuditService::addressSharedInbox($co, [['name' => 'Someone Else', 'rank' => 100]]), 'an addressed lead keeps its person');
    }
}
