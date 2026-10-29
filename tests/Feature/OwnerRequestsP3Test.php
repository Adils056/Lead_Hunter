<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Models\WhatsappTemplate;
use App\Services\Audit\TlsInspector;
use App\Services\Audit\WebsiteAuditService;
use App\Support\Phone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner's list, 30 Oct 2026: website technology / security checks with fixes, and WhatsApp templates. */
class OwnerRequestsP3Test extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_certificate_redirect_headers_versions_and_sitemap_with_fixes(): void
    {
        config(['leadpilot.sources.website_crawler.tech_checks' => true, 'leadpilot.sources.pagespeed.enabled' => false,
            'leadpilot.sources.website_crawler.dns_overrides' => ['oldsite.example' => ['93.184.216.34']]]);
        $this->app->instance(TlsInspector::class, new class extends TlsInspector {
            public function inspect(string $url, int $timeout = 8): array
            {
                return $this->judge(['validFrom_time_t' => now()->subYear()->timestamp, 'validTo_time_t' => now()->subDays(3)->timestamp,
                    'subject' => ['CN' => 'oldsite.example'], 'issuer' => ['O' => "Let's Encrypt"], 'extensions' => ['subjectAltName' => 'DNS:oldsite.example']], 'oldsite.example');
            }
        });
        $page = '<html lang="en"><head><title>Old Site Plumbing | Leeds</title><meta name="viewport" content="width=device-width"></head><body><h1>Plumbers</h1>'
            . str_repeat('<p>We fix pipes across Leeds every day.</p>', 30) . '<a href="/contact">Contact</a></body></html>';
        Http::fake([
            'http://oldsite.example*' => Http::response($page, 200),
            'https://oldsite.example/sitemap.xml' => Http::response('Not found', 404),
            'https://oldsite.example*' => Http::response($page, 200, ['Server' => 'Apache/2.4.29 (Ubuntu)', 'X-Powered-By' => 'PHP/7.4.3']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'Old Site Plumbing', 'website' => 'https://oldsite.example', 'domain' => 'oldsite.example', 'country' => 'United Kingdom']);

        $audit = app(WebsiteAuditService::class)->audit($c);
        $checks = collect($audit->checks)->keyBy('key');

        $this->assertSame(['fail', 'ssl_invalid'], [$checks['ssl_certificate']['status'], $checks['ssl_certificate']['evidence_keys'][0]]);
        $this->assertStringContainsString('expired on', $checks['ssl_certificate']['evidence']);
        $this->assertSame('warn', $checks['https_redirect']['status'], 'http:// stays on http');
        $this->assertSame('warn', $checks['security_headers']['status']);
        $this->assertStringContainsString('Apache/2.4.29', $checks['server_disclosure']['evidence']);
        $this->assertSame(['fail', 'PHP 7.4'], [$checks['outdated_server']['status'], $checks['outdated_server']['value']]);
        $this->assertSame('warn', $checks['sitemap']['status']);
        $this->assertContains('maintenance', array_column($audit->opportunities, 'service'), 'security findings map to the maintenance & security offer');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('audits.show', $audit))->assertOk()->assertSee('How to fix:')->assertSee('Upgrade PHP to a supported version');

        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'x', 'email' => 'info@oldsite.example', 'contact_status' => 'generic_inbox', 'country' => 'United Kingdom']);
        $facts = app(\App\Services\Sales\ConversionAssistantService::class)->facts($lead->fresh());
        $this->assertStringContainsString('SSL certificate on oldsite.example is expired on', $facts[0]['text'], 'the strongest finding opens the email');
    }

    public function test_certificate_verdicts(): void
    {
        $tls = new TlsInspector();
        $base = ['validFrom_time_t' => now()->subMonth()->timestamp, 'validTo_time_t' => now()->addMonths(2)->timestamp, 'subject' => ['CN' => 'x'], 'issuer' => ['O' => 'CA']];
        $this->assertTrue($tls->judge($base + ['extensions' => ['subjectAltName' => 'DNS:*.shop.example, DNS:shop.example']], 'www.shop.example')['ok']);
        $this->assertStringContainsString('issued for', $tls->judge($base + ['extensions' => ['subjectAltName' => 'DNS:other.example']], 'shop.example')['problem']);
        $this->assertFalse($tls->judge($base + ['extensions' => ['subjectAltName' => 'DNS:*.example']], 'a.b.example')['ok'], 'a wildcard covers one level only');
        $soon = $tls->judge(['validTo_time_t' => now()->addDays(10)->timestamp] + $base + ['extensions' => ['subjectAltName' => 'DNS:shop.example']], 'shop.example');
        $this->assertSame([true, 10], [$soon['ok'], $soon['days_left']]);
    }

    public function test_whatsapp_template_opens_a_filled_message_for_the_leads_number(): void
    {
        $this->assertSame('923001234567', Phone::international('0300-1234567', 'Pakistan'));
        $this->assertSame('442079460018', Phone::international('020 7946 0018', 'United Kingdom'));
        $this->assertSame('12125551234', Phone::international('(212) 555-1234', 'United States'));
        $this->assertSame('971501234567', Phone::international('+971 50 123 4567', null));
        $this->assertNull(Phone::international('0300 1234567', 'Atlantis'), 'unknown country: never guess a code');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $c = Company::create(['name' => 'Smile Dental Ltd', 'website' => 'https://smile.example', 'domain' => 'smile.example', 'country' => 'Pakistan', 'public_phone' => '042 35761234']);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'opportunity_score' => 40,
            'checks' => [['key' => 'viewport', 'label' => 'Viewport', 'status' => 'fail', 'evidence' => 'No viewport', 'evidence_keys' => ['no_viewport']]], 'audited_at' => now()]);
        $lead = Lead::create(['company_id' => $c->id, 'first_name' => 'Ayesha', 'last_name' => 'Khan', 'full_name' => 'Ayesha Khan', 'email' => 'ayesha@smile.example',
            'contact_status' => 'identified', 'country' => 'Pakistan']);

        $this->actingAs($admin)->get(route('leads.show', $lead))->assertOk()->assertSee('Open in WhatsApp')->assertSee('+924235761234');
        $tpl = WhatsappTemplate::where('purpose', 'first_contact')->firstOrFail();
        $res = $this->actingAs($admin)->post(route('leads.whatsapp', $lead), ['template_id' => $tpl->id]);
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/924235761234?text=', $url);
        $text = rawurldecode(substr($url, strpos($url, '=') + 1));
        $this->assertStringContainsString('Hi Ayesha', $text);
        $this->assertStringContainsString("smile.example isn't set up for mobile screens", $text);
        $this->assertStringNotContainsString('{', $text, 'no placeholder left unfilled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp_opened']);

        Suppression::create(['email' => 'ayesha@smile.example', 'reason' => 'unsubscribe', 'source' => 'test']);
        $this->actingAs($admin)->post(route('leads.whatsapp', $lead), ['template_id' => $tpl->id])->assertSessionHas('error');

        $this->actingAs($admin)->post(route('whatsapp-templates.store'), ['name' => 'Eid offer', 'purpose' => 'follow_up', 'body' => 'Hi {first_name}!', 'is_active' => 1])->assertRedirect();
        $this->actingAs($admin)->get(route('whatsapp-templates.index'))->assertOk()->assertSee('Eid offer');
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $this->actingAs($sales)->post(route('whatsapp-templates.store'), ['name' => 'x', 'purpose' => 'reply', 'body' => 'y'])->assertForbidden();
    }
}
