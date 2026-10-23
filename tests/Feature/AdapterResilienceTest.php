<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\Discovery\Adapters\ApolloOrgDiscoveryAdapter;
use App\Services\Discovery\Adapters\GreenhouseLeverWatchlistAdapter;
use App\Services\Discovery\Adapters\UkContractsFinderAdapter;
use App\Services\Discovery\Adapters\UkFindATenderAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the real failure seen on the user's PC (2026-10-22): cURL error 28
 * "Resolving timed out" on Contracts Finder and Apollo. Adapters must retry once on connection-level
 * errors, then record ONE clear message naming the cause (DNS) instead of throwing.
 * Note: Http::assertSentCount() does not count attempts whose fake threw, so attempts are counted by hand.
 */
class AdapterResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected const DNS = 'cURL error 28: Resolving timed out after 10001 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html) for https://www.contractsfinder.service.gov.uk/Published/Notices/OCDS/Search';

    protected int $attempts = 0;

    /** Every request fails at connection level (DNS); attempts are counted. */
    protected function fakeAlwaysDown(): void
    {
        $this->attempts = 0;
        Http::fake(function (Request $r) {
            $this->attempts++;
            throw new ConnectionException(self::DNS);
        });
    }

    /** First attempt fails at connection level, second succeeds with $body. */
    protected function fakeFlaky(array $body): void
    {
        $this->attempts = 0;
        Http::fake(function (Request $r) use ($body) {
            if (++$this->attempts === 1) {
                throw new ConnectionException('cURL error 28: Connection timed out after 10000 milliseconds');
            }

            return Http::response($body, 200);
        });
    }

    public function test_contracts_finder_dns_failure_is_recorded_not_thrown(): void
    {
        $this->fakeAlwaysDown();
        $r = (new UkContractsFinderAdapter())->discover(['limit' => 2, 'days' => 3]);
        $this->assertCount(1, $r->errors);
        $this->assertStringContainsString('Contracts Finder unreachable', $r->errors[0]);
        $this->assertStringContainsString('DNS lookup failed', $r->errors[0]);
        $this->assertSame(0, $r->newCompaniesCount);
        $this->assertSame(2, $this->attempts, 'one retry, then give up');

        $t = (new UkContractsFinderAdapter())->testConnection();
        $this->assertFalse($t['success']);
        $this->assertStringContainsString('DNS lookup failed', $t['message']);
    }

    public function test_find_a_tender_dns_failure_is_recorded_not_thrown(): void
    {
        $this->fakeAlwaysDown();
        $r = (new UkFindATenderAdapter())->discover(['limit' => 2, 'days' => 3]);
        $this->assertCount(1, $r->errors);
        $this->assertStringContainsString('Find a Tender unreachable (DNS lookup failed', $r->errors[0]);
        $this->assertFalse((new UkFindATenderAdapter())->testConnection()['success']);
    }

    public function test_connection_error_is_retried_once_then_succeeds(): void
    {
        $this->fakeFlaky(['releases' => []]);
        $r = (new UkContractsFinderAdapter())->discover(['limit' => 2, 'days' => 3]);
        $this->assertSame([], $r->errors, json_encode($r->errors));
        $this->assertSame(2, $this->attempts);
    }

    public function test_http_error_statuses_are_still_handled_by_the_adapter_not_retried_or_thrown(): void
    {
        $this->attempts = 0;
        Http::fake([
            'contractsfinder.service.gov.uk/*' => function () {
                $this->attempts++;

                return Http::response('slow down', 403);
            },
            'find-tender.service.gov.uk/*' => Http::response('', 503, ['Retry-After' => '30']),
        ]);
        $r = (new UkContractsFinderAdapter())->discover(['limit' => 2]);
        $this->assertStringContainsString('rate limit hit (HTTP 403)', $r->errors[0]);
        $this->assertSame(1, $this->attempts, 'status errors are not retried');

        $r = (new UkFindATenderAdapter())->discover(['limit' => 2]);
        $this->assertStringContainsString('HTTP 503, Retry-After 30s', $r->errors[0]);
    }

    public function test_greenhouse_lever_unreachable_board_is_skipped_with_message_and_others_continue(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect: Connection refused'),
            'api.lever.co/*' => Http::response([['text' => 'Senior Backend Engineer', 'hostedUrl' => 'https://jobs.lever.co/acme/1', 'categories' => ['location' => 'Remote'], 'createdAt' => 1760000000000]], 200),
        ]);
        $r = (new GreenhouseLeverWatchlistAdapter())->discover(['watchlist' => ['greenhouse:ghost|ghost.test', 'lever:acme|acme.test']]);
        $this->assertCount(1, $r->errors);
        $this->assertStringContainsString('Greenhouse board ghost unreachable (connection refused', $r->errors[0]);
        $this->assertSame(1, $r->newCompaniesCount, 'the reachable board is still ingested');
    }

    public function test_apollo_dns_failure_is_recorded_without_calling_paid_search(): void
    {
        $i = new Integration(['provider' => 'apollo', 'name' => 'Apollo', 'is_active' => true]);
        $i->encrypted_credentials = ['api_key' => 'test-key-not-real'];
        $i->save();
        $this->fakeAlwaysDown();
        $a = new ApolloOrgDiscoveryAdapter();
        $this->assertTrue($a->isConfigured());
        $t = $a->testConnection();
        $this->assertFalse($t['success']);
        $this->assertStringContainsString('Apollo.io unreachable (DNS lookup failed', $t['message']);
        $r = $a->discover(['limit' => 1]);
        $this->assertStringContainsString('Apollo.io unreachable', $r->errors[0]);
        $this->assertSame(0, $r->newCompaniesCount);
    }

    public function test_connection_error_text_explains_tls_and_timeout_causes(): void
    {
        Http::fake([
            'contractsfinder.service.gov.uk/*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'),
            'find-tender.service.gov.uk/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received'),
        ]);
        $this->assertStringContainsString('TLS/certificate problem', (new UkContractsFinderAdapter())->testConnection()['message']);
        $this->assertStringContainsString('network timeout', (new UkFindATenderAdapter())->testConnection()['message']);
    }
}
