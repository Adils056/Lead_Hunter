<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Sales\ConversionAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner's list, 30 Oct 2026: short factual emails with one clear CTA, installable app (PWA), country filters. */
class OwnerRequestsP2Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        OutreachSetting::current()->update(['approved_services' => array_keys(config('leadpilot.services'))]);
    }

    protected function auditedLead(): Lead
    {
        $c = Company::create(['name' => 'Smile Dental', 'website' => 'https://smiledental.example', 'domain' => 'smiledental.example', 'country' => 'United Kingdom']);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'opportunity_score' => 40,
            'checks' => [['key' => 'viewport', 'label' => 'Viewport', 'status' => 'fail', 'evidence' => 'No viewport', 'evidence_keys' => ['no_viewport']]],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport']]], 'audited_at' => now()]);

        return Lead::create(['company_id' => $c->id, 'full_name' => 'x', 'email' => 'info@smiledental.example', 'contact_status' => 'generic_inbox', 'country' => 'United Kingdom']);
    }

    public function test_every_email_ends_with_the_owners_call_to_action_and_carries_the_phone(): void
    {
        $lead = $this->auditedLead();
        $svc = app(ConversionAssistantService::class);

        $default = $svc->draftFirstEmail($lead->fresh(), false)['draft']['body'];
        $this->assertStringContainsString('Want me to send the 3 fixes I would make first?', $default);

        $this->actingAs($this->admin)->get(route('settings.outreach-automation'))->assertOk()->assertSee('Call to action');
        $s = OutreachSetting::current();
        $s->update(['cta_type' => 'meeting', 'booking_url' => 'https://cal.com/eziline/15min', 'contact_phone' => '+92 300 1234567']);
        $body = $svc->draftFirstEmail($lead->fresh(), false)['draft']['body'];
        $this->assertStringContainsString('Pick a time here: https://cal.com/eziline/15min', $body);
        $this->assertStringEndsWith("+92 300 1234567", $body, 'phone under the signature');
        $this->assertLessThanOrEqual(90, str_word_count($body), 'short');

        $s->update(['cta_type' => 'call']);
        $this->assertStringContainsString('quick call or WhatsApp: +92 300 1234567', $svc->draftFirstEmail($lead->fresh(), false)['draft']['body']);
        $s->update(['cta_type' => 'landing', 'landing_url' => null]);
        $this->assertStringContainsString('3 fixes', $svc->draftFirstEmail($lead->fresh(), false)['draft']['body'], 'no landing URL: falls back to a reply CTA, never an empty link');
    }

    public function test_the_app_is_installable_on_a_phone(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        foreach (array_column($manifest['icons'], 'src') as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')));
        }
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringNotContainsString("cache.put", file_get_contents(public_path('sw.js')), 'private pages are never cached');
        $this->get(route('login'))->assertOk()->assertSee('rel="manifest"', false);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('rel="manifest"', false)->assertSee("register('/sw.js')", false);
    }

    public function test_win_ladder_and_buying_signals_filter_by_country(): void
    {
        $uk = Lead::create(['full_name' => 'UK', 'email' => 'a@uk.example', 'country' => 'United Kingdom']);
        $pk = Lead::create(['full_name' => 'PK', 'email' => 'a@pk.example', 'country' => 'Pakistan']);
        foreach ([$uk, $pk] as $l) {
            $l->forceFill(['win_tier' => 'C', 'win_points' => 10])->saveQuietly();
        }
        $this->actingAs($this->admin)->get(route('leads.win-ladder', ['tier' => 'C', 'country' => 'Pakistan']))->assertOk()
            ->assertViewHas('leads', fn ($p) => $p->total() === 1 && $p->first()->id === $pk->id)
            ->assertViewHas('counts', fn ($c) => $c['C'] === 1);

        $a = Company::create(['name' => 'A', 'country' => 'Pakistan']);
        $b = Company::create(['name' => 'B', 'country' => 'United States']);
        foreach ([[$a, 'web_dev'], [$b, 'erp_crm']] as [$c, $svc]) {
            BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'vendor_request', 'title' => "Need {$svc}", 'evidence_text' => 'x', 'observed_date' => now()->toDateString(), 'confidence' => 80, 'relevant_service' => $svc]);
        }
        $this->actingAs($this->admin)->get(route('buying-signals.index', ['country' => 'Pakistan']))->assertOk()->assertViewHas('signals', fn ($p) => $p->total() === 1);
        $this->actingAs($this->admin)->get(route('buying-signals.index', ['service' => 'erp_crm']))->assertOk()->assertViewHas('signals', fn ($p) => $p->total() === 1 && $p->first()->company_id === $b->id);
    }
}
