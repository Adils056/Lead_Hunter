<?php

namespace App\Services\Audit;

use App\Models\Company;
use App\Models\WebsiteAudit;
use Illuminate\Support\Collection;

/**
 * Benchmarks a business's website against its local competitors: the same kind of business (industry group) in the
 * same city first, then the same country, each with its own audited website. Scores come only from audit checks
 * that were actually run, per area (0-100); where a business trails, the report says how many competitors already
 * have what it lacks. Nothing is estimated: a site that was not audited is not scored.
 */
class CompetitorBenchmark
{
    /** Areas a visitor or Google notices, built from audit check keys. */
    public const AREAS = [
        'mobile_speed' => ['label' => 'Mobile & speed', 'checks' => ['viewport', 'response_time', 'pagespeed_mobile', 'html_size']],
        'security' => ['label' => 'Security & trust', 'checks' => ['https', 'ssl_certificate', 'https_redirect', 'security_headers', 'server_disclosure', 'outdated_server', 'mixed_content', 'legacy_tech', 'copyright']],
        'seo' => ['label' => 'Google visibility (SEO)', 'checks' => ['title', 'meta_description', 'h1', 'canonical', 'structured_data', 'robots_noindex', 'sitemap', 'lang', 'alt_text', 'broken_links']],
        'conversion' => ['label' => 'Turning visitors into enquiries', 'checks' => ['cta', 'contact_form', 'click_to_call', 'og_tags']],
    ];

    /** Software that usually pays off first for each kind of business: [solution, why, service key]. */
    public const NEEDS = [
        'Dental' => [['Online appointment booking with WhatsApp/SMS reminders', 'Patients book after hours and no-shows drop', 'custom_software'], ['Clinic management (records, billing, recall)', 'Front desk time goes to patients, not paperwork', 'erp_pos']],
        'Healthcare' => [['Online appointment booking with reminders', 'Fewer phone calls and missed appointments', 'custom_software'], ['Patient portal for reports and follow-ups', 'Repeat visits without queueing at reception', 'custom_software']],
        'Beauty & Wellness' => [['Online booking with deposits', 'Fills empty slots and cuts no-shows', 'custom_software'], ['Loyalty and rebooking reminders', 'Clients come back sooner', 'ai_automation']],
        'Fitness & Sports' => [['Membership, class booking and payments app', 'Members renew and book without staff', 'mobile_app']],
        'Restaurants & Cafes' => [['Online ordering and table reservations', 'Orders without delivery-app commissions', 'ecommerce'], ['QR digital menu', 'Menu and prices change in minutes', 'web_dev']],
        'Food & Bakery' => [['Online ordering with delivery slots', 'Pre-orders and bulk orders arrive online', 'ecommerce'], ['POS with stock tracking', 'Less waste, clear daily numbers', 'erp_pos']],
        'Hotels & Travel' => [['Direct booking engine', 'Bookings without OTA commissions', 'custom_software'], ['Channel manager integration', 'No double bookings across platforms', 'api_integration']],
        'Fashion & Jewellery' => [['Online store with WhatsApp checkout', 'Sales beyond shop hours and walk-ins', 'ecommerce'], ['POS and stock across branches', 'One view of stock and sales', 'erp_pos']],
        'Furniture & Home' => [['Online catalogue with quote requests', 'Customers shortlist before visiting', 'ecommerce']],
        'Automotive' => [['Online service booking', 'Workshop slots fill without phone calls', 'custom_software'], ['Vehicle inventory listing site', 'Buyers find stock on Google', 'web_dev']],
        'Electronics & Mobile' => [['Online store with stock sync', 'Sell online from the same stock', 'ecommerce']],
        'Real Estate' => [['Property listing portal with lead capture', 'Enquiries come straight to agents', 'custom_software'], ['CRM for buyers and follow-ups', 'No lead forgotten', 'erp_pos']],
        'Construction & Architecture' => [['Portfolio site with quote request form', 'Projects win trust before the first call', 'web_redesign'], ['Project and quotation management', 'Faster, consistent quotes', 'custom_software']],
        'Legal' => [['Online client intake and appointment booking', 'New matters arrive pre-qualified', 'custom_software'], ['Client portal for documents and updates', 'Fewer status calls', 'custom_software']],
        'Accounting & Finance' => [['Client portal for document upload', 'Year-end files arrive complete', 'custom_software'], ['Online booking for consultations', 'Leads book without email back-and-forth', 'web_dev']],
        'Education' => [['Online admissions and fee payment', 'Admissions without paper forms or queues', 'custom_software'], ['Student / school management system', 'Attendance, results and parents in one place', 'erp_pos']],
        'Software & IT' => [['API integrations and automation', 'Systems talk to each other without manual work', 'api_integration']],
        'Marketing & Media' => [['Client portal with approvals', 'Faster sign-offs, fewer email threads', 'custom_software']],
        'Logistics & Transport' => [['Shipment tracking portal', 'Customers track parcels without calling', 'custom_software'], ['Fleet and dispatch management', 'More deliveries per vehicle', 'erp_pos']],
        'Manufacturing & Industry' => [['ERP for orders, stock and production', 'One source of truth from order to delivery', 'erp_pos'], ['B2B ordering portal', 'Dealers reorder online', 'ecommerce']],
        'Retail' => [['Online store with stock sync', 'Sales beyond opening hours', 'ecommerce'], ['POS and inventory', 'Know what sells, reorder on time', 'erp_pos']],
        'Non-profit & Religious' => [['Online donations and member management', 'Recurring donations without paperwork', 'custom_software']],
        'Public Sector' => [['Citizen service portal', 'Requests handled online, end to end', 'custom_software']],
    ];

