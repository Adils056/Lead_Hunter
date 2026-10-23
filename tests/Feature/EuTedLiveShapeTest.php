<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Services\Discovery\Adapters\EuTedAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fixture = trimmed copy of a REAL response captured 2026-10-23 from POST https://api.ted.europa.eu/v3/notices/search
 * (publication numbers, buyer organisations, generic buyer mailboxes and lot deadlines are as returned; personal
 * mailboxes were replaced by role addresses, multilingual maps trimmed to two languages).
 */
class EuTedLiveShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function realShape(): array
    {
        return ['notices' => [
            [ // single buyer, single lot, eng title present
                'deadline-receipt-tender-date-lot' => [now()->addDays(18)->format('Y-m-d') . '+02:00'],
                'publication-number' => '696544-2026',
                'classification-cpv' => ['72250000', '72250000'],
                'buyer-name' => ['slk' => ['Železničná spoločnosť Slovensko, a.s.']],
                'publication-date' => '2026-10-09+02:00',
                'notice-title' => ['eng' => 'Slovakia – System and support services – SAP Standard Support + Back Maintenance', 'slk' => 'SAP Standard Support + Back Maintenance'],
                'title-proc' => ['slk' => 'SAP Standard Support + Back Maintenance na obdobie dvoch rokov'],
                'buyer-country' => ['SVK'],
                'buyer-email' => ['procurement@slovakrail.sk'],
                'organisation-tel-buyer' => ['+421940498692'],
                'form-type' => 'competition',
                'notice-type' => 'cn-standard',
                'links' => ['html' => ['ENG' => 'https://ted.europa.eu/en/notice/-/detail/696544-2026']],
            ],
            [ // award RESULT (form-type result): must be skipped — nobody is buying any more
                'publication-number' => '696551-2026',
                'classification-cpv' => ['48000000'],
                'buyer-name' => ['spa' => ['Dirección General de Racionalización y Centralización de la Contratación']],
                'publication-date' => '2026-10-09+02:00',
                'notice-title' => ['eng' => 'Spain – Software package and information systems – Licences'],
                'buyer-country' => ['ESP'],
                'buyer-email' => ['secretaria.contratacioncentralizada@hacienda.gob.es'],
                'form-type' => 'result',
                'notice-type' => 'can-standard',
            ],
            [ // several buyers (list inside the language map), several websites/emails, no deadline field
                'publication-number' => '696571-2026',
                'classification-cpv' => ['48900000'],
                'buyer-name' => ['est' => ['AS Tallinna Lennujaam', 'AS Airport City', 'AS Airport Fuel Terminal']],
                'publication-date' => '2026-10-09+02:00',
                'buyer-internet-address' => ['http://www.tallinn-airport.ee', 'https://groundhandling.ee'],
                'notice-title' => ['eng' => 'Estonia – Miscellaneous software package and computer systems – Microsoft licences'],
                'buyer-country' => ['EST', 'EST', 'EST'],
                'buyer-email' => ['administration@tll.aero', 'hange@tll.aero', 'info@aft.ee'],
                'form-type' => 'competition',
                'notice-type' => 'cn-standard',
            ],
            [ // eight lots with the same deadline, already PASSED: must be skipped
                'deadline-receipt-tender-date-lot' => array_fill(0, 8, now()->subDays(2)->format('Y-m-d') . '+02:00'),
                'publication-number' => '696577-2026',
                'classification-cpv' => ['48820000', '30200000'],
                'buyer-name' => ['ron' => ['UNIVERSITATEA "BABES-BOLYAI"']],
                'publication-date' => '2026-10-09+02:00',
                'buyer-internet-address' => ['https://www.ubbcluj.ro'],
                'notice-title' => ['eng' => 'Romania – Servers – IT equipment for laboratories'],
                'buyer-country' => ['ROU'],
                'buyer-email' => ['achizitii@ubbcluj.ro'],
                'form-type' => 'competition',
                'notice-type' => 'cn-standard',
            ],
        ], 'totalNoticeCount' => 3263, 'iterationNextToken' => null, 'timedOut' => false];
    }

    public function test_adapter_ingests_the_real_v3_response_shape(): void
    {
        Http::fake(['api.ted.europa.eu/*' => Http::response($this->realShape(), 200), '*' => Http::response('', 404)]);

        $r = (new EuTedAdapter())->discover(['limit' => 10, 'days' => 14]);

        $this->assertSame([], $r->errors);
        $this->assertSame(2, $r->totalFound, 'result notice and expired notice are skipped');
        $this->assertSame(2, $r->newCompaniesCount);
        $this->assertDatabaseHas('companies', ['name' => 'Železničná spoločnosť Slovensko, a.s.', 'public_email' => 'procurement@slovakrail.sk', 'public_phone' => '+421940498692', 'country' => 'SVK']);
        $this->assertDatabaseHas('companies', ['name' => 'AS Tallinna Lennujaam', 'website' => 'http://www.tallinn-airport.ee', 'public_email' => 'administration@tll.aero']);
        $this->assertDatabaseMissing('companies', ['name' => 'Dirección General de Racionalización y Centralización de la Contratación']);
        $this->assertDatabaseMissing('companies', ['name' => 'UNIVERSITATEA "BABES-BOLYAI"']);
        $this->assertDatabaseHas('buying_signals', ['source_url' => 'https://ted.europa.eu/en/notice/-/detail/696544-2026', 'signal_type' => 'procurement_rfp',
            'expiry_date' => now()->addDays(18)->format('Y-m-d') . ' 00:00:00']);
        $this->assertSame('generic_inbox', Lead::where('email', 'procurement@slovakrail.sk')->value('contact_status'), 'published tender contacts are never auto-emailed');
        $this->assertSame(now()->addDays(18)->format('Y-m-d'), EuTedAdapter::deadline([now()->addDays(3)->format('Y-m-d') . '+02:00', now()->addDays(18)->format('Y-m-d') . '+02:00']), 'latest lot deadline wins');

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'api.ted.europa.eu/v3/notices/search')
                && str_contains($req['query'], 'form-type=competition')
                && in_array('deadline-receipt-tender-date-lot', $req['fields'], true)
                && $req['paginationMode'] === 'PAGE_NUMBER';
        });
    }

    public function test_connection_failure_is_a_clear_error_not_an_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Resolving timed out after 10000 milliseconds'));

        $adapter = new EuTedAdapter();
        $r = $adapter->discover(['limit' => 5]);
        $this->assertSame(0, $r->newCompaniesCount);
        $this->assertCount(1, $r->errors);
        $this->assertStringContainsString('TED unreachable (DNS/network): cURL error 28', $r->errors[0]);
        $this->assertStringContainsString('8.8.8.8', $r->errors[0]);

        $t = $adapter->testConnection();
        $this->assertFalse($t['success']);
        $this->assertStringContainsString('TED unreachable (DNS/network)', $t['message']);
        $this->assertSame(0, Company::count());
    }
}
