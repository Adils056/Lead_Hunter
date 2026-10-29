<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\WhatsappTemplate;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WhatsApp templates and the per-lead "Open in WhatsApp" action. Nothing is sent by the system: the message opens
 * in WhatsApp (app or web) for a person to read and send, which keeps the business number safe from spam bans.
 */
class WhatsappTemplateController extends Controller
{
    public function index()
    {
        WhatsappTemplate::ensureStarters();

        return view('whatsapp_templates.index', ['templates' => WhatsappTemplate::orderBy('purpose')->orderBy('name')->get()]);
    }

    public function create()
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('whatsapp_templates.form', ['template' => new WhatsappTemplate(['is_active' => true, 'purpose' => 'first_contact'])]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $t = WhatsappTemplate::create($this->validated($request));
        AuditLog::record('whatsapp_template_created', $t);

        return redirect()->route('whatsapp-templates.index')->with('success', "Template \"{$t->name}\" saved.");
    }

    public function edit(WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('whatsapp_templates.form', ['template' => $whatsappTemplate]);
    }

    public function update(Request $request, WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $whatsappTemplate->update($this->validated($request));

        return redirect()->route('whatsapp-templates.index')->with('success', "Template \"{$whatsappTemplate->name}\" saved.");
    }

    public function destroy(WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $whatsappTemplate->delete();

        return back()->with('success', 'Template removed.');
    }

    /** Fill the template for this lead, log it, and hand over to WhatsApp. */
    public function open(Request $request, Lead $lead)
    {
        $this->authorize('view', $lead);
        $data = $request->validate(['template_id' => ['required', 'integer', 'exists:whatsapp_templates,id']]);
        if ($lead->lifecycle_stage === 'opted_out' || ($lead->email && Suppression::isSuppressed($lead->email))) {
            return back()->with('error', 'This contact opted out. Do not message them.');
        }
        $number = Phone::international($lead->phone ?: $lead->company?->public_phone, $lead->country ?: $lead->company?->country);
        if (!$number) {
            return back()->with('error', 'No usable phone number for this lead (or its country code is unknown).');
        }
        $template = WhatsappTemplate::findOrFail($data['template_id']);
        $text = $template->render($lead);
        AuditLog::record('whatsapp_opened', $lead, null, ['template' => $template->name, 'to' => $number]);

        return redirect()->away('https://wa.me/' . $number . '?text=' . rawurlencode($text));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::in(array_keys(WhatsappTemplate::PURPOSES))],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