    public function __construct(protected WebsiteAuditService $audits)
    {
    }

    /** Up to $n local competitors: same industry group, own website, same city first, then audited, then most reviewed. */
    public function competitors(Company $company, int $n = 4): Collection
    {
        if (!$company->industry_group || !$company->country) {
            return collect();
        }

        return Company::where('id', '<>', $company->id)->where('status', '<>', 'archived')->whereNotNull('website')
            ->where('industry_group', $company->industry_group)->where('country', $company->country)
            ->when($company->domain, fn ($q) => $q->where(fn ($w) => $w->whereNull('domain')->orWhere('domain', '<>', $company->domain)))
            ->with('latestWebsiteAudit')
            ->orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [(string) $company->city])
            ->orderByRaw('CASE WHEN website_audited_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('google_review_count')->orderBy('id')
            ->limit($n)->get();
    }

    /** Audit competitors with no recent audit, within a time budget (the request must stay short). */
    public function auditMissing(Collection $competitors, int $seconds = 20): int
    {
        $deadline = microtime(true) + $seconds;
        $done = 0;
        foreach ($competitors as $c) {
            $latest = $c->latestWebsiteAudit;
            if ($latest && $latest->audited_at && $latest->audited_at->gt(now()->subDays(60))) {
                continue;
            }
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                $this->audits->audit($c);
                $done++;
            } catch (\Throwable) {
                // an unreachable competitor is simply left out of the comparison
            }
            $c->load('latestWebsiteAudit');
        }

