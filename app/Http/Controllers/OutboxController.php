<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use Illuminate\Http\Request;

/** Sent & Replies: every e-mail that actually went out, to whom, and what came back. */
class OutboxController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'replies' ? 'replies' : 'sent';
        $q = trim((string) $request->query('q', ''));
        $match = fn ($w) => $w->where('email', 'like', "%{$q}%")->orWhere('full_name', 'like', "%{$q}%")
            ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%"));

        $stats = [
            'sent' => CampaignRecipient::whereNotNull('sent_at')->count(),
            'today' => CampaignRecipient::where('sent_at', '>=', now(config('leadpilot.timezone', 'Asia/Karachi'))->startOfDay()->utc())->count(), // the business day, like Autopilot
            'replied' => CampaignRecipient::whereNotNull('sent_at')->whereHas('replies')->count(),
            'bounced' => CampaignRecipient::whereNotNull('sent_at')->where(fn ($w) => $w->whereNotNull('bounced_at')->orWhere('status', 'bounced'))->count(),
        ];
        $stats['rate'] = $stats['sent'] ? round(100 * $stats['replied'] / $stats['sent'], 1) : 0;

        if ($tab === 'replies') {
            $items = InboundReply::with(['lead.company', 'recipient'])
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('from_email', 'like', "%{$q}%")->orWhere('subject', 'like', "%{$q}%")->orWhereHas('lead', $match)))
                ->latest('received_at')->paginate(25)->withQueryString();
        } else {
            $items = CampaignRecipient::with(['lead.company', 'replies' => fn ($r) => $r->latest('received_at')])
                ->whereNotNull('sent_at')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('personalized_subject', 'like', "%{$q}%")->orWhereHas('lead', $match)))
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
