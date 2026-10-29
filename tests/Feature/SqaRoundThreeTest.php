<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SQA report 29 Oct 2026, round 3: page weight, search input, exports, privacy, settings. */
class SqaRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_bug_19_pipeline_renders_twenty_cards_per_column_with_one_form_each(): void
    {
        foreach (range(1, 30) as $i) {
            Lead::create(['full_name' => "Lead {$i}", 'email' => "l{$i}@p{$i}.example", 'lifecycle_stage' => 'new', 'overall_score' => $i]);
        }

        $html = $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()->getContent();
        $this->assertSame(20, substr_count($html, 'name="lifecycle_stage"'), 'one stage form per visible card');
        $this->assertStringContainsString('Lead 30', $html);
        $this->assertStringNotContainsString('Lead 5<', $html);
        $this->assertStringContainsString('View all 30', $html);

        $more = $this->get(route('crm.pipeline', ['expand' => 'new']))->assertOk()->getContent();
        $this->assertSame(30, substr_count($more, 'name="lifecycle_stage"'));
    }

    public function test_bug_20_company_field_searches_instead_of_listing_every_company(): void
    {
        foreach (range(1, 30) as $i) {
            Company::create(['name' => "Acme Branch {$i}", 'country' => 'Pakistan', 'city' => 'Lahore', 'domain' => "acme{$i}.pk"]);
        }
        Company::create(['name' => '24 Hour Fitness', 'country' => 'United States', 'city' => 'Dallas', 'domain' => '24hourfitness.com']);

        $page = $this->actingAs($this->admin)->get(route('leads.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Acme Branch 7', $page);
        $this->assertStringContainsString('company-picker', $page);

        $rows = $this->getJson(route('companies.lookup', ['q' => 'acme']))->assertOk()->json();
        $this->assertCount(20, $rows);
        $this->assertSame([['id' => Company::where('domain', '24hourfitness.com')->value('id'), 'label' => '24 Hour Fitness — Dallas, United States · 24hourfitness.com']],
            $this->getJson(route('companies.lookup', ['q' => 'fitness']))->json());

        $lead = Lead::create(['full_name' => 'X', 'email' => 'x@acme3.pk', 'company_id' => Company::where('domain', 'acme3.pk')->value('id')]);
        $this->get(route('leads.edit', $lead))->assertOk()->assertSee('Acme Branch 3 — Lahore', false);
    }

    public function test_bug_25_lead_export_has_bom_and_the_useful_columns(): void
    {
        $c = Company::create(['name' => 'وقت اللياقة', 'country' => 'Saudi Arabia', 'website' => 'https://fit.sa', 'domain' => 'fit.sa', 'public_phone' => '+966 11 000']);
        Lead::create(['full_name' => 'وقت اللياقة — general inbox', 'email' => 'info@fit.sa', 'company_id' => $c->id, 'source_name' => 'Overture Maps', 'score_explanation' => "Good fit.\nNo SSL."]);

        $csv = $this->actingAs($this->admin)->get(route('leads.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $head = array_flip($rows[0]);
        foreach (['Phone', 'Website', 'Source', 'Score Reasons'] as $col) {
            $this->assertArrayHasKey($col, $head);
        }
        $this->assertSame('وقت اللياقة — general inbox', $rows[1][$head['Full Name']]);
        $this->assertSame(['+966 11 000', 'https://fit.sa', 'Overture Maps', 'Good fit. No SSL.'],
            [$rows[1][$head['Phone']], $rows[1][$head['Website']], $rows[1][$head['Source']], $rows[1][$head['Score Reasons']]]);
    }

    public function test_bugs_26_27_search_ignores_arrays_and_matches_percent_literally(): void
    {
        Lead::create(['full_name' => 'Plain Lead', 'email' => 'a@plain.example']);
        Lead::create(['full_name' => '100% Growth Co', 'email' => 'b@growth.example']);
        Company::create(['name' => 'Under_Score Ltd', 'country' => 'UK']);
        Company::create(['name' => 'UnderXScore Ltd', 'country' => 'UK']);
        $this->actingAs($this->admin);

        foreach (['/leads?search[]=x', '/companies?search[]=x', '/outbox?q[]=x', '/outbox?tab=replies&q[]=x', '/companies/lookup?q[]=x', '/crm/pipeline?expand[]=new'] as $url) {
            $this->get($url)->assertOk();
        }

        $html = $this->get('/leads?search=' . urlencode('%'))->assertOk()->getContent();
        $this->assertStringContainsString('100% Growth Co', $html);
        $this->assertStringNotContainsString('Plain Lead', $html);

        $html = $this->get('/companies?search=' . urlencode('Under_Score'))->assertOk()->getContent();
        $this->assertStringContainsString('Under_Score Ltd', $html);
        $this->assertStringNotContainsString('UnderXScore Ltd', $html);
    }

    public function test_bug_28_app_asks_not_to_be_indexed_and_hides_versions(): void
    {
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
        $res = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $res->assertHeaderMissing('X-Powered-By');
        $this->assertStringNotContainsString('PHP v', $res->getContent());
        $this->assertStringNotContainsString('Laravel v', $res->getContent());
    }

    public function test_bug_29_follow_ups_must_fit_inside_max_sends(): void
    {
        $s = OutreachSetting::current();
        $form = ['sender_name' => 'E', 'sender_email' => 'a@eziline.net', 'sending_domain' => 'eziline.net', 'min_lead_score' => 65, 'min_evidence_confidence' => 65,
            'max_daily_sends' => 25, 'max_sends_per_recipient' => 2, 'sending_window_start' => '09:00', 'sending_window_end' => '18:00', 'sending_timezone' => 'Asia/Karachi',
            'follow_up_delay_days' => 3, 'max_follow_ups' => 2];

        $this->actingAs($this->admin)->put(route('settings.outreach-automation.update'), $form)->assertSessionHasErrors('max_follow_ups');
        $this->put(route('settings.outreach-automation.update'), ['max_sends_per_recipient' => 3] + $form)->assertSessionHasNoErrors();
        $this->assertSame(3, $s->fresh()->maxTouches());

        $s->fresh()->update(['max_sends_per_recipient' => 2, 'max_follow_ups' => 4]);
        $this->assertSame(2, $s->fresh()->maxTouches(), 'the sequence never plans more emails than the send gate allows');
        (require base_path('database/migrations/2026_10_29_000003_sqa_round_three_settings.php'))->up();
        $this->assertSame(1, $s->fresh()->max_follow_ups);
    }
}
