# LeadPilot AI — Phase 1 audit: prioritized findings and staged plan

Date: 23 Oct 2026. Method: four independent read-only reviews (discovery, qualification, AI/outreach/CRM, security/UI/tests) over the whole `app/`, `routes/`, `database/`, `resources/views/`, `tests/` trees, followed by hand verification of every P0 and the top P1s. Nothing was modified during the audit. Line numbers refer to commit `e42460f`.

Baseline: 122 routes, 146 PHP files / 16,365 lines in `app/`, 68 services, 43 models, 23 migrations, 42 views, 26 phpunit files (152 tests, 1,205 assertions, all green). Live deployment: `https://hunter.eziline.net` (cPanel shared hosting, cron-driven scheduler and queue worker).

Severity: **P0** = exploitable or data-corrupting on the live site today · **P1** = defeats a core guarantee (no fabrication, verified-only outreach, budget 0, dedupe) · **P2** = quality/reliability gap · **P3** = hygiene.

---

## P0 — fix before anything else

### P0-1 REST API `/api/v1/*` is public: prospect PII dump and paid actions without login — VERIFIED LIVE
- Evidence: `routes/api.php:12-23` has no `auth` middleware; `bootstrap/app.php:17` exempts `api/*` from CSRF; `app/Http/Controllers/Api/V1/ApiController.php:53-57` returns full lead rows, `:92-99` triggers paid LLM research. `tests/Feature/ApiV1Test.php:44` asserts 200 **without** a user. Live check 23 Oct 17:50 PKT: `GET https://hunter.eziline.net/api/v1/leads` → **200**, 590 bytes of lead JSON, no cookie.
- Impact: anyone can enumerate every lead and company (names, e-mails, phones), rescore leads, and burn LLM budget.
- Fix: `->middleware('auth')` (session) or `auth:sanctum` on the group, policies per action, tests asserting 401. Effort S.

### P0-2 Webhooks accept unsigned payloads: anyone can suppress addresses, fake bounces, inject replies and leads
- Evidence: `WebhookController.php:24` reads `X-Webhook-Signature` and only forwards it; `WebhookProcessorService.php:37-47` stores it and processes immediately; no `hash_hmac`/`hash_equals` anywhere in the webhook path; route `POST /webhooks/{provider}` has no throttle; idempotency key is attacker-chosen (`WebhookController.php:26-29`). `:145-155` suppression + `not_a_fit`, `:74-87` lead creation from ad payloads, `:157-182` inbound replies feeding `ReplyOutcomeService` (approvals, `SearchAllocator.recordPositive`).
- Impact: unauthenticated mass-unsubscribe of the prospect list, poisoned deliverability stats, fake leads/replies driving automation.
- Fix: per-provider shared secret (HMAC, constant-time) verified before `WebhookEvent::create`; reject unknown providers; `throttle:60,1`; provider-scoped idempotency key; negative tests. Effort M.

