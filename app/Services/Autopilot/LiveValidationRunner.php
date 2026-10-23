<?php

namespace App\Services\Autopilot;

use App\Models\Company;
use App\Models\DataProvenance;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Discovery\DiscoveryManager;
use App\Services\EligibilityGateService;
use App\Services\Hunting\LeadHuntService;
use App\Services\LeadScoringService;
use App\Services\Mail\ReplySyncService;
use App\Services\Outreach\OutreachMailer;
use App\Services\Sales\ConversionAssistantService;
use Illuminate\Support\Facades\DB;

/**
 * Real-data end-to-end validation (Phase 12). Uses REAL providers with whatever credentials exist.
 * Never emails prospects: the optional live pilot goes only to an active admin's own address.
 * Produces storage/app/validation/validation-<timestamp>.{md,json}. Nothing here is mocked.
 */
class LiveValidationRunner
{
    protected array $r = ['started_at' => null, 'sections' => []];
    protected $out;

    public function run(?IcpProfile $icp, array $opts = [], ?callable $out = null): array
    {
        $this->out = $out ?? fn ($l) => null;
        $this->r['started_at'] = now()->toIso8601String();
        $usageStart = UsageLog::max('id') ?? 0;
        $t0 = microtime(true);

        // 1. Integrations & credentials
        $this->section('1. Environment & integration checks', function () {
            $checks = app(HealthService::class)->checks();

            return ['ok' => collect($checks)->where('status', 'ok')->count(), 'warn' => collect($checks)->where('status', 'warn')->count(),
                'fail' => collect($checks)->where('status', 'fail')->count(), 'checks' => $checks];
        }, fn ($d) => $d['fail'] > 0 ? 'fail' : ($d['warn'] > 0 ? 'warn' : 'pass'));

        $this->section('2. Real provider connectivity', function () {
            $rows = [];
            foreach (app(DiscoveryManager::class)->getAdapters() as $slug => $a) {
                if (!$a->isConfigured()) {
                    $rows[$slug] = ['configured' => false];
                    continue;
                }
                $t = microtime(true);
                $res = $a->testConnection();
                if ($res['success'] === true) {
                    app(\App\Services\Integrations\ProviderHealthService::class)->success($slug, true);
                } elseif ($res['success'] === false) {
                    app(\App\Services\Integrations\ProviderHealthService::class)->failure($slug, $res['message']);
                }
                $rows[$slug] = ['configured' => true, 'success' => $res['success'], 'message' => $res['message'], 'ms' => (int) ((microtime(true) - $t) * 1000)];
            }

            return $rows;
        }, function ($rows) {
            $tested = collect($rows)->filter(fn ($r) => ($r['configured'] ?? false) && ($r['success'] ?? null) !== null);
            $okCount = $tested->where('success', true)->count();

            return $tested->isEmpty() ? 'not_tested' : ($okCount === $tested->count() ? 'pass' : ($okCount === 0 ? 'fail' : 'warn'));
        });

        if (!$icp) {
            $this->r['sections'][] = ['title' => 'Search', 'ok' => false, 'data' => 'No active ICP. Run php artisan db:seed --class=IcpProfileSeeder or create one in Lead Hunter.'];

            return $this->finish($usageStart, $t0);
        }
        $icp->forceFill(['max_results_per_run' => (int) ($opts['limit'] ?? 5)])->syncOriginalAttribute('max_results_per_run'); // small run; not persisted
        $hunter = app(LeadHuntService::class);
        $before = Lead::max('id') ?? 0;

        // 3-5. Real search, provenance, dedupe
        $runs = $this->section("3. Real lead search — ICP \"{$icp->name}\"", function () use ($hunter, $icp, $opts) {
            $runs = $hunter->hunt($icp, null, false, 0, $opts['source'] ?? null);

            return array_map(fn ($r) => $r->only(['source_slug', 'status', 'total_found', 'new_companies', 'new_leads', 'duplicates', 'excluded', 'errors', 'estimated_cost_cents']), $runs);
        }, function ($runs) {
            $found = collect($runs)->sum('total_found');
            $ran = collect($runs)->whereIn('status', ['completed', 'failed']);

            return $found > 0 ? 'pass' : ($ran->isEmpty() ? 'not_tested' : 'fail');
        });
        $newLeads = Lead::with('company')->where('id', '>', $before)->get();

        $this->section('4. Source evidence & provenance (sample)', function () use ($newLeads) {
            return $newLeads->take(10)->map(fn ($l) => [
                'company' => $l->company?->name, 'city' => $l->company?->city, 'website' => $l->company?->website,
                'source' => $l->company?->source_provider, 'source_url' => $l->company?->source_url,
                'contact_status' => $l->contact_status, 'has_email' => (bool) $l->email, // no personal data in the report
                'provenance_fields' => DataProvenance::where('subject_type', (new Company())->getMorphClass())->where('subject_id', $l->company_id)->pluck('field')->unique()->values()->all(),
            ])->values()->all();
        }, fn ($d) => $d ? 'pass' : 'not_tested');

        $this->section('5. Deduplication (same search re-run)', function () use ($hunter, $icp, $opts) {
            $runs = $hunter->hunt($icp, null, false, 0, $opts['source'] ?? null);

            $dups = collect($runs)->sum('duplicates');
            $new = collect($runs)->sum('new_companies');

            return ['new_companies_on_rerun' => $new, 'duplicates_detected' => $dups,
                'verdict' => $dups === 0 && $new === 0 ? 'NOT TESTED — the first search returned nothing to de-duplicate'
                    : ($new === 0 ? 'PASS — every re-found business was recognised, no duplicates created' : 'CHECK — new companies on re-run (may be genuinely new results)')];
        }, fn ($d) => str_starts_with($d['verdict'], 'PASS') ? 'pass' : (str_starts_with($d['verdict'], 'NOT') ? 'not_tested' : 'warn'));

        // 6-7. Audits & scores
        $this->section('6. Website audits (reachable sites, max ' . ($opts['audits'] ?? 5) . ')', function () use ($newLeads, $opts) {
            $rows = [];
            foreach ($newLeads->filter(fn ($l) => $l->company?->website)->unique('company_id')->take((int) ($opts['audits'] ?? 5)) as $l) {
                $t = microtime(true);
                try {
                    $a = app(WebsiteAuditService::class)->audit($l->company);
                    $rows[] = ['company' => $l->company->name, 'url' => $a->final_url ?? $a->url, 'status' => $a->status, 'http' => $a->http_status,
                        'opportunity' => $a->opportunity_score, 'failed_checks' => collect($a->checks)->where('status', 'fail')->pluck('label')->all(),
                        'public_email_found' => (bool) collect($a->contacts_found['emails'] ?? [])->firstWhere('on_company_domain', true), 'ms' => (int) ((microtime(true) - $t) * 1000)];
                } catch (\Throwable $e) {
                    $rows[] = ['company' => $l->company->name, 'error' => $e->getMessage()];
                }
            }

            return $rows ?: 'No new leads with websites to audit.';
        }, fn ($d) => is_string($d) ? 'not_tested' : (collect($d)->where('status', 'completed')->isNotEmpty() ? 'pass' : 'fail'));

        $this->section('7. Scores from observed data', function () use ($newLeads) {
            return $newLeads->map(function ($l) {
                app(LeadScoringService::class)->evaluateLead($l->fresh());
                $l->refresh();
                $bd = $l->score_breakdown ?? [];

                return ['company' => $l->company?->name, 'score' => $l->overall_score, 'band' => \App\Support\ScoreBands::band($l->overall_score),
                    'confidence' => $bd['confidence'] ?? null, 'state' => $l->pipeline_state, 'next' => $bd['suggested_next_action'] ?? null];
            })->sortByDesc('score')->values()->take(15)->all();
        }, fn ($d) => $d ? 'pass' : 'not_tested');

        // 8-10. Eligibility, drafts, suppression
        $this->section('8. Outreach eligibility (gate, no sending)', function () use ($newLeads) {
            $gate = app(EligibilityGateService::class);
            $rows = $newLeads->map(fn ($l) => ['company' => $l->company?->name, 'eligible' => ($e = $gate->evaluate($l->fresh()))->isEligible, 'reason' => $e->primaryReason]);

            return ['eligible' => $rows->where('eligible', true)->count(), 'not_eligible' => $rows->where('eligible', false)->count(),
                'top_reasons' => $rows->where('eligible', false)->countBy('reason')->sortDesc()->take(5)->all()];
        }, fn ($d) => ($d['eligible'] + $d['not_eligible']) === 0 ? 'not_tested' : 'pass');

        $drafts = $this->section('9. Personalised drafts from verified facts (not sent)', function () use ($newLeads) {
            $a = app(ConversionAssistantService::class);
            $rows = [];
            foreach ($newLeads->take(10) as $l) {
                $d = $a->draftFirstEmail($l->fresh(), false);
                if ($d['ok'] ?? false) {
                    $rows[] = ['company' => $l->company?->name, 'subject' => $d['draft']['subject'], 'words' => $d['draft']['word_count'],
                        'facts' => array_map(fn ($f) => $f['text'], $d['facts_used']), 'lead_id' => $l->id];
                }
            }

            return $rows ?: 'No drafts possible yet (no verified observations — audits needed or none reachable).';
        }, fn ($d) => is_string($d) ? 'not_tested' : 'pass');

        $this->section('10. Suppression & gate rules (rolled back, no data changed)', function () use ($newLeads) {
            $lead = $newLeads->firstWhere('email') ?? Lead::whereNotNull('email')->first();
            if (!$lead) {
                return 'No lead with an email to test against.';
            }
            DB::beginTransaction();
            try {
                Suppression::addSuppression($lead->email, 'unsubscribe', 'validation_test');
                $e = app(EligibilityGateService::class)->evaluate($lead->fresh());
                $ok = !$e->isEligible && isset($e->failedChecks['suppression']);
            } finally {
                DB::rollBack();
            }

            return $ok ? 'PASS — suppressed address is blocked by the gate' : 'FAIL — suppression did not block';
        }, fn ($d) => str_starts_with((string) $d, 'PASS') ? 'pass' : (str_starts_with((string) $d, 'FAIL') ? 'fail' : 'not_tested'));

        // 11-12. Controlled live pilot (only to an admin's own address)
        $this->section('11. Controlled live email pilot', function () use ($opts, $drafts) {
            $to = $opts['pilot_to'] ?? null;
            if (!$to) {
                return 'Skipped (pass --pilot-to=<your admin email> to send ONE real test email to yourself).';
            }
            $admin = User::where('email', $to)->where('role', 'admin')->where('is_active', true)->first();
            if (!$admin) {
                return "Refused: {$to} is not an active admin user. Pilot emails only go to your own team.";
            }
            $d = is_array($drafts) ? ($drafts[0] ?? null) : null;
            $body = $d ? "This is a LeadPilot pilot. It is the real draft the system would send to {$d['company']}:\n\n---\n" . app(ConversionAssistantService::class)->draftFirstEmail(Lead::find($d['lead_id']), false)['draft']['body'] . "\n---\n\nReply to this email with any text to test reply sync."
                : "LeadPilot pilot email (no draft available yet). Reply to this email to test reply sync.";
            $t = microtime(true);
            try {
                $id = app(OutreachMailer::class)->send($to, $admin->name, '[LeadPilot pilot] ' . ($d['subject'] ?? 'SMTP + reply test'), $body);

                return ['status' => in_array(config('mail.default'), ['log', 'array'], true) ? 'logged_only (MAIL_MAILER=' . config('mail.default') . ')' : 'accepted_by_smtp',
                    'message_id' => $id, 'ms' => (int) ((microtime(true) - $t) * 1000),
                    'note' => 'SMTP acceptance is not inbox delivery — check your inbox/spam folder. Reply to it, then run: php artisan leadpilot:sync-replies'];
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'hint' => $this->smtpHint($e->getMessage())];
            }
        }, fn ($d) => is_string($d) ? (str_starts_with($d, 'Refused') ? 'fail' : 'not_tested') : (($d['status'] ?? '') === 'accepted_by_smtp' ? 'pass' : (str_starts_with((string) ($d['status'] ?? ''), 'logged_only') ? 'warn' : 'fail')));

