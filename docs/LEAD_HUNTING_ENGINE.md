# LeadPilot AI — Lead Hunting Engine (v3)

The pipeline is ICP → search config → multi-source discovery → website audit → evidence score → conversion assistant.

**Rule that applies to every step: nothing is invented.** If a value was not returned by a source or measured by a check, it is stored as `null` and listed under "missing information".

---

## 1. Setup (10 minutes)

```bash
composer install
php artisan migrate --force
php artisan db:seed --class=IcpProfileSeeder   # two starter ICPs
php artisan test                                # 69 tests
php artisan queue:work database                  # REQUIRED: hunts, audits and bulk re-scoring run as queued jobs
```

`.env` keys (see `.env.example`):

| Key | Purpose |
|---|---|
| `GOOGLE_PLACES_API_KEY` | Local-business discovery. In Google Cloud, enable **Places API (New)** and restrict the key to it. |
| `LEADPILOT_AGENCY_NAME`, `LEADPILOT_SENDER_NAME`, `LEADPILOT_BOOKING_URL` | Used in email drafts. |
| `PAGESPEED_ENABLED`, `PAGESPEED_API_KEY` | Optional real mobile performance data in audits. |
| `LEADPILOT_REMOTIVE_ENABLED` | Keep `false`. Remotive's terms forbid using its listings to collect leads. |

Scheduler (`php artisan schedule:work`, or a cron entry for `schedule:run`):

- `leadpilot:places-purge` runs daily. It enforces Google's content-retention rule.
- `leadpilot:hunt` runs every Monday for all active ICPs.

Manual commands: `php artisan leadpilot:hunt {icpId}` and `php artisan leadpilot:audit {companyId}`.

---

## 2. What was built

| Area | Files | What it does |
|---|---|---|
| ICP engine | `IcpProfile`, `IcpSearchConfigBuilder`, `hunting/*` views | Covers geography, niche, size, business type, services, website quality, tech include/exclude, rating/review floors, contact roles, minimum score, exclusions, sources, channels and budget. The UI shows a preview of the exact queries and the estimated cost before a paid run. |
| Discovery | `Discovery/Adapters/*`, `ProspectIngestor`, `LeadHuntService`, `DiscoveryRun` | Sources: Google Places (local), UK Contracts Finder (live tenders), Greenhouse/Lever watchlist (hiring signals), Apollo (B2B), and Remotive (off by default). Every run is logged with found/new/excluded/errors/cost. |
| Provenance | `data_provenance` table, `DataProvenance::record()` | Records the source, URL, method, confidence and timestamp for each field value. |
| Website audit | `Audit/WebsiteAuditService`, `audits/show` | Runs about 20 real checks and stores the evidence for each. Also does tech fingerprinting, broken-link checks, public contact extraction, robots.txt handling, an SSRF guard, and optional PageSpeed. |
| Scoring v3 | `LeadScoringService`, `ScoringSetting`, `settings/scoring` | Ten weighted factors whose weights are set by an admin. It shows evidence, missing information, confidence, freshness, the offer and the next action. Factors that cannot be evaluated are excluded and the score is renormalised instead of being scored 0. |
| Calibration | `ScoreCalibrationService` | Shows positive rate by score band, AUC and Brier score. It reports "insufficient data" until 30+ leads have resolved outcomes. |
| Conversion assistant | `Sales/ConversionAssistantService`, `assistant/show` | Recommends an offer and explains why, writes a first email under 80 words from verified facts only, plans follow-ups (+3/+4/+4/+5 days, max 5 touches, stops on reply), and gives BANT questions. |
| Reply classifier | `Sales/ReplyClassifier` | Sorts replies into interested (with a meeting_request subtype), not_interested, referral, objection (price/timing/has_vendor/in_house/trust/need_info), out_of_office, unsubscribe, or uncertain. It handles negation and ignores quoted text. |

### Integrity fixes to the existing code