        return $done;
    }

    /** Area scores 0-100 from one audit (null = no check of that area was run). */
    public function scores(?WebsiteAudit $audit): ?array
    {
        if (!$audit || $audit->status !== 'completed') {
            return null;
        }
        $byKey = collect((array) $audit->checks)->keyBy('key');
        $out = [];
        foreach (self::AREAS as $area => $def) {
            $got = $max = 0;
            foreach ($def['checks'] as $k) {
                $status = $byKey[$k]['status'] ?? null;
                if (!in_array($status, ['pass', 'warn', 'fail'], true)) {
                    continue;
                }
                $w = WebsiteAuditService::WEIGHTS[$k] ?? 2;
                $max += $w;
                $got += $w * ['pass' => 1, 'warn' => 0.5, 'fail' => 0][$status];
            }
            $out[$area] = $max ? (int) round(100 * $got / $max) : null;
        }
        $vals = array_filter($out, fn ($v) => $v !== null);
        $out['overall'] = $vals ? (int) round(array_sum($vals) / count($vals)) : null;

        return $out;
    }

    /** The whole comparison for the report. */
    public function build(Company $company, Collection $competitors): array
    {
        $own = $this->scores($company->latestWebsiteAudit);
        $rows = $competitors->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'website' => $c->website, 'city' => $c->city,
            'reviews' => $c->google_review_count, 'rating' => $c->google_rating, 'scores' => $this->scores($c->latestWebsiteAudit)])
            ->filter(fn ($r) => $r['scores'] !== null)->values();

        $areas = [];
        foreach (array_merge(array_keys(self::AREAS), ['overall']) as $a) {
            $vals = $rows->pluck("scores.{$a}")->filter(fn ($v) => $v !== null);
            $areas[$a] = ['label' => self::AREAS[$a]['label'] ?? 'Overall', 'you' => $own[$a] ?? null,
                'average' => $vals->count() ? (int) round($vals->avg()) : null, 'best' => $vals->count() ? (int) $vals->max() : null];
        }
        $lead = $behind = [];
        foreach (self::AREAS as $a => $def) {
            [$you, $avg] = [$areas[$a]['you'], $areas[$a]['average']];
            if ($you === null || $avg === null) {
                continue;
            }
            if ($you >= $avg + 10) {
                $lead[] = "{$def['label']}: {$you}/100 against a local average of {$avg}.";
            } elseif ($you <= $avg - 10) {
                $behind[] = "{$def['label']}: {$you}/100, local competitors average {$avg} (best {$areas[$a]['best']}).";
            }
        }

        // what competitors already have that this business lacks
        $gaps = [];
        $ownChecks = collect((array) $company->latestWebsiteAudit?->checks)->keyBy('key');
        foreach ($ownChecks as $k => $chk) {
            if (!in_array($chk['status'] ?? '', ['fail', 'warn'], true)) {
                continue;
            }
            $have = $competitors->filter(fn ($c) => (collect((array) $c->latestWebsiteAudit?->checks)->firstWhere('key', $k)['status'] ?? null) === 'pass')->count();
            $gaps[] = ['key' => $k, 'label' => $chk['label'] ?? $k, 'status' => $chk['status'], 'evidence' => $chk['evidence'] ?? '',
                'fix' => WebsiteAuditService::FIXES[$k] ?? null, 'competitors_with_it' => $have, 'competitors' => $rows->count()];
        }
        usort($gaps, fn ($x, $y) => [$y['competitors_with_it'], $y['status'] === 'fail'] <=> [$x['competitors_with_it'], $x['status'] === 'fail']);

        $rank = null;
        if (($own['overall'] ?? null) !== null) {
            $rank = 1 + $rows->filter(fn ($r) => ($r['scores']['overall'] ?? -1) > $own['overall'])->count();
        }

        return ['own' => $own, 'areas' => $areas, 'competitors' => $rows->all(), 'leads_in' => $lead, 'behind_in' => $behind,
            'gaps' => $gaps, 'rank' => $rank, 'field' => $rows->count() + 1];
    }

    /** Recommended software for this kind of business, plus fixes the audit itself points to. */
    public function solutions(Company $company): array
    {
        $out = [];
        foreach (self::NEEDS[$company->industry_group] ?? [] as [$name, $why, $service]) {
            $out[] = ['solution' => $name, 'why' => $why, 'service' => $service, 'source' => 'industry'];
        }
        foreach (array_slice((array) $company->latestWebsiteAudit?->opportunities, 0, 3) as $o) {
            $out[] = ['solution' => $o['label'] ?? $o['service'], 'why' => implode(' ', array_slice((array) ($o['evidence'] ?? []), 0, 2)),
                'service' => $o['service'] ?? null, 'source' => 'audit'];
        }

        return $out;
    }
}
