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
