# Free data sources — setup and what each one really gives you

All sources below cost $0 and need no key unless stated. "Verified live" = run from the owner's PC on 23 Oct 2026 against the real provider (details and counts in `IMPLEMENTATION_REPORT.md`).

| Source | Slug | Key | What it yields | Verified live |
|---|---|---|---|---|
| **Overture Maps Places** (Meta, Microsoft, Foursquare, BrightQuery data) | `overture_places` | none (bundled DuckDB CLI in `tools/duckdb`) | Local businesses by niche and city with **email, phone, website, Facebook page, category, address** for most records | Yes — Islamabad + Rawalpindi: 31,234 places, 18,839 with email, 15,928 with website |
| OpenStreetMap Overpass + Nominatim | `osm_overpass` | none (`OSM_CONTACT_EMAIL` recommended) | Local businesses by niche and city: name, category, address, sometimes website/phone/email | Yes |
| **Wikidata organisations** (CC0) | `wikidata_orgs` | none | B2B companies by industry keyword + country with official website, HQ city, sometimes employees/e-mail/phone. The free alternative to Apollo for established buyers | Yes — Pakistan textile/logistics/pharma/food: 12 companies in 11 s |
| UK Contracts Finder (OCDS) | `uk_contracts_finder` | none | Open UK tenders with buyer name, website and published contact | Yes |
| UK Find a Tender (OCDS) | `uk_find_a_tender` | none | Above‑threshold UK tenders, same shape | Yes |
| EU TED API v3 | `eu_ted` | none for search | EU tenders for IT CPV codes (competition notices only) | Yes; shapes corrected against the live response |
| Greenhouse / Lever job boards | `ats_watchlist` | none | Tech hiring at named companies you watch | Yes, with public board tokens |
| Built‑in DNS/MX + **SMTP mailbox probe** | `dns_mx` | none | Syntax, real MX lookup, disposable/free-mail lists, then RCPT TO on the MX (mailbox exists / rejected / catch-all). The free alternative to Hunter | Yes — on this PC outbound port 25 is blocked by the ISP, so the probe reports "blocked" and the MX-level result is used (honest status, no false "verified") |
| **Ollama local LLM** | `ollama` | none (install Ollama on the PC) | Draft polishing and company research with a local model; nothing leaves the machine. The free alternative to OpenAI | Yes — Ollama 0.40.9 installed 23 Oct (H:\Ollama), qwen2.5 1.5b/3b. Drafts work behind guardrails (31–85 s each on this CPU); open-ended research is **off** for local models because they invented facts |
| Google PageSpeed Insights | `pagespeed` | optional key | Mobile performance score for audits | Off by default (`PAGESPEED_ENABLED`) |

