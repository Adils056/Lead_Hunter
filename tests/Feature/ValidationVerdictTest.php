<?php

namespace Tests\Feature;

use App\Models\IcpProfile;
use App\Models\User;
use App\Services\Autopilot\LiveValidationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValidationVerdictTest extends TestCase
{
    use RefreshDatabase;

    public function test_unreachable_providers_are_reported_as_fail_or_not_tested_never_pass(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]); // like the build sandbox
        config(['mail.default' => 'array']);
        User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'me@eziline.test']);
        IcpProfile::create(['name' => 'V', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'business_types' => ['local_business'], 'is_active' => true, 'website_requirement' => 'any', 'sources' => ['osm_overpass']]);
        $r = app(LiveValidationRunner::class)->run(IcpProfile::first(), ['limit' => 2]);
        $v = collect($r['sections'])->mapWithKeys(fn ($s) => [substr($s['title'], 0, 2) => $s['verdict']]);
        $this->assertSame('fail', $v['2.']);
        $this->assertSame('fail', $v['3.']);
        $this->assertSame('not_tested', $v['5.'], 'dedup with nothing found is NOT a pass');
        $this->assertSame('not_tested', $v['11'], 'no pilot requested: ' . json_encode($v));
        $this->assertSame('not_tested', $v['10'], 'no lead with an email exists to test suppression against');
        $md = file_get_contents($r['report_files'][0]);
        $this->assertStringContainsString('NOT TESTED', $md);
        @unlink($r['report_files'][0]);
        @unlink($r['report_files'][1]);
    }

    public function test_smtp_hints_and_mail_test_command(): void
    {
        $h = app(LiveValidationRunner::class);
        $this->assertStringContainsString('DNS/network', $h->smtpHint('getaddrinfo for smtp.gmail.com failed'));
        $this->assertStringContainsString('App Password', $h->smtpHint('535-5.7.8 Username and Password not accepted'));

        config(['mail.default' => 'array']);
        $this->artisan('leadpilot:mail-test stranger@x.test')->assertFailed();
        User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'me@eziline.test']);
        $this->artisan('leadpilot:mail-test me@eziline.test')->expectsOutputToContain('LOGGED ONLY')->assertSuccessful();
    }
}
