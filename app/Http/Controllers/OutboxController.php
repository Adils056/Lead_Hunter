<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Support\Like;
use Illuminate\Http\Request;

/** Sent & Replies: every e-mail that actually went out, to whom, and what came back. */
class OutboxController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'replies' ? 'replies' : 'sent';
        $q = Like::term($request->query('q'));
        $match = fn ($w) => Like::where(Like::where($w, 'email', $q), 'full_name', $q, 'or')
            ->orWhereHas('company', fn ($c) => Like::where($c, 'name', $q));

        $m = \App\Support\OutreachMetrics::summary(); // same numbers as Dashboard / Analytics
        $stats = [
            'sent' => $m['sent'],
            'today' => CampaignRecipient::where('sent_at', '>=', now(config('leadpilot.timezone', 'Asia/Karachi'))->startOfDay()->utc())->count(), // the business day, like Autopilot
            'replied' => $m['replied'],
            'bounced' => $m['bounced'],
            'rate' => $m['reply_rate'],
        ];

        if ($tab === 'replies') {
            $items = InboundReply::with(['lead.company', 'recipient'])
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => Like::where(Like::where($x, 'from_email', $q), 'subject', $q, 'or')->orWhereHas('lead', $match)))
                ->latest('received_at')->paginate(25)->withQueryString();
        } else {
            $items = CampaignRecipient::with(['lead.company', 'replies' => fn ($r) => $r->latest('received_at')])
                ->whereNotNull('sent_at')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => Like::where($x, 'personalized_subject', $q)->orWhereHas('lead', $match)))
                ->latest('sent_at')->paginate(25)->withQueryString();
        }

        return view('outbox.index', compact('tab', 'q', 'items', 'stats'));
    }

    public function show(CampaignRecipient $recipient)
    {
        $recipient->load(['lead.company', 'campaign', 'replies' => fn ($r) => $r->oldest('received_at')]);
        $thread = CampaignRecipient::where('lead_id', $recipient->lead_id)->whereNotNull('sent_at')->oldest('sent_at')->get();
        $otherReplies = InboundReply::where('lead_id', $recipient->lead_id)->whereNotIn('id', $recipient->replies->pluck('id'))->oldest('received_at')->get();

        return view('outbox.show', compact('recipient', 'thread', 'otherReplies'));
    }
}
