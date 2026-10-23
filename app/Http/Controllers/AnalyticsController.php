<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Deal;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $startDate = now()->subDays($days);

        // Funnel Counts
        $totalLeads = Lead::where('created_at', '>=', $startDate)->count();
        $verifiedLeads = Lead::where('created_at', '>=', $startDate)->where('email_verification_status', 'verified')->count();
        $qualifiedLeads = Lead::where('created_at', '>=', $startDate)->where('overall_score', '>=', 60)->count();
        $outreachSent = CampaignRecipient::where('sent_at', '>=', $startDate)->count();
        $delivered = CampaignRecipient::where('delivered_at', '>=', $startDate)->count();
        $bounced = CampaignRecipient::where('bounced_at', '>=', $startDate)->count();
        $replies = InboundReply::where('received_at', '>=', $startDate)->count();
        $positiveReplies = InboundReply::where('received_at', '>=', $startDate)->whereIn('classification', ['positive', 'meeting_request'])->count();
        $meetings = Meeting::where('created_at', '>=', $startDate)->count();
        $proposals = Deal::where('created_at', '>=', $startDate)->whereIn('stage', ['proposal_sent', 'negotiation', 'won'])->count();
        $wonDeals = Deal::where('created_at', '>=', $startDate)->where('stage', 'won')->count();
        $wonRevenue = Deal::where('created_at', '>=', $startDate)->where('stage', 'won')->sum('value');

        // Conversion Rates (division-by-zero protected)
        $verificationRate = $totalLeads > 0 ? round(($verifiedLeads / $totalLeads) * 100, 1) : 0;
        $qualificationRate = $totalLeads > 0 ? round(($qualifiedLeads / $totalLeads) * 100, 1) : 0;
        $deliveryRate = $outreachSent > 0 ? round(($delivered / $outreachSent) * 100, 1) : 0;
        $bounceRate = $outreachSent > 0 ? round(($bounced / $outreachSent) * 100, 1) : 0;
        $replyRate = $delivered > 0 ? round(($replies / $delivered) * 100, 1) : 0;
        $positiveRate = $replies > 0 ? round(($positiveReplies / $replies) * 100, 1) : 0;
        $meetingRate = $totalLeads > 0 ? round(($meetings / $totalLeads) * 100, 1) : 0;

        // Cost Analysis
        $totalCost = UsageLog::where('occurred_at', '>=', $startDate)->sum('cost_estimated');
        $costPerQualifiedLead = $qualifiedLeads > 0 ? round($totalCost / $qualifiedLeads, 2) : 0;
        $costPerMeeting = $meetings > 0 ? round($totalCost / $meetings, 2) : 0;

        // Breakdown charts
        $leadsByCountry = Lead::select('country', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(6)
            ->get();

        $leadsByIndustry = Lead::select('industry', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('industry')
            ->groupBy('industry')
            ->orderByDesc('count')
            ->limit(6)
            ->get();

        $leadsBySource = Lead::select('source_name', DB::raw('count(*) as count'), DB::raw('avg(overall_score) as avg_score'))
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('source_name')
            ->groupBy('source_name')
            ->orderByDesc('count')
            ->get();

        // Score Distribution
        $scoreDistribution = [
            'High Priority (' . \App\Support\ScoreBands::high() . '-100)' => Lead::where('overall_score', '>=', \App\Support\ScoreBands::high())->count(),
            'Qualified (' . \App\Support\ScoreBands::qualified() . '-' . (\App\Support\ScoreBands::high() - 1) . ')' => Lead::whereBetween('overall_score', [\App\Support\ScoreBands::qualified(), \App\Support\ScoreBands::high() - 1])->count(),
            'Nurture (' . \App\Support\ScoreBands::nurture() . '-' . (\App\Support\ScoreBands::qualified() - 1) . ')' => Lead::whereBetween('overall_score', [\App\Support\ScoreBands::nurture(), \App\Support\ScoreBands::qualified() - 1])->count(),
            'Low Priority (30-49)' => Lead::whereBetween('overall_score', [30, 49])->count(),
            'Needs Review (<30)' => Lead::where('overall_score', '<', 30)->count(),
        ];

        return view('analytics.index', compact(
            'days',
            'totalLeads',
            'verifiedLeads',
            'qualifiedLeads',
            'outreachSent',
            'delivered',
            'bounced',
            'replies',
            'positiveReplies',
            'meetings',
            'proposals',
            'wonDeals',
            'wonRevenue',
            'verificationRate',
            'qualificationRate',
            'deliveryRate',
            'bounceRate',
            'replyRate',
            'positiveRate',
            'meetingRate',
            'totalCost',
            'costPerQualifiedLead',
            'costPerMeeting',
            'leadsByCountry',
            'leadsByIndustry',
            'leadsBySource',
            'scoreDistribution'
        ));
    }

    public function export(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $startDate = now()->subDays($days);

        $leadsBySource = Lead::select('source_name', DB::raw('count(*) as total_leads'), DB::raw('avg(overall_score) as avg_score'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('source_name')
            ->get();

        $filename = "leadpilot_analytics_report_" . date('Y-m-d') . ".csv";

        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function () use ($leadsBySource) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Source Name', 'Total Leads Generated', 'Average Lead Score']);

            foreach ($leadsBySource as $row) {
                fputcsv($file, [
                    $row->source_name,
                    $row->total_leads,
                    round($row->avg_score, 1),
                ]);
            }
            fclose($file);
        };

        AuditLog::record('analytics_report_exported', null, null, ['days' => $days]);

        return Response::stream($callback, 200, $headers);
    }
}
