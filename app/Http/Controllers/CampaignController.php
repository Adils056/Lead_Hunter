<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\CampaignDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CampaignController extends Controller
{
    protected CampaignDeliveryService $deliveryService;

    public function __construct(CampaignDeliveryService $deliveryService)
    {
        $this->deliveryService = $deliveryService;
    }

    public function index()
    {
        $this->authorize('viewAny', Campaign::class);

        $campaigns = Campaign::with(['template', 'creator', 'approver'])
            ->withCount([
                'recipients as total_recipients',
                'recipients as sent_count' => fn($q) => $q->whereNotNull('sent_at'),
                'recipients as delivered_count' => fn($q) => $q->whereNotNull('sent_at')->whereNull('bounced_at')->where('status', '!=', 'bounced'),
                // a reply is matched to the prospect (threading headers are often stripped), auto-replies excluded
                'recipients as replied_count' => fn($q) => $q->whereNotNull('sent_at')->whereHas('lead.inboundReplies', fn ($r) => $r->where(fn ($w) => $w->whereNull('category')->orWhere('category', '!=', 'out_of_office'))),
                'recipients as bounced_count' => fn($q) => $q->where('status', 'bounced'),
            ])
            ->latest()
            ->paginate(10);

        return view('campaigns.index', compact('campaigns'));
    }

    public function create()
    {
        $this->authorize('create', Campaign::class);

        EmailTemplateController::ensureStarters();
        $settings = \App\Models\OutreachSetting::current();
        $templates = EmailTemplate::where('is_active', true)->orderBy('name')->get();
        $sources = LeadSource::where('is_active', true)->get();
        // only what Settings → Outreach Engine allows (the form used to tick Canada / Germany and offer 8 services)
        $services = \App\Support\ServiceCatalog::approved();
        $countries = $settings->allowed_countries ?: Lead::whereNotNull('country')->distinct()->pluck('country')->all();
        $industries = Lead::whereNotNull('industry')->distinct()->pluck('industry');
        $maxDaily = (int) $settings->max_daily_sends;

        return view('campaigns.create', compact('templates', 'sources', 'services', 'countries', 'industries', 'maxDaily'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Campaign::class);

        $settings = \App\Models\OutreachSetting::current();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'service_offered' => ['required', 'string', 'in:' . implode(',', array_keys(\App\Support\ServiceCatalog::approved()))],
            'target_countries' => ['nullable', 'array'],
            'target_countries.*' => $settings->allowed_countries ? ['string', 'in:' . implode(',', $settings->allowed_countries)] : ['string'],
            'target_industries' => ['nullable', 'array'],
            'min_lead_score' => ['required', 'integer', 'between:1,100'],
            'eligible_sources' => ['nullable', 'array'],
            'daily_limit' => ['required', 'integer', 'between:1,' . max(1, (int) $settings->max_daily_sends)],
            'email_template_id' => ['required', 'exists:email_templates,id'],
            'require_approval' => ['boolean'],
            'timezone' => ['required', 'string'],
        ]);

        $campaign = Campaign::create([
            'name' => $data['name'],
            'service_offered' => $data['service_offered'],
            'target_countries' => $data['target_countries'] ?? [],
            'target_industries' => $data['target_industries'] ?? [],
            'min_lead_score' => $data['min_lead_score'],
            'eligible_sources' => $data['eligible_sources'] ?? [],
            'contact_rules' => ['require_verified_email' => true],
            'daily_limit' => $data['daily_limit'],
            'email_template_id' => $data['email_template_id'],
            'require_approval' => $request->boolean('require_approval', true),
            'timezone' => $data['timezone'],
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);

        // Generate recipient drafts based on targeting rules
        $recipientsCount = $this->deliveryService->buildCampaignDrafts($campaign);

        AuditLog::record('campaign_created', $campaign, null, ['recipients_drafted' => $recipientsCount]);

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', "Campaign '{$campaign->name}' created! {$recipientsCount} qualified prospect drafts generated.");
    }

    public function show(Campaign $campaign)
    {
        $this->authorize('view', $campaign);

        $campaign->load(['template', 'creator', 'approver']);

        $recipients = $campaign->recipients()
            ->with('lead.company')
            ->latest()
            ->paginate(15);

        $metrics = [
            'total' => $campaign->recipients()->count(),
            'draft' => $campaign->recipients()->where('status', 'draft')->count(),
            'approved' => $campaign->recipients()->where('status', 'approved')->count(),
            ...array_intersect_key(\App\Support\OutreachMetrics::summary(null, null, $campaign->id), array_flip(['sent', 'delivered', 'replied'])),
            'bounced' => $campaign->recipients()->where('status', 'bounced')->count(),
            'opted_out' => $campaign->recipients()->where('status', 'opted_out')->count(),
        ];

        return view('campaigns.show', compact('campaign', 'recipients', 'metrics'));
    }

    public function approve(Campaign $campaign)
    {
        $this->authorize('approve', $campaign);

        // Approve campaign and all draft recipients
        $campaign->update([
            'status' => 'running',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'emergency_stopped' => false,
        ]);

        $campaign->recipients()->whereIn('status', ['draft', 'queued'])->update(['status' => 'approved']);

        AuditLog::record('campaign_approved', $campaign);

        return back()->with('success', "Campaign '{$campaign->name}' approved by Administrator. Sending queue is now active.");
    }

    public function pause(Campaign $campaign)
    {
        $this->authorize('update', $campaign);

        $campaign->update(['status' => 'paused']);
        AuditLog::record('campaign_paused', $campaign);

        return back()->with('info', "Campaign '{$campaign->name}' paused.");
    }

    public function resume(Campaign $campaign)
    {
        $this->authorize('update', $campaign);

        if ($campaign->require_approval && !$campaign->approved_by) {
            return back()->with('error', 'Cannot resume: Campaign requires administrator approval before sending.');
        }

        $campaign->update(['status' => 'running', 'emergency_stopped' => false]);
        AuditLog::record('campaign_resumed', $campaign);

        return back()->with('success', "Campaign '{$campaign->name}' resumed.");
    }

    public function emergencyStop(Campaign $campaign)
    {
        $this->authorize('emergencyStop', $campaign);

        $this->deliveryService->emergencyStop($campaign, 'Administrative emergency killswitch triggered.');

        return back()->with('warning', "EMERGENCY STOP ACTIVATED: Campaign '{$campaign->name}' has been halted immediately.");
    }

    public function dispatchRecipient(CampaignRecipient $recipient)
    {
        $this->authorize('update', $recipient->campaign);

        $success = $this->deliveryService->dispatchRecipient($recipient);

        if ($success) {
            return back()->with('success', "Message dispatched successfully to {$recipient->lead->email}.");
        } else {
            return back()->with('error', "Could not dispatch message: {$recipient->error_message}");
        }
    }

    public function sendTestEmail(Request $request, Campaign $campaign)
    {
        // Phase 0 hotfix: this used to be an open relay (any user, any address, no guard).
        $this->authorize('update', $campaign);
        abort_unless(Auth::user()?->isAdmin(), 403, 'Only administrators can send test e-mails.');
        $request->validate([
            'test_email' => ['required', 'email'],
        ]);
        $allowed = \App\Models\User::where('role', 'admin')->where('is_active', true)->pluck('email')->map(fn ($e) => strtolower($e))
            ->push(strtolower((string) \App\Models\OutreachSetting::current()->controlled_pilot_recipient))->filter()->all();
        if (!in_array(strtolower(trim($request->input('test_email'))), $allowed, true)) {
            return back()->withErrors(['test_email' => 'Test e-mails can only go to an active administrator address or the controlled-pilot recipient.'])->withInput();
        }

        $sent = $this->deliveryService->sendTestEmail($campaign, $request->input('test_email'));

        if ($sent) {
            return back()->with('success', "Safe preview test email dispatched to {$request->input('test_email')}.");
        }

        return back()->with('error', 'Failed to send test email. Check email format and mail configuration.');
    }

    public function unsubscribe(Request $request)
    {
        // Laravel already URL-decodes the query; decoding again turns "john+sales@" into "john sales@".
        $raw = \App\Support\Like::term($request->input('email', '')); // '' for array input (?email[]=) instead of a 500
        $candidates = array_unique([$raw, urldecode($raw)]); // v1-v3 links were double-encoded
        $email = null;
        $valid = false;
        $token = \App\Support\Like::term($request->input('token', ''));
        foreach ($candidates as $c) {
            if (hash_equals(\App\Services\Outreach\OutreachMailer::unsubscribeToken($c), $token) || hash_equals(md5($c . config('app.key')), $token)) {
                $valid = true;
                $email = strtolower($c);
                break;
            }
        }
        $email ??= strtolower($raw);

        // v4: the token must match, otherwise anyone could suppress any address
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            && $valid) {
            \App\Models\Suppression::addSuppression($email, 'unsubscribe', 'unsubscribe_link');
            
            // Advance lead stage
            $lead = Lead::where('email', $email)->first();
            if ($lead) {
                $lead->update(['lifecycle_stage' => 'not_a_fit']);
            }

            if ($request->isMethod('post')) {
                return response('Unsubscribed', 200); // RFC 8058 one-click
            }

            return view('campaigns.unsubscribed', ['email' => $email]);
        }

        return $request->isMethod('post') ? response('Invalid unsubscribe link', 400) : response('This unsubscribe link is invalid or expired. Reply "unsubscribe" to our email and we will remove you.', 400);
    }
}
