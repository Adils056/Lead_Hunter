<?php

namespace App\Services\Sales;

use App\Models\Lead;
use App\Models\ProposalDraft;

/**
 * Builds a NON-BINDING proposal draft from verified audit findings + your service catalog price ranges.
 * Price range = your own catalog estimate (config/leadpilot.php), labelled as such. Sending always needs approval.
 */
class ProposalDraftService
{
    public function __construct(protected ConversionAssistantService $assistant)
    {
    }

    public function draft(Lead $lead): ?ProposalDraft
    {
        $rec = $this->assistant->recommendOffer($lead);
        $offer = $rec['offer'];
        if (!$offer || !$lead->company) {
            return null;
        }
        $facts = $this->assistant->facts($lead);
        $agency = config('leadpilot.agency');
        [$lo, $hi] = $offer['typical_value_usd'];
        $greeting = ($lead->contact_status === 'identified' && $lead->first_name) ? "Dear {$lead->first_name}," : "Dear {$lead->company->name} team,";
        $findings = collect($facts)->take(5)->map(fn ($f) => '- ' . ucfirst($f['text']) . ' (observed ' . $f['observed'] . ')')->implode("\n");

        $body = <<<TXT
{$greeting}

Thank you for your interest. Below is a short, non-binding outline for {$offer['label']}.

1. What we observed
{$findings}

2. Proposed scope
- Fix the issues above, starting with the ones that most affect enquiries.
- {$offer['pitch']}.
- Hand-over walkthrough and 30 days of post-launch fixes.

3. Indicative investment
USD {$lo} – {$hi}, depending on the final scope we agree after a short call. This is an estimate, not a quotation.

4. Next step
A 20–30 minute call to confirm priorities, after which we send a fixed quote.

This outline is for discussion only and is not an offer capable of acceptance.

{$agency['sender_name']}
{$agency['name']} · {$agency['website']}
TXT;

        return ProposalDraft::create([
            'lead_id' => $lead->id, 'company_id' => $lead->company_id, 'service' => $offer['service'],
            'title' => "Proposal outline: {$offer['label']} for {$lead->company->name}",
            'body' => $body, 'price_low_usd' => $lo, 'price_high_usd' => $hi, 'status' => 'draft',
            'evidence' => array_slice($facts, 0, 5),
        ]);
    }
}
