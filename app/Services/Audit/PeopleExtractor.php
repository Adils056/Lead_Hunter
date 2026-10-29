<?php

namespace App\Services\Audit;

/**
 * Decision-makers a company names on its own website (SQA item 21): schema.org Person data, team cards
 * ("Jane Doe" + "Founder") and lines like "Jane Doe, Managing Director". An email is attached only when the
 * site itself publishes one on the company domain that matches the name; nothing is ever guessed.
 */
class PeopleExtractor
{
    /** Roles that can say yes to a website / software project, best first. */
    public const ROLES = [
        'owner' => 100, 'proprietor' => 100, 'founder' => 95, 'co-founder' => 95, 'ceo' => 95, 'chief executive' => 95, 'managing director' => 90,
        'president' => 85, 'principal' => 85, 'managing partner' => 85, 'partner' => 75, 'director' => 75, 'general manager' => 70, 'cto' => 70,
        'chief technology' => 70, 'coo' => 65, 'cmo' => 65, 'chief marketing' => 65, 'head of marketing' => 65, 'marketing manager' => 60,
        'practice manager' => 60, 'clinic manager' => 60, 'office manager' => 50, 'operations manager' => 50, 'head of' => 50, 'manager' => 40,
    ];

    /** Words that make "Two Words" a heading or menu item, not a person. */
    protected const NOT_NAME_WORDS = ['about', 'contact', 'our', 'team', 'home', 'read', 'more', 'services', 'service', 'privacy', 'policy', 'terms', 'copyright',
        'rights', 'reserved', 'get', 'touch', 'book', 'now', 'call', 'menu', 'opening', 'hours', 'street', 'road', 'lane', 'avenue', 'meet', 'the', 'and', 'of', 'for',
        'with', 'view', 'all', 'learn', 'find', 'us', 'we', 'you', 'your', 'free', 'quote', 'news', 'blog', 'latest', 'company', 'limited', 'ltd', 'llc', 'inc',
        'clinic', 'dental', 'care', 'centre', 'center', 'group', 'studio', 'salon', 'restaurant', 'office', 'director', 'directors', 'manager', 'founder', 'owner',
        'managing', 'general', 'head', 'chief', 'executive', 'partner', 'partners', 'board', 'leadership', 'management', 'staff', 'people', 'customer', 'customers',
        'testimonials', 'reviews', 'review', 'gallery', 'portfolio', 'projects', 'careers', 'jobs', 'faq', 'help', 'support', 'sign', 'login', 'register', 'shop', 'cart'];

    protected const NAME = "(?:(?:Dr|Mr|Mrs|Ms|Prof|Engr)\.?\s+)?[A-Z][a-z'’\-]{1,20}(?:\s+[A-Z][a-z'’\-]{1,20}){1,2}";

    /**
     * @param array $emails emails found on the site: [['value' => .., 'on_company_domain' => bool], ...]
     * @return list<array{name:string, first_name:string, last_name:string, role:string, rank:int, email:?string, source_url:string}>
     */
    public function extract(string $html, string $pageUrl, array $emails = []): array
    {
        $found = [];
        foreach ($this->fromJsonLd($html) as [$name, $role, $email]) {
            $this->add($found, $name, $role, $email, $pageUrl, $emails);
        }

        $lines = $this->lines($html);
        $roleRe = '(' . implode('|', array_map(fn ($r) => preg_quote($r, '/'), array_keys(self::ROLES))) . ')';
        foreach ($lines as $i => $line) {
            if (mb_strlen($line) > 120) {
                continue;
            }
            // "Jane Doe, Founder" / "Jane Doe – Managing Director" / "Jane Doe (Owner)"
            if (preg_match('/^(' . self::NAME . ')\s*(?:[,|–—\-:]|\()\s*([^()]{0,40}\b' . $roleRe . '\b[^()]{0,30})\)?$/iu', $line, $m)) {
                $this->add($found, $m[1], $m[2], null, $pageUrl, $emails);
            } elseif (preg_match('/^([^:]{0,30}\b' . $roleRe . '\b[^:]{0,20})\s*[:–—\-]\s*(' . self::NAME . ')$/iu', $line, $m)) {
                // "Founder: Jane Doe"
                $this->add($found, end($m), $m[1], null, $pageUrl, $emails);
            } elseif (preg_match('/^' . self::NAME . '$/u', $line) && isset($lines[$i + 1]) && mb_strlen($lines[$i + 1]) <= 60
                && preg_match('/^[^.!?]{0,25}\b' . $roleRe . '\b/i', $lines[$i + 1])) {
                // team card: name on one line, role on the next
                $this->add($found, $line, $lines[$i + 1], null, $pageUrl, $emails);
            }
        }

        usort($found, fn ($a, $b) => [$b['email'] !== null, $b['rank']] <=> [$a['email'] !== null, $a['rank']]);

        return array_slice(array_values($found), 0, 5);
    }

