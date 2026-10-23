# LeadPilot AI v4 — Autopilot

The day-to-day experience is meant to be:

1. Open **Autopilot** and see the real opportunities.
2. Get notified only when something needs a human.
3. Approve or resolve it.
4. The system carries on.

---

## 1. Status of every capability (honest)

Labels used below:

- **Unit/feature-tested**: automated tests with mocked HTTP and mail. These are repeatable.
- **Integration-tested**: the module was exercised together with others end-to-end, still on mocks.
- **Real-data verified**: run against the real provider. **Nothing is in this category yet.** The build environment had no internet access for the app, and your `.env` holds no API keys. Run `php artisan leadpilot:validate-live` on your PC to do it.

| Capability | Implemented | Unit-tested | Integration-tested (mocked) | Real-data verified | Needs credentials | Needs your authorisation |
|---|---|---|---|---|---|---|
| Score thresholds from one source (fixes 75/70/60/50 drift) | ✅ | ✅ | ✅ | n/a | — | — |
| Lead pipeline states (12) separate from score/CRM stage | ✅ | ✅ | ✅ | — | — | — |
| Provider registry, free-first selection, fallback, circuit breaker, budgets, cost ledger | ✅ | ✅ | ✅ | ❌ | — | Budgets (you set them) |
| OpenStreetMap local discovery (free) | ✅ | ✅ | ✅ | ❌ | none | — |
| UK Find a Tender (free) | ✅ | ✅ | ✅ | ❌ | none | — |
| EU TED tenders (free) | ✅ | ✅ | — | ❌ (field names unverified) | none | — |
| Google Places / Apollo (paid) | ✅ (v3) | ✅ | ✅ | ❌ | API key | Budget + approval |
| Website audit + SSRF protection | ✅ (v3, hardened) | ✅ | ✅ | ❌ | none | — |
| Explainable scoring + calibration report | ✅ (v3) | ✅ | ✅ | ❌ (needs 30+ real outcomes) | — | — |
| Autonomy policy (automatic / approval / prohibited per action) | ✅ | ✅ | ✅ | n/a | — | You choose modes |
| Approvals: approve, edit & approve, reject, request info, reassign, escalate, pause, retry; history; escalation on timeout | ✅ | ✅ | ✅ | ❌ | — | Yes, by design |
| Notifications (in-app + email), honest delivery status, dedupe, re-notify | ✅ | ✅ | ✅ | ❌ (Gmail SMTP not reachable from build env) | SMTP (you have it) | — |
| Incidents (bounce/complaint spikes, stalled worker/scheduler, auth failures, overspend, duplicate sends, notification failures), per-workflow pause | ✅ | ✅ | ✅ | ❌ | — | Resolve/resume |
| Autopilot outreach: first touch + threaded follow-ups, sending window (Asia/Karachi), daily caps, 24h duplicate guard, stop on any reply, out-of-office pause | ✅ | ✅ | ✅ | ❌ | SMTP | First touch = approval by default |
| RFC 8058 one-click unsubscribe + real Message-ID + token-checked unsubscribe | ✅ | ✅ | ✅ | ❌ | — | — |
| IMAP reply + bounce sync (built-in client, no extension needed) | ✅ | ✅ (scripted IMAP server) | ✅ | ❌ | Gmail App Password | — |
| 8-category reply classifier + confidence routing + outcome rules | ✅ | ✅ | ✅ | ❌ | — | Low-confidence replies |
| Meeting slots from working hours minus CRM meetings | ✅ | ✅ | ✅ | n/a | — | Approval to send |
| Proposal drafts (non-binding, catalog price range) | ✅ | ✅ | — | n/a | — | Always approval |
| Learning loop (search allocation by qualified leads + positive replies, cost-aware; rests dry searches) | ✅ | ✅ | ✅ | ❌ (needs real outcomes) | — | — |
| Performance and cost report (actual vs estimated; cost per lead/qualified/positive reply/meeting/SQL/won) | ✅ | ✅ | ✅ | ❌ | — | — |
| LLM drafting: Gemini free tier → OpenAI fallback; no personal data sent; number guard | ✅ | ✅ | — | ❌ | GEMINI_API_KEY or OpenAI | — |
| Setup & health dashboard, `leadpilot:doctor`, "apply recommended defaults" | ✅ | ✅ | — | Partly (it read your real `.env`) | — | — |
| Real-data validation runner (`leadpilot:validate-live`) | ✅ | ✅ (mocked run) | — | **Run it on your PC** | — | Pilot email to an admin only |
| Google Calendar booking (create events) | ❌ | — | — | — | OAuth | — |
| Gmail API (OAuth) reply reading | ❌ (IMAP used instead) | — | — | — | OAuth | — |
| LinkedIn / WhatsApp automation | ❌ (deliberately: platform terms) | — | — | — | — | — |
| Self-modifying code / auto-installing new APIs | ❌ (deliberately; see §8) | — | — | — | — | — |

