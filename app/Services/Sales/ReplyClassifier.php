<?php

namespace App\Services\Sales;

/**
 * Deterministic, negation-aware reply classifier.
 *
 * Categories: interested, not_interested, request_info, objection (incl. negotiation), referral,
 * out_of_office, unsubscribe, uncertain (ambiguous -> always routed to a human).
 * 'interested' carries subtype 'meeting_request' when the prospect proposes/asks for a call.
 *
 * Order matters: unsubscribe > out_of_office > referral > not_interested > objection > interested.
 * Rule-based on purpose: auditable, free, and fast; ambiguous replies go to human review.
 */
class ReplyClassifier
{
    public const CATEGORIES = ['interested', 'not_interested', 'request_info', 'objection', 'referral', 'out_of_office', 'unsubscribe', 'uncertain'];

    public function classify(string $text, ?string $contactName = null): array
    {
        $raw = trim($text);
        // Drop quoted history ("On ... wrote:" and "> " lines) so our own email doesn't trigger matches
        $body = preg_split('/\n\s*(On .{5,120}wrote:|-{2,}\s*Original Message|From: )/i', $raw)[0];
        $body = implode("\n", array_filter(preg_split('/\r?\n/', $body), fn ($l) => !str_starts_with(ltrim($l), '>')));
        $t = ' ' . strtolower(preg_replace('/\s+/', ' ', $body)) . ' ';
        $name = $contactName ?: 'there';

        if (trim($t) === '') {
            return $this->out('uncertain', 40, 'Empty reply body.', true);
        }

        $negUnsub = preg_match('/\b(don\'?t|do not|please don\'?t|no need to|not)\s+(unsubscribe|remove)\b/', $t);
        if (!$negUnsub && preg_match('/\b(unsubscribe|remove me|take me off|stop (emailing|contacting|sending)|opt[ -]?out|do not (email|contact)|don\'t (email|contact) me|never contact|gdpr.*(delete|erase))\b/', $t)) {
            return $this->out('unsubscribe', 99, 'Explicit opt-out request. Suppress immediately and stop all sequences.', false);
        }

        if (preg_match('/\b(out of (the )?office|ooo|automatic reply|auto-?reply|on (annual |parental |maternity |sick )?leave|on vacation|on holiday|away from (my desk|the office)|limited access to (my )?email|back in the office|returning (to the office )?on|auto(matic)? response)\b/', $t)) {
            $return = null;
            if (preg_match('/\b(?:back|return(?:ing)?|until)\s+(?:on\s+)?((?:mon|tue|wed|thu|fri|sat|sun)[a-z]*,?\s*)?(\d{1,2}(?:st|nd|rd|th)?\s+[a-z]{3,9}|[a-z]{3,9}\s+\d{1,2}(?:st|nd|rd|th)?|\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?)/', $t, $m)) {
                $return = trim($m[2]);
            }

            return $this->out('out_of_office', 95, 'Auto-reply / out of office. Pause the sequence; resume after return date.', false, null, ['return_date_text' => $return]);
        }

        $negated = preg_match('/\b(not|no longer|isn\'t|aren\'t|don\'t|do not|won\'t|never)\s+(really\s+)?(interested|looking|in need|need)\b/', $t)
            // "we're not working with external recruiters, staffing agencies, or contract teams" (seen live, was "uncertain")
            || preg_match('/\b(not|aren\'t|are not|don\'t|do not|won\'t|never)\s+(currently\s+)?(working|work|partnering|engaging|using|hiring)\s+(with\s+)?(any\s+)?(external|outside|third[- ]party|offshore)?\s*(recruiters?|agenc(y|ies)|vendors?|contractors?|freelancers?|staffing|outsourc\w*|consultants?|contract teams?)\b/', $t)
            || preg_match('/\b(no|without)\s+(recruiters?|agenc(y|ies)|solicitations?|vendors?|outsourcing)\b|\b(remove me|take me off|stop (emailing|contacting) (me|us))\b/', $t);
        if ($negated || preg_match('/\b(no thanks|no thank you|not at this time|not right now|please pass|we\'?ll pass|not a (good )?fit|no need|not relevant)\b/', $t)) {
            return $this->out('not_interested', 92, 'Prospect declined. Stop the sequence; optionally re-engage in 6 months if a new trigger appears.', false,
                "Hi {$name},\n\nUnderstood — thanks for letting me know. I won't follow up further.\n\nAll the best.");
        }

        $meeting = preg_match('/\b(schedule|book|set up|arrange|hop on|jump on)\b.{0,30}\b(call|meeting|chat|demo|zoom|meet)\b|\b(call|meeting|chat|zoom|google meet|teams)\b.{0,40}\b(monday|tuesday|wednesday|thursday|friday|tomorrow|next week|this week|\d{1,2}\s?(am|pm))\b|\bwhen are you (free|available)|send (me )?(an |a calendar )?invite|calendar link\b/', $t);
        // A meeting request wins; plain interest that also raises a price objection is an objection.
        $priceObjection = preg_match('/\b(too expensive|over (our|the) budget|out of (our )?budget|no budget|can\'?t afford|too pricey)\b/', $t);
        $explicit = preg_match('/\b(interested|sounds (good|great|interesting)|let\'?s (talk|discuss|connect|do it|go ahead)|keen|(would|\'d) love to|yes,? please|happy to (chat|talk)|go ahead|count us in)\b/', $t);
        $infoAsk = preg_match('/\b(tell me more|more (info|information|details)|send (me |us )?(more |some )?(info|information|details|portfolio|case studies|examples|a proposal|a quote|your pricing|pricing|rate card)|send (me |us )?(a |your )?(proposal|quote|rate card)|what\'?s your (budget|price|rate|pricing)|how much (would|does|do|is)|what do you charge|do you have examples)\b/', $t);
        if ($meeting) {
            $booking = config('leadpilot.agency.booking_url');

            return $this->out('interested', 93, 'Prospect wants to meet. Offer real available times (or your booking link) within 24h.', false,
                "Hi {$name},\n\nGreat — " . ($booking ? "you can pick any slot that suits you here: {$booking}" : '[LeadPilot will insert real available times from your calendar settings]') . "\n\nI'll bring the specific findings so we can keep it short.",
                ['subtype' => 'meeting_request']);
        }
        if ($explicit && !$priceObjection) {
            return $this->out('interested', 88, 'Positive interest. Reply within hours with the specific next step.', false,
                "Hi {$name},\n\nThanks — glad it's useful. What would you most want to improve in the next 3 months? I'll come back with a short plan and a rough cost range.",
                ['subtype' => $infoAsk ? 'interested_with_question' : 'positive']);
        }
        if ($infoAsk && !$priceObjection) {
            return $this->out('request_info', 86, 'Prospect asked for more information (pricing, examples or a proposal). Answer specifically; a human checks any price before it is sent.', true,
                "Hi {$name},\n\nHappy to. To make it relevant: roughly what would you want done first, and by when? I'll send 2–3 examples and a cost range that matches.");
        }

        if (preg_match('/\b(not the right person|wrong person|not my (area|department|responsibility)|you should (speak|talk|reach out|contact)|please (contact|reach out to|speak (to|with)|talk to|email) (my|our|him|her|them|the [a-z]+ (team|manager|department|head)|[a-z0-9._%+-]+@)|better (person|contact) (is|would be)|i\'?ve (cc|copied|forwarded)|cc\'?(ing|d)|forwarded (this|your email) to|handles (this|that))\b/', $t)) {
            preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $t, $em);

            return $this->out('referral', 90, 'Prospect referred us to someone else. Create a new contact (only with the details given) and reference the referral.', true,
                "Hi {$name},\n\nThank you for pointing me in the right direction — I'll reach out to them directly and mention you suggested it.\n\nAppreciate it!",
                ['referred_email' => $em[0] ?? null]);
        }

