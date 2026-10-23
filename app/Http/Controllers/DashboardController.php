<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Deal;
use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\UsageLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Filters
        $country = $request->query('country');
        $service = $request->query('service');
        $source = $request->query('source');
        $dateRange = $request->query('range', '30'); // 7, 30, 90, all

        $leadQuery = Lead::query();
        $companyQuery = Company::query();
        $campaignQuery = Campaign::query();

        if ($country) {
            $leadQuery->where('country', $country);
            $companyQuery->where('country', $country);
        }
        if ($service) {
            $leadQuery->whereJsonContains('relevant_services', $service);
        }
        if ($source) {
            $leadQuery->where('source_name', $source);
        }

        if ($dateRange !== 'all') {
            $days = (int) $dateRange;
            $startDate = now()->subDays($days);
            $leadQuery->where('created_at', '>=', $startDate);
        }

        // Real Database Calculated Metrics
        $totalCompanies = (clone $companyQuery)->count();
        $totalContacts = (clone $leadQuery)->count();
        $newlyDiscovered = (clone $leadQuery)->where('lifecycle_stage', 'new')->count();
        $qualifiedLeads = (clone $leadQuery)->where('overall_score', '>=', 60)->count();
        $highPriorityLeads = (clone $leadQuery)->where('overall_score', '>=', \App\Support\ScoreBands::high())->count();
        $leadsAwaitingReview = (clone $leadQuery)->whereIn('lifecycle_stage', ['ready_for_review', 'researching'])->count();
        // Only mailbox-level proof (SMTP probe or Hunter) counts; MX-only addresses are shown separately.
        $verifiedEmails = (clone $leadQuery)->where('email_verification_status', \App\Support\EmailStatus::VERIFIED)->count();
        $mxOnlyEmails = (clone $leadQuery)->where('email_verification_status', \App\Support\EmailStatus::MX_ONLY)->count();

        // Campaign & Delivery Metrics
        $runningCampaigns = Campaign::where('status', 'running')->where('emergency_stopped', false)->count();
        $emailsSent = CampaignRecipient::whereIn('status', ['sent', 'delivered', 'replied'])->count();
        $emailsDelivered = CampaignRecipient::whereIn('status', ['delivered', 'replied'])->count();
        $emailsBounced = CampaignRecipient::where('status', 'bounced')->count();
        $repliesReceived = InboundReply::count();
        $positiveReplies = InboundReply::whereIn('classification', ['positive', 'meeting_request'])->count();

        // CRM Metrics
        $meetingsBooked = Meeting::count();
        $proposalsSent = Deal::whereIn('stage', ['proposal_sent', 'negotiation', 'won'])->count();
        $dealsWon = Deal::where('stage', 'won')->count();
        $totalDealValue = Deal::where('stage', 'won')->sum('value');
        $pipelineValue = Deal::whereIn('stage', ['discovery', 'proposal_sent', 'negotiation'])->sum('value');

        // Rates (with safe division-by-zero handling)
        $deliveryRate = $emailsSent > 0 ? round(($emailsDelivered / $emailsSent) * 100, 1) : 0;
        $bounceRate = $emailsSent > 0 ? round(($emailsBounced / $emailsSent) * 100, 1) : 0;
        $replyRate = $emailsDelivered > 0 ? round(($repliesReceived / $emailsDelivered) * 100, 1) : 0;
        $positiveReplyRate = $repliesReceived > 0 ? round(($positiveReplies / $repliesReceived) * 100, 1) : 0;
        $leadToMeetingRate = $totalContacts > 0 ? round(($meetingsBooked / $totalContacts) * 100, 1) : 0;

        // Cost per qualified lead (Total usage cost / qualified leads)
        $totalSpend = UsageLog::sum('cost_estimated');
        $costPerQualifiedLead = $qualifiedLeads > 0 ? round($totalSpend / $qualifiedLeads, 2) : 0;

        // Source-wise Lead Performance
        $sourcePerformance = Lead::select('source_name', DB::raw('count(*) as count'), DB::raw('avg(overall_score) as avg_score'))
            ->whereNotNull('source_name')
            ->groupBy('source_name')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Country distribution
        $countriesList = Lead::whereNotNull('country')->distinct()->pluck('country');
        $servicesList = [
            'Website design and development',
            'E-commerce development',
            'Custom software development',
            'Laravel and PHP development',
            'ERP and CRM development',
            'API development and integration',
            'SaaS product development',
            'Mobile application development',
            'AI automation and business workflow automation',
            'Database design, optimization, and system integrations',
            'Software maintenance and technical consulting',
        ];

        // Recent Activity & Signals
        $recentSignals = BuyingSignal::with('company')->latest()->limit(5)->get();
        $recentReplies = InboundReply::with('lead')->latest()->limit(5)->get();
        $pendingTasks = Task::with(['lead', 'company', 'assignee'])->where('status', '!=', 'completed')->orderBy('due_date')->limit(5)->get();
        $topLeads = Lead::with('company')->orderByDesc('overall_score')->limit(6)->get();

        // Chart Data: Pipeline Stage Breakdown
        $stages = [
            'new' => 'New',
            'researching' => 'Researching',
            'qualified' => 'Qualified',
            'ready_for_review' => 'Review',
            'contacted' => 'Contacted',
            'replied' => 'Replied',
            'meeting_booked' => 'Meeting',
            'proposal_sent' => 'Proposal',
            'won' => 'Won',
            'lost' => 'Lost',
        ];
        $stageCounts = [];
        foreach ($stages as $key => $label) {
            $stageCounts[$label] = Lead::where('lifecycle_stage', $key)->count();
        }

        return view('dashboard.index', compact(
            'totalCompanies',
            'totalContacts',
            'newlyDiscovered',
            'qualifiedLeads',
            'highPriorityLeads',
            'leadsAwaitingReview',
            'verifiedEmails',
            'mxOnlyEmails',
            'runningCampaigns',
            'emailsSent',
            'emailsDelivered',
            'emailsBounced',
            'repliesReceived',
            'positiveReplies',
            'meetingsBooked',
            'proposalsSent',
            'dealsWon',
            'totalDealValue',
            'pipelineValue',
            'deliveryRate',
            'bounceRate',
            'replyRate',
            'positiveReplyRate',
            'leadToMeetingRate',
            'costPerQualifiedLead',
            'sourcePerformance',
            'countriesList',
            'servicesList',
            'recentSignals',
            'recentReplies',
            'pendingTasks',
            'topLeads',
            'stageCounts',
            'country',
            'service',
            'source',
            'dateRange'
        ));
    }
}
