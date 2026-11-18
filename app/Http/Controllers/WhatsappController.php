<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInboundMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappReply;
use App\Services\Outreach\WhatsappAnswer;
use App\Services\Outreach\WhatsappInbox;
use App\Services\Outreach\WhatsappOutreach;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** WhatsApp: every message LeadPilot sent, and what came back, one conversation per business. */
class WhatsappController extends Controller
{
    public const TABS = [
        'all' => 'All',
        'replies' => 'Real replies',
        'automatic' => 'Automatic',
        'wrong' => 'Wrong number',
        'stopped' => 'Asked to stop',
        'waiting' => 'No answer yet',
        'failed' => 'Not delivered',
        'other' => 'Other numbers',
    ];

    protected const INBOUND_FOR_TAB = ['replies' => 'reply', 'automatic' => 'auto_reply', 'wrong' => 'wrong_number', 'stopped' => 'opt_out'];

    public function index(Request $request)
    {
        $tab = is_string($request->query('tab')) && array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'all';
        $q = Like::term($request->query('q'));
        $user = $request->user();

        $in = fn (string $action) => WhatsappInboundMessage::where('action', $action);
        $stats = [
            'sent' => WhatsappMessage::where('status', 'sent')->count(),
            'today' => app(WhatsappOutreach::class)->sentToday(),
            'cap' => (int) config('leadpilot.whatsapp.outreach.daily_cap', 10),
            'replies' => $in('reply')->distinct()->count('whatsapp_message_id'),
            'automatic' => $in('auto_reply')->count(),
            'wrong' => $in('wrong_number')->count(),
            'stopped' => $in('opt_out')->count(),
            'failed' => WhatsappMessage::where('status', 'failed')->count(),
        ];
        $stats['rate'] = $stats['sent'] ? round(100 * $stats['replies'] / $stats['sent'], 1) : 0;

        if ($tab === 'other') {
            // messages from numbers LeadPilot never wrote to (people who saved the number, the team testing it)
            $items = WhatsappInboundMessage::whereIn('action', ['unmatched'])
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => Like::where(Like::where($x, 'from_number', $q), 'body', $q, 'or')))
                ->latest('id')->paginate(25)->withQueryString();

            return view('whatsapp.index', compact('tab', 'q', 'items', 'stats'));
        }

        $items = WhatsappMessage::with(['lead.company', 'company', 'inbound' => fn ($r) => $r->latest('id')])
            ->when(!$user->isAdmin(), fn (Builder $w) => $w->where(fn ($x) => $x->whereNull('lead_id')
                ->orWhereHas('lead', fn ($l) => $l->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id))))
            ->when(isset(self::INBOUND_FOR_TAB[$tab]), fn ($w) => $w->whereHas('inbound', fn ($i) => $i->where('action', self::INBOUND_FOR_TAB[$tab])))
            ->when($tab === 'waiting', fn ($w) => $w->where('status', 'sent')->whereDoesntHave('inbound', fn ($i) => $i->whereIn('action', ['reply', 'opt_out', 'wrong_number'])))
            ->when($tab === 'failed', fn ($w) => $w->where('status', 'failed'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => Like::where($x, 'to_number', $q)->orWhereHas('company', fn ($c) => Like::where($c, 'name', $q))))
            ->latest('id')->paginate(25)->withQueryString();

        return view('whatsapp.index', compact('tab', 'q', 'items', 'stats'));
    }

    public function show(WhatsappMessage $message, WhatsappAnswer $answer)
    {
        $message->load(['lead.company', 'company']);
        // the conversation belongs to the lead: whoever may not open the lead may not read its messages
        $message->lead ? $this->authorize('view', $message->lead) : abort_unless(auth()->user()?->isAdmin(), 403);

        // everything we sent to this business (the first message and any to a number they gave us), and every answer
        $sent = WhatsappMessage::where(fn ($w) => $message->company_id ? $w->where('company_id', $message->company_id) : $w->whereKey($message->id))->get();
        $received = WhatsappInboundMessage::whereIn('whatsapp_message_id', $sent->pluck('id'))->get();
        $answers = WhatsappReply::with('user')->whereIn('whatsapp_message_id', $sent->pluck('id'))->get();
        $timeline = $sent->map(fn ($m) => ['out' => true, 'at' => $m->sent_at ?? $m->created_at, 'm' => $m])
            ->concat($received->map(fn ($m) => ['out' => false, 'at' => $m->created_at, 'm' => $m]))
            ->concat($answers->map(fn ($m) => ['out' => true, 'answer' => true, 'at' => $m->sent_at ?? $m->created_at, 'm' => $m]))
            ->sortBy(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values();

        $canAnswer = $message->lead ? auth()->user()->can('update', $message->lead) : auth()->user()->isAdmin();
        $blocked = $answer->blockReason($message);
        $draft = $canAnswer && !$blocked ? $answer->draft($message) : null;

        return view('whatsapp.show', compact('message', 'timeline', 'canAnswer', 'blocked', 'draft'));
    }

    /** Send a salesperson's answer to someone who replied. A person presses Send every time; nothing is automatic. */
    public function answer(Request $request, WhatsappMessage $message, WhatsappAnswer $answer)
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:1500']);
        $message->lead ? $this->authorize('update', $message->lead) : abort_unless($request->user()->isAdmin(), 403);
        [$ok, $text] = $answer->send($message, $data['body'], $request->user());

        return $ok ? back()->with('success', $text) : back()->withInput()->with('error', $text);
    }

    public function label(Request $request, WhatsappInboundMessage $inbound, WhatsappInbox $inbox)
    {
        $data = $request->validate(['action' => 'required|in:reply,auto_reply,wrong_number']);
        $inbound->lead ? $this->authorize('update', $inbound->lead) : abort_unless($request->user()->isAdmin(), 403);
        abort_if(in_array($inbound->action, ['opt_out', 'unmatched', 'ignored'], true), 422, 'A stop request and messages from other numbers cannot be relabelled here.');
        $result = $inbox->relabel($inbound, $data['action'], $request->user());

        return back()->with('success', $result);
    }
}
