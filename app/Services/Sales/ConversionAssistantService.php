<?php

namespace App\Services\Sales;

use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Services\AiResearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversion assistant: offer -> why -> evidence-only first email -> follow-ups -> reply handling.
 *
 * Grounding rules:
 *  - Personalisation uses only "facts" built from stored evidence (audit checks, signals, company record).
 *  - Never greets an invented person: unknown contact => "Hi {Company} team".
 *  - Optional OpenAI polish receives ONLY the facts; its output is rejected (template kept) if it is
 *    over the word limit or introduces any number not present in the facts.
 */
class ConversionAssistantService
{
    public function __construct(
        protected OfferRecommender $offers,
        protected ReplyClassifier $classifier,
    ) {
    }

    // ------------------------------------------------------------ facts

    /** Verifiable observations about this prospect, strongest first. Each has text + source. */
    public function facts(Lead $lead): array
    {
        $c = $lead->company;
        $facts = [];
        if (!$c) {
            return $facts;
        }
        $site = $c->domain ?: ($c->website ? parse_url($c->website, PHP_URL_HOST) : null);

        $audit = $c->latestWebsiteAudit;
        if ($audit && $audit->status === 'completed') {
            $phr = [
                'viewport' => "{$site} isn't set up for mobile screens (no viewport tag), so it shows zoomed-out on phones",
                'cta' => "the {$site} homepage has no clear 'contact', 'quote' or 'book' button",
                'https' => "{$site} loads without HTTPS, so Chrome labels it 'Not secure'",
                'response_time' => "the {$site} homepage took {$audit->response_time_ms} ms just to return its HTML when I checked",
                'contact_form' => "I couldn't find an enquiry form on {$site}",
                'meta_description' => "the {$site} homepage has no meta description, so Google writes its own search snippet",
                'title' => "the {$site} homepage title is missing or poorly sized for Google results",
                'copyright' => null,
                'broken_links' => "some internal links on {$site} return errors",
                'robots_noindex' => "the {$site} homepage is set to 'noindex', which hides it from Google",
                'click_to_call' => "mobile visitors can't tap to call from {$site}",
                'pagespeed_mobile' => "Google PageSpeed rates {$site}'s mobile performance " . ($audit->pagespeed['performance_score'] ?? '?') . '/100',
                'legacy_tech' => "{$site} runs outdated front-end code",
                'mixed_content' => "{$site} loads some files insecurely on its https pages",
                'og_tags' => "links to {$site} shared on WhatsApp or LinkedIn show no preview card",
            ];
            $order = ['robots_noindex', 'viewport', 'https', 'cta', 'pagespeed_mobile', 'broken_links', 'response_time', 'contact_form', 'click_to_call', 'meta_description', 'title', 'copyright', 'og_tags', 'legacy_tech', 'mixed_content'];
            $byKey = collect($audit->issues())->keyBy('key');
            foreach ($order as $k) {
                if (!$byKey->has($k)) {
                    continue;
                }
                $chk = $byKey[$k];
                $text = $k === 'copyright' ? "the footer on {$site} still says © {$chk['value']}" : ($phr[$k] ?? null);
                if ($text) {
                    $facts[] = ['text' => $text, 'source' => 'website_audit', 'check' => $k, 'evidence' => $chk['evidence'], 'observed' => $audit->audited_at?->toDateString()];
                }
            }
        } elseif ($audit && $audit->status === 'unreachable') {
            $facts[] = ['text' => "{$site} didn't load when I tried it on " . $audit->audited_at?->format('j M'), 'source' => 'website_audit', 'check' => 'reachable', 'evidence' => $audit->summary, 'observed' => $audit->audited_at?->toDateString()];
        }

        foreach ($c->buyingSignals()->active()->orderByDesc('confidence')->limit(5)->get() as $s) {
            $text = match (true) {
                ($s->metadata['evidence_key'] ?? null) === 'no_website' => "{$c->name} doesn't list a website on its Google Business profile" . ($c->google_review_count ? " despite {$c->google_review_count} Google reviews" : ''),
                $s->signal_type === 'developer_hiring' => "you're hiring for " . preg_replace('/^(Hiring Demand: |Hiring )/i', '', $s->title),
                $s->signal_type === 'procurement_rfp' => 'you published the tender "' . preg_replace('/^Tender: /', '', $s->title) . '"',
                in_array($s->signal_type, ['funding_announcement', 'business_expansion', 'business_launch'], true) => 'I saw the news: ' . $s->title,
                default => null,
            };
            if ($text) {
                $facts[] = ['text' => $text, 'source' => 'buying_signal', 'signal_id' => $s->id, 'evidence' => $s->source_url, 'observed' => (string) $s->observed_date];
            }
        }

        return $facts;
    }

