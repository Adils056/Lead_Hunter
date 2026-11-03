<?php

namespace App\Http\Controllers;

use App\Models\WebsiteReport;
use App\Services\Sales\EngagementAlerts;
use Illuminate\Http\Request;

/**
 * The website & competitor report as the prospect sees it, from the private link in our e-mail. No login: the
 * unguessable token is the key. A view counts only when a person stays on the page (a small script reports it after
 * a few seconds); e-mail security scanners that pre-open every link do not run it, so they never trigger an alert.
 */
class ReportShareController extends Controller
{
    protected const BOTS = '/bot|crawl|spider|preview|scanner|safelinks|proofpoint|mimecast|barracuda|headless|python|curl|wget|httpclient|facebookexternalhit|slack|whatsapp|telegram/i';

    public function show(string $token)
    {
        $report = WebsiteReport::where('share_token', $token)->with('company')->firstOrFail();
        abort_unless($report->company, 404);
        $html = view('reports.pdf', app(WebsiteReportController::class)->data($report->company, $report))->render();
        // the same layout as the PDF, made readable on a phone screen (most prospects open it from their mail app)
        $screen = '<meta name="viewport" content="width=device-width,initial-scale=1"><style>@media screen{body{font-size:15px;max-width:860px;margin:0 auto;padding:20px 16px 40px}'
            . 'h1{font-size:26px}h2{font-size:17px}.brand{font-size:12px}th{font-size:11px}.tag{font-size:11px}footer{position:static;margin-top:28px;font-size:12px;order:99}body{display:flex;flex-direction:column}body>*{max-width:100%}}'
            . '@media screen and (max-width:640px){body{font-size:14px}table{font-size:13px}td,th{padding:6px 4px;overflow-wrap:anywhere;white-space:normal}.bar{min-width:36px}.two td{display:block;width:auto}'
            . '.fixes thead{display:none}.fixes tr{display:block;padding:8px 0;border-bottom:1px solid #e5e7eb}.fixes td{display:block;border:0;padding:2px 0;text-align:left}'
            . '.fixes td[data-label]::before{content:attr(data-label) ": ";font-weight:600;color:#6b7280}}</style>';
        $beacon = $screen . '<meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="' . csrf_token() . '">';
        $script = '<script>(function(){var sent=false;function go(){if(sent||document.visibilityState!=="visible")return;sent=true;'
            . 'fetch(' . json_encode(route('reports.public.seen', $token)) . ',{method:"POST",headers:{"X-CSRF-TOKEN":document.querySelector(\'meta[name="csrf-token"]\').content,"X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"});}'
            . 'setTimeout(go,4000);})();</script>';
        $html = str_replace('</head>', $beacon . '</head>', $html);
        $html = str_replace('</body>', $script . '</body>', $html);

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function seen(Request $request, string $token)
    {
        $report = WebsiteReport::where('share_token', $token)->first();
        if (!$report || preg_match(self::BOTS, (string) $request->userAgent())) {
            return response()->noContent();
        }
        $first = $report->first_viewed_at === null;
        $report->forceFill(['view_count' => $report->view_count + 1, 'first_viewed_at' => $report->first_viewed_at ?? now(), 'last_viewed_at' => now()])->save();
        if ($first) {
            app(EngagementAlerts::class)->reportViewed($report);
        }

        return response()->noContent();
    }
}
