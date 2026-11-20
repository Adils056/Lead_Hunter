<?php

namespace App\Http\Controllers;

use App\Models\AutoReply;
use App\Models\InboundReply;
use App\Models\WhatsappInboundMessage;
use App\Services\Autopilot\AutonomyPolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Automatic answers: every reply LeadPilot answered by itself, left to a person, skipped, or could not send. */
class AutoReplyController extends Controller
{
    public const TABS = [
        'all' => 'All',
        'sent' => 'Answered',
        'held' => 'Left to a person',
        'failed' => 'Not sent yet',
        'skipped' => 'No answer needed',
    ];

    public function index(Request $request, AutonomyPolicyService $policy)
    {
        $tab = is_string($request->query('tab')) && array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'all';
        $user = $request->user();
        $mine = fn (Builder $q) => $q->when(!$user->isAdmin(), fn ($w) => $w->where(fn ($x) => $x->whereNull('lead_id')
            ->orWhereHas('lead', fn ($l) => $l->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id))));

        $week = $mine(AutoReply::query())->where('updated_at', '>=', now()->subDays(7))->get(['status'])->countBy('status');
        $stats = [
            'on' => $policy->isAutomatic('reply.auto_respond'),
            'sent' => (int) ($week['sent'] ?? 0),
            'held' => (int) ($week['held'] ?? 0),
            'failed' => (int) ($week['failed'] ?? 0),
            'skipped' => (int) ($week['skipped'] ?? 0),
            'waiting_people' => $mine(AutoReply::query())->where('status', 'held')
                ->whereHas('lead', fn ($l) => $l->whereHas('tasks', fn ($t) => $t->where('status', 'pending')->where('title', 'like', 'Answer now: LeadPilot left this reply to you%')))->count(),
        ];

        $items = $mine(AutoReply::with('lead.company'))
            ->when($tab !== 'all', fn ($q) => $q->where('status', $tab))
            ->latest('updated_at')->paginate(25)->withQueryString();

        // what they wrote, from the message each row answers
        $emails = InboundReply::whereIn('id', $items->where('channel', 'email')->pluck('source_id'))->get(['id', 'body_text', 'from_email'])->keyBy('id');
        $wa = WhatsappInboundMessage::whereIn('id', $items->where('channel', 'whatsapp')->pluck('source_id'))->get(['id', 'body', 'from_number', 'whatsapp_message_id'])->keyBy('id');

        return view('auto_replies.index', compact('tab', 'items', 'stats', 'emails', 'wa'));
    }
}
