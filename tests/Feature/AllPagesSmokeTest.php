<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every GET page (admin and sales) with normal and hostile query strings, and every form with an empty or
 * array-stuffed body: none may answer 500. Found live: ?tier[]=A on the Win Ladder, email[]= on /unsubscribe.
 */
class AllPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_page_or_form_answers_a_server_error(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->seed();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $c = Company::first();
        $lead = Lead::first();
        $audit = WebsiteAudit::create(['company_id' => $c->id, 'url' => 'https://x.test', 'status' => 'completed', 'audited_at' => now(), 'checks' => [], 'opportunities' => [],
            'contacts_found' => ['people' => [['name' => 'Jane Doe', 'role' => 'Owner', 'email' => null, 'source_url' => 'https://x.test/team']]]]);
        $icp = IcpProfile::create(['name' => 'Test ICP', 'countries' => ['Pakistan'], 'is_active' => true]);
        $rec = CampaignRecipient::first() ?? CampaignRecipient::create(['campaign_id' => Campaign::first()->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => 'k', 'sent_at' => now()]);
        app(\App\Services\Outreach\WinLadderService::class)->refreshAll();
        $params = ['company' => $c->id, 'lead' => $lead->id, 'campaign' => Campaign::first()->id, 'audit' => $audit->id, 'icp' => $icp->id, 'recipient' => $rec->id,
            'approval' => 999999, 'template' => EmailTemplate::first()?->id ?? 1, 'id' => 1];
        $hostile = '?page=abc&search[]=x&q[]=y&country[]=z&stage[]=a&status[]=b&tier[]=A&range=abc&min_score=abc&industry[]=x&service[]=x&tab[]=x&expand[]=x&assigned_to=abc';
        $fails = [];
        $check = function ($res, string $what) use (&$fails) {
            if ($res->baseResponse->getStatusCode() >= 500) {
                $fails[] = $what . ' → ' . ($res->exception ? mb_substr($res->exception->getMessage(), 0, 120) . ' @ ' . basename($res->exception->getFile()) . ':' . $res->exception->getLine() : $res->baseResponse->getStatusCode());
            }
        };

        foreach (Route::getRoutes() as $r) {
            if (preg_match('#^(livewire|storage|_ignition|sanctum|up$|logout|webhooks|api/)#', $r->uri())) {
                continue;
            }
            $uri = '/' . ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? 1, $r->uri()), '/');
            if (in_array('GET', $r->methods(), true)) {
                foreach ([$admin, $sales] as $u) {
                    foreach (['', $hostile] as $qs) {
                        $this->app['auth']->forgetGuards();
                        $check($this->actingAs($u)->get($uri . $qs), "GET {$u->role} {$uri}{$qs}");
                    }
                }
                continue;
            }
            $method = array_values(array_diff($r->methods(), ['GET', 'HEAD']))[0];
            foreach ([[], ['name' => ['x'], 'email' => ['x'], 'token' => ['x'], 'lifecycle_stage' => ['x'], 'status' => ['x'], 'mode' => ['x'], 'q' => ['x'], 'service' => ['x']]] as $body) {
                $this->app['auth']->forgetGuards();
                $check($this->actingAs($admin)->call($method, $uri, $body), "{$method} {$uri} " . ($body ? 'arrays' : 'empty'));
                $this->flushSession(); // the next page must not inherit this request's flashed input
            }
        }

        config(['leadpilot.registration.key' => 'secret-key']); // the private register link
        $this->app['auth']->forgetGuards();
        $check($this->get('/register?key[]=x'), 'GET /register?key[]=x');

        $this->assertSame([], $fails);
    }
}
