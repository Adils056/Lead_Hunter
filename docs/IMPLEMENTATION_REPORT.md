# Implementation report — LeadPilot AI v4.1 made real on the owner's PC (22–23 Oct 2026)

Everything below was executed on this Windows/XAMPP machine with internet. Nothing is mocked unless the row says "tested (mocked)". No prospect received an email (the outbound mail log is empty; the only "sent" recipient in the database is 20 Oct seed data with an example.com address). Global paid budget stayed $0.

## 1. Per-source verdict (live)

| Source | Verdict | Raw from API | Duplicates | Unique new | With website | With public contact | Notes |
|---|---|---|---|---|---|---|---|
| OpenStreetMap Overpass — software / web design / real estate / construction, Islamabad + Rawalpindi | PASS | 60 named places (query capped at 60; box covers both cities) + 101 in the Islamabad box probe | 0 within run; 0 vs DB | 12 ingested (limit 12) | 2 of 60 (3 %) | phone 57/60 (95 %), email 56/60 (93 %) | Islamabad *area* query timed out (504 / "Query timed out"); bounding box answers in 25–32 s. Adapter now prefers the box. Earlier clinic ICP run: 5 clinics, 0 websites |
| UK Contracts Finder (OCDS) | PASS | 57 releases (7 days) | 5 | 0 (all 5 keyword matches already in DB) | 0/57 raw | email 57/57, phone 29/57 | Field paths verified in raw JSON: ocid, buyer.name, parties[].contactPoint, tender.title, tenderPeriod.endDate; `links.next` absent when one page fits |
| UK Find a Tender (OCDS) | PASS | 44 releases (7 days) | 1 | 1 (PSD Scotland) | 43/44 raw; 1/1 new | email 44/44, phone 30/44 | Same OCDS shape; ids `NNNNNN-YYYY` |
| EU TED API v3 | PASS (shapes fixed) | 5 of 1 850 matching notices | 0 first run, 3 on re-run (dedupe works) | 3 (2 skipped: deadline passed) | 2/3 | email 3/3, phone 3/3 | Field names were valid but shapes differed: buyer-name is a language map of **lists**, deadlines are per-lot with offsets, award results were mixed in. Fixed |
| Greenhouse / Lever watchlist (gitlab, cloudflare, spotify) | PASS | 417 tech roles (123 / 251 / 43) | 0 | 3 companies | 3/3 | 0 (no contact invented; leads = needs enrichment) | Dead boards: lever shopify, netflix |
| Apollo | PASS (health only) | auth/health HTTP 200 | — | — | — | — | Paid search not called ($0 budget) |
| Google Places | NOT TESTED | — | — | — | — | — | No `GOOGLE_PLACES_API_KEY`; budget $0 |
| Remotive | NOT TESTED (disabled) | — | — | — | — | — | Terms forbid lead collection |
| Website audit (5 real sites from the retrieved leads) | PASS | 5 audited | — | — | — | — | 3 completed (PCS Scotland, GitLab, Cloudflare), 2 correctly **unreachable** (beauvaisis.fr anti-DDoS HTTP 418; mpwik.com.pl empty reply). Findings matched an independent curl of each page (viewport, HTTPS, tel: link, forms, meta description, canonical, JSON-LD) |
| SSRF guard | PASS | 13 URLs | — | — | — | — | localhost, 127.0.0.1, 169.254.169.254, 10/172/192 ranges, ::1, 0.0.0.0, a public name resolving to 127.0.0.1, ftp://, embedded credentials and non-web ports all refused; public site allowed |
| SMTP (Gmail) | PASS | `leadpilot:mail-test sellerhub056@gmail.com` → ACCEPTED by SMTP in 12.9 s, Message-ID 9e747a24-… | — | — | — | — | Accepted ≠ delivered: check the inbox. `shahpk72@gmail.com` is **refused** because it is not an admin user of the app (see §4) |
| IMAP reply sync | NOT TESTED | — | — | — | — | — | No `IMAP_*` variables in `.env` |
| Gemini / OpenAI drafting | NOT TESTED live | — | — | — | — | — | Gemini key absent; OpenAI key present but no draft was generated (no lead reached the drafting step with verified facts) |

## 2. What was broken on this PC and is now fixed (with regression tests)