### P0-3 MX-only lookup is stored as `verified` and flows everywhere as "deliverable"
- Evidence: `EmailVerificationService.php:167` default branch → `['verified', 85, 'Domain accepts mail (MX found); mailbox not probed …']` whenever the SMTP probe is blocked/skipped/greylisted; asserted by `FreeAlternativesTest.php:124`. Consumed as deliverable by `EligibilityGateService.php:83-86`, `WinLadderService.php:127-128` (+100), `Lead::isVerifiedEmail()`, dashboard "Verified Deliverable" tiles. Also `:225-229, 268-272`: one domain whose MX refuses TCP marks the whole network "port 25 blocked" for an hour.
- Impact: on any host without outbound port 25 (the owner's PC, most shared hosting) every syntactically valid address on an MX-bearing domain is "verified"; `require_verified_email` protects nothing; bounce rate and sender reputation are exposed.
- Fix: new status `mx_only` (sendable only when an explicit `allow_mx_only` setting is on, default off until a probe or Hunter confirms); gate/ladder/scoring/views read one `EmailStatus::isSendable()`; dashboards count `has_smtp_check=true`; port-25 flag only after N distinct domains fail or a known-good MX probe. Effort S code + M re-verification of existing leads.

## P1 — core guarantees currently broken

| # | Finding | Evidence | Fix / effort |
|---|---|---|---|
| P1-1 | **Any URL becomes company identity**: Facebook/Instagram/WhatsApp/tender-portal URLs stored as `website` → `domain=facebook.com` → unrelated businesses merged, second one's phone/e-mail "filled into" the first, outreach to the wrong business | `ProspectIngestor.php:48,64-66`; only Overture strips social hosts (`OverturePlacesAdapter.php:311-316`); OSM `:303-306`, Places `:116`, OCDS `ProcessesOcdsReleases.php:53` pass through | Move `isSocialUrl()` + shared-host denylist into `Company::normalizeDomain()`/ingestor; keep URL in `social_url`. M |
| P1-2 | **Cross-source dedupe fails by construction when no website**: name fallback requires `whereNull('external_id')`, every directory adapter sets one → same clinic from Overture and OSM = two companies, two leads | `ProspectIngestor.php:69-72`; city differs by source (`OverturePlacesAdapter.php:322` vs OSM local script) | Key cascade external_id → domain → E.164 phone → normalised name + (city or 300 m). M |
| P1-3 | **Budget-0 guarantee false on CLI path**: Apollo never sets `estimatedCostCents` (no ledger, no budget trip); `DiscoveryManager::runAllActive()` runs every configured adapter with no policy/budget gate | `ApolloOrgDiscoveryAdapter.php`, `LeadHuntService.php:109-111`, `DiscoveryManager.php:92-111`, `routes/console.php` `leadpilot:discover`/`autonomous-cycle` | Record Apollo cost; route `runAllActive` through `hunt()` gates or delete; delete superseded `autonomous-cycle`. S |
| P1-4 | **Allocator learns from the wrong signal**: `qualified` counted right after discovery scoring (pre-audit) and never recomputed; skipped paid arms never get `last_run_at` → recency 1.0 every cycle, hog slots; no `isAvailable()`/policy filter | `AutopilotRunner.php:70`, `LeadHuntService.php:97,161`, `SearchAllocator.php:25,36` | Compute successes at pick time from `leads`; update arms on skipped runs; health + paid pre-filter; ε-exploration. M |
| P1-5 | **Hunter mapping reads wrong field** (`status` vs `result`) → every Hunter answer becomes `unknown`, cached 30 d, credits spent | `EmailVerificationService.php:105-112,118-119` (not verified against live API) | Map on `result`; `accept_all`→`catch_all`; booleans default false; canned-payload test. S |
| P1-6 | **E-mail status vocabulary inconsistent**: free probe says `risky` for catch-all, Hunter says `catch_all`; gate accepts `risky` not `catch_all`; views colour statuses differently | `EligibilityGateService.php:83`, `WinLadderService.php:129`, `leads/index.blade.php:126`, `leads/show.blade.php:168` | One enum + `isSendable()` helper (ties to P0-3). S |
| P1-7 | **Two gate checks can never fail**: `legitimate_source` (fallback string never empty; `Company::$source` is not a column) and `concrete_evidence` (passes whenever `score_explanation` is set, which scoring always writes) → audit trail prints "Concrete evidence confirmed" for leads with none | `EligibilityGateService.php:64-73`, `LeadScoringService.php:262`, dead `WinLadderService::FIXABLE['legitimate_source']` | Require provenance row / ≥1 fresh signal or completed audit with opportunities. S |
| P1-8 | **Unreachable site = 85/100 opportunity with a 92 %-confidence "verified" signal; audits never go stale** | `WebsiteAuditService.php:545-546,648,659-662`; no code compares `website_audited_at` to an age | ≥2 failures ≥1 h apart; cap unreachable at 40 / unverified; treat audits >90 d as absent; show audit age. M |
| P1-9 | **Score renormalisation inflates zero-evidence leads** (null factors excluded → 27/42 → 64) and `confidence_score` is computed but never enforced | `LeadScoringService.php:177-192` | Don't renormalise intent factors, or require confidence ≥ medium for `qualified`. M |
| P1-10 | **Follow-up 3+ can never send**: `References` header built with brackets, split incorrectly → `RfcComplianceException` (reproduced with vendor Symfony Mime), recipient `failed`, never retried → 5-touch cadence is really 2 touches live | `CampaignDeliveryService.php:177-191`, `OutreachMailer.php:57`, swallowed at `:237-243`; tests stop at step 2 and use `Mail::fake` | `preg_match_all('/<([^>]+)>/')`; test touches 1→5 with real message build; surface failed recipients. S |
| P1-11 | **`sendTestEmail` is an open relay**: no authorization, raw `Mail::raw` to any address, bypasses guard/dry-run/emergency stop | `CampaignController.php:186-199`, `CampaignDeliveryService.php:273-276` | Admin only, recipient limited to admin/pilot addresses, send via `OutreachMailer`. S |
| P1-12 | **Classic campaign path ignores dry-run on manual dispatch, sending window, workflow pauses and incidents** | `CampaignController.php:177` → `isAutomated=false`; `EligibilityGateService.php:36-37`; `dispatch-campaigns` never checks window | `OutreachGuard::blockReason` at top of `dispatchRecipient`; window check in `dispatch-campaigns`; regression test. S |
| P1-13 | **Human-approved replies, meeting offers and proposals are sent but never recorded** (no `EmailLog`, Message-ID discarded) → next reply unmatched, frequency caps undercount, no audit trail | `ApprovalService.php:257-265,308-327`, `ReplyOutcomeService.php:151`, `ReplySyncService.php:127-135` | Write outbound `EmailLog` + Message-ID on every send; extend thread matching. M |
| P1-14 | **CRM and buying-signal endpoints have no authorization (IDOR)**: any active user rewrites any lead's stage, closes others' tasks, fabricates deals/won revenue, verifies fake signals | `CrmPipelineController.php:59-154`, `BuyingSignalController.php:67-103` vs `LeadPolicy.php:29-36` | `authorize()` everywhere, Task/Deal/Meeting policies, `in:` validation of stages. S |
| P1-15 | **No login throttling**; seeded demo users keep `password123`; `.env.example` ships `APP_DEBUG=true`, `SESSION_ENCRYPT=false`; `DatabaseSeeder` always runs demo seeders | `routes/web.php:22`, `UserSeeder.php:18-60`, `.env.example:2-4,30` | `throttle:5,1` on login, demo seeders behind `--demo`/local, `Password::defaults()`, secure-cookie defaults. S |
| P1-16 | **Self-registration on by default + every user sees every lead** | `config/leadpilot.php` registration block, `LeadController.php:45-76`, `CompanyController.php:32-61` | Default off for internet deployments or admin-activation; assignment scoping for sales. S |
| P1-17 | **No DB uniqueness on dedupe keys** (`companies.domain`, `external_id`, `leads.email`, `suppressions.email` are plain indexes) → concurrent jobs create duplicates; "unique companies" KPI untrustworthy | migrations `2026_10_21_000003:15`, `000005:18`, `000012:13`, `2026_10_22_000001:137` | One-off dedupe migration, then unique indexes; catch violations in ingestors. M |

## P2 — quality and reliability

- Allocator rest logic: one empty run rests an arm (comment says two); saturated OSM arms count known rows as found → never rest → same Overpass query every 15 min (`LeadHuntService.php:166-169`, `OsmOverpassAdapter.php:302,333`). Port Overture's known-id pre-skip to OSM. M
- ICP fields silently ignored per source (`no_website` not passed to Overture, UK tenders ignore countries, TED ignores keywords, Wikidata ignores cities/sizes, watchlist tokens leak into keyword filters, every city bound to `countries[0]`, `monthly_budget_cents`/`tech_*`/`contact_roles` unused) (`IcpSearchConfigBuilder.php:55-77,118-136`). Capability matrix + hunt-preview warnings. M
- Google Places: retention purge misses signals/`source_url`; `testConnection()` is billable; same top-20 re-billed each run. S–M
- "No website" from Places stored as a **verified** 90 % signal; all directory adapters choose `web_dev` from absence (`GooglePlacesAdapter.php:167-183`, `ProspectIngestor::addSignal` default `verified`). S
- Fabrication edges: ATS company name invented from URL slug at confidence 90 (`GreenhouseLeverWatchlistAdapter.php:83-89`); `MetaLeadAdsConnector.php:117` invents domain/website/industry/country; shared Gmail inbox across two businesses silently drops the second lead (`ProspectIngestor.php:139-144`). S
- Health/circuit breaker not enforced on the hunt path; `verified` set on partially failed runs; Contracts Finder 403 back-off not persisted. S
- Nominatim/Overpass pacing is cache-based without locks (policy risk under parallel workers); one Nominatim error aborts the whole run. S
- Audit evidence double-counted across 3–5 scoring factors plus ladder terms; RFP can be outranked by a bad-website clinic (`LeadScoringService.php:81,166-171,312`, `WinLadderService.php:106-157`). M
- Re-scoring/re-ranking not triggered by verification or new signals for up to 7 days (`AutopilotRunner.php:328-331`, `ProspectIngestor::addSignal`). S
- Contact harvested from the **final redirect host**; no parked/offsite-redirect detection → wrong-company e-mail and "weak website" audit of a registrar page (`WebsiteAuditService.php:140,479,613-638`). M
- Gate ordering/reasons: first failure wins → suppressed low-score lead reported as `nurture`; empty country passes; daily cap counts all outbound mail globally; sending window defined in three places and the admin-editable one is ignored by the autopilot (`EligibilityGateService.php:53-54,116-127,139-141`, `SequenceEngine.php:286-292`). S–M
- Discovery holds one lead per company forever; later named contacts are dropped (`ProspectIngestor.php:139-144`). S
- Approved reply drafts can carry literal `[placeholders]` to a prospect (`ReplyClassifier.php:61,109`, `ApprovalService::executeReplyResponse`). S
- Rule-based research shown as "AI Company Intelligence · Confidence High" with an invented "proven track record" angle; provider never stored (`AiResearchService.php:288-303`, `companies/show.blade.php:82-84`). S
- CRM: any string accepted as stage; `closed_at`/`lost_reason` never set so `PerformanceReport` won = 0 while Analytics disagrees; `recordPositive` only on replies, no negative feedback; `ProposalDraftService` unreachable from UI; no meeting confirmation/outcome. L
- Referral replies auto-create a lead from the first e-mail in the body (often the replier's own) and can be cold-sequenced (`ReplyClassifier.php:75`, `ReplyOutcomeService.php:88-95`). S
- Queue/scheduler: job `timeout` is a no-op without pcntl; step failures never raise incidents; `QueueHeartbeatJob` backlog when the worker is down; failed webhook events can never be reprocessed (`WebhookProcessorService.php:28-67`). M
- Dry-run does not stop manual campaign sends (same root as P1-12). S
- RBAC split across role string, roles table and ad-hoc `role !== 'admin'` checks; registered users get no pivot permissions; no user-management UI. M
- No erasure path: `destroy` routes exist without methods (500), SoftDeletes migrated but not used (cascade hard-deletes consent/replies), no retention for non-responders or audit logs. M
- Dashboard KPIs inconsistent: "qualified" hard-coded ≥60 in three places vs config 65; sent/delivered all-time on dashboard vs windowed in analytics; proposals = deal-stage proxy; no drill-down links (`DashboardController.php:54-134`, `AnalyticsController.php:27-45`). M
- Heavy synchronous work: ~35 uncached dashboard queries, unbounded CRM board and exports, synchronous LLM/SMTP calls in web requests, N+1 in `PerformanceReport`. M
- Tests: no 401/403 matrix, no webhook-signature test, no real-message follow-up chain, live scripts outside phpunit touch the real DB, no global `Http::preventStrayRequests()`. M

## P3 — hygiene
Unredacted provider errors in incidents/flash messages; Hunter key in query string; CSV exports not formula-safe; legacy md5 unsubscribe token still accepted; unsubscribe performs the action on GET (link scanners unsubscribe people); `env()` read at runtime in `AiResearchService` (breaks under `config:cache`); dead code (`PublicProcurementAdapter`, `autonomous-cycle`, `testAll()`, `areaId()`, `canReceiveOutreach()`, unused columns `query_cursor`, `lead_source_id`, `monthly_budget_cents`); duplicate reply-label and cost-column logic; Overpass regex metacharacters not escaped; TED page 1 only; no length clamping before insert (one over-long OSM tag aborts a whole page).

## What is solid (keep as-is)
- `SafeFetcher` SSRF guard (allow-lists, DNS pinning, private ranges, capped redirects) and robots.txt handling.
- Single write path `ProspectIngestor`: never invents a person, field-level provenance, ICP exclusions before write, blanks-only fill on duplicates.
- Overture adapter (known-id pre-skip, social-URL detection, atomic extracts, attribution), OSM pacing/mirrors, free-first gating with approvals and budgets, scheduled Places purge.
- Autopilot outbound path: `OutreachGuard` → 10-check gate → window/caps/24 h duplicate guard; first touch defaults to approval; idempotent, locked approvals.
- Deterministic reply classifier (no LLM on inbound text), unsubscribe always honoured, IMAP ingest idempotent with anti-spoof bounce handling, RFC 8058 one-click unsubscribe with HMAC + `hash_equals`.
- AI drafting is facts-only with hard output guards; local Ollama excluded from open-ended research; `ScoreCalibrationService` refuses a verdict under 30 outcomes.
- Encrypted integration credentials, secret redaction in health errors, root `.htaccess` blocking sensitive paths, `.gitignore` for env backups.

## Required dashboard KPIs — current state

| KPI | Exists | Where | Defined / linked |
|---|---|---|---|
| Discovered | partial | `DashboardController:56`, `AutopilotController:37,44` | 3 different definitions, no drill-down |
| Unique verified companies | no | `Company::count()` | undefined; no unique index |
| Contactable | report only | `PerformanceReport:47` (admin) | not on dashboard |
| Qualified | yes | three controllers hard-code ≥60 vs config 65 | inconsistent |
| High-intent | partial | score ≥ high band | score proxy, not intent |
| Eligibility / blocking reasons | per record | `win_blockers`, recipient `error_message` | never aggregated |
| Sent / delivered / bounced / replied | yes | dashboard (all-time) vs analytics (windowed) | two definitions |
| Meetings | yes | `Meeting::count()` | not linked |
| Proposals | proxy | deal stage | `ProposalDraft` unused |
| Won | yes | `stage='won'` vs `closed_at` (always null) | two time bases |
| Conversion rates | partial | ratios of counts | no stage funnel |
| Source effectiveness | yes | dashboard top-5, analytics, performance report | dashboard lacks outcomes |
| API cost / provider health | partial | `UsageLog` + `CostLedger`; health only on `/autopilot` | not on main dashboard |

---

## Staged implementation plan (effort in working days; one phase at a time, each with tests)

| Phase | Scope | Days | Exit criterion |
|---|---|---|---|
| **0 Hotfix (live site)** | P0-1 API auth; P0-2 webhook HMAC + throttle + unknown-provider reject; P1-11 test-email lock-down; P1-14 CRM/signal authorization; P1-15 login throttle + demo seeders behind a flag; P1-12 guard on manual dispatch; P1-10 References header | 1 | 401/403 matrix tests green; live `GET /api/v1/leads` → 401; unsigned webhook → 401; touches 1→5 build a valid message |
| **1 Verification truth** | P0-3 `mx_only` status + `isSendable()`; P1-5 Hunter mapping; P1-6 vocabulary; port-25 flag logic; re-verify existing leads; dashboard tiles count real checks | 1.5 | no lead is "verified" without SMTP/Hunter proof; gate/ladder/UI agree |
| **2 Discovery** | P1-1 host denylist in `normalizeDomain`; P1-2 identity cascade (external_id → domain → phone → name+city/geo); P1-17 dedupe migration + unique indexes; P1-3 Apollo cost + `runAllActive` gate; P1-4 allocator outcomes/health/ε; OSM known-id skip + rest logic; ICP capability matrix in hunt preview; Places purge completeness; "no website" as unverified; length clamps, Overpass `preg_quote`, Nominatim locks | 4 | same clinic from two sources = one company; duplicates KPI ≈ 0; budget-0 holds on every path; allocator test with skipped paid arm |
| **3 Verified opportunities** | explicit assessment record per lead (authenticity, ICP fit, evidence, intent, decision-maker, contact provenance/deliverability, freshness, eligibility) persisted and shown; P1-7 real gate checks; P1-8 audit staleness + unreachable rule + offsite-redirect/parked detection; P1-9 scoring confidence; double-count cleanup; rescoring triggers; gate ordering/country/cap/window unification; placeholder upgrade for named contacts | 3.5 | every rung-A lead shows 8 assessments with evidence links; no "verified" claim without a check that can fail |
| **4 AI sales intelligence** | provider/generator label + confidence rules in UI; rule-based output never labelled AI; evidence citations per research claim (signal/audit ids) with hallucination check (claims must reference stored facts); placeholder guard on reply/meeting/proposal executors; prompt-injection hardening (delimit scraped text); referral handling | 2 | no AI text reaches a prospect or a rep without evidence refs and a provider label |
| **5 Conversion engine** | P1-13 record every human send; stage validation + transitions + `closed_at`/`lost_reason`; `recordPositive` on meeting/won + `recordNegative`; proposal action in UI → `proposal_send` approval; meeting confirm/outcome; webhook replay; incidents on repeated step failures; sending-window single source; erasure path (SoftDeletes + anonymise job) | 4.5 | funnel qualified→contacted→replied→meeting→won computable from records; calibration receives won/lost |
| **6 Dashboard** | `KpiService` with one definition per KPI (bands from config, windows, timezone), all 13 required KPIs, blocking-reason aggregation, provider health + cost tiles, drill-down links, caching, pagination/streaming for heavy pages, RBAC consolidation (`Gate::before`, policies, users page) | 3.5 | every tile links to its records; two pages never disagree on a number |
| **Test hardening (across phases)** | authz matrix, webhook signature, real-message follow-up chain, dedupe, `Http::preventStrayRequests()` globally, move live scripts under `tools/` | included | — |

Total ≈ 20 working days. Phases 0 and 1 are prerequisites for trusting any number the system reports; 2 and 3 carry the "higher verified-lead precision" goal; 5 and 6 carry "measurable meetings and won deals".

Honesty notes: nothing above was validated against paid providers (Hunter, Apollo, Google Places, OpenAI) — those mappings are reviewed from code and documentation only. Live checks performed: API exposure (verified), login page state (verified), Overture/Wikidata/SMTP-probe behaviour (verified earlier today from the owner's PC).

---

## Status update — Phase 0 hotfix shipped the same day (commit after `e42460f`)

Implemented and tested (157 tests / 1,248 assertions green; `tests/Feature/Phase0HotfixTest.php` + updated API, webhook, campaign tests):

| Finding | Change |
|---|---|
| P0-1 public API | `routes/api.php` group now `auth` + `throttle:120,1`; JSON callers get 401 |
| P0-2 unsigned webhooks | `WebhookController` verifies HMAC-SHA256 of the raw body against `LEADPILOT_WEBHOOK_SECRET` or the provider integration's `webhook_secret`; unknown provider 404, no secret 503 (fail closed), bad signature 401, `throttle:60,1`, provider-scoped idempotency keys |
| P1-10 References header | `OutreachMailer::messageIds()` parses `<id>` tokens; In-Reply-To tolerates brackets; real Symfony build asserted in a test |
| P1-11 test-e-mail relay | admin only, recipient must be an active admin address or the controlled-pilot recipient |
| P1-12 manual dispatch | `CampaignDeliveryService::dispatchRecipient` applies `OutreachGuard::blockReason` (dry-run, emergency stop, pilot, pause, local APP_URL) to manual sends too |
| P1-14 CRM / signal IDOR | `authorize('update', …)` on stage change, tasks, meetings, deals, buying signals; lifecycle stage validated against a fixed list; task completion limited to assignee/admin |
| P1-15 login / demo seeds | `throttle:5,1` on POST /login; `DatabaseSeeder` seeds demo users and demo data only in local/testing or with `LEADPILOT_SEED_DEMO=true` |

Deploy note for the live server: after `git pull`, set `LEADPILOT_WEBHOOK_SECRET` only if a provider actually posts webhooks (otherwise all webhook calls are rejected, which is the safe default), then `php artisan config:cache`. Verify: `curl -sI https://hunter.eziline.net/api/v1/leads` must return 401.

Not changed in Phase 0 (needs the owner's decision, see P1-16): self-registration stays enabled and new accounts stay active immediately; every active user still sees all leads.
