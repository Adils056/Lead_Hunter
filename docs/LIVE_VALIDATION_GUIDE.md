# Live validation guide — how to prove LeadPilot works on your machine

Everything below runs real providers from your PC. Nothing is mocked. Nothing is sent to a prospect.

## Before you start (once)

```bat
cd C:\xampp\htdocs\Leed_generation
php artisan migrate
php artisan config:clear
php artisan leadpilot:doctor
```

`doctor` must show the scheduler and queue worker heartbeats as OK. If not, run `start_workers.bat` (two console windows: `queue:work` and `schedule:work`). **After every code update, close the queue worker window and start it again** — a running worker keeps the old code in memory.

## The pilot address

Test and pilot emails go only to an **active admin user of this app**. To use your own address:

1. Users → add `shahpk72@gmail.com` with role *admin* (or ask an existing admin to do it).
2. Then the commands below accept `shahpk72@gmail.com`. Until then they answer `Refused: … is not an active admin user`.

## Commands

| What | Command | What "pass" looks like |
|---|---|---|
| Health | `php artisan leadpilot:doctor` | No FAIL rows; WARN rows explain themselves |
| SMTP | `php artisan leadpilot:mail-test shahpk72@gmail.com` | `ACCEPTED by the SMTP server … Message-ID …`. Accepted ≠ delivered: check Inbox and Spam |
| Full live validation | `php artisan leadpilot:validate-live --pilot-to=shahpk72@gmail.com` | 13 steps with PASS / WARN / FAIL / NOT TESTED, report in `storage\app\validation\validation-<date>.md` and `.json` |
| One source only | `php artisan leadpilot:validate-live --source=osm_overpass --limit=5` | Step 3 shows raw/new/duplicates for that source |
| Who to email first | `php artisan leadpilot:win-ladder --tier=A` (or open **Win Ladder** in the sidebar) | Rung A lists leads that pass every check, best first |
| One autopilot cycle, no writes | `php artisan leadpilot:autopilot --dry-run` | Every step `ok: true` |
| One real autopilot cycle (outreach mode still dry‑run → nothing sent) | `php artisan leadpilot:autopilot` | Drafts appear under Approvals |
| Reply sync | `php artisan leadpilot:sync-replies` | Needs `IMAP_*` in `.env`; otherwise "not configured" |

## Reading the report

- **PASS**: executed against the real provider and produced the expected result.
- **WARN**: executed, but something needs your decision (e.g. approved services, APP_URL, Gmail limits).
- **FAIL**: executed and broke; the JSON under the step shows the provider error.
- **NOT TESTED**: could not be executed (no credentials, no input data, previous step empty). Never counted as a pass.

Expected timings on this network: Overpass 40–120 s per city (the public server is slow and sometimes answers 502/504 first; the adapter retries), tenders a few seconds, audits ~5 s per site, the whole run 7–17 minutes.

## Typical blockers and the fix

| Symptom | Cause | Fix |
|---|---|---|
| Step 3 OSM `cURL error 28 … timed out` | Mirror unreachable from your ISP | Automatic: unreachable mirrors are skipped for 20 min and the mirror that answered last is tried first. Override order with `OSM_OVERPASS_MIRRORS` |
| `Could not resolve area for "Rawalpindi, Pakistan"` | OSM has no boundary relation for the city | Fixed: falls back to the city bounding box |
| Step 6/9 NOT TESTED | New leads have no website in OSM | Enrich the company website or audit a company manually: Company → Audit website |
| Step 11 `Refused: … not an active admin user` | Pilot address is not an app admin | See *The pilot address* above |
| Step 12 NOT TESTED | No `IMAP_*` settings | Add `IMAP_ENABLED=true`, `IMAP_HOST=imap.gmail.com`, `IMAP_PORT=993`, `IMAP_USERNAME`, `IMAP_PASSWORD` (Gmail App Password), `IMAP_MAILBOX=INBOX` |
| Autopilot steps fail with `array offset on null` | Queue worker running old code | Restart the worker window |
| Lead found but on Win Ladder rung C | Score below outreach minimum, email unverified, or no named contact | Verify the email, audit the website, or lower *minimum lead score* in Settings → Outreach Engine |

## Re‑running the proof after a change

```bat
php artisan test
php artisan leadpilot:doctor
php artisan leadpilot:validate-live --pilot-to=<admin address>
php artisan leadpilot:win-ladder --tier=all --limit=10
```