**Tests: 111 passed (907 assertions).** That is 69 from v3 plus 42 new, including 10 regression tests for the independent review. They pass on both SQLite and MySQL/MariaDB, and the migrations go up, down and up cleanly on MySQL.

---

## 2. Install (on your XAMPP machine)

1. Back up `C:\xampp\htdocs\Leed_generation` and the database.
2. Extract the zip over the folder and choose **Replace**.
3. Run, in the project folder:
   ```
   php artisan migrate
   php artisan leadpilot:setup                 # applies safe defaults + prints the health checklist
   php artisan test                            # expect 111 passed
   ```
4. Supervise the worker and scheduler with Task Scheduler instead of terminal windows. In PowerShell **as Administrator**:
   ```
   powershell -ExecutionPolicy Bypass -File deploy\windows\install_tasks.ps1
   ```
   This registers *LeadPilot Scheduler* (runs `schedule:run` every minute) and *LeadPilot Queue Worker* (starts at boot, with a 5-minute watchdog, restarting every hour).
   - The worker and scheduler send heartbeats.
   - If either stops, the dashboard turns red and you get an incident notification.
   - On Linux, use `deploy/linux/leadpilot-worker.conf` (Supervisor) plus a cron entry.
5. Run the real-data validation (Phase 12):
   ```
   php artisan leadpilot:validate-live --limit=5 --audits=5 --pilot-to=<your admin login email>
   ```
   The report is written to `storage/app/validation/validation-<time>.md`. Send it to me and I'll analyse it.

---

## 3. Setup checklist — only what cannot be automated

| # | Item | Why | How | Required? |
|---|---|---|---|---|
| 1 | **APP_URL** set to an address prospects can reach | Unsubscribe links in emails must work | `.env` → `APP_URL=https://your-domain` (or a tunnel) | **Required before any live outreach** |
| 2 | **Gmail App Password** for reply sync | LeadPilot must read replies to stop follow-ups and route interest | Google Account → Security → 2-Step Verification ON → App passwords → create. Then in `.env`: `IMAP_ENABLED=true`, `IMAP_PASSWORD=<app password>` | **Required for autonomous follow-ups** |
| 3 | **Sending mailbox on your own domain** (Workspace or similar) with SPF, DKIM, DMARC | Your current sender is a personal Gmail address — limited to about 500/day and can't align a custom domain. Gmail's sender rules require authentication and a spam rate under 0.3% | Your DNS provider + Google Admin | Strongly recommended |
| 4 | **Reply-To** | The default was the demo `sales@leadpilot.test`, so replies would be lost | `php artisan leadpilot:setup` fixes it automatically | Done by setup |
| 5 | Worker + scheduler supervision | Background work | `deploy\windows\install_tasks.ps1` | Required |
| 6 | (Optional) `GEMINI_API_KEY` | Free AI polishing of drafts | aistudio.google.com → API key | Optional |
| 7 | (Optional) Google Places key + budget | More local businesses than OSM covers | Google Cloud → Places API (New); then set a budget in **Autonomy & Budgets** | Optional (paid) |
| 8 | (Optional) Hunter key | Better email verification | hunter.io (50 free credits/month) | Optional |
| 9 | (Optional) Booking link | Prospects can self-book | `LEADPILOT_BOOKING_URL=` (Calendly/Cal.com) | Optional |

**Missing credentials detected in your current `.env`:** OpenAI, Hunter, Apollo, Google Places, PageSpeed and Gemini keys are all empty, and IMAP is not configured. Only Gmail SMTP is set. The system runs fully free without them. Item 2 (IMAP) is the one that matters for autonomy.

---

## 4. How the autonomy works

Every 15 minutes, in Asia/Karachi time, the autopilot runs these steps:

1. **monitor**: health checks; it raises incidents and pauses only the affected workflow.
2. **approvals**: escalates overdue requests and re-notifies people.
3. **reply_sync**: reads replies and bounces over IMAP, classifies them and applies the outcome rules.
4. **discovery**: the learning allocator picks the 2 best ICP × source pairs, free sources first.
5. **enrichment**: website audits, then public business email, then free email verification.
6. **scoring**
7. **outreach**:
   - first touches are planned and need **approval by default**;
   - follow-ups are **automatic by default**, but only inside an approved thread, and they stop on any reply;
   - messages are dispatched inside the sending window, under daily caps and the duplicate guard.