| Problem found | Fix |
|---|---|
| The job-board adapter invented `companyname.com` domains, `contact@` emails, company size and tech stack. | Removed. A lead with no known contact is stored as `contact_status = needs_enrichment`, and the eligibility gate blocks it from outreach. |
| `PublicProcurementAdapter` was a **hard-coded list of fake tenders** with fabricated URLs and emails (e.g. `tenders@pitb.gov.pk`). | Deleted. Replaced by a live UK Contracts Finder OCDS feed. |
| The Apollo adapter created `info@domain` placeholder contacts, a fake tech stack, defaulted to "United States" with 50 employees, and added a synthetic "growth" signal to every organisation. | All removed. |
| The reply classifier read "**not** interested" as **positive**. | Replaced with a negation-aware classifier. Out-of-office replies no longer end a sequence. |
| The UI showed "Executive" when the job title was unknown. | Now shows "Role unknown". |
| The score panel read keys the engine never wrote, so growth always showed 0. | Replaced with a panel driven by the real weights. |

---

## 3. Lead-acquisition strategies (Phase 2)

| # | Strategy | Data source in LeadPilot | Inputs | Qualification | Typical data problems | Cost / constraints | Legal / platform |
|---|---|---|---|---|---|---|---|
| 1 | Local business discovery | Google Places Text Search | niche × city, rating/review floor | operational, rating, reviews, website rule | chains/franchises; wrong category; max 60 results per query | Enterprise SKU about $35 per 1,000 after 1,000 free per month | Google terms: store only place_id long-term; attribution if shown on a map |
| 2 | B2B company prospecting | Apollo Org Search (your key) | keywords, countries, headcount | ICP match + signals | stale headcounts; no contact on the free plan | Apollo credits | Apollo ToS; GDPR for EU contacts |
| 3 | SaaS prospecting | Apollo + ATS watchlist | keywords "saas", watchlist | hiring signals, funding | — | low | — |
| 4 | Agency client acquisition | Places (agencies niche) + audit | "digital agency in X" | white-label need, weak own site | agencies are also competitors | low | — |
| 5 | Outdated / poorly working websites | Website audit | company website | opportunity ≥ 30, fails on viewport/HTTPS/CTA | false positives on JS-rendered sites (audit reads HTML only) | free | follows robots.txt; one-page-plus-contact crawl only |
| 6 | Redesign candidates | Audit: copyright year, legacy jQuery/WordPress, no viewport | — | `web_redesign` offer ranked first | copyright year is only a hint | free | — |
| 7 | Technology gaps | Audit tech fingerprint + ICP tech include/exclude | tech lists | e.g. WordPress without SSL | fingerprints miss server-side tech | free | — |
| 8 | Companies hiring developers | Greenhouse/Lever public APIs (watchlist) | `greenhouse:token`, `lever:site` | open tech roles | you need each company's board token | free | public endpoints; Remotive off by default due to its terms |
| 9 | Growth signals | Signals (manual or adapters), review counts | — | growth factor | sparse in Pakistan | — | — |
| 10 | Service / budget / industry match | ICP + offer catalog (`config/leadpilot.php`) | services, budget | service_fit + catalog value ranges | ranges are your estimates | — | — |
| 11 | Public directories / tenders | UK Contracts Finder (live) | keywords, days | open, IT CPV 72*/48* | buyer is a public body: respond to the tender, don't cold-pitch | free; HTTP 403 means wait 5 minutes | Open Government Licence |
| 12 | Inbound / visitor ID | Existing Meta/Google/TikTok lead-ad webhooks | — | `voluntary_inquiry` = explicit request | — | ad spend | Not built: person-level visitor ID is US-only and consent-sensitive |

**Recommended starting play for Eziline: strategies 1 + 5 + 6.** Find local businesses (Islamabad/Rawalpindi first), audit their sites, and only contact those with a measurable gap or no website. Call the published business number when no email is available. The phone often works better than email for Pakistani SMBs; this is an assumption, so track it in the calibration panel.

