<?php

namespace App\Services\Discovery\Adapters;

use App\Models\IcpProfile;
use App\Models\LeadSource;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\LeadDiscoveryInterface;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing: LeadSource status bookkeeping, error capture, ingestor wiring.
 * Subclasses implement fetchAndIngest().
 */
abstract class AbstractDiscoveryAdapter implements LeadDiscoveryInterface
{
    protected string $sourceSlug;
    protected string $sourceName;
    protected string $providerType;
    protected string $description = '';

    public function getSourceSlug(): string
    {
        return $this->sourceSlug;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    abstract protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void;

    public function discover(array $options = []): DiscoveryResult
    {
        $result = new DiscoveryResult($this->sourceSlug, $this->sourceName);

        if (!$this->isConfigured()) {
            $result->addError("{$this->sourceName} is not configured (missing API key or disabled).");

            return $result;
        }

        $source = LeadSource::firstOrCreate(['slug' => $this->sourceSlug], [
            'name' => $this->sourceName,
            'provider_type' => $this->providerType,
            'description' => $this->description,
            'is_active' => true,
            'status' => 'idle',
        ]);
        $source->update(['status' => 'running']);

        $icp = $options['icp'] ?? null;
        $ingestor = new ProspectIngestor($this->sourceSlug, $this->sourceName, $icp instanceof IcpProfile ? $icp : null);

        try {
            $this->fetchAndIngest($options, $result, $ingestor);
            $source->update([
                'status' => 'idle',
                'last_synced_at' => now(),
                'total_discovered' => (int) $source->total_discovered + $result->newCompaniesCount,
                'last_error' => $result->errors ? implode(' | ', $result->errors) : null,
            ]);
        } catch (\Throwable $e) {
            $msg = $e instanceof ConnectionException ? $this->connectionError($this->sourceName, $e) : $e->getMessage();
            $result->addError($msg);
            $source->update([
                'status' => 'error',
                'last_error' => $msg,
                'total_errors' => (int) $source->total_errors + 1,
            ]);
            Log::error(static::class . ' failure: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * HTTP client for keyless public APIs: 10 s to connect, one retry (1.5 s later) on connection-level
     * failures only (DNS, TLS handshake, connect/read timeouts). HTTP error statuses are returned, not thrown,
     * so adapters keep handling 403/429/503 themselves. A ConnectionException is still thrown after the retry.
     */
    protected function resilientHttp(int $timeout = 30): PendingRequest
    {
        return Http::connectTimeout(10)->timeout($timeout)
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** One clear sentence for a connection failure: what broke (DNS / timeout / TLS / refused) and what to check. */
    protected function connectionError(string $label, \Throwable $e): string
    {
        $m = $e->getMessage();
        $why = match (true) {
            str_contains($m, 'Resolving timed out'), str_contains($m, 'Could not resolve host'), str_contains($m, 'getaddrinfo')
                => 'DNS lookup failed — check this PC\'s DNS server and firewall (try 8.8.8.8 / 1.1.1.1)',
            str_contains($m, 'Connection timed out'), str_contains($m, 'Operation timed out'), str_contains($m, 'timed out')
                => 'network timeout — the host did not answer in time (firewall, proxy or a slow link)',
            str_contains($m, 'SSL'), str_contains($m, 'certificate'), str_contains($m, 'schannel')
                => 'TLS/certificate problem — check php.ini curl.cainfo / openssl.cafile',
            str_contains($m, 'refused') => 'connection refused by the host or a firewall',
            default => 'network error',
        };

        return "{$label} unreachable ({$why}): " . mb_substr($m, 0, 160);
    }

    /** Map free text to our service keys (shared heuristic). */
    protected function inferServices(string $text): array
    {
        $t = strtolower($text);
        $map = [
            'erp_pos' => ['erp', 'pos ', 'point of sale', 'inventory', 'crm'],
            'api_integration' => ['api', 'integration', 'integrate'],
            'saas_mvp' => ['saas', 'mvp', 'platform build'],
            'mobile_app' => ['mobile', 'ios', 'android', 'flutter', 'react native'],
            'ecommerce' => ['ecommerce', 'e-commerce', 'shopify', 'woocommerce', 'online store', 'magento'],
            'web_redesign' => ['redesign', 'website', 'web site', 'portal', 'cms', 'wordpress'],
            'ai_automation' => [' ai ', 'machine learning', 'automation', 'llm', 'chatbot'],
            'laravel_php' => ['laravel', 'php'],
        ];
        $out = [];
        foreach ($map as $svc => $needles) {
            foreach ($needles as $n) {
                if (str_contains(' ' . $t . ' ', $n)) {
                    $out[] = $svc;
                    break;
                }
            }
        }

        return $out ?: ['custom_software'];
    }
}
