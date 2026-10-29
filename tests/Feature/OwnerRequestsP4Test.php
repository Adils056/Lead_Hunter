<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Company;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Audit\CompetitorBenchmark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner's list, 30 Oct 2026: AI website analysis, competitor benchmarking and a PDF report. */
class OwnerRequestsP4Test extends TestCase
{
    use RefreshDatabase;

    protected function clinic(string $name, string $city, array $statuses, int $reviews = 10): Company
    {
        $slug = strtolower(str_replace(' ', '', $name));
        $c = Company::create(['name' => $name, 'website' => "https://{$slug}.example", 'domain' => "{$slug}.example", 'industry' => 'Dental clinic',
            'country' => 'Pakistan', 'city' => $city, 'google_review_count' => $reviews]);
        $checks = [];
        foreach ($statuses as $key => $status) {
            $checks[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'status' => $status, 'evidence' => "{$key} {$status}"];
        }
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'checks' => $checks,
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'evidence' => ['No viewport']]], 'audited_at' => now()->subDay()]);
        $c->update(['website_audited_at' => now()->subDay()]);

        return $c->fresh();
    }

    public function test_competitors_are_the_same_kind_of_business_nearby_and_scores_come_from_real_checks(): void
    {
        $me = $this->clinic('Smile Dental', 'Lahore', ['viewport' => 'fail', 'cta' => 'fail', 'https' => 'pass', 'title' => 'pass', 'ssl_certificate' => 'pass']);
        $near = $this->clinic('Bright Teeth', 'Lahore', ['viewport' => 'pass', 'cta' => 'pass', 'https' => 'pass', 'title' => 'pass'], 50);
        $far = $this->clinic('Karachi Dental', 'Karachi', ['viewport' => 'pass', 'cta' => 'fail', 'https' => 'fail', 'title' => 'pass'], 900);
        Company::create(['name' => 'Lahore Bakery', 'website' => 'https://bakery.example', 'industry' => 'Bakery', 'country' => 'Pakistan', 'city' => 'Lahore']);

        $bench = app(CompetitorBenchmark::class);
        $comps = $bench->competitors($me);
        $this->assertSame([$near->id, $far->id], $comps->pluck('id')->all(), 'same city first; a bakery is not a dental competitor');

        $b = $bench->build($me, $comps);
        $this->assertSame(0, $b['areas']['mobile_speed']['you']);
        $this->assertSame(100, $b['areas']['mobile_speed']['average']);
        $this->assertSame(100, $b['areas']['security']['you']);
        $this->assertSame(50, $b['areas']['security']['average']);
        $this->assertNotEmpty($b['leads_in']);
        $this->assertNotEmpty($b['behind_in']);
        $viewport = collect($b['gaps'])->firstWhere('key', 'viewport');
        $this->assertSame([2, 2], [$viewport['competitors_with_it'], $viewport['competitors']], 'both competitors already have a mobile layout');
        $this->assertNotNull($viewport['fix']);
        $this->assertContains('Online appointment booking with WhatsApp/SMS reminders', array_column($bench->solutions($me), 'solution'));
    }

    public function test_report_with_ai_analysis_is_shown_and_downloads_as_a_pdf(): void
    {
        $me = $this->clinic('Smile Dental', 'Lahore', ['viewport' => 'fail', 'cta' => 'fail', 'https' => 'pass', 'title' => 'pass']);
        $this->clinic('Bright Teeth', 'Lahore', ['viewport' => 'pass', 'cta' => 'pass', 'https' => 'pass', 'title' => 'pass']);
        AiProvider::create(['priority' => 1, 'name' => 'Groq', 'kind' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'm', 'api_key' => 'k', 'daily_limit' => 100, 'is_active' => true]);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'category' => 'Dental clinic',
            'summary' => 'Smile Dental is easy to trust on security, but on phones it falls behind Bright Teeth, which patients can contact in one tap.',
            'competitor_insight' => 'Clinics that win 37% more bookings use online booking.', // a number the facts do not contain: dropped
            'needs' => [['solution' => 'Online appointment booking', 'why' => 'Bright Teeth already takes enquiries from its homepage.']],
        ])]]]], 200), '*' => Http::response('', 404)]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('companies.report.build', $me))->assertRedirect(route('companies.report', $me));
        $report = $me->fresh()->latestWebsiteReport;
        $this->assertSame('Groq', $report->ai_provider);
        $this->assertNull($report->ai['competitor_insight'], 'invented statistic removed');
        $this->assertStringContainsString('falls behind Bright Teeth', $report->ai['summary']);

        $this->actingAs($admin)->get(route('companies.show', $me))->assertOk()->assertSee('competitor report');
        $this->actingAs($admin)->get(route('companies.report', $me))->assertOk()->assertSee('How the website compares')->assertSee('Online appointment booking')->assertSee('1 of 1');
        $pdf = $this->actingAs($admin)->get(route('companies.report.pdf', $me));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Website-Report-smiledental-example.pdf', $pdf->headers->get('Content-Disposition'));
    }

    public function test_without_any_ai_the_report_still_lists_software_from_the_industry_and_audit(): void
    {
        config(['leadpilot.keys.gemini' => null]);
        Http::fake(['*' => Http::response('', 404)]);
        $me = $this->clinic('Solo Dental', 'Multan', ['viewport' => 'fail']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('companies.report.build', $me))->assertRedirect();
        $this->actingAs($admin)->get(route('companies.report', $me))->assertOk()->assertSee('Clinic management')->assertSee('No audited local competitor found yet');
        $this->assertStringStartsWith('%PDF', $this->actingAs($admin)->get(route('companies.report.pdf', $me))->getContent());
    }
}
