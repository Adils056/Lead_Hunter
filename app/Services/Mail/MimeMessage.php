<?php

namespace App\Services\Mail;

/** Small RFC 5322/2045 parser: headers, encoded words, multipart, QP/base64, charsets, delivery-status reports. */
class MimeMessage
{
    public array $headers = [];
    public string $text = '';
    public bool $isBounce = false;
    public ?string $bouncedRecipient = null;
    public ?string $bouncedMessageId = null;
    public ?string $bounceStatus = null;

    public static function parse(string $raw): self
    {
        $m = new self();
        [$head, $body] = self::split($raw);
        $m->headers = self::parseHeaders($head);
        $m->text = trim(self::bodyText($m->headers, $body, $m));
        $from = strtolower($m->header('from') ?? '');
        $ctype = strtolower($m->header('content-type') ?? '');
        // A bounce = a real DSN (multipart/report; report-type=delivery-status) or a sender whose local part is
        // exactly mailer-daemon/postmaster. A display name like "Postmaster fan" is NOT enough.
        $fromLocal = strtolower((string) strstr((string) $m->fromEmail(), '@', true));
        if (str_contains($ctype, 'report-type=delivery-status') || in_array($fromLocal, ['mailer-daemon', 'postmaster'], true)) {
            $m->isBounce = true;
            if (!$m->bouncedRecipient && preg_match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+)>?/i', $raw, $x)) {
                $m->bouncedRecipient = strtolower($x[1]);
            }
            if (preg_match('/^Status:\s*([245]\.\d+\.\d+)/mi', $raw, $x)) {
                $m->bounceStatus = $x[1];
            }
            if (preg_match_all('/^Message-ID:\s*<([^>]+)>/mi', $raw, $x) && count($x[1]) > 0) {
                $m->bouncedMessageId = end($x[1]); // the embedded original message's id
            }
        }

        return $m;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function messageId(): ?string
    {
        return ($h = $this->header('message-id')) ? trim($h, " <>\t") : null;
    }

    /** @return string[] ids referenced by In-Reply-To/References */
    public function referencedIds(): array
    {
        preg_match_all('/<([^>]+)>/', ($this->header('in-reply-to') ?? '') . ' ' . ($this->header('references') ?? ''), $m);

        return array_values(array_unique($m[1]));
    }

    public function fromEmail(): ?string
    {
        $f = $this->header('from') ?? '';

        return preg_match('/<?([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})>?/i', $f, $m) ? strtolower($m[1]) : null;
    }

    /** Gmail/most providers add Authentication-Results; false when SPF and DKIM both explicitly failed. */
    public function senderAuthenticated(): ?bool
    {
        $ar = strtolower($this->header('authentication-results') ?? '');
        if ($ar === '') {
            return null; // unknown (header not present)
        }

        return str_contains($ar, 'dkim=pass') || str_contains($ar, 'spf=pass') || str_contains($ar, 'dmarc=pass');
    }

    public function isAutoSubmitted(): bool
    {
        $a = strtolower($this->header('auto-submitted') ?? 'no');

        return $a !== 'no' || stripos($this->header('x-autoreply') ?? '', 'yes') !== false;
    }

    protected static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");

        return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    protected static function parseHeaders(string $head): array
    {
        $out = [];
        $head = preg_replace("/\n[ \t]+/", ' ', $head); // unfold
        foreach (explode("\n", $head) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $v = self::decodeWords(trim($v));
            $out[$k] = isset($out[$k]) && $k !== 'message-id' ? $out[$k] : $v; // first occurrence wins
        }

        return $out;
    }

    public static function decodeWords(string $v): string
    {
        if (!str_contains($v, '=?')) {
            return $v;
        }
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $d !== false ? $d : (mb_decode_mimeheader($v) ?: $v);
    }

    protected static function param(string $header, string $name): ?string
    {
        return preg_match('/' . $name . '\s*=\s*"?([^";]+)"?/i', $header, $m) ? trim($m[1]) : null;
    }

    protected static function bodyText(array $headers, string $body, self $msg, int $depth = 0): string
    {
        $ctype = $headers['content-type'] ?? 'text/plain';
        $enc = strtolower($headers['content-transfer-encoding'] ?? '7bit');

        if ($depth < 5 && stripos($ctype, 'multipart/') === 0 && ($b = self::param($ctype, 'boundary'))) {
            $parts = preg_split('/^--' . preg_quote($b, '/') . '(--)?[ \t]*$/m', $body);
            $plain = $html = null;
            foreach ($parts as $p) {
                $p = ltrim($p, "\n");
                if (trim($p) === '') {
                    continue;
                }
                [$ph, $pb] = self::split($p);
                $phs = self::parseHeaders($ph);
                $pct = strtolower($phs['content-type'] ?? 'text/plain');
                if (str_starts_with($pct, 'message/delivery-status') && preg_match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+)>?/i', $pb, $x)) {
                    $msg->bouncedRecipient = strtolower($x[1]);
                }
                if (str_contains(strtolower($phs['content-disposition'] ?? ''), 'attachment')) {
                    continue;
                }
                $t = self::bodyText($phs, $pb, $msg, $depth + 1);
                if (str_starts_with($pct, 'text/plain') || str_starts_with($pct, 'multipart/')) {
                    $plain ??= $t;
                } elseif (str_starts_with($pct, 'text/html')) {
                    $html ??= $t;
                }
            }

            return (string) ($plain ?? $html ?? '');
        }

        $decoded = match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $charset = self::param($ctype, 'charset');
        if ($charset && strtoupper($charset) !== 'UTF-8') {
            $conv = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            $decoded = $conv !== false ? $conv : $decoded;
        }
        if (stripos($ctype, 'text/html') === 0) {
            $cleaned = preg_replace(['/<br\s*\/?>/i', '/<\/p>/i', '/<(style|script)[^>]*>.*?<\/\1>/is'], ["\n", "\n\n", ''], mb_substr($decoded, 0, 500000));
            $decoded = html_entity_decode(strip_tags($cleaned ?? mb_substr($decoded, 0, 500000)), ENT_QUOTES, 'UTF-8'); // PCRE failure -> plain strip
        }

        return $decoded;
    }
}