        $this->section('12. Reply sync (IMAP)', function () {
            $svc = app(ReplySyncService::class);

            return $svc->isConfigured() ? $svc->sync(20) : 'Not configured (IMAP_ENABLED/IMAP_PASSWORD).';
        }, fn ($d) => is_string($d) ? 'not_tested' : (empty($d['error']) ? 'pass' : 'fail'));

        // 13-14. Approvals & notifications
        $this->section('13. Human approval + notification', function () {
            $req = app(ApprovalService::class)->request('system_test', 'validation:' . now()->format('YmdHis'), [
                'title' => 'LeadPilot validation: please approve to test workflow resumption',
                'summary' => 'Created by leadpilot:validate-live. Approving it proves the approval → execution path works. No external effect.',
                'priority' => 'low', 'options' => ['approve', 'reject'], 'recommended' => 'approve',
            ]);
            $deliveries = \App\Models\NotificationDelivery::where('dedupe_key', 'like', "approval:{$req->id}:%")->get(['channel', 'status', 'error'])->toArray();

            return ['approval_id' => $req->id, 'url' => route('approvals.show', $req), 'status' => $req->fresh()->status, 'notification_deliveries' => $deliveries,
                'next' => 'Open the URL as an admin and click Approve (or run: php artisan leadpilot:approval ' . $req->id . ' approve --as=<admin email>). Status will move pending → executed.'];
        }, function ($d) {
            $mail = collect($d['notification_deliveries'])->where('channel', 'mail');
            $inApp = collect($d['notification_deliveries'])->where('channel', 'database')->where('status', 'stored')->isNotEmpty();

            return !$inApp ? 'fail' : ($mail->isNotEmpty() && $mail->every(fn ($m) => $m['status'] === 'accepted_by_smtp') ? 'pass' : 'warn');
        });

