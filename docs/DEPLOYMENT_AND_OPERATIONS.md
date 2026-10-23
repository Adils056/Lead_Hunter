# LeadPilot AI — Deployment, Security & Operations Guide

## 1. System Requirements & Architecture Overview

- **Operating System:** Linux (Ubuntu 22.04 LTS / 24.04 LTS), macOS, or Windows (via WSL2 or XAMPP)
- **PHP Version:** PHP 8.2 or PHP 8.3 with extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `xml`, `bcmath`, `fileinfo`
- **Database:** MySQL 8.0+ or MariaDB 10.6+
- **Cache & Queues:** Redis 6+ (recommended) or MySQL Database Driver
- **Web Server:** Nginx or Apache with `mod_rewrite` enabled
- **Node.js:** Node v18+ & NPM for assets

---

## 2. Environment Configuration (`.env`)

Verify and adjust your `.env` parameters:

```env
APP_NAME="LeadPilot AI"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://leadpilot.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=leadpilot_ai
DB_USERNAME=leadpilot_user
DB_PASSWORD=your_secure_password

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Mail Delivery (SMTP / Resend / Mailgun)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@yourdomain.com
MAIL_PASSWORD=your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="sales@yourdomain.com"
MAIL_FROM_NAME="LeadPilot Sales AI"

# AI & Verification API Keys (or configure in Settings -> Integrations UI)
OPENAI_API_KEY="sk-proj-..."
HUNTER_API_KEY="..."
APOLLO_API_KEY="..."
```

---

## 3. Database Migration & Initial Seeding

Run database schema setup and initial records:

```bash
# Run all database migrations
php artisan migrate --force

# Seed RBAC roles, templates, integrations, and demo data
php artisan db:seed --force
```

### Initial Administrator Credentials:
- **Email:** `admin@leadpilot.test`
- **Password:** `password123`

### Initial Sales Representative Credentials:
- **Email:** `sales@leadpilot.test`
- **Password:** `password123`

---

## 4. Background Workers & Task Automation

LeadPilot AI operates scheduled outreach checks and lead scoring in the background.

### 4.1 Laravel Queue Worker (Systemd or Supervisor)
Create `/etc/supervisor/conf.d/leadpilot-worker.conf`:
```ini
[program:leadpilot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/leadpilot/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/leadpilot-worker.log
stopwaitsecs=3600
```

### 4.2 Cron Scheduler Setup
Add this line to your web server user's crontab (`crontab -e -u www-data`):
```cron
* * * * * cd /var/www/leadpilot && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler automatically manages:
1. `leadpilot:dispatch-campaigns`: Runs every 10 minutes to deliver approved drafts adhering to daily limits and suppressions.
2. `leadpilot:score-leads`: Runs hourly to score any newly discovered leads with the 100-point algorithm.
3. `leadpilot:sync-lead-sources`: Runs daily at 02:00 UTC to refresh source monitors.

---

## 5. Security & Legal Compliance Protocols

### 5.1 Strict Suppression Enforcement
- The system enforces a live suppression check right before sending (`Suppression::isSuppressed`).
- Hard bounces, spam complaints, and unsubscribe requests are automatically added to the `suppressions` table.
- Suppressed emails are completely barred from campaign recipient draft creation and dispatch.

### 5.2 Mandatory Campaign Approval Gate
- Automated campaigns do not send upon creation.
- A campaign remains in `draft` status until an Administrator reviews prospect list and clicks **Approve & Launch**.
- Administrators have an **Emergency Killswitch** button that immediately halts all active and queued dispatches.

### 5.3 Daily Quotas & Sending Throttles
- Each campaign enforces a configurable daily volume cap (`daily_limit`).
- Recipients exceeding the daily cap are tagged as `queued` and carried over to the next dispatch cycle.

### 5.4 Data Protection & Audit Trails
- All sensitive API keys are encrypted at rest via Laravel's encryption engine and masked in the UI.
- All critical actions (logins, credential updates, campaign approvals, lead stage changes, exports) write non-destructive records to `audit_logs`.
