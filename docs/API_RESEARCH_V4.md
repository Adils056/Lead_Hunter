# API & provider research — LeadPilot v4 (checked 23 Oct 2026)

**Method.** I used official documentation, pricing and policy pages first. Third-party sources are marked **[3P]**. Anything I could not confirm is marked **[unverified]**. The v3 competitor matrix is in `COMPETITOR_RESEARCH.md`.

## Decision table (what LeadPilot now uses)

| Capability | Primary (free) | Fallback | Paid upgrade (budget + approval) | Status in v4 |
|---|---|---|---|---|
| Local business discovery | **OpenStreetMap Overpass** (private.coffee mirror, then overpass-api.de); area lookup via Nominatim, cached | — | Google Places Text Search | Built; mocked tests only |
| UK tenders | **Contracts Finder** OCDS | **Find a Tender** OCDS | — | Built; mocked tests only |
| EU tenders | **TED API v3** (anonymous search) | — | — | Built. Field names follow the docs but were **not live-verified** |
| Pakistan tenders | **None.** PPRA/EPADS publish HTML only and their "OCDS" link points to a maintenance page | — | — | Not built |
| Hiring signals | Greenhouse / Lever public job boards (watchlist) | Remotive (off: its terms forbid lead collection) | — | Built (v3) |
| B2B company search | — | — | Apollo Organizations | Built (v3); paid path gated |
| Email verification | Built-in syntax + DNS/MX check | — | Hunter (50 free credits/month, 0.5 credit per verification) | Existing; budget-gated |
| Website performance | PageSpeed Insights (key optional) | — | — | Existing (opt-in) |
| Sending | Your SMTP (Gmail) | — | Google Workspace / own domain / Amazon SES | Existing, hardened |
| Reply reading | **IMAP with a Gmail App Password** (built-in client) | Webhooks (Resend etc.) | Gmail API + OAuth (preferred long-term) | Built; mocked tests only |
| Draft polishing (LLM) | **Gemini Flash-Lite free tier** (public facts only) | OpenAI (within budget) | Claude Haiku / Gemini paid | Built; mocked tests only |
| Booking | A static Calendly/Cal.com link (`LEADPILOT_BOOKING_URL`), or real free slots from working hours | Cal.com API v2 | Calendly Standard (webhooks) | Built (slots); calendar API not built |

## Key facts used in the code

**OpenStreetMap Overpass**
- No key is needed. Keep volume modest, send an identifying User-Agent, and don't run requests in parallel.
- After a 429, wait about 30 s.
- The main server discourages commercial use, so LeadPilot tries the private.coffee mirror first.
- Sources: https://dev.overpass-api.de/overpass-doc/en/preface/commons.html, https://wiki.openstreetmap.org/wiki/Overpass_API

**Nominatim**
- At most 1 request/second, with an identifying User-Agent.
- You must cache results, and bulk or scheduled geocoding is discouraged. LeadPilot caches each city lookup forever.
- Source: https://operations.osmfoundation.org/policies/nominatim/

**OSM licence (ODbL)**
- Credit "© OpenStreetMap contributors" wherever OSM data is shown. Substantial public extracts are subject to share-alike.
- Source: https://osmfoundation.org/wiki/Licence/Attribution_Guidelines
- My interpretation of how this applies to LeadPilot is not legal advice **[unverified]**.

**Find a Tender**
- `GET https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages`
- Parameters: `updatedFrom`, `updatedTo`, `stages`, `limit` (1–100), `cursor`.
- On 429 or 503, honour Retry-After.
- Source: https://www.find-tender.service.gov.uk/apidocumentation/1.0/GET-ocdsReleasePackages

**TED v3**
- `POST https://api.ted.europa.eu/v3/notices/search`. The search API needs no key.
- Up to 250 notices per page.
- Field names: https://docs.ted.europa.eu/ODS/latest/reuse/field-list.html (also https://docs.ted.europa.eu/api/latest/index.html)

**Google Places (New)**
- Text Search Enterprise costs $35 per 1,000 calls after 1,000 free a month.
- Only `place_id` may be stored long-term.
- Source: https://developers.google.com/maps/billing-and-pricing/pricing

**Hunter Email Verifier**
- Limits: 10 requests/s and 300/min.
- The free plan gives 50 credits/month; one verification costs 0.5 credit.
- Sources: https://hunter.io/api-documentation/v2, https://hunter.io/pricing

**Gmail sending**
- Personal accounts: about 500 emails/day.
- Workspace accounts: 2,000/day, and 100 recipients per message over SMTP.
- Sources: https://support.google.com/mail/answer/22839, https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace

**Gmail sender requirements**
- Every sender needs SPF or DKIM, PTR records and TLS, and must keep the spam rate below 0.3%.
- Bulk senders also need DMARC and one-click unsubscribe (RFC 8058). LeadPilot now sends these headers.
- Source: https://support.google.com/mail/answer/81126

**Gmail IMAP**
- Password ("less secure app") access ended on 14 Mar 2025, except for App Passwords.
- App Passwords require 2-Step Verification.
- Sources: https://knowledge.workspace.google.com/admin/sync/transition-from-less-secure-apps-to-oauth, https://support.google.com/accounts/answer/185833

**Gemini API**
- A free tier exists on Flash and Flash-Lite. Free-tier inputs may be used to improve Google products, so LeadPilot sends only public business facts, never names or emails.
- Paid 2.5 Flash-Lite costs $0.10 per 1M input tokens and $0.40 per 1M output tokens.
- Pakistan is a supported region.
- Sources: https://ai.google.dev/gemini-api/docs/pricing, https://ai.google.dev/gemini-api/docs/available-regions

**OpenAI**
- The API has no free tier. Your logs show an HTTP 429 (quota) on 21 Oct 2026.
- Source: https://developers.openai.com/api/docs/pricing

**Google Calendar API**
- Free below its quotas, but it requires OAuth.
- Source: https://developers.google.com/workspace/calendar/api/guides/quota

**Calendly**
- Its scheduling API and webhooks need a paid plan.
- Source: https://calendly.com/help/calendly-api-overview

**Cal.com API v2**
- Bearer key; about 120 requests/min.
- Source: https://cal.com/docs/api-reference/v2/introduction

**SAM.gov opportunities (US)**
- Needs a free account API key. Not built.
- Source: https://open.gsa.gov/api/get-opportunities-public-api/

## Limitations

- The research was done from documentation. **None of these APIs could be called from the build environment**, because its network blocks them.
- Each adapter is therefore verified against the documented response shape with mocks, not against live responses. Run `php artisan leadpilot:validate-live` on a machine with internet access to confirm.