**Default autonomy policy** (change it in *Autonomy & Budgets*):

| Mode | Actions |
|---|---|
| Automatic | free discovery, audits, email verification, scoring, AI drafting, follow-ups in approved threads, reply classification, CRM stage updates |
| Approval | first email to a new prospect, paid discovery, meeting times, proposals, enabling integrations |
| Prohibited | auto-replying to humans, spending over budget |

**Commercial commitments can never be set to automatic**: proposals, over-budget spend and enabling integrations.

Hard safety rules apply regardless of policy:

- Only contacts with a **named, identified person** can be emailed automatically. Shared inboxes such as `info@`, published tender contacts and unknown contacts are never auto-contacted.
- The eligibility gate checks suppression, opt-outs, country, service approval, mode (dry-run or pilot), the emergency stop and caps on every send.
- Any reply stops the sequence. An unsubscribe is honoured immediately.

---

## 5. Audit of v3 (what was verified and fixed in v4)

| Finding | Evidence | Fix |
|---|---|---|
| Score thresholds inconsistent: engine 80/65/45, UI and analytics 75/50, `Lead::scopeQualified` **60**, campaign default 60/65, outreach minimum 75 | grep of views, controllers and models | `App\Support\ScoreBands` is now the single source everywhere |
| Unsubscribe link **did not check its token**, so anyone could suppress any address | `CampaignController::unsubscribe` | Constant-time token check, RFC 8058 one-click POST, legacy links still valid |
| Sent emails used a **random fake provider ID**, not the real Message-ID, and had no List-Unsubscribe header | `CampaignDeliveryService` | `OutreachMailer`: real Message-ID, List-Unsubscribe headers, threading |
| **Replies never ingested** with Gmail SMTP (webhooks only), so follow-ups would continue after a reply | architecture | IMAP sync + shared outcome rules for webhook and IMAP replies |
| Approved-services list didn't include the v3 catalog keys, so autopilot campaigns could fail the gate | `OutreachSetting` defaults | Catalog services added; setup applies them for your ICPs |
| Seeded local ICP used only Google Places (paid, not configured), so nothing would be found | demo run | Free OSM fallback per capability; seeder updated |
| Failed website audits retried **every cycle forever** | demo run | 7-day attempt window |
| Classifier's meeting reply **invented availability** ("Tuesday 11am or Wednesday 3pm") | `ReplyClassifier` | Real free slots from working hours minus CRM meetings, sent only with approval |
| Fake-tender file still present on your install (install step 3 skipped) | your upload | Overwritten by an inert stub |
| Old v1 autonomous-cycle and discovery schedules would double-run alongside v4 | `routes/console.php` | Retired; replaced by the autopilot |
| Your log (21 Oct): OpenAI **HTTP 429** (quota), Apollo **timeout**, plus several schema errors | `storage/logs/laravel.log` | OpenAI is now budget-gated with a free Gemini fallback. Providers have health tracking and a circuit breaker. The schema errors were already fixed in v3 |
| Apollo is organization search only (no people search on free plans) | v3 adapter | Unchanged; marked optional and paid |

---

## 6. Security (v4)

- **SSRF:** all crawling uses `SafeFetcher` (from v3). New adapters only call fixed official hosts.
- **Approvals:**
  - Role checks run on every decision.
  - Sales reps can decide only sales-type requests on leads they can see.
  - Spend, integrations and proposals are admin-only.
  - Decisions lock the row (no double execution), and the full history is kept.
- **Unsubscribe:** token required and constant-time compared. It is rate-limited, and CSRF is exempted for that one path only (RFC 8058).
- **Secrets:**
  - The IMAP password and API keys are read from `.env` or encrypted integration records.
  - They never appear in approvals, notifications, health output or validation reports.
  - Validation reports omit personal data.
- **LLM:** only public business facts are sent. The greeting (which may contain a name) is a placeholder, and any number the model invents causes the draft to be rejected.
- Findings from the independent code review and their fixes are listed in §9.

---

## 7. Costs

- Free-first by default: the **global budget is $0**, so paid providers are never called until you set a budget in *Autonomy & Budgets*, or `LEADPILOT_BUDGET_*_CENTS` in `.env`.
- Every provider call is written to `usage_logs` with `is_estimate`. The **Performance** page separates *actual* from *estimated* cost and shows cost per lead, qualified lead, verified contact, positive reply, meeting, SQL and won client.
- In the build environment, actual and estimated cost were both $0.00: no paid calls were possible or made.