    // ------------------------------------------------------------ offer

    public function recommendOffer(Lead $lead): array
    {
        $offers = $this->offers->recommend($lead, 3);
        if (!$offers) {
            return ['offer' => null, 'why' => 'No evidence-backed offer yet. Run a website audit or add a buying signal first.', 'alternatives' => []];
        }
        $o = $offers[0];
        $why = "Recommended because: " . implode(' | ', array_slice($o['evidence'], 0, 3)) . '.';

        return ['offer' => $o, 'why' => $why, 'alternatives' => array_slice($offers, 1)];
    }

    // ------------------------------------------------------------ first email

    public function draftFirstEmail(Lead $lead, bool $allowAi = true): array
    {
        $agency = config('leadpilot.agency');
        $limit = (int) config('leadpilot.outreach.max_words_first_email', 80);
        $c = $lead->company;
        $facts = $this->facts($lead);
        $rec = $this->recommendOffer($lead);
        $offer = $rec['offer'];

        if (!$c || !$facts || !$offer) {
            return [
                'ok' => false,
                'reason' => !$facts ? 'No verified observation to personalise with. Run the website audit or add a signal — sending a generic email is not recommended.' : 'No evidence-backed offer.',
                'facts' => $facts,
            ];
        }

        $greeting = ($lead->contact_status === 'identified' && $lead->first_name) ? "Hi {$lead->first_name}," : "Hi {$c->name} team,";
        $f1 = $facts[0]['text'];
        $subject = $this->subjectFor($facts[0], $c->name);
        $cta = $agency['booking_url']
            ? 'Worth a 15-minute call? You can pick a time here: ' . $agency['booking_url']
            : 'Want me to send the 3 fixes I would make first? No charge.';
        $body = "{$greeting}\n\nI noticed {$f1}.\n\nWe help businesses like yours with {$offer['pitch']}.\n\n{$cta}\n\n{$agency['sender_name']}\n{$agency['name']}";

        $draft = ['subject' => $subject, 'body' => $body, 'generator' => 'template'];

        if ($allowAi) {
            $ai = $this->aiPolish($greeting, $facts, $offer, $cta, $limit);
            if ($ai) {
                $draft = $ai + ['generator' => $ai['ai_provider'] ?? 'ai'];
            }
        }

        $draft['word_count'] = $this->words($draft['body'], true);
        $draft['within_limit'] = $draft['word_count'] <= $limit;

        return [
            'ok' => true,
            'draft' => $draft,
            'facts_used' => array_slice($facts, 0, 2),
            'offer' => $offer,
            'why' => $rec['why'],
            'warnings' => array_values(array_filter([
                !$lead->email ? 'No email address on record — use phone or find the decision-maker first.' : null,
                $lead->contact_status === 'generic_inbox' ? 'Going to a shared inbox; a named decision-maker usually responds better.' : null,
                $lead->email_verification_status !== 'verified' && $lead->email ? 'Email not verified — verify before sending.' : null,
            ])),
        ];
    }

    protected function subjectFor(array $fact, string $company): string
    {
        return match ($fact['check'] ?? $fact['source']) {
            'viewport', 'pagespeed_mobile' => "{$company} on mobile",
            'cta', 'contact_form', 'click_to_call' => "Enquiries from your website",
            'https', 'mixed_content' => "'Not secure' warning on your site",
            'robots_noindex', 'meta_description', 'title' => "{$company} in Google results",
            'reachable' => "Your website is down?",
            'buying_signal' => "Quick idea for {$company}",
            default => "Quick note about {$company}'s website",
        };
    }

