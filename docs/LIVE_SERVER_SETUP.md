# Live server setup — the configuration that gets results

Written 23 Oct 2026 for the move from the owner's Windows PC to a live server. The generic steps (PHP, MySQL, migrations,
supervisor, cron) are in `DEPLOYMENT_AND_OPERATIONS.md`; this page is the *opinionated* setup: what to buy, what to
switch on, and in which order, so every free source and every safety rail works at full strength from day one.

Honest framing first: this setup makes the **pipeline** reliable (discovery every 15 minutes, audits, verification,
inbox-landing e-mail, replies synced, autopilot sending only to rung A). It cannot guarantee that a prospect buys.
What it guarantees is that nothing in the chain is silently broken, which is what blocked results on the PC
(local APP_URL, blocked port 25, no reply sync, worker running stale code).

## 1. What the PC could not do and the server fixes

| On the PC | Effect | On the server |
|---|---|---|
| `APP_URL=http://localhost:8000` | `public_url` guard blocks every prospect e-mail (unsubscribe links dead) | real domain + HTTPS → guard passes |
| ISP blocks outbound port 25 | SMTP mailbox probe always "blocked"; verification stays MX-level | VPS with port 25 open → real mailbox verification (the free Hunter replacement) |
| Gmail SMTP from a personal account | ~500/day, reputation shared with every Gmail cold-mailer, no DKIM for your domain | own-domain mailbox with SPF/DKIM/DMARC |
| Workers in `cmd` windows | stop when the PC sleeps; stale code after edits | supervisor + cron, restart on deploy |
| C: drive 98% full, 8 GB RAM, no GPU | Ollama slow, disk risk | 8 GB VPS runs `qwen2.5:3b` for drafts, or skip Ollama and use the Gemini free tier |
| No IMAP keys | replies never synced; a "yes" sits unseen | IMAP on the same mailbox → replies classified, lead moves to `replied` |

## 2. What to buy (all small)