        return $this->finish($usageStart, $t0);
    }

    /**
     * Run a section. The VERDICT (pass | warn | fail | not_tested) is judged from the data, not from
     * "did it throw" — a step that ran but found nothing is NOT a pass.
     */
    protected function section(string $title, callable $fn, ?callable $judge = null): mixed
    {
        ($this->out)("▶ {$title}");
        $t = microtime(true);
        try {
            $data = $fn();
            $verdict = $judge ? $judge($data) : 'pass';
            $this->r['sections'][] = ['title' => $title, 'ok' => in_array($verdict, ['pass', 'warn'], true), 'verdict' => $verdict, 'ms' => (int) ((microtime(true) - $t) * 1000), 'data' => $data];

            return $data;
        } catch (\Throwable $e) {
            $this->r['sections'][] = ['title' => $title, 'ok' => false, 'verdict' => 'fail', 'ms' => (int) ((microtime(true) - $t) * 1000), 'data' => 'ERROR: ' . $e->getMessage()];

            return null;
        }
    }

    /** Plain-language diagnosis of common SMTP errors (no secrets echoed). */
    public function smtpHint(string $err): string
    {
        $e = strtolower($err);

        return match (true) {
            str_contains($e, 'getaddrinfo') || str_contains($e, 'name or service not known') || str_contains($e, 'no such host') => 'DNS/network: this machine cannot reach smtp.gmail.com (offline, firewall, or sandbox). Not a password problem.',
            str_contains($e, '535') || str_contains($e, 'username and password not accepted') || str_contains($e, 'badcredentials') => 'Gmail rejected the login: use a 16-character App Password (2-Step Verification ON), written in .env WITHOUT spaces and in quotes: MAIL_PASSWORD="abcdabcdabcdabcd".',
            str_contains($e, '534') || str_contains($e, 'application-specific password required') => 'Gmail requires an App Password for this account (not your normal password).',
            str_contains($e, 'timed out') || str_contains($e, 'connection refused') => 'Port 587 blocked (antivirus/firewall/ISP). Try MAIL_PORT=465 with MAIL_SCHEME=smtps, or allow outbound 587.',
            str_contains($e, 'certificate') || str_contains($e, 'ssl') => 'TLS/certificate problem: update XAMPP CA bundle (php.ini openssl.cafile) or check system time.',
            str_contains($e, '550') || str_contains($e, '5.7') => 'Gmail refused the message (policy/sending limit). Check the Gmail account for security alerts.',
            default => 'See the error text; run php artisan leadpilot:mail-test <your email> for a focused SMTP test.',
        };
    }

    protected function finish(int $usageStart, float $t0): array
    {
        $this->r['verdicts'] = collect($this->r['sections'])->countBy(fn ($s) => $s['verdict'] ?? ($s['ok'] ? 'pass' : 'fail'))->all();
        $usage = UsageLog::where('id', '>', $usageStart)->get();
        $this->r['costs'] = [
            'actual_usd' => round($usage->where('is_estimate', false)->sum('cost_cents') / 100, 2),
            'estimated_usd' => round($usage->where('is_estimate', true)->sum('cost_cents') / 100, 2),
            'calls' => $usage->groupBy('provider')->map->count()->all(),
        ];
        $this->r['total_ms'] = (int) ((microtime(true) - $t0) * 1000);
        $this->r['finished_at'] = now()->toIso8601String();
        $this->r['environment'] = ['app_env' => config('app.env'), 'mailer' => config('mail.default'), 'php' => PHP_VERSION, 'db' => config('database.default')];

        $dir = storage_path('app/validation');
        @mkdir($dir, 0775, true);
        $base = $dir . '/validation-' . now()->format('Ymd-His');
        file_put_contents("{$base}.json", json_encode($this->r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents("{$base}.md", $this->markdown());
        $this->r['report_files'] = ["{$base}.md", "{$base}.json"];

        return $this->r;
    }

    protected function markdown(): string
    {
        $md = "# LeadPilot live validation report\n\nStarted {$this->r['started_at']} · finished {$this->r['finished_at']} · {$this->r['total_ms']} ms\n\n";
        $md .= "> Real providers only — nothing in this report is mocked. Personal data is omitted.\n\n";
        $icon = ['pass' => '✅ PASS', 'warn' => '⚠️ WARN', 'fail' => '❌ FAIL', 'not_tested' => '⏸ NOT TESTED'];
        $md .= "| Step | Verdict | ms |\n|---|---|---|\n";
        foreach ($this->r['sections'] as $s) {
            $md .= "| {$s['title']} | " . ($icon[$s['verdict'] ?? 'pass'] ?? $s['verdict']) . " | {$s['ms']} |\n";
        }
        $md .= "\n";
        foreach ($this->r['sections'] as $s) {
            $md .= '## ' . ($s['ok'] ? '' : '⚠ ') . $s['title'] . " ({$s['ms']} ms)\n\n```json\n" . json_encode($s['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```\n\n";
        }
        $md .= "## Costs\n\nActual: \${$this->r['costs']['actual_usd']} · Estimated: \${$this->r['costs']['estimated_usd']}\n\n```json\n" . json_encode($this->r['costs']['calls']) . "\n```\n";

        return $md;
    }
}