| # | Problem found live | Cause | Fix | Test |
|---|---|---|---|---|
| 1 | Every scheduled autopilot cycle failed: `Autopilot step monitor/reply_sync/outreach failed: Trying to access array offset on value of type null` | Queue worker started at 00:16 kept running after v4.1 files were copied at 01:29 — stale classes in memory | Worker relaunched; procedure documented (Live Validation Guide) | n/a (process) — heartbeat `ok:true` every cycle since 21:15 UTC |
| 2 | `Could not resolve area for "Rawalpindi, Pakistan"`; the failure was cached forever | OSM has no boundary relation for Rawalpindi; adapter cached `0` with `Cache::forever` | `resolveArea()` prefers Nominatim's bounding box, uses an area id only when no box exists, caches misses 6 h | `OsmLiveBehaviourTest` ×2 |
| 3 | Overpass `cURL error 28` ×3 per search; 502/504 from overpass-api.de; HTTP 200 with "Query timed out" remark and no data | Public mirrors slow/overloaded; area queries time out | Retry 429/502/503/504 and timeout remarks with a pause; a mirror that times out is skipped for 20 min; the mirror that answered last is tried first; HTTP timeout 120 s; `OSM_OVERPASS_MIRRORS` override | `OsmLiveBehaviourTest` ×2 |
| 4 | Free OSM source "rested" until 25 Oct after one network failure | Arm-resting rule counted failed/skipped runs as "nothing found" | Only completed runs can rest an arm | `OsmLiveBehaviourTest::failed_or_skipped_run_does_not_rest_the_search_arm` |
| 5 | OSM `addr:city` stored in Urdu script | OSM data | Prefer `addr:city:en` | `OsmLiveBehaviourTest` |
| 6 | TED: award results ingested as tenders; multi-buyer names; per-lot deadline shifted a day by `strtotime` on `2026-11-10+02:00` | Docs-only implementation | `form-type=competition` filter, list-aware parsing, latest lot deadline kept as a calendar date, phone stored | `EuTedLiveShapeTest` ×2 |
| 7 | DNS/timeout errors (`Resolving timed out`) surfaced as raw cURL text and stopped a hunt | No connect timeout, no retry, exception escaped | `connectTimeout(10)`, one retry on connection errors only, clear message naming DNS/firewall (8.8.8.8 / 1.1.1.1) for Contracts Finder, Find a Tender, Greenhouse/Lever, Apollo, TED | `AdapterResilienceTest` ×7, `EuTedLiveShapeTest` |
| 8 | No ordered answer to "who do we email first" | — | Win Ladder (R/A/B/S/C/D rungs, evidence points, page + CSV + command; autopilot sends top of rung A first) | `WinLadderTest` ×2 |

Tests: **127 passed, 1 001 assertions** (was 111 before this work), SQLite in CI config; the suite also ran green against MySQL earlier in the session.

## 3. End-to-end pipeline (dry run, this PC)

`php artisan leadpilot:autopilot --dry-run` → monitor ok · approvals ok · reply_sync "not configured" · discovery "would run google_places for ICP #1" · enrichment {audited 10} · scoring "14 lead(s) scored" · outreach "dry run: no drafts or sends". Scheduled real cycles run every 15 min with outreach mode `dry_run`: 2 approval requests are open, 1 draft is pending approval, nothing is sent.

The clinic ICP end-to-end (`validate-live`, 21:18–21:35 UTC): discovery 5 real clinics → dedupe on re-run 5/5 duplicates → audits NOT TESTED (no websites in OSM) → scores 42–48 (nurture) → gate: 0 eligible (score < 75 minimum) → drafts NOT TESTED (no verified observation) → suppression PASS → pilot email accepted by SMTP → approval + notification PASS.

## 4. Final validation run — `leadpilot:validate-live --pilot-to=shahpk72@gmail.com` (22:29–22:37 UTC, report `storage\appalidationalidation-20261022-223721.md`)