1. **VPS**: Ubuntu 24.04, 2 vCPU, **8 GB RAM** (4 GB is enough without Ollama), 80 GB SSD, in Europe (Frankfurt/Falkenstein:
   closest to Overture's S3 and the EU/UK tender APIs; Pakistan traffic is fine from there). Hetzner CX32 / Contabo / DigitalOcean 8 GB.
   **Before paying, check the provider allows outbound SMTP (port 25)** — Hetzner and Contabo open it on request for
   accounts in good standing; DigitalOcean/Vultr block it for new accounts. Without port 25 the mailbox probe cannot run
   (everything else still works, exactly as on the PC).
2. **Domain for the app**, e.g. `leads.eziline.com` (a sub-domain of the agency site is ideal: free, trusted).
3. **A sending mailbox on the agency domain**, e.g. `ismail@eziline.com` or `hello@eziline.com`, on Google Workspace
   (SMTP 2,000/day, IMAP included) or Zoho Mail (free plan has SMTP+IMAP). Never send cold e-mail from `@gmail.com`.
4. Nothing else. No paid API. The free stack (Overture, OSM, Wikidata, tenders, job boards, MX+SMTP probe, Ollama/Gemini) is complete.

## 3. DNS records (the part most people skip, and the one that decides inbox vs spam)

At the agency domain's DNS:

| Record | Value | Why |
|---|---|---|
| `A leads.eziline.com` | VPS IP | the app |
| SPF `TXT @` | `v=spf1 include:_spf.google.com ~all` (Workspace) or `include:zoho.com` | receivers accept your mailbox provider as sender |
| DKIM `TXT google._domainkey` / `zmail._domainkey` | key from the mailbox admin console | signs each message |
| DMARC `TXT _dmarc` | `v=DMARC1; p=quarantine; rua=mailto:dmarc@eziline.com; pct=100` | Gmail/Microsoft require it for bulk-ish senders since 2024 |
| PTR (reverse DNS) of the VPS IP | `leads.eziline.com` | set in the VPS panel; needed for the SMTP probe's EHLO to be trusted |

Check with `php artisan leadpilot:doctor` after `.env` is in place (it reports SPF/DKIM/DMARC presence) and with
https://www.mail-tester.com (send the pilot mail there once; aim for 9/10 or better before going live).

## 4. Install sequence (copy in this order)

```bash
# 1. base
sudo apt update && sudo apt install -y nginx mysql-server php8.3-fpm php8.3-{cli,mysql,mbstring,xml,curl,zip,bcmath,intl,gd,imap,sqlite3} \
    composer git unzip supervisor certbot python3-certbot-nginx nodejs npm
# 2. DuckDB CLI for the Overture source (Linux build of the same version used on the PC)
sudo mkdir -p /var/www/leadpilot/tools/duckdb && cd /tmp && \
    curl -L -o duckdb.zip https://install.duckdb.org/v1.4.5/duckdb_cli-linux-amd64.zip && unzip duckdb.zip && \
    sudo mv duckdb /var/www/leadpilot/tools/duckdb/duckdb && sudo chmod +x /var/www/leadpilot/tools/duckdb/duckdb
# 3. app (copy the project folder, or git clone; never copy the PC's .env)
cd /var/www/leadpilot && composer install --no-dev --optimize-autoloader && npm ci && npm run build
cp .env.example .env && php artisan key:generate
# 4. .env (names only — values stay on the server):
#   APP_ENV=production APP_DEBUG=false APP_URL=https://leads.eziline.com
#   DB_*  (MySQL user with its own password)
#   MAIL_MAILER=smtp MAIL_HOST=smtp.gmail.com (Workspace) or smtp.zoho.com, MAIL_PORT=587, MAIL_ENCRYPTION=tls,
#   MAIL_USERNAME=hello@eziline.com MAIL_PASSWORD=<app password> MAIL_FROM_ADDRESS=hello@eziline.com
#   IMAP_ENABLED=true IMAP_HOST=imap.gmail.com|imap.zoho.com IMAP_PORT=993 IMAP_USERNAME=... IMAP_PASSWORD=...
#   OSM_CONTACT_EMAIL=hello@eziline.com
#   OVERTURE_DUCKDB_BIN=/var/www/leadpilot/tools/duckdb/duckdb
#   LEADPILOT_SMTP_PROBE=true  LEADPILOT_OLLAMA_ENABLED=true (if Ollama installed)  GEMINI_API_KEY=<optional free key>
#   LEADPILOT_BUDGET_DAILY_CENTS=0 LEADPILOT_BUDGET_MONTHLY_CENTS=0   (keeps every paid provider off)
php artisan migrate --force && php artisan db:seed --class=IntegrationSeeder --force
php artisan storage:link && sudo chown -R www-data:www-data storage bootstrap/cache tools
php artisan config:cache && php artisan route:cache && php artisan view:cache
# 5. nginx + TLS
sudo certbot --nginx -d leads.eziline.com
# 6. workers (see DEPLOYMENT_AND_OPERATIONS.md §4 for the files)
sudo supervisorctl reread && sudo supervisorctl update          # queue:work database --tries=3 --max-time=3600
( crontab -u www-data -l; echo "* * * * * cd /var/www/leadpilot && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -u www-data -
# 7. optional local LLM (8 GB VPS): nothing leaves the server
curl -fsSL https://ollama.com/install.sh | sh && ollama pull qwen2.5:3b
```

Each deploy afterwards: `git pull && composer install --no-dev && npm run build && php artisan migrate --force && php artisan config:cache && sudo supervisorctl restart all`.

## 5. Go-live checklist (run on the server, in this order)

1. `php artisan leadpilot:doctor` — everything OK or WARN with a reason you accept.
2. `php artisan leadpilot:mail-test <your address>` — ACCEPTED, and the message lands in Inbox, not Spam.
3. `php artisan leadpilot:validate-live --pilot-to=<your address>` — pilot PASS; sources LIVE (Overture, OSM, Wikidata, tenders).
4. Port 25: `php artisan tinker --execute="dump(app(App\Services\EmailVerificationService::class)->smtpProbe('postmaster@gmail.com', ['gmail-smtp-in.l.google.com']))"`
   → `result` must not be `blocked`. If it is, open a ticket with the VPS provider; until then verification is MX-level (still honest).
5. Create the owner admin user (Settings → Users), set `controlled_pilot_recipient` to the owner's address, change the temporary password.
6. Settings → Outreach Engine: minimum score 65, daily cap **10 for week 1** (then 25), sending window 09:00–17:00 Asia/Karachi, allow shared inbox **on**,
   mode **approval** for the first 20 e-mails (you click send), then **live**.
7. ICPs: keep #1 (clinics Islamabad/Rawalpindi, Overture+OSM); add #3 "Pakistan exporters & logistics" (business type SMB, no cities, niches textile/logistics/pharma/food → Wikidata);
   enable #2 (UK tenders) only if you will answer tenders through the portal — the system never cold-emails tender contacts.
8. Watch `/approvals` and the Autopilot page daily for two weeks. Pause outreach if bounce rate passes 5 % or a complaint arrives.

## 6. Capacity you should expect (free stack, one 8 GB VPS)

| Stage | Throughput | Limit comes from |
|---|---|---|
| Discovery | Overture: a whole city in one 45 s extract, then 40 new businesses per run; OSM 25–40 per 2 min; Wikidata 12–50 per 10 s | autopilot runs 2 arms per 15-minute cycle |
| Audits | ~15 sites per cycle | polite crawling (robots.txt, one request at a time) |
| Verification | MX instant; SMTP probe 1–10 s per address | MX servers' greylisting |
| Outreach | 10 → 25 first touches/day, follow-ups on top | daily cap + sending window + mailbox reputation (Workspace 2,000/day is never the limit) |
| Replies | synced every 10 minutes via IMAP | — |

At 25/day with a 2–3 % reply rate on evidence-based first e-mails, expect 3–5 real conversations a week after warm-up. Rung A fills
only when a business has a verified address **and** a measured website problem, so the number sent is decided by evidence, not by the cap.

## 7. What not to do on the server

- Do not copy the PC's `.env` (localhost URL, Gmail password, test keys). Do not put secrets in chat or in the repo.
- Do not raise the daily cap above 25 in the first month, and do not add purchased lists; the suppression and gate logic assume first-party data only.
- Do not run `queue:work` from a shell by hand; supervisor owns it, otherwise code changes go stale again.
- Do not switch `require_verified_email` off to "get more leads": the probe and MX checks are the free deliverability protection.