    protected function aiPolish(string $greeting, array $facts, array $offer, string $cta, int $limit): ?array
    {
        $allowedFacts = array_map(fn ($f) => $f['text'], array_slice($facts, 0, 2));
        $agency = config('leadpilot.agency');
        // No personal data leaves the system: the greeting (may contain a name) is a placeholder.
        $res = app(LlmClient::class)->json(
            "You write short, plain, friendly B2B cold emails. Use ONLY the facts provided; do not add any other claim, number, statistic, client name or promise. Under {$limit} words. No buzzwords, no flattery, no exclamation marks. Start the body with the literal token {{GREETING}} and end with the exact call-to-action and signature given. Return JSON {\"subject\": string, \"body\": string}.",
            json_encode(['facts' => $allowedFacts, 'offer' => $offer['pitch'], 'call_to_action' => $cta, 'signature' => $agency['sender_name'] . "\n" . $agency['name']]),
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'body'], 'properties' => ['subject' => ['type' => 'string'], 'body' => ['type' => 'string']]]
        );
        $out = $res['data'] ?? null;
        if (!is_array($out) || empty($out['body']) || empty($out['subject'])) {
            return null;
        }
        $out['body'] = str_contains($out['body'], '{{GREETING}}') ? str_replace('{{GREETING}}', $greeting, $out['body']) : $greeting . "\n\n" . $out['body'];

        // Guardrails: word limit and no number that is not in the facts/CTA
        $allowedText = implode(' ', $allowedFacts) . ' ' . $cta . ' ' . $agency['name'] . ' ' . $greeting;
        preg_match_all('/\d+(?:[.,]\d+)*/', $allowedText, $okNums);
        $allowedSet = array_flip($okNums[0]);
        preg_match_all('/\d+(?:[.,]\d+)*/', $out['body'] . ' ' . $out['subject'], $nums);
        foreach ($nums[0] as $n) {
            if (!isset($allowedSet[$n])) {
                Log::info("AI draft rejected: introduced number {$n}");

                return null;
            }
        }
        if ($this->words($out['body'], true) > $limit) {
            return null;
        }

        return ['subject' => mb_substr($out['subject'], 0, 80), 'body' => $out['body'], 'ai_provider' => $res['provider']];
    }

    // ------------------------------------------------------------ follow-ups

    public function suggestFollowUp(Lead $lead): array
    {
        $cadence = (array) config('leadpilot.outreach.follow_up_days', [3, 4, 4, 5]);
        $max = (int) config('leadpilot.outreach.max_touches', 5);

        $lastReply = InboundReply::where('lead_id', $lead->id)->latest('received_at')->first();
        $pauseUntil = null;
        if ($lastReply && $lastReply->classification === 'out_of_office') {
            $r = $this->classifier->classify($lastReply->body_text);
            $pauseUntil = $this->parseReturnDate($r['return_date_text'] ?? null, $lastReply->received_at) ?? $lastReply->received_at->copy()->addDays(7);
        }
        if ($lastReply && $lastReply->classification !== 'out_of_office') {
            $r = $this->classifier->classify($lastReply->body_text, $lead->first_name);

            return [
                'action' => 'respond',
                'message' => "Stop the sequence — they replied ({$r['category']}). " . $r['reasoning'],
                'draft' => $r['suggested_reply'],
                'reply_category' => $r['category'],
            ];
        }

        $sent = EmailLog::where('lead_id', $lead->id)->where('direction', 'outbound')->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])->orderBy('occurred_at')->get();
        $touches = $sent->count();
        if ($touches === 0) {
            return ['action' => 'first_touch', 'message' => 'No email sent yet — start with the first-touch draft.', 'draft' => null];
        }
        if ($touches >= $max) {
            return ['action' => 'stop', 'message' => "Sequence complete ({$touches} touches, no reply). Move to nurture; re-engage only on a new trigger (e.g. new hiring or audit change).", 'draft' => null];
        }

        $last = $sent->last();
        $wait = $cadence[min($touches - 1, count($cadence) - 1)];
        $due = $last->occurred_at->copy()->addDays($wait);
        if ($pauseUntil && $pauseUntil->gt($due)) {
            $due = $pauseUntil->copy()->addDay(); // they're away: resume the day after they return
        }
        $c = $lead->company;
        $name = ($lead->contact_status === 'identified' && $lead->first_name) ? $lead->first_name : (($c?->name ?? '') . ' team');
        $facts = $this->facts($lead);
        $offer = $this->offers->recommend($lead, 1)[0] ?? null;
        $agency = config('leadpilot.agency');
        $sig = "\n\n{$agency['sender_name']}";

        $draft = match ($touches) {
            1 => "Hi {$name},\n\nBumping this up in case it got buried." . (isset($facts[1]) ? " One more thing I noticed: {$facts[1]['text']}." : '') . "\n\nWorth a quick look?{$sig}",
            2 => "Hi {$name},\n\nI put the issues I found into a short list with what I'd fix first. Want me to send it over? No cost, no obligation.{$sig}",
            3 => "Hi {$name},\n\nIf " . ($offer ? strtolower($offer['label']) : 'this') . " isn't a priority right now, no problem — is there someone else I should speak to, or a better time to check back?{$sig}",
            default => "Hi {$name},\n\nI'll close the loop here and won't email again. If things change, just reply to this thread.{$sig}",
        };

        return [
            'action' => $due->isPast() ? 'send_now' : 'scheduled',
            'touch' => $touches + 1,
            'due_at' => $due->toDateString(),
            'message' => "Touch " . ($touches + 1) . " of {$max}" . ($due->isPast() ? ' is due now.' : " is due on {$due->toFormattedDateString()}."),
            'draft' => $draft,
            'subject' => 'Re: ' . ($last->subject ?? 'my last note'),
        ];
    }

    public function parseReturnDate(?string $text, $from = null): ?\Carbon\Carbon
    {
        if (!$text) {
            return null;
        }
        try {
            $d = \Carbon\Carbon::parse(preg_replace('/(\d)(st|nd|rd|th)/', '$1', $text));
            $base = $from ? \Carbon\Carbon::parse($from) : now();
            if ($d->lt($base->copy()->subDay())) {
                $d->addYear();
            }

            return $d->lt($base->copy()->addDays(120)) ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------ discovery call prep

    /** BANT-style discovery questions tailored to the recommended offer (for the first call). */
    public function discoveryQuestions(Lead $lead): array
    {
        $offer = $this->offers->recommend($lead, 1)[0]['service'] ?? null;
        $specific = match ($offer) {
            'web_redesign', 'web_dev', 'conversion_optimization' => ['How do most new customers find you today?', 'Roughly how many enquiries does the website bring in per month?'],
            'seo_technical' => ['Which searches do you most want to appear for?', 'Who manages the site content today?'],
            'ecommerce' => ['What do you sell online today, and through which channels?', 'Which payment methods do your customers need?'],
            'laravel_php', 'custom_software', 'api_integration' => ['What is the project you are hiring for, and what is the deadline?', 'What would make an external team a safe choice for you?'],
            'erp_pos' => ['Which spreadsheets or tools are you replacing?', 'How many branches/users will use it?'],
            default => ['What prompted you to look at this now?'],
        };

        return [
            'need' => array_merge(['What is the main problem you want solved in the next 3 months?'], $specific),
            'authority' => ['Who else will be involved in choosing a provider?'],
            'budget' => ['Have you set a budget range for this, or would options at different price points help?'],
            'timeline' => ['When would you ideally want this live?', 'Is anything else competing for priority right now?'],
        ];
    }

    public function classifyReply(string $text, ?string $name = null): array
    {
        return $this->classifier->classify($text, $name);
    }

    protected function words(string $body, bool $excludeSignature = false): int
    {
        if ($excludeSignature) {
            $agency = config('leadpilot.agency');
            $body = str_replace([$agency['sender_name'], $agency['name']], '', $body);
        }

        return str_word_count(preg_replace('/https?:\/\/\S+/', 'link', $body));
    }
}
