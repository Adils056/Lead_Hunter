<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EnrichmentRecord;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Services\AiResearchService;
use App\Services\Discovery\Adapters\WikidataOrgsAdapter;
use App\Services\EmailVerificationService;
use App\Services\Hunting\IcpSearchConfigBuilder;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Sales\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Free, keyless alternatives to the paid providers (23 Oct 2026):
 *  Apollo  -> Wikidata organisations (SPARQL, CC0)
 *  Hunter  -> built-in MX + SMTP mailbox probe
 *  OpenAI  -> local Ollama (through LlmClient; AI research falls back to it before the rule-based engine)
 */
class FreeAlternativesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function sparqlFixture(): array
    {
        $row = fn (array $v) => array_map(fn ($x) => ['type' => 'literal', 'value' => $x], $v);

        return ['results' => ['bindings' => [
            $row(['item' => 'http://www.wikidata.org/entity/Q140068449', 'itemLabel' => 'GHC Sportswear', 'site' => 'https://ghcsportswear.com/', 'hq' => 'Sialkot', 'emp' => '260',
                'since' => '2005-01-01T00:00:00Z', 'mail' => 'mailto:Info@ghcsportswear.com', 'industries' => 'textile industry']),
            $row(['item' => 'http://www.wikidata.org/entity/Q7669775', 'itemLabel' => 'TCS Courier', 'site' => 'http://www.tcscourier.com/', 'hq' => 'Karachi', 'industries' => 'logistics']),
            $row(['item' => 'http://www.wikidata.org/entity/Q99999999', 'itemLabel' => 'Q99999999', 'site' => 'https://unlabeled.example', 'industries' => 'logistics']),
        ]]];
    }

    public function test_wikidata_is_the_default_free_b2b_source_and_stores_only_what_wikidata_has(): void
    {
        Http::fake([WikidataOrgsAdapter::SPARQL . '*' => Http::response($this->sparqlFixture())]);
        $icp = IcpProfile::create(['name' => 'Exporters', 'is_active' => true, 'countries' => ['Pakistan'], 'niches' => ['textile', 'logistics'], 'business_types' => ['smb'],
            'target_services' => ['erp_pos'], 'website_requirement' => 'any', 'max_results_per_run' => 10]);

        $cfg = app(IcpSearchConfigBuilder::class)->build($icp);
        $this->assertSame(['wikidata_orgs'], array_keys($cfg), 'B2B ICP without cities defaults to the free Wikidata source, not Apollo');
        $this->assertSame(['textile', 'logistics'], $cfg['wikidata_orgs']['keywords']);
        $this->assertSame(['Pakistan'], $cfg['wikidata_orgs']['countries']);
        $this->assertSame('wikidata_orgs', app(ProviderRegistry::class)->select('b2b_discovery')[0]);

        $a = new WikidataOrgsAdapter();
        $q = $a->buildQuery('Q843', ['textile', 'logis"tics'], 10);
        $this->assertStringContainsString('wdt:P17 wd:Q843', $q);
        $this->assertStringContainsString('CONTAINS(LCASE(?il), "logistics")', $q, 'quotes stripped from keywords');
        $this->assertStringContainsString('FILTER NOT EXISTS { ?item wdt:P576', $q, 'dissolved companies excluded');

        $res = $a->discover($cfg['wikidata_orgs'] + ['icp' => $icp]);

        $this->assertSame([], $res->errors);
        $this->assertSame(2, $res->totalFound, 'unlabeled item skipped');
        $this->assertSame(2, $res->newCompaniesCount);
        $ghc = Company::where('external_id', 'wikidata:Q140068449')->firstOrFail();
        $this->assertSame('https://ghcsportswear.com/', $ghc->website);
        $this->assertSame('Sialkot', $ghc->city);
        $this->assertSame('Pakistan', $ghc->country);
        $this->assertSame('Textile industry', $ghc->industry);
        $this->assertSame('201-500', $ghc->size_range);
        $this->assertSame('info@ghcsportswear.com', $ghc->public_email);
        $this->assertSame('wikidata_orgs', $ghc->source_provider);
        $this->assertSame('https://www.wikidata.org/wiki/Q140068449', $ghc->source_url);
        $lead = Lead::where('company_id', $ghc->id)->firstOrFail();
        $this->assertSame('generic_inbox', $lead->contact_status);
        $this->assertSame(['erp_pos'], $lead->relevant_services);

        $tcs = Company::where('external_id', 'wikidata:Q7669775')->firstOrFail();
        $this->assertNull($tcs->size_range, 'no employee count in Wikidata => no size invented');
        $this->assertNull($tcs->public_email);
        $this->assertSame('needs_enrichment', Lead::where('company_id', $tcs->id)->value('contact_status'));
        $this->assertStringContainsString('Wikidata (CC0)', json_encode($res->items));
    }

    public function test_wikidata_errors_are_reported_not_hidden(): void
    {
        Http::fake([WikidataOrgsAdapter::SPARQL . '*' => Http::response('<html>Too many requests</html>', 429)]);
        $res = (new WikidataOrgsAdapter())->discover(['keywords' => ['bank'], 'countries' => ['Pakistan'], 'limit' => 5]);
        $this->assertSame(0, $res->totalFound);
        $this->assertStringContainsString('HTTP 429', $res->errors[0]);
        $this->assertStringContainsString('industry keyword', (new WikidataOrgsAdapter())->discover(['countries' => ['Pakistan']])->errors[0]);
    }

    protected function verifier(array $probe, array $mx = ['mx1.example.pk']): EmailVerificationService
    {
        return new class($probe, $mx) extends EmailVerificationService {
            public function __construct(public array $probe, public array $mx) { parent::__construct(); }
            public function mxHosts(string $domain): array { return $this->mx; }
            public function smtpProbe(string $email, array $mxHosts): array { return $this->probe + ['code' => null, 'host' => 'mx1.example.pk', 'note' => 'test']; }
        };
    }

    public function test_free_email_verification_reports_exactly_what_was_proven(): void
    {
        $v = $this->verifier(['result' => 'deliverable', 'code' => 250])->verifyEmail('info@clinic.pk');
        $this->assertSame(['verified', 95, 'smtp_check', true], [$v['status'], $v['score'], $v['provider'], $v['has_smtp_check']]);

        $v = $this->verifier(['result' => 'undeliverable', 'code' => 550])->verifyEmail('nobody@clinic.pk');
        $this->assertSame(['invalid', 5, 'smtp_check'], [$v['status'], $v['score'], $v['provider']]);

        $v = $this->verifier(['result' => 'catch_all', 'code' => 250])->verifyEmail('anyone@catchall.pk');
        $this->assertSame(['catch_all', 60], [$v['status'], $v['score']]);

        $v = $this->verifier(['result' => 'blocked'])->verifyEmail('owner@firm.pk');
        $this->assertSame(['mx_only', 50, 'dns_check', false], [$v['status'], $v['score'], $v['provider'], $v['has_smtp_check']], 'port 25 blocked: MX-level result, never "verified"');

        $v = $this->verifier(['result' => 'blocked'])->verifyEmail('dentist@gmail.com');
        $this->assertSame(['mx_only', 40, true], [$v['status'], $v['score'], $v['is_free_mailbox']]);

        $v = $this->verifier(['result' => 'deliverable', 'code' => 250])->verifyEmail('dentist2@gmail.com');
        $this->assertSame(['verified', 80], [$v['status'], $v['score']], 'a probed free mailbox is verified');

        $v = $this->verifier(['result' => 'deliverable'], [])->verifyEmail('x@no-mx-domain.invalid');
        $this->assertSame(['invalid', 0, false], [$v['status'], $v['score'], $v['has_mx_records']]);

        $v = $this->verifier(['result' => 'deliverable'])->verifyEmail('spam@mailinator.com');
        $this->assertSame('invalid', $v['status']);
        $this->assertTrue($v['is_disposable']);

        $this->assertDatabaseHas('email_verifications', ['email' => 'info@clinic.pk', 'status' => 'verified', 'provider' => 'smtp_check', 'has_smtp_check' => true]);
    }

    public function test_smtp_probe_is_skipped_when_disabled_or_when_port_25_was_found_blocked(): void
    {
        $svc = new EmailVerificationService();
        config(['leadpilot.smtp_probe' => false]);
        $this->assertSame('skipped', $svc->smtpProbe('a@b.pk', ['mx.b.pk'])['result']);
        config(['leadpilot.smtp_probe' => true]);
        Cache::put('smtp_probe_port25_blocked', now()->toIso8601String(), 3600);
        $this->assertSame('blocked', $svc->smtpProbe('a@b.pk', ['mx.b.pk'])['result']);
        // multi-line reply parsing
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, "250-mx.b.pk Hello\r\n250-SIZE 1000\r\n250 OK\r\n");
        rewind($fp);
        $read = new \ReflectionMethod($svc, 'smtpRead');
        $this->assertSame(250, $read->invoke($svc, $fp));
    }

    public function test_ollama_is_a_free_llm_provider_and_ai_research_uses_it_before_the_rule_engine(): void
    {
        config(['leadpilot.sources.ollama.enabled' => true, 'leadpilot.sources.ollama.url' => 'http://localhost:11434', 'leadpilot.sources.ollama.research' => true, 'leadpilot.keys.gemini' => null, 'leadpilot.keys.openai' => null]);
        Cache::put('ollama_reachable', true, 600);
        $research = ['business_summary' => 'A Sialkot sportswear exporter.', 'industry_classification' => 'Textile', 'potential_software_needs' => ['ERP'],
            'recommended_agency_service' => 'ERP and CRM development', 'supporting_evidence' => 'exporter with a website', 'missing_information' => 'current systems',
            'confidence_level' => 'Medium', 'suggested_next_action' => 'Call', 'personalized_outreach_angle' => 'Order tracking'];
        Http::fake(['localhost:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => json_encode($research)], 'eval_count' => 42])]);

        $this->assertSame(['ollama'], app(ProviderRegistry::class)->select('llm'));
        $out = app(LlmClient::class)->json('sys', 'user', ['type' => 'object']);
        $this->assertSame('ollama', $out['provider']);
        $this->assertSame('Textile', $out['data']['industry_classification']);
        Http::assertSent(fn ($r) => $r['model'] === 'qwen2.5:3b' && $r['stream'] === false && $r['format'] === ['type' => 'object']);

        $company = Company::create(['name' => 'GHC Sportswear', 'website' => 'https://ghcsportswear.com', 'country' => 'Pakistan', 'industry' => 'Textile industry']);
        $analysis = app(AiResearchService::class)->researchCompany($company);
        $this->assertSame('A Sialkot sportswear exporter.', $analysis['business_summary']);
        $this->assertSame('A Sialkot sportswear exporter.', $company->fresh()->ai_research['business_summary']);
        $this->assertDatabaseHas('enrichment_records', ['enrichable_id' => $company->id, 'provider' => 'ollama', 'operation' => 'company_research', 'cost_estimated' => 0]);
        $this->assertDatabaseHas('usage_logs', ['provider' => 'ollama', 'cost_cents' => 0]);
    }

    public function test_local_model_is_not_used_for_open_ended_research_unless_the_owner_opts_in(): void
    {
        config(['leadpilot.sources.ollama.enabled' => true, 'leadpilot.sources.ollama.research' => false, 'leadpilot.keys.gemini' => null, 'leadpilot.keys.openai' => null]);
        Cache::put('ollama_reachable', true, 600);
        Http::fake(['localhost:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['business_summary' => 'invented'])]])]);
        $company = Company::create(['name' => 'Careful Co', 'website' => 'https://careful.example', 'country' => 'Pakistan']);
        $analysis = app(AiResearchService::class)->researchCompany($company);
        $this->assertNotSame('invented', $analysis['business_summary'] ?? null, 'rule-based engine used, not the small local model');
        Http::assertNothingSent();
        // drafts (guarded) still may use it
        $this->assertSame('ollama', app(LlmClient::class)->json('s', 'u', ['type' => 'object'])['provider']);
    }

    public function test_draft_guardrails_fix_the_greeting_token_and_reject_placeholders_or_a_changed_cta(): void
    {
        config(['leadpilot.sources.ollama.enabled' => true, 'leadpilot.keys.gemini' => null, 'leadpilot.keys.openai' => null]);
        Cache::put('ollama_reachable', true, 600);
        $cta = 'Reply with a good time this week.';
        $sig = config('leadpilot.agency.sender_name') . "\n" . config('leadpilot.agency.name');
        $good = "{{Greeting}} I noticed your homepage has no mobile viewport tag. A short review could help. {$cta}\n{$sig} {{greeting}}";
        $placeholder = "Dear [Client's Name], your homepage has no mobile viewport tag. {$cta}\n{$sig}";
        $changedCta = "{{GREETING}} your homepage has no mobile viewport tag. Could you reply with a 20-minute website review?\n{$sig}";
        Http::fake(['localhost:11434/api/chat' => Http::sequence()
            ->push(['message' => ['content' => json_encode(['subject' => 'Mobile view', 'body' => $good])]])
            ->push(['message' => ['content' => json_encode(['subject' => 'Mobile view', 'body' => $placeholder])]])
            ->push(['message' => ['content' => json_encode(['subject' => 'Mobile view', 'body' => $changedCta])]])
            ->push(['message' => ['content' => json_encode(['subject' => 'Mobile view', 'body' => "{{GREETING}} your homepage has no mobile viewport tag. {$cta}"])]])]);
        $svc = app(\App\Services\Sales\ConversionAssistantService::class);
        $polish = new \ReflectionMethod($svc, 'aiPolish');
        $args = ['Hi Ali,', [['text' => 'your homepage has no mobile viewport tag']], ['pitch' => 'a 20-minute review'], $cta, 90];

        $ok = $polish->invoke($svc, ...$args);
        $this->assertSame('ollama', $ok['ai_provider']);
        $this->assertStringStartsWith('Hi Ali, I noticed', $ok['body'], 'wrong-case token replaced once');
        $this->assertStringNotContainsString('{{', $ok['body'], 'duplicate token removed');
        $this->assertNull($polish->invoke($svc, ...$args), '[Client\'s Name] placeholder rejected');
        $this->assertNull($polish->invoke($svc, ...$args), 'distorted call-to-action rejected');
        $noSig = $polish->invoke($svc, ...$args);
        $this->assertStringEndsWith($sig, $noSig['body'], 'missing signature appended');
    }

    public function test_without_any_llm_the_research_falls_back_to_the_rule_engine_and_nothing_is_called(): void
    {
        config(['leadpilot.sources.ollama.enabled' => false, 'leadpilot.keys.gemini' => null, 'leadpilot.keys.openai' => null]);
        $this->assertSame([], app(ProviderRegistry::class)->select('llm'));
        $company = Company::create(['name' => 'Offline Co', 'website' => 'https://offline.example', 'country' => 'Pakistan']);
        $analysis = app(AiResearchService::class)->researchCompany($company);
        $this->assertIsArray($analysis);
        $this->assertNotEmpty($analysis['business_summary'] ?? $analysis);
        $this->assertSame(0, EnrichmentRecord::where('provider', 'ollama')->count());
        Http::assertNothingSent();
    }
}
