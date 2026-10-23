<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageLoadAndNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales;
    protected Company $company;
    protected Lead $lead;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->sales = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'Acme Corporation',
            'domain' => 'acmecorp.com',
            'country' => 'United States',
            'industry' => 'Financial Technology',
        ]);

        $this->lead = Lead::create([
            'company_id' => $this->company->id,
            'full_name' => 'Alice Walker',
            'email' => 'alice@acmecorp.com',
            'job_title' => 'Chief Technology Officer',
            'email_verification_status' => 'verified',
            'country' => 'United States',
            'overall_score' => 85,
            'lifecycle_stage' => 'qualified',
        ]);

        $this->campaign = Campaign::create([
            'name' => 'Q4 Enterprise Outreach',
            'service_offered' => 'Custom software development',
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_unauthenticated_access_is_blocked_on_all_protected_routes(): void
    {
        $protectedRoutes = [
            '/dashboard',
            '/companies',
            "/companies/{$this->company->id}",
            '/companies/create',
            "/companies/{$this->company->id}/edit",
            '/leads',
            "/leads/{$this->lead->id}",
            '/leads/create',
            "/leads/{$this->lead->id}/edit",
            '/leads/import',
            '/buying-signals',
            '/campaigns',
            "/campaigns/{$this->campaign->id}",
            '/campaigns/create',
            '/crm/pipeline',
            '/analytics',
            '/settings/integrations',
            '/profile',
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->get($route);
            $response->assertRedirect('/login', "Route {$route} did not redirect unauthenticated visitor to login.");
        }
    }

    public function test_all_core_pages_render_for_admin_user_without_errors(): void
    {
        $pages = [
            '/dashboard' => 'Dashboard',
            '/companies' => 'Companies',
            "/companies/{$this->company->id}" => 'Acme Corporation',
            '/companies/create' => 'Add Company',
            "/companies/{$this->company->id}/edit" => 'Edit Company',
            '/leads' => 'Leads',
            "/leads/{$this->lead->id}" => 'Alice Walker',
            '/leads/create' => 'Create Lead Prospect',
            "/leads/{$this->lead->id}/edit" => 'Edit Prospect Information',
            '/leads/import' => 'Import Leads',
            '/buying-signals' => 'Buying Signals',
            '/campaigns' => 'Campaigns',
            "/campaigns/{$this->campaign->id}" => 'Q4 Enterprise Outreach',
            '/campaigns/create' => 'Create Campaign',
            '/crm/pipeline' => 'Sales Pipeline',
            '/analytics' => 'Analytics',
            '/settings/integrations' => 'Integrations',
            '/profile' => 'Profile',
        ];

        foreach ($pages as $url => $expectedContent) {
            $response = $this->actingAs($this->admin)->get($url);
            $this->assertEquals(200, $response->getStatusCode(), "Failed loading page: {$url}");
            $response->assertSee($expectedContent);
        }
    }

    public function test_sales_rep_has_access_to_leads_pipeline_and_blocked_from_integrations(): void
    {
        // Allowed
        $response = $this->actingAs($this->sales)->get('/dashboard');
        $response->assertStatus(200);

        $response = $this->actingAs($this->sales)->get('/crm/pipeline');
        $response->assertStatus(200);

        $response = $this->actingAs($this->sales)->get('/leads');
        $response->assertStatus(200);

        // Blocked from Settings / Integrations by policy
        $response = $this->actingAs($this->sales)->get('/settings/integrations');
        $response->assertStatus(403);
    }

    public function test_login_and_logout_flow(): void
    {
        // GET Login
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('LeadPilot AI');

        // POST Login valid
        $response = $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'password', // default factory password
        ]);
        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);

        // POST Logout
        $response = $this->actingAs($this->admin)->post('/logout');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
