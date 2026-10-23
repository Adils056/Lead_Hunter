<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Sales\ConversionAssistantService;
use Illuminate\Http\Request;

class SalesAssistantController extends Controller
{
    public function show(Lead $lead, ConversionAssistantService $assistant)
    {
        $this->authorize('view', $lead);
        $email = $assistant->draftFirstEmail($lead, false); // AI polish only on explicit request (paid, slow)
        if (($ai = session('ai_draft')) && ($email['ok'] ?? false)) {
            $email['draft'] = $ai;
        }
        $lead->load('company.latestWebsiteAudit');

        return view('assistant.show', [
            'lead' => $lead,
            'offer' => $assistant->recommendOffer($lead),
            'email' => $email,
            'aiAvailable' => (bool) (app(\App\Services\Integrations\ProviderRegistry::class)->select('llm') || app(\App\Services\Integrations\ProviderRegistry::class)->isConfigured('gemini')),
            'followUp' => $assistant->suggestFollowUp($lead),
            'questions' => $assistant->discoveryQuestions($lead),
            'facts' => $assistant->facts($lead),
            'classification' => session('classification'),
            'replyText' => session('reply_text'),
        ]);
    }

    public function polish(Lead $lead, ConversionAssistantService $assistant)
    {
        $this->authorize('view', $lead);
        $out = $assistant->draftFirstEmail($lead, true);
        if (!($out['ok'] ?? false)) {
            return back()->with('error', $out['reason'] ?? 'Cannot draft.');
        }
        $gen = $out['draft']['generator'];

        return redirect()->route('assistant.show', $lead)->with('ai_draft', $out['draft'])
            ->with($gen !== 'template' ? 'success' : 'error', $gen !== 'template' ? 'AI-polished draft (facts-only, guard-checked).' : 'AI draft was rejected by the guardrails or unavailable — template kept.');
    }

    public function classify(Request $request, Lead $lead, ConversionAssistantService $assistant)
    {
        $this->authorize('view', $lead);
        $data = $request->validate(['reply' => ['required', 'string', 'max:10000']]);

        return redirect()->route('assistant.show', $lead)
            ->with('classification', $assistant->classifyReply($data['reply'], $lead->contact_status === 'identified' ? $lead->first_name : null))
            ->with('reply_text', $data['reply']);
    }
}