| Step | Verdict | What happened |
|---|---|---|
| 1. Environment & integration checks | WARN | APP_URL is localhost; personal Gmail limits; approved services missing web_redesign / conversion_optimization / seo_technical; IMAP not configured |
| 2. Real provider connectivity | WARN | Find a Tender, TED, Contracts Finder, Apollo health all HTTP 200 in ~2 s; the Overpass *health probe* (trivial `node(1)` query) hit the 120 s timeout on the first mirror and was reported unreachable even though the real search 2 minutes later succeeded — probe now uses a 20 s budget |
| 3. Real lead search (clinic ICP) | PASS | OSM: 5 clinics found, **0 errors, no timeouts** (bounding-box query); Google Places skipped (no key) |
| 4. Source evidence & provenance | NOT TESTED | No *new* leads this run (all 5 already known) — the runner samples new leads only |
| 5. Deduplication (same search re-run) | PASS | 5/5 recognised, 0 duplicates created |
| 6. Website audits | NOT TESTED | OSM clinics carry no website tag (audits were proven separately on 5 real sites, §1) |
| 7. Scores / 8. Gate / 9. Drafts | NOT TESTED | Depend on new leads with websites — none in this ICP; proven on the 21:35 run (scores 42–48, gate 0/5 because minimum is 75) |
| 10. Suppression & gate rules | PASS | Suppressed address blocked |
| 11. Controlled live email pilot | **FAIL (refused by design)** | `shahpk72@gmail.com is not an active admin user` — add that user, rerun; SMTP itself passed to the admin mailbox (Message-ID 22dd8a7c-…, 21:35 run, and 9e747a24-… via mail-test) |
| 12. Reply sync (IMAP) | NOT TESTED | No IMAP credentials |
| 13. Human approval + notification | PASS | Approval created, in-app + email notifications accepted by SMTP |

Runner limitation noted for the roadmap: steps 4 and 7–9 only look at leads created *in that run*, so a second run over the same ICP reports NOT TESTED instead of re-using known leads.

## 5. Genuinely operational vs blocked

Operational now, free, on this PC: OSM discovery (twin cities), UK Contracts Finder, UK Find a Tender, EU TED, Greenhouse/Lever hiring signals, website audits with SSRF guard, evidence scoring, Win Ladder, approvals, notifications, SMTP sending to admins, scheduler + worker heartbeats, incident monitor.

