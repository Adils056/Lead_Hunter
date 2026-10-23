# Security & compliance — LeadPilot AI v4.1 (verified on the owner's PC, 23 Oct 2026)

Short and honest. "Verified live" means the check was executed on this machine against the real network; "tested" means an automated test with mocked HTTP.

## 1. Outbound crawling (SSRF)

`App\Services\Audit\SafeFetcher` is the only HTTP path the website auditor uses for third‑party URLs.

Verified live on 23 Oct 2026 (`blockReason()` and `fetch()`), all refused:

| URL | Result |
|---|---|
| `http://localhost/` | Refusing local/internal host |
| `http://127.0.0.1:8000/`, `http://example.com:22/` | Refusing non‑web port (only 80/443/8080/8443) |
| `http://169.254.169.254/latest/meta-data/` | non‑public address (cloud metadata) |
| `http://10.0.0.1/`, `http://172.16.0.1/`, `http://192.168.0.1/`, `http://[::1]/`, `http://0.0.0.0/` | non‑public address |
| `http://localtest.me/` (public name → 127.0.0.1) | non‑public address after DNS resolution |
| `ftp://example.com/` | non‑HTTP scheme |
| `http://user:pw@example.com/` | embedded credentials |
| `https://www.eziline.com/` | allowed, HTTP 200 |

Design: every hop (first URL and each redirect, max 5) is resolved, every resolved IP must be public, the connection is pinned to the vetted IP (`CURLOPT_RESOLVE`) so DNS rebinding cannot redirect it, and a host that does not resolve fails closed. Automated test: `test_ssrf_guard_blocks_internal_targets_and_redirects` (LeadHuntingEngineTest).

Crawler etiquette: identifying User‑Agent (`LEADPILOT_CRAWLER_UA`), robots.txt respected (`blocked_by_robots` status), a handful of pages per site, 15 s timeout per request.

## 2. Secrets

- Secrets live only in `.env`; nothing in this repo, docs or reports prints them. `leadpilot:doctor` prints names/"set"/"missing" only.
- The validation report omits personal data by design.
- The Gmail App Password that was pasted into a chat earlier is compromised by definition: revoke it in the Google account and create a new one, then update `MAIL_PASSWORD` yourself.
- Gmail is a personal mailbox: ~500 mails/day and no SPF/DKIM/DMARC alignment for your own domain. For real outreach use a Google Workspace / own‑domain mailbox.

## 3. Email sending controls (all enforced in code, not policy text)

- Outreach mode is `dry_run` by default; `controlled_pilot` sends only to one configured address; `live` requires an admin to switch it.
- Test and pilot mails (`leadpilot:mail-test`, `--pilot-to`) are refused unless the address belongs to an **active admin user** of this app. That is why `shahpk72@gmail.com` is refused today: it is not a user. Add it as an admin (Users page) and it becomes an allowed pilot address.
- Ten eligibility checks run before every send (emergency stop, mode, score minimum, concrete evidence, legitimate source, email verification, named contact, suppression, country allow‑list, sender identity, daily and per‑recipient caps, follow‑up spacing).
- First touch needs human approval by default; every autopilot send is logged with Message‑ID; RFC 8058 one‑click unsubscribe with a signed token; suppression list honoured on email and domain; stop on any reply.
- The Win Ladder never puts suppressed, declined, invalid‑address, out‑of‑country or closed leads on an emailable rung.

## 4. Data‑source terms

| Source | Terms we rely on | How the code complies |
|---|---|---|
| OpenStreetMap (Overpass, Nominatim) | ODbL; Nominatim usage policy (identify yourself, ≤1 req/s, cache) | Attribution stored with every record; 1 req/s limiter and permanent cache of area lookups; identifying User‑Agent with contact email (`OSM_CONTACT_EMAIL`); modest query sizes; community mirror tried first |
| UK Contracts Finder / Find a Tender | Open Government Licence (OCDS) | Public buyer contacts only; tender contacts are flagged `generic_inbox` and are never auto‑emailed |
| EU TED | Free reuse with source acknowledgement | Notice URL stored as provenance |
| Greenhouse / Lever job boards | Public, keyless board APIs | Only a watchlist of named companies; job titles used as hiring signals |
| Google Places | Only `place_id` may be stored indefinitely | 30‑day purge of other fields (`leadpilot:places-purge`); disabled until a key and budget exist |
| Remotive | Terms forbid lead collection | Disabled (`LEADPILOT_REMOTIVE_ENABLED=false`) |
| Apollo | Paid; needs key | Only the free health endpoint is called without an approved budget |

No CAPTCHA, robots.txt or ToS bypass exists in the code base.

## 5. Personal data

- Discovery stores business records (company, public phone/email, website). Named contacts come only from published sources (tender contact points, job boards) or your own enrichment.
- Reply bodies are stored for classification; AI drafting sends public business facts only (no names or emails) to Gemini/OpenAI.
- Unsubscribe and "not interested" replies add the address to the suppression list automatically.

## 6. Operational security on this PC

- Workers run as console windows started by `start_workers.bat`; they must be relaunched after a code update (a stale worker caused silent autopilot failures on 22 Oct).
- `APP_URL` is `http://localhost`: unsubscribe links in sent mail will not work for recipients until it points to a reachable address.
- Budgets are $0: no paid provider can be called; the cost ledger records estimated vs actual spend.