    protected function add(array &$found, string $name, string $role, ?string $email, string $pageUrl, array $emails): void
    {
        $name = trim(preg_replace(['/\s+/', '/^(dr|mr|mrs|ms|miss|prof|sir|engr)\.?\s+/i'], [' ', ''], trim($name)));
        $parts = explode(' ', $name);
        if (count($parts) < 2 || count($parts) > 3 || !preg_match('/^' . self::NAME . '$/u', $name)) {
            return;
        }
        foreach ($parts as $p) {
            if (in_array(strtolower($p), self::NOT_NAME_WORDS, true)) {
                return;
            }
        }
        $role = trim(preg_replace('/\s+/', ' ', strip_tags($role)), " ,.-–—|:()");
        $rank = 0;
        foreach (self::ROLES as $r => $score) {
            if (preg_match('/\b' . preg_quote($r, '/') . '\b/i', $role)) {
                $rank = max($rank, $score);
            }
        }
        if ($rank === 0) {
            return;
        }
        $first = $parts[0];
        $last = end($parts);
        $email = $email ? strtolower($email) : $this->matchingEmail($first, $last, $emails);
        $key = strtolower($name);
        if (isset($found[$key]) && ($found[$key]['email'] || !$email) && $found[$key]['rank'] >= $rank) {
            return;
        }
        $found[$key] = ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'role' => mb_substr($role, 0, 80), 'rank' => $rank, 'email' => $email, 'source_url' => $pageUrl];
    }

    /** A published on-domain address that is plainly this person's: jane@, jane.doe@, janedoe@, jdoe@, j.doe@, jane_doe@. */
    protected function matchingEmail(string $first, string $last, array $emails): ?string
    {
        $f = strtolower(\Illuminate\Support\Str::ascii($first));
        $l = strtolower(\Illuminate\Support\Str::ascii(preg_replace("/['’\-]/u", '', $last)));
        $wanted = [$f, "{$f}.{$l}", "{$f}{$l}", "{$f[0]}{$l}", "{$f[0]}.{$l}", "{$f}_{$l}", "{$f}-{$l}"];
        foreach ($emails as $e) {
            if (!empty($e['on_company_domain']) && in_array(strtolower(strstr($e['value'], '@', true)), $wanted, true)) {
                return strtolower($e['value']);
            }
        }

        return null;
    }

    /** @return list<array{0:string,1:string,2:?string}> name, role, email */
    protected function fromJsonLd(string $html): array
    {
        $out = [];
        if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
            return $out;
        }
        $walk = function ($node) use (&$walk, &$out) {
            if (!is_array($node)) {
                return;
            }
            $type = (array) ($node['@type'] ?? []);
            if (in_array('Person', $type, true) && is_string($node['name'] ?? null) && is_string($node['jobTitle'] ?? null)) {
                $email = is_string($node['email'] ?? null) ? preg_replace('/^mailto:/i', '', $node['email']) : null;
                $out[] = [$node['name'], $node['jobTitle'], $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null];
            }
            foreach (['founder' => 'Founder', 'founders' => 'Founder'] as $k => $role) {
                foreach (isset($node[$k]['name']) ? [$node[$k]] : (array) ($node[$k] ?? []) as $p) {
                    if (is_array($p) && is_string($p['name'] ?? null)) {
                        $out[] = [$p['name'], is_string($p['jobTitle'] ?? null) ? $p['jobTitle'] : $role, null];
                    }
                }
            }
            foreach ($node as $k => $child) {
                if (is_array($child) && !in_array($k, ['founder', 'founders'], true)) {
                    $walk($child);
                }
            }
        };
        foreach ($m[1] as $json) {
            $walk(json_decode(html_entity_decode(trim($json)), true));
        }

        return $out;
    }

    /** Visible text, one block element per line. */
    protected function lines(string $html): array
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|footer)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/span|/strong|/b|/a|/figcaption|/dt|/dd)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text)), fn ($l) => $l !== ''));
    }
}