Blocked and why:
- **Pilot email to shahpk72@gmail.com** — refused by the app's own guard until that address is an active admin user. Creating the user from this session was not permitted; add it in the Users page, then rerun `leadpilot:validate-live --pilot-to=shahpk72@gmail.com`.
- **Reply sync** — no IMAP credentials.
- **AI draft polishing** — no Gemini key (OpenAI key exists but was not exercised).
- **Real local-clinic outreach** — OSM clinics have no website/email; rung C until enriched. Outreach settings also require score ≥ 75 and approved services do not include web_redesign / conversion_optimization / seo_technical (the clinic ICP's targets).
- **Google Places** — no key, $0 budget.
- **Unsubscribe links** — `APP_URL=http://localhost`.

## 6. Keys / settings you must add (names only, never paste them in chat)

`IMAP_ENABLED`, `IMAP_HOST`, `IMAP_PORT`, `IMAP_USERNAME`, `IMAP_PASSWORD` (new Gmail App Password), `IMAP_MAILBOX`; `GEMINI_API_KEY` (free tier); `OSM_CONTACT_EMAIL`; optional `GOOGLE_PLACES_API_KEY` + a budget, `HUNTER_API_KEY`, `PAGESPEED_API_KEY`; `APP_URL` to a reachable address. Revoke the Gmail App Password that was pasted into chat earlier and set a new `MAIL_PASSWORD` yourself.

## 7. Exact commands to re-run

```bat
cd C:\xampp\htdocs\Leed_generation
php artisan migrate
php artisan config:clear
php artisan test
php artisan leadpilot:doctor
php artisan leadpilot:mail-test <active admin address>
php artisan leadpilot:validate-live --pilot-to=<active admin address>
php artisan leadpilot:win-ladder --tier=all --limit=10
php artisan leadpilot:autopilot --dry-run
```
After any code change: close the "LeadPilot - Queue Worker" window and run `start_workers.bat` again.

Raw evidence kept in this session's scratchpad: `ted-live-response.json`, `osm-stats.json`, `isb_probe.out`, `cf.json`, `fat.json`, `gh.json`, `lv.json`; validation reports in `storage\app\validation\`.

## 5. Follow-up on 23 Oct 2026 (owner asked for every blocker to be cleared)

Applied through the app (no `.env` change):

| Change | Result |
|---|---|
| Admin user `shahpk72@gmail.com` created (temporary password handed to the owner in chat); controlled pilot recipient set to that address | `leadpilot:mail-test shahpk72@gmail.com` ACCEPTED (Message-ID 08b77f46-…); validate-live step 11 **PASS** |
| Outreach minimum score 75 → 65 (qualified band). Approved services already included web_redesign / conversion_optimization / seo_technical | 17 real leads now on rung B |
| Free enrichment: built-in DNS/MX verification run on every real lead with a published address | 25 real leads verified (3 invalid), none invented |
| `SearchAllocator` skips sources without a key (Google Places had been picked and skipped 19 cycles in a row) | test `SearchAllocationFixesTest` |
| A capped run (found == limit) no longer rests an arm for 3 days; the OSM rest caused by the 5-result validation run was cleared | test `SearchAllocationFixesTest` |
| Overpass default order: overpass-api.de first, private.coffee last; recent-success memory 24 h | no 120 s wait at the start of a hunt |
| ICP #1 widened: niches + clinic, doctor, hospital, dental; sources osm_overpass + google_places; 40 per run | fresh hunt: 40 found, 35 new real clinics/hospitals (Rawalpindi + Islamabad) |
| Lead Hunter "Discovery sources" panel shows live state (last run, counts, health, rest) and refreshes every 30 s | `DiscoverySourceStatusTest` |

State after the follow-up: 105 companies (78 real), 104 leads, 40 audits, 0 prospect emails sent, $0 spent, **131 tests / 1,028 assertions**. Real leads by rung: B 17 · C 54 · D 7; rung A is still demo data only, because no real lead has a *named* decision-maker with a verified address (shared inboxes are "send manually after review" by design).

Still needs the owner (secrets / infrastructure): `APP_URL` reachable from outside, `IMAP_*` for reply sync, `GEMINI_API_KEY` (OpenAI account has no credits), revoke the Gmail App Password pasted in chat, an own-domain mailbox with SPF/DKIM/DMARC, then switch outreach mode from `dry_run` to approval.

### 5a. Later on 23 Oct: "why has nobody been emailed" — findings and changes

Findings: (1) outreach mode was `dry_run`, so the guard blocked every send by design; (2) no real lead passed the gate (no named contact, scores under the minimum); (3) **8 demo seed leads were sitting as "approved" autopilot recipients with invented addresses such as info@microsoft.com / info@amazon.com** — switching to live would have emailed them first. Changes, all tested (134 tests / 1,045 assertions):

- Demo seed companies (27) and leads (26) soft-deleted; their 10 queued recipients cancelled. The database now holds real finds only (78 companies / 78 leads).
- New setting **Allow a business's own published inbox** (`allow_shared_inbox`, default off, switched on for the owner): a verified published address of a local business may receive the evidence-based first email; published tender contacts never. Gate, pipeline state, Win Ladder and the first-touch planner honour it.
- New gate + dispatch check **public_url**: in live mode no prospect email is sent while `APP_URL` is a local address (unsubscribe links would be dead). The owner's pilot address is exempt.
- Autopilot first touch now takes **rung A only** (or not-yet-ranked leads, which the gate re-checks). Rung B/C/D never get an automatic email.
- Test environment `APP_URL` set to a public example host in `phpunit.xml`.

Still the owner's decision and not done by the assistant: switching outreach mode to **live** (Settings → Outreach Engine, confirmation phrase), a public `APP_URL` (domain or tunnel), an own-domain mailbox, and relaunching the worker afterwards. After that the autopilot sends automatically to rung A only, within the daily cap and sending window.

### 5b. 23 Oct 2026, 13:15–14:00 PKT: Overture Maps Places replaces the Google Places key

Owner instruction: "add Overture Maps Places keys in place of the Google Places API keys". Overture has **no keys**: it is open data (GeoParquet on a public S3 bucket, monthly releases, ~88 M places from Meta, Microsoft, Foursquare, BrightQuery, AllThePlaces). It was wired in as a free, keyless source; the Google adapter stays in the code but is no longer in any ICP.

| Item | Detail |
|---|---|
| Reader | DuckDB CLI v1.4.5 (LTS), `tools/duckdb/duckdb.exe`, downloaded from duckdb.org, SHA256 verified (`tools/duckdb/README.md`). No Python, no cloud account. |
| Adapter | `App\Services\Discovery\Adapters\OverturePlacesAdapter` (`overture_places`): release id from the Overture STAC catalog (cached 24 h), city → bounding box via the shared Nominatim lookup, one-off per-city extract to `storage/app/overture/<release>/`, then local SQL per niche. Niche → taxonomy map; confidence ≥ 0.6; permanently closed skipped; social-profile "websites" (Instagram/YouTube/LinkedIn/Facebook) stored as social, not website; no "no website" signal from a missing field. |
| Wiring | registered first in `DiscoveryManager`; `ProviderRegistry` entry (free, $0) and `local_discovery` preference Overture → OSM → Google; `IcpSearchConfigBuilder` same option shape as OSM; ICP #1 sources now `overture_places, osm_overpass`; Lead Hunter panel note; attribution on the Autopilot dashboard; `docs/FREE_SOURCE_SETUP.md` §0; `.env.example` (all optional: `OVERTURE_DUCKDB_BIN`, `OVERTURE_RELEASE`, `OVERTURE_MIN_CONFIDENCE`, `OVERTURE_EXTRACT_TIMEOUT`). `phpunit.xml` points the binary at a non-existent path so tests never depend on the PC. |
| Tests | `tests/Feature/OverturePlacesAdapterTest.php` (5 tests, 64 assertions: configured/not, niche → category SQL without false word matches, real-field ingestion with ICP exclusions, limit, known-row paging, website requirement, status panel). Full suite **139 tests / 1,109 assertions** green. |
| Live (release 2026-10-21.0) | Islamabad + Rawalpindi box: **31,234 places in 45 s** (18,839 with email, 15,928 with website) vs a few hundred from OSM. Real hunt for ICP #1 (`overture_places` only, 40 cap, 15 audits): run #37 completed in 399 s, **40 found, 38 new companies, 39 new leads, 0 errors**, every lead with a published email and a website (e.g. Maroof International Hospital, Kulsum International Hospital, Rahman & Rahman Dental Surgeons, Z Dental Studio). Three "websites" were social pages and were cleared afterwards. |

What this changes for outreach: the bottleneck was never the gate, it was contactable local businesses. OSM gave names and phones; Overture gives published emails and websites for most records, so the audit → score → verify → rung A path can actually fill. The new leads start at `discovered`; the autopilot audits, scores and DNS-verifies them on its 15-minute cycle **after the owner relaunches the queue worker** (it still runs the pre-Overture code).

Not done / honest limits: Overture is a monthly snapshot (releases are removed from S3 after ~60 days); `confidence` is an existence score, not quality; localities often come in Urdu script, so the ICP city name is used then; Google Places code remains for anyone who later adds a key, but nothing calls it without one.

### 5c. 23 Oct 2026, 14:10–15:00 PKT: free, keyless alternatives for every paid API

Owner instruction: "for all paid APIs required in our software, find free alternatives that work for free and give real-time results". Inventory of paid or keyed providers and what now stands in for each (registry preference order is free-first, so nothing paid is called while its free stand-in works):

| Paid / keyed | Capability | Free alternative added | Live from this PC |
|---|---|---|---|
| Google Places | local business discovery | **Overture Maps Places** (`overture_places`, bundled DuckDB) — §5b | Yes: 40 found / 39 leads with e-mail + website |
| Apollo.io | B2B company discovery | **Wikidata organisations** (`wikidata_orgs`, SPARQL, CC0); Overture for local B2B categories | Yes: 12 Pakistani exporters/logistics/pharma/food companies with websites in 11 s (CA Sports, Gul Ahmed, TCS Courier, Interloop, Shan Foods, …) |
| Hunter.io | e-mail verification | **Built-in MX + SMTP mailbox probe** (RCPT TO on the MX, catch-all detection, no mail sent) | Code verified; **this ISP blocks outbound port 25**, so the probe reports "blocked" and the MX-level status is kept, clearly marked `has_smtp_check=false`. Works on a VPS/office line. |
| OpenAI | LLM (draft polish, company research) | **Ollama local LLM** (`ollama`, JSON-schema output, nothing leaves the PC); keyed Gemini free tier stays first when present | Code verified with a faked Ollama; **Ollama is not installed on this PC** (owner: install + `ollama pull qwen2.5:3b`) |
| Google PageSpeed | audit performance | already free (optional key); unchanged | — |
| Remotive | hiring signals | stays disabled (terms) | — |

Code: `WikidataOrgsAdapter`; `EmailVerificationService::verifyWithDnsAndSyntax` rewritten (real MX order, bigger disposable list, `smtpProbe`, honest statuses); `LlmClient::ollama` + `ProviderRegistry::ollamaReachable`; `AiResearchService` now shares its prompts/schema (`researchPrompts`) and tries the free providers through `LlmClient` before the rule-based engine; `IcpSearchConfigBuilder` default B2B source = first configured `b2b_discovery` provider (Wikidata); config `providers.b2b_discovery = [wikidata_orgs, apollo_orgs]`, `providers.llm = [ollama, openai]`, `sources.ollama`, `smtp_probe`; `phpunit.xml` disables Ollama and the probe for determinism. Tests: `tests/Feature/FreeAlternativesTest.php` (6 tests, 53 assertions). Full suite **145 tests / 1,162 assertions** green.

Not fabricated, not claimed: no mailbox is reported "SMTP-verified" on this PC; Ollama is reported NOT TESTED live; Wikidata leads without a published e-mail start as `needs_enrichment`.