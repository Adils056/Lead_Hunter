<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAndLeadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_can_create_company_with_normalized_domain(): void
    {
        $response = $this->actingAs($this->admin)->post('/companies', [
            'name' => 'Acme Technologies',
            'website' => 'https://www.acme-tech.com/about-us',
            'country' => 'United Kingdom',
            'industry' => 'FinTech',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', [
            'name' => 'Acme Technologies',
            'domain' => 'acme-tech.com',
            'country' => 'United Kingdom',
        ]);
    }

    public function test_duplicate_company_domain_is_prevented(): void
    {
        Company::create([
            'name' => 'Existing Company Ltd',
            'website' => 'https://existing.com',
            'domain' => 'existing.com',
            'country' => 'United States',
        ]);

        $response = $this->actingAs($this->admin)->post('/companies', [
            'name' => 'Existing Company Copy',
            'website' => 'http://www.existing.com/products',
            'country' => 'United States',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(1, Company::where('domain', 'existing.com')->count());
    }

    public function test_lead_can_be_created_and_scored(): void
    {
        $company = Company::create([
            'name' => 'Apex Systems',
            'country' => 'Pakistan',
            'domain' => 'apex.pk',
        ]);

        $response = $this->actingAs($this->admin)->post('/leads', [
            'full_name' => 'Tariq Mehmood',
            'job_title' => 'Chief Technology Officer',
            'email' => 'tariq@apex.pk',
            'country' => 'Pakistan',
            'company_id' => $company->id,
            'relevant_services' => ['ERP and CRM development', 'Laravel and PHP development'],
        ]);

        $response->assertSessionHasNoErrors();
        $lead = Lead::where('email', 'tariq@apex.pk')->first();
        $this->assertNotNull($lead);
        $this->assertGreaterThan(0, $lead->overall_score);
        $this->assertNotNull($lead->score_explanation);
    }

    public function test_duplicate_lead_email_is_prevented(): void
    {
        Lead::create([
            'full_name' => 'John Doe',
            'email' => 'duplicate@example.com',
            'country' => 'United States',
        ]);

        $response = $this->actingAs($this->admin)->post('/leads', [
            'full_name' => 'John Doe 2',
            'email' => 'duplicate@example.com',
            'country' => 'United States',
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(1, Lead::where('email', 'duplicate@example.com')->count());
    }

    public function test_suppressed_email_warns_on_lead_creation(): void
    {
        Suppression::create([
            'email' => 'suppressed@badprospect.com',
            'reason' => 'unsubscribe',
        ]);

        $response = $this->actingAs($this->admin)->post('/leads', [
            'full_name' => 'Blocked Contact',
            'email' => 'suppressed@badprospect.com',
            'country' => 'United States',
        ]);

        $response->assertSessionHas('warning');
    }

    public function test_csv_import_service_deduplicates_and_scores_leads(): void
    {
        $csvService = app(CsvImportService::class);

        $rows = [
            [
                'Full Name' => 'Edward Smith',
                'Work Email' => 'edward@growthco.co.uk',
                'Company' => 'GrowthCo UK',
                'Title' => 'VP of Engineering',
                'Country' => 'United Kingdom',
                'Industry' => 'Financial Technology',
                'Website' => 'https://growthco.co.uk',
            ],
            [
                'Full Name' => 'Edward Smith Duplicate',
                'Work Email' => 'edward@growthco.co.uk', // Duplicate
                'Company' => 'GrowthCo UK',
                'Title' => 'VP',
                'Country' => 'United Kingdom',
                'Industry' => 'Financial Technology',
                'Website' => 'https://growthco.co.uk',
            ]
        ];

        $mapping = [
            'full_name' => 'Full Name',
            'email' => 'Work Email',
            'company_name' => 'Company',
            'job_title' => 'Title',
            'country' => 'Country',
            'industry' => 'Industry',
            'website' => 'Website',
        ];

        $result = $csvService->import($rows, $mapping, $this->admin->id);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(1, $result['duplicates']);
        $this->assertDatabaseHas('leads', ['email' => 'edward@growthco.co.uk']);
        $this->assertDatabaseHas('companies', ['domain' => 'growthco.co.uk']);
    }

    public function test_csv_with_separate_first_and_last_name_columns_imports_without_a_full_name_column(): void
    {
        // QA 2 Nov: the mapping page forced "Full Name", so an Apollo-style file (first_name, last_name) could not be imported
        $result = app(CsvImportService::class)->import([
            ['first_name' => 'Zara', 'Last Name' => 'Ahmed', 'email' => 'zara@qaimport.example', 'company' => 'QA Import Co', 'website' => 'https://qaimport.example'],
        ], ['email' => 'email', 'company_name' => 'company', 'website' => 'website'], $this->admin->id);

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('leads', ['email' => 'zara@qaimport.example', 'first_name' => 'Zara', 'last_name' => 'Ahmed']);

        // full upload -> map -> import through the pages (QA 2 Nov: "Start Import" always said the session had expired,
        // because the upload went to storage/app/private and the import looked in storage/app)
        \Illuminate\Support\Facades\Storage::fake('local');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('apollo.csv', "first_name,last_name,email\nAli,Khan,ali@qaimport.example\n");
        $this->actingAs($this->admin)->post(route('leads.import.preview'), ['csv_file' => $file])->assertOk()
            ->assertSee('Optional: your file has a first-name column', false);
        $this->actingAs($this->admin)->post(route('leads.import.process'), ['mapping' => ['email' => 'email']])
            ->assertRedirect(route('leads.index'))->assertSessionHas('success');
        $this->assertDatabaseHas('leads', ['email' => 'ali@qaimport.example', 'first_name' => 'Ali', 'last_name' => 'Khan']);
    }
}