Every paid provider now has a free, keyless stand-in that the registry prefers automatically: Google Places → Overture, Apollo → Wikidata (+ Overture for local B2B), Hunter → MX + SMTP probe, OpenAI → Ollama (or the keyed Gemini free tier). Paid or keyed (not used while the budget is $0): Google Places (`GOOGLE_PLACES_API_KEY`, replaced by Overture in ICP #1 on 23 Oct 2026), Apollo (`APOLLO_API_KEY`), Hunter (`HUNTER_API_KEY`), Gemini (`GEMINI_API_KEY`, free tier), OpenAI (`OPENAI_API_KEY`). Remotive is disabled because its terms forbid lead collection.

## 0. Overture Maps Places (the replacement for the Google Places key)

What it is: the Overture Maps Foundation (Linux Foundation project backed by Meta, Microsoft, Amazon, TomTom) publishes a free monthly "places" dataset of ~88 million businesses, merged from Meta (Facebook pages), Microsoft, Foursquare, BrightQuery, AllThePlaces and others. There is **no API and no key**: the data is GeoParquet in a public S3 bucket. LeadPilot reads it with the bundled DuckDB command-line tool (`tools/duckdb/duckdb.exe`, v1.4.5, SHA256 in `tools/duckdb/README.md`).

How it works (`App\Services\Discovery\Adapters\OverturePlacesAdapter`):
1. The current release id comes from `https://stac.overturemaps.org/catalog.json` (cached 24 h; pin one with `OVERTURE_RELEASE`).
2. Each ICP city is resolved to a bounding box with the same cached Nominatim lookup OSM uses.
3. The first time a city is searched in a release, DuckDB copies every place inside the box from S3 into `storage/app/overture/<release>/places_<box>.parquet` (Islamabad + Rawalpindi: ~45 s, 4 MB). Later searches run against that file in well under a second.
4. Niches are mapped to Overture's taxonomy (`dental clinic` → `dental_clinic`, `skin clinic` → `dermatology`, `physiotherapy` → `physical_therapy`, `hospital` → `hospital*`, `software` → `software_development` / `web_designer` / `it_service*`, unknown words → name contains the word). Places with confidence under `OVERTURE_MIN_CONFIDENCE` (default 0.6) or marked permanently closed are skipped.
5. Results are ordered email + website first, then email, then website, then Overture's confidence, and rows already in the CRM are paged past, so every run brings new businesses until the city is exhausted.
6. Stored per business: name, website, published email, phone, address, city, country (from the ISO code), category as industry, `external_id = overture:<id>`, a map link, and provenance with Overture's confidence. The lead is created as a **general inbox** (`generic_inbox`) when the email is a business address, otherwise `needs_enrichment`. No "has no website" signal is ever created from a missing field.

Licence and attribution: records are CDLA-Permissive-2.0 (Meta, Microsoft, BrightQuery, …) or Apache-2.0 (Foursquare); storage is allowed. Each stored item carries the attribution string `Overture Maps Foundation places (CDLA-Permissive-2.0 / Apache-2.0 per source)`.

Limits: data is a monthly snapshot (releases are removed from S3 after about 60 days, so an old pinned `OVERTURE_RELEASE` will stop working); confidence is an *existence* score, not a quality score; localities are often in the local script, so the ICP city name is kept in that case; the first extract needs outbound HTTPS to `overturemaps-us-west-2.s3.amazonaws.com` and, once, the DuckDB `httpfs` extension download.

Settings: none required. Optional `OVERTURE_DUCKDB_BIN`, `OVERTURE_RELEASE`, `OVERTURE_MIN_CONFIDENCE`, `OVERTURE_EXTRACT_TIMEOUT`.

## 0b. Wikidata organisations (the replacement for Apollo)

`https://query.wikidata.org/sparql`, no key, CC0 data. One SPARQL query per country: organisations with an official website (P856) in the country (P17) whose industry label (P452) contains an ICP keyword; dissolved organisations (P576) are excluded. Stored only when Wikidata has it: headquarters city, employee count → size range, inception, published e-mail (P968), phone (P1329). Coverage is companies notable enough to have a Wikidata item — established exporters, logistics firms, pharma, banks, universities — which is exactly the segment Apollo was meant for. Corner shops come from Overture / OSM instead.

Use: an ICP without cities and without the *government* business type now defaults to `wikidata_orgs`; keywords = ICP keywords, else niches, else industries. Honest limits: no employee count for most Pakistani items (no size is invented), few published e-mails (leads start as `needs_enrichment`; the website audit's contact-page scan and the owner's enrichment fill them).

## 0c. Free e-mail verification (the replacement for Hunter)

`App\Services\EmailVerificationService` without a Hunter key now does: syntax → real MX lookup (`getmxrr`, A-record fallback) → disposable list → free-mail list → **SMTP mailbox probe**: connect to the MX on port 25, `EHLO`, `MAIL FROM`, `RCPT TO:<address>`, then `RCPT TO` a random address to detect catch-all, then `QUIT`. No e-mail is sent. Results:

| What the MX said | Stored status | Score | provider |
|---|---|---|---|
| 250 for the address, random address rejected | verified | 95 (80 for a free mailbox) | smtp_check |
| 5xx for the address | invalid | 5 | smtp_check |
| 250 for any address (catch-all) | risky | 60 | smtp_check |
| no MX / disposable | invalid | 0 / 10 | dns_check |
| probe impossible (port 25 blocked, 4xx greylisting) | verified 85 / risky 55 for free mailbox — the old MX-level meaning, marked `has_smtp_check=false` | | dns_check |

When no MX accepts a TCP connection, the network is assumed to block outbound port 25 for an hour (cache key `smtp_probe_port25_blocked`), so later checks don't wait. **This PC's ISP blocks port 25** (checked 23 Oct 2026: Gmail's MX unreachable), so here the probe reports "blocked"; on a VPS or office line it runs. `LEADPILOT_SMTP_PROBE=false` turns it off.

## 0d. Ollama local LLM (the replacement for OpenAI)

Install Ollama from https://ollama.com/download, then `ollama pull qwen2.5:3b` (about 2 GB; any chat model works, set `LEADPILOT_OLLAMA_MODEL`). LeadPilot detects the local API at `http://localhost:11434` (checked every 10 minutes) and `LlmClient` uses it for e-mail draft polishing and `AiResearchService` for company research, with the same JSON-schema-constrained output as OpenAI. Order: keyed Gemini free tier → Ollama → OpenAI (only within budget) → rule-based engine. Nothing leaves the PC; cost is logged as 0.

Live findings on the owner's PC (i7-7600U, 8 GB, no GPU, 23 Oct 2026): a cold-e-mail draft takes about 31 s with `qwen2.5:1.5b` and 85 s with `qwen2.5:3b`; company research 90–115 s. **Both models invented facts in open-ended research** ("known for its advanced equipment", "looking for a Laravel agency", "we have implemented solutions for various healthcare facilities") and labelled them High/Medium confidence. Therefore:

- Drafts: allowed, because the guardrails make a bad draft harmless — numbers must come from the facts, the greeting token is normalised, bracket placeholders (`[Client's Name]`) and leftover `{{tokens}}` are rejected, and every significant word of the call-to-action must survive. A rejected draft falls back to the deterministic template, which is what was sending before.
- Company research: local models are **excluded by default** (`LEADPILOT_OLLAMA_RESEARCH=false`). Turn it on only with a 7b+ model on a machine with RAM for it, and read the output as a draft, never as fact. The keyed Gemini free tier is still used for research when present.

Install on this PC: installer and models live on `H:\Ollama` (C: was 98 % full), the user environment variable `OLLAMA_MODELS=H:\Ollama\models` is set, and the Ollama tray app is in the Startup folder so it starts at login.

## 1. OpenStreetMap (Overpass)

How it works: the ICP's cities are resolved with Nominatim (cached forever; ≤1 request/s). If OSM has an administrative boundary (relation) the Overpass query uses it; if the city is only a point in OSM (true for Rawalpindi) the city's bounding box is used instead. Niches map to OSM tags (`dental` → `amenity=dentist`, `software` → `office=it|company`, `real estate` → `office=estate_agent`, `construction` → `office=construction_company`/`craft=builder`, unknown words → name contains the word).

Network behaviour on this PC: `overpass.private.coffee` and `overpass.kumi.systems` never answer; `overpass-api.de` answers in 30–120 s and sometimes returns 502/504 first. The adapter therefore:
- retries 429/502/503/504 with a pause,
- marks a mirror that timed out as down for 20 minutes and tries the mirror that answered last first,
- can be forced to an order with `OSM_OVERPASS_MIRRORS=https://overpass-api.de/api/interpreter,...`,
- waits `OSM_HTTP_TIMEOUT` (default 120 s) per request.

Expect: many named businesses, few with a website or email tag. OSM gives you *who exists where*; website and contact usually need enrichment (website search, phone call). Absence of a website tag is **not** stored as "has no website".

Settings: `OSM_CONTACT_EMAIL=<your address>` (Nominatim policy), optional `OSM_OVERPASS_URL`, `OSM_OVERPASS_MIRRORS`, `OSM_HTTP_TIMEOUT`, `OSM_RETRY_PAUSE`.

## 2. UK Contracts Finder and Find a Tender

OCDS release packages (`GET …/ocdsReleasePackages?publishedFrom=…&stages=tender`). Fields used: `ocid`, `buyer.name`, `parties[].contactPoint.{name,email,telephone,url}`, `tender.title`, `tender.tenderPeriod.endDate`, `links.next`. Only notices whose tender deadline is still in the future are kept. Tender contacts are created as leads flagged *tender contact* (`generic_inbox`): visible on the Win Ladder, never auto‑emailed, respond through the tender portal.

Enable in an ICP: business type *government*, country United Kingdom, keywords such as `website, portal, CRM, software`. The sample ICP "UK public‑sector web & software tenders" is inactive by default.

## 3. EU TED

`POST https://api.ted.europa.eu/v3/notices/search` with an expert‑search query on CPV 72/48 and a publication date window. No key for search; `fields` must be non-empty (an unknown name returns HTTP 400 listing the valid ones). Live-verified shapes (23 Oct 2026): `notices[]`, `totalNoticeCount`; `notice-title` is a language map with lower-case 3-letter keys (`eng` always present); `buyer-name` is a language map whose values are **lists** (multi-buyer notices); `buyer-email`, `buyer-country` (ISO-3), `buyer-internet-address`, `organisation-tel-buyer` are plain lists, one per buyer; `deadline-receipt-tender-date-lot` is one entry per lot like `2026-11-10+02:00`. The adapter now filters `form-type=competition` (award results were mixed in), keeps the latest lot deadline as a calendar date, and links to `ted.europa.eu/en/notice/-/detail/<publication-number>`.

## 4. Greenhouse / Lever watchlist

Keyless public board APIs:
- `https://boards-api.greenhouse.io/v1/boards/<token>/jobs?content=false`
- `https://api.lever.co/v0/postings/<site>?mode=json`

Put entries in the ICP *keywords* (or the hunt options) as `greenhouse:<token>` / `lever:<site>|<domain>`. The token is the last part of the company's careers URL. Verified live on 23 Oct 2026: `greenhouse:gitlab`, `greenhouse:cloudflare`, `lever:spotify` (also answering: greenhouse stripe/airbnb/coinbase, lever palantir; dead: lever shopify/netflix). A board that is down is skipped with a message while the others continue. Only technical roles (developer, engineer, PHP, Laravel, DevOps, CTO, product…) become hiring signals.

## 5. Website audit (free, your own crawler)

Fetches the homepage and up to a few contact/about pages through the SSRF‑safe fetcher, honours robots.txt, and records only what it observed (viewport, HTTPS, CTA, forms, click‑to‑call, meta/title, canonical, schema.org, broken links, response time, copyright year, legacy tech). The opportunity score and the first‑email facts come from these checks. Run one: Company page → *Audit website*, or `php artisan leadpilot:audit <company id>`.

## 6. What free sources cannot do

- Give you a verified decision‑maker email for a local clinic or shop. Expect enrichment work (website contact page, phone) before rung A.
- Deliverability verification without Hunter: the built‑in check proves the domain accepts mail, not that the mailbox exists.
- Pakistan tenders: PPRA/EPADS publish HTML only; not built.