---

## 4. Scoring model

These defaults can be edited in Settings → Lead Scoring and must sum to 100:

| Factor | Weight | Evidence |
|---|---|---|
| Explicit request (RFP/tender/enquiry) | 22 | signal confidence |
| Active software need | 14 | hiring / stack-change signals |
| Website gap | 12 | audit opportunity score, or "no website" from Places |
| ICP match | 10 | share of known ICP criteria met (unknown criteria are not counted) |
| Contact role | 9 | decision-maker regex or an ICP-preferred role |
| Service fit | 10 | evidence-backed offer |
| Growth | 7 | growth signals, size, review volume |
| Contact verification | 8 | email verification + source trust |
| Data completeness | 4 | 7 key fields |
| Freshness | 4 | age of the newest evidence |

Thresholds: High is 80 or above, Qualified 65 or above, Nurture 45 or above.

**This is a heuristic ranking, not a probability.** Treat it as predictive only after the calibration panel shows AUC ≥ 0.7 with monotonic bands, measured on at least 30 resolved leads.

---

## 5. Security hardening (from independent code review)

- **SSRF:** all crawling goes through `Audit/SafeFetcher`.
  - Only http/https URLs on ports 80, 443, 8080 or 8443.
  - No URLs with embedded credentials.
  - Every redirect hop is re-validated.
  - Every resolved IP must be public; this blocks loopback, private ranges, link-local/cloud-metadata (169.254.x), CGNAT (100.64/10) and IPv4-mapped IPv6.
  - If a host doesn't resolve, the fetch is refused rather than attempted.
  - The connection is pinned to the vetted IP, which prevents DNS rebinding.
- **Same-origin checks:** done on the parsed scheme, host and port, so `user@host` and lookalike-suffix tricks don't pass.
- **Third-party links:** only `http(s)` URLs are stored or rendered. `javascript:` links are dropped.
- **Authorization:** the assistant, audits and ICP lead lists follow the existing Lead/Company policies (sales reps see their own and unassigned leads). Hunts, ICPs and scoring settings are admin-only.
- **Outreach gate:** the eligibility gate now blocks automated email unless `contact_status = identified`. Shared inboxes and tender contacts need manual review.
- **Cost and latency:** paid AI drafting runs only when someone clicks "Polish with AI" (rate-limited). Hunts, audits and re-scoring are queued jobs.

## 6. Compliance notes (not legal advice)

- **Google Places:** only `place_id` may be stored indefinitely. `leadpilot:places-purge` clears Places-sourced phone, address, rating and similar fields after 30 days unless another source confirmed them, then re-fetches when needed. Check the current Google Maps Platform terms.
- **Crawling:** robots.txt is honoured for our user agent and `*`. At most 3 pages and 15 link checks per site. No CAPTCHA, login or paywall bypass.
- **Contacts:** only business emails or phones published on the company's own site or in official notices are collected. No email guessing. No personal social profiles.
- **Outreach:** the existing eligibility gate, suppression list, approval gate and kill-switch all still apply. Leads with `needs_enrichment` cannot be emailed. Pakistan's PECA, UK PECR/GDPR, EU GDPR and US CAN-SPAM differ; for B2B email to UK/EU, use legitimate interest with a clear opt-out (already in templates).
- **Tenders:** contact points are published for questions about that tender. Respond through the official process.

---

## 7. Known limitations

- The audit reads server HTML. Sites built only in JavaScript (some Wix/React builds) can show false "missing" results. Turn on PageSpeed for those.
- "Server response time" is HTML download time from your server, not real user load time.
- Decision-maker names are not discovered automatically. No free, compliant source exists for that. Use Apollo/Hunter (paid) or add them manually.
- Hunts run synchronously from the UI. Keep `max_results_per_run` at 25 or below, or use `php artisan leadpilot:hunt` from cron for larger runs.
- The cost estimate for Google Places is computed from config and does not come from Google billing.