        $objections = [
            'price' => '/\b(too expensive|(very|quite|bit) expensive|over (our|the) budget|out of (our )?budget|no budget|cost(s)? too (much|high)|can\'?t afford|cheaper|price is too|too pricey)\b/',
            'timing' => '/\b(not now|next (quarter|year|month)|later this year|bad time|busy (right now|at the moment)|revisit|circle back|after (eid|ramadan|the holidays))\b/',
            'has_vendor' => '/\b(already (have|work with|using|use)|existing (agency|vendor|developer|partner)|our (agency|vendor|developer) (handles|does))\b/',
            'in_house' => '/\b(in-?house|internal team|our (own )?(team|developers) (handle|do))\b/',
            'trust' => '/\b(how did you get|who are you|is this (spam|legit)|never heard)\b/',
            'need_info' => '/\b(what exactly|can you explain|what do you mean)\b/',
        ];
        foreach ($objections as $type => $re) {
            if (preg_match($re, $t)) {

                return $this->out('objection', 85, "Objection detected ({$type}). Needs a human response.", true,
                    $this->objectionReply($type, $name), ['objection_type' => $type]);
            }
        }

        return $this->out('uncertain', 50, 'Could not confidently classify. Route to a human.', true);
    }

    protected function objectionReply(string $type, string $name): string
    {
        return match ($type) {
            'price' => "Hi {$name},\n\nFair point. We can phase it — fix the highest-impact items first for a fixed, small fee, and you decide on the rest after seeing results. Want me to outline that first phase?",
            'timing' => "Hi {$name},\n\nUnderstood. When would be a better time to revisit — I'll make a note and only follow up then.",
            'has_vendor' => "Hi {$name},\n\nGood to hear you're covered. If it's useful, I'm happy to share the audit findings with your current team — no strings attached.",
            'in_house' => "Hi {$name},\n\nMakes sense. Some teams use us for overflow or specialist work (e.g. integrations) so their own developers stay on the roadmap. If that's ever useful, I'm here.",
            'trust' => "Hi {$name},\n\nFair question. I found your business through public listings and reviewed your public website; that's where the observations in my note came from. If you'd rather not hear from us, just say so and I'll remove you.",
            default => "Hi {$name},\n\nHappy to explain. In short: [one-sentence answer]. Would a 15-minute call be easier, or shall I send a short written summary?",
        };
    }

    protected function out(string $category, int $confidence, string $reasoning, bool $review, ?string $reply = null, array $extra = []): array
    {
        return [
            'category' => $category,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'requires_human_review' => $review,
            'suggested_reply' => $reply,
        ] + $extra;
    }

    /** Map to the legacy labels stored by the webhook pipeline. */
    public static function legacyLabel(array $r): string
    {
        return match ($r['category']) {
            'interested' => ($r['subtype'] ?? '') === 'meeting_request' ? 'meeting_request' : 'positive',
            'not_interested' => 'negative',
            'request_info' => 'positive',
            default => $r['category'],
        };
    }
}
