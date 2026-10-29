<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\WebsiteAudit;
use App\Services\EmailVerificationService;
use App\Services\Enrichment\EmailFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner's choice A (29 Oct): pattern + SMTP proof for website-named decision-makers; catch-all proves nothing. */
class EmailFinderTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeMailServers(): void
    {
        // proof.test has exactly nadia.hussain@; catchall.test accepts anything; quiet.test never answers
        $this->app->instance(EmailVerificationService::class, new class extends EmailVerificationService {
            public array $probed = [];

            public function mxHosts(string $domain): array
            {
                return in_array($domain, ['proof.test', 'catchall.test', 'quiet.test'], true) ? ["mx.{$domain}"] : [];
            }

            public function smtpProbe(string $email, array $mxHosts): array
            {
                $this->probed[] = $email;
                $domain = substr(strrchr($email, '@'), 1);

                return ['result' => match ($domain) {
                    'proof.test' => $email === 'nadia.hussain@proof.test' ? 'deliverable' : 'undeliverable',
                    'catchall.test' => 'catch_all',
                    default => 'unknown',
                }, 'code' => 250, 'host' => $mxHosts[0] ?? null, 'note' => 'fake'];
            }
        });
    }

    protected function companyWithPerson(string $name, string $domain, string $first, string $last): Company
    {
        $c = Company::create(['name' => $name, 'website' => "https://{$domain}", 'domain' => $domain, 'country' => 'United States']);
        Lead::create(['company_id' => $c->id, 'full_name' => "{$name} — general inbox", 'email' => "info@{$domain}", 'contact_status' => 'generic_inbox', 'overall_score' => 70]);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => "https://{$domain}", 'status' => 'completed', 'audited_at' => now(), 'contacts_found' => ['people' => [
            ['name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last, 'role' => 'Founder & CEO', 'rank' => 95, 'email' => null, 'source_url' => "https://{$domain}/team"],
        ]]]);

        return $c;
    }

    public function test_patterns_are_common_business_formats(): void
    {
        $this->fakeMailServers();
        $this->assertSame(['nadia@x.test', 'nadia.hussain@x.test', 'nhussain@x.test', 'nadiahussain@x.test', 'n.hussain@x.test', 'nadia_hussain@x.test', 'nadiah@x.test', 'hussain@x.test'],
            app(EmailFinder::class)->candidates('Nadia', "Hussain", 'x.test'));
        $this->assertSame([], app(EmailFinder::class)->candidates('N', 'Hussain', 'x.test'), 'an initial alone is not a name');
    }

    public function test_only_a_proven_mailbox_becomes_a_contact(): void
    {
        $this->fakeMailServers();
        $proof = $this->companyWithPerson('Proof Labs', 'proof.test', 'Nadia', 'Hussain');
        $catch = $this->companyWithPerson('Catch Co', 'catchall.test', 'Omar', 'Khan');
        $quiet = $this->companyWithPerson('Quiet Inc', 'quiet.test', 'Sara', 'Ali');

        $this->artisan('leadpilot:find-emails')
            ->expectsOutputToContain('nadia.hussain@proof.test ✓')
            ->expectsOutputToContain('Omar Khan → catch_all')
            ->expectsOutputToContain('Sara Ali → cannot_probe')
            ->assertExitCode(0);

        $nadia = Lead::where('email', 'nadia.hussain@proof.test')->firstOrFail();
        $this->assertSame(['Nadia', 'Hussain', 'Founder & CEO', 'identified'], [$nadia->first_name, $nadia->last_name, $nadia->job_title, $nadia->contact_status]);
        $this->assertSame('verified', $nadia->email_verification_status);
        $this->assertDatabaseHas('data_provenance', ['subject_id' => $nadia->id, 'field' => 'email', 'source' => 'email_pattern_smtp']);
        $this->assertSame(0, Lead::where('company_id', $catch->id)->where('contact_status', 'identified')->count(), 'catch-all proves nothing: nothing kept');
        $this->assertSame(0, Lead::where('company_id', $quiet->id)->where('contact_status', 'identified')->count());

        // each company is tried once; the found one is now skipped for having a named contact
        $this->artisan('leadpilot:find-emails')->expectsOutputToContain('Tried 0 companies')->assertExitCode(0);
    }
}
