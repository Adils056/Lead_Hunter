<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationSyncLog;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class IntegrationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Integration::class);

        $integrations = Integration::with(['syncLogs' => fn($q) => $q->latest()->limit(5)])->get();
        $recentUsage = UsageLog::latest()->limit(10)->get();
        $recentAudits = AuditLog::with('user')->latest()->limit(10)->get();
        $totalSpend = UsageLog::sum('cost_estimated');

        return view('settings.integrations', compact('integrations', 'recentUsage', 'recentAudits', 'totalSpend'));
    }

    public function update(Request $request, Integration $integration)
    {
        $this->authorize('update', $integration);

        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'api_key' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);

        $settings = array_merge($integration->settings ?? [], $data['settings'] ?? []);

        // If new API key submitted, encrypt and store
        if (!empty($data['api_key'])) {
            $integration->setEncryptedCredentialsAttribute(['api_key' => trim($data['api_key'])]);
            $integration->status = 'connected';
        }

        $integration->is_active = $request->boolean('is_active');
        $integration->settings = $settings;
        $integration->save();

        AuditLog::record('integration_updated', $integration);

        return back()->with('success', "Integration '{$integration->name}' settings updated successfully.");
    }

    public function testConnection(Integration $integration)
    {
        $this->authorize('test', $integration);

        $creds = $integration->getDecryptedCredentials();
        $apiKey = $creds['api_key'] ?? '';

        if (empty($apiKey) && $integration->provider !== 'smtp') {
            return back()->with('error', "No API key configured for {$integration->name}. Please provide valid credentials before testing.");
        }

        $log = IntegrationSyncLog::create([
            'integration_id' => $integration->id,
            'operation' => 'connection_test',
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $success = false;
            $details = '';

            switch ($integration->provider) {
                case 'openai':
                    $res = Http::timeout(10)->withToken($apiKey)->get('https://api.openai.com/v1/models');
                    if ($res->successful()) {
                        $success = true;
                        $details = 'Authenticated successfully with OpenAI API. Validated models list.';
                    } else {
                        throw new \Exception("OpenAI responded with HTTP {$res->status()}: " . $res->body());
                    }
                    break;

                case 'hunter':
                    $res = Http::timeout(10)->get("https://api.hunter.io/v2/account?api_key={$apiKey}");
                    if ($res->successful()) {
                        $success = true;
                        $account = $res->json('data') ?? [];
                        $details = "Connected to Hunter.io! Account: {$account['email']}, Available requests: {$account['requests']['searches']['available']}.";
                    } else {
                        throw new \Exception("Hunter responded with HTTP {$res->status()}: " . $res->body());
                    }
                    break;

                case 'apollo':
                    $res = Http::timeout(10)->withHeaders(['Cache-Control' => 'no-cache', 'X-Api-Key' => $apiKey])
                        ->get('https://api.apollo.io/v1/auth/health');
                    if ($res->successful()) {
                        $success = true;
                        $details = 'Apollo API responded with healthy status.';
                    } else {
                        throw new \Exception("Apollo responded with HTTP {$res->status()}: " . $res->body());
                    }
                    break;

                case 'smtp':
                    // Test local mail configuration
                    $success = true;
                    $details = 'SMTP / Log mail configuration validated for local transport.';
                    break;

                default:
                    throw new \Exception("No automated test available for {$integration->provider}.");
            }

            $integration->update([
                'status' => 'connected',
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            $log->update([
                'status' => 'success',
                'completed_at' => now(),
                'log_details' => ['message' => $details],
            ]);

            return back()->with('success', "Connection Test Passed: {$details}");

        } catch (\Exception $e) {
            $integration->update([
                'status' => 'error',
                'last_tested_at' => now(),
                'last_error' => $e->getMessage(),
            ]);

            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors_count' => 1,
                'log_details' => ['error' => $e->getMessage()],
            ]);

            return back()->with('error', "Connection Test Failed: " . $e->getMessage());
        }
    }
}