---

## 8. Deliberately not built (and why)

- **Self-rewriting code or auto-installing integrations at runtime.** The system *recommends* via the provider registry and setup checks. Adding an integration stays a reviewed code change with tests and rollback, as your brief requires.
- **LinkedIn or WhatsApp automation.** Platform terms forbid automated messaging. The channels stay manual.
- **Email-address guessing or SMTP probing.** It fabricates contacts and risks blacklisting your IP.
- **Pakistan PPRA tenders.** No API exists, so it would need scraping. Check their terms first.
- **Google Calendar event creation.** This needs OAuth consent. Use a booking link, or the slot proposals, for now.

---

## 9. Independent code review — findings & fixes

A separate reviewer audited the v4 diff and reproduced each finding with a probe test. All were fixed, and each now has a regression test in `tests/Feature/AutopilotReviewRegressionTest.php`.

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | HIGH | The old 10-minute `dispatch-campaigns` cron sent autopilot mail and skipped every v4 guard: pause, policy, window, caps and dry-run | Autopilot campaigns are excluded from the old cron. `dispatchRecipient` refuses autopilot messages unless SequenceEngine calls it. Scheduled sends count as *automated*, so dry-run is enforced |
| 2 | HIGH | Approving a request sent mail even when the workflow was paused, the action prohibited, or the system in dry-run | A new `OutreachGuard` (emergency stop, dry-run, pilot recipient, paused workflow, prohibited action) runs before **every** prospect email, human-approved or automatic. Approved messages wait and send once the block clears |
| 3 | HIGH | A follow-up cancelled because the prospect replied could be revived by approving its stale request | Stopping a sequence cancels its open approvals. The executor refuses anything not `pending_approval` or `approved` |
| 4 | HIGH | One-click unsubscribe failed for `name+tag@` addresses because `+` was decoded twice | No second decode. Legacy links are still accepted |
| 5 | MED-HIGH | A forged "postmaster" email could suppress any lead, and their whole domain | Bounces must reference a Message-ID **we sent** and name the same recipient. A missing status counts as soft. Sender-only reply matches are rejected when SPF and DKIM both fail. **Also fixed a v1 bug: every unsubscribe silently blocked the person's entire domain** (e.g. all of gmail.com). Only explicit domain-level blocks do that now |
| 6 | MED | The Gemini key was in the URL and could reach error messages shown to sales reps; keys were read with `env()`, which breaks under `config:cache` | The key goes in an `x-goog-api-key` header. Secrets are redacted from stored errors. Keys moved to config. Autopilot and Performance pages are admin-only |
| 7 | MED | Spend approvals had no effect (global budget stayed $0), and the `spend.over_budget`, `meeting.propose_slots` and `reply.auto_respond` modes were ignored | Approving spend raises the global and provider budgets and queues the approved search. All three policy modes are honoured. A rejected spend isn't re-asked for 7 days |
| 8 | MED | The approvals list did about 400 queries for reps, and could show them an empty list | SQL filter, eager loading, pagination |
| 9 | LOW-MED | Sales reps could pause a global workflow | Pausing a workflow is admin-only |
| 10 | LOW | No IMAP literal/line caps; one bad message blocked the cursor; auto-replies treated as human; NULL-category replies didn't stop follow-ups; References header had only one ID; pipeline state went stale after suppression; global approvals badge; notification redirect had no host check | All fixed: 25 MB / 64 KB caps, per-message checkpoint, `Auto-Submitted` → out-of-office, NULL handled, full References chain, suppression create/delete refreshes state, per-user badge, same-host redirect |

---

## 10. Continuation plan (priority order)

1. **Run `php artisan leadpilot:validate-live` on your PC and send me the report.** This is the real-data verification that couldn't be done here. Or link this chat to your PC in the Claude desktop app and I'll run it.
2. Set up the Gmail App Password for IMAP, and APP_URL. Then do a pilot: approve 5 first emails, observe replies and bounces for a week, then consider setting `outreach.first_touch` to automatic with a cap of 10/day.
3. Move sending to an own-domain mailbox with SPF, DKIM and DMARC before going above about 20 emails/day.
4. After 30 or more resolved outcomes, check the calibration panel and Performance page, then adjust scoring weights.
5. Optional integrations, in order of value: Gemini key (free), Hunter (free tier), Google Places with a small budget, Gmail API/OAuth to replace IMAP, Google Calendar OAuth.
