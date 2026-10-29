<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Audit\WebsiteReportService;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Competitor-benchmark website report: build it, read it, download it as a PDF to send to the business. */
class WebsiteReportController extends Controller
{
    public function store(Company $company, WebsiteReportService $reports)
    {
        $this->authorize('view', $company);
        if (!$company->website) {
            return back()->with('error', 'This company has no website to analyse yet.');
        }
        $report = $reports->generate($company, auth()->id());
        AuditLog::record('website_report_built', $company);
        $n = count($report->benchmark['competitors'] ?? []);

        return redirect()->route('companies.report', $company)->with('success', "Report built against {$n} local competitor(s)" . ($report->ai ? ', with AI analysis.' : '.'));
    }

    public function show(Company $company)
    {
        $this->authorize('view', $company);
        $report = $company->latestWebsiteReport;
        abort_unless($report, 404, 'No report yet: build one from the company page.');

        return view('reports.show', $this->data($company, $report));
    }

    public function pdf(Company $company)
    {
        $this->authorize('view', $company);
        $report = $company->latestWebsiteReport;
        abort_unless($report, 404);
        $options = new Options();
        $options->set('isRemoteEnabled', false); // never fetch anything while rendering
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.pdf', $this->data($company, $report))->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $name = 'Website-Report-' . (preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($company->domain ?: $company->name)) ?: $company->id) . '.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    protected function data(Company $company, $report): array
    {
        $company->load('latestWebsiteAudit');

        return ['company' => $company, 'report' => $report, 'b' => $report->benchmark, 'audit' => $company->latestWebsiteAudit,
            'settings' => \App\Models\OutreachSetting::current(), 'agency' => config('leadpilot.agency')];
    }
}
