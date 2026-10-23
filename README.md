# LeadPilot AI

### AI-Powered B2B Lead Generation, Sales Intelligence, Outreach Automation & CRM Platform

LeadPilot AI is an enterprise-grade, production-oriented sales automation and lead intelligence platform designed for modern software development agencies. Built with **Laravel 12**, **MySQL**, **Livewire 4**, **Bootstrap 5.3**, and **Chart.js**, it bridges the gap between raw market signals and closed deals.

---

## 🌟 Key Capabilities & Architectural Highlights

### 1. 100-Point Explainable Lead Scoring Engine
- **No black boxes**: Every lead receives a transparent score (0–100) with a mathematical breakdown and natural language evidence summary.
- **Criteria Evaluated**:
  1. *Explicit RFP / Procurement Project Requirement* (Up to 30 pts)
  2. *Active Technical Hiring* (e.g. Senior Laravel/Full-Stack Developers) (Up to 20 pts)
  3. *Verified Business Growth / Funding Evidence* (Up to 15 pts)
  4. *Direct Decision Maker Confirmed* (CTO, VP Eng, CEO, Founder) (Up to 15 pts)
  5. *Core Agency Service Fit* (Laravel, React, CRM/ERP, E-Commerce, APIs) (Up to 10 pts)
  6. *Contact Deliverability & Signal Freshness* (Up to 10 pts)

### 2. Deep Buying Intent Signals
- Tracks market signals across target geographies (Pakistan, United States, United Kingdom, Canada, Australia, Germany / EU).
- Categorizes RFPs, hiring bursts, legacy modernization needs, tech stack migrations, and funding rounds.

### 3. OpenAI GPT-4o-mini Research & Structured Synthesis
- Structured JSON outputs with strict schemas for consistent company intelligence.
- **Prompt Injection Defense**: Sanitizes untrusted website content before prompt synthesis.
- **Token & Cost Telemetry**: Logs prompt tokens, completion tokens, and real dollar costs into `usage_logs`.
- **Deterministic Rule-Based Fallback**: Fully functional even when API keys are absent or rate limits are reached.
- **Inbound Sentiment Classifier**: Classifies incoming prospect replies (`meeting_request`, `positive`, `objection`, `unsubscribe`, `neutral`) and auto-updates CRM lifecycle stages.

### 4. Campaign Outreach Automation & Compliance
- **Mandatory Approval Gate**: Campaigns cannot send emails without explicit Administrator review and sign-off.
- **Pre-Send Live Suppression Check**: Validates `suppressions` table milliseconds before delivery.
- **Emergency Killswitch**: Instantly halts all active dispatches across the platform.
- **Daily Quotas & Anti-Spam Throttling**: Daily sending caps per campaign with gradual ramp-up.
- **Safe Admin Test Email**: Lets you preview rendered drafts delivered directly to an admin inbox without contacting leads.

### 5. Full CRM Sales Pipeline & Kanban
- 12 CRM lifecycle stages: `new`, `researching`, `qualified`, `ready_for_review`, `contacted`, `replied`, `interested`, `meeting_booked`, `proposal_sent`, `negotiation`, `won`, `lost`.
- Drag-and-drop or one-click stage progression with full audit trails.
- Task scheduler, meeting tracking, and deal revenue forecasting.

### 6. Analytics & Conversion Funnel
- Real-time conversion funnel metrics with division-by-zero protection.
- Cost-per-qualified-lead and cost-per-meeting metrics.
- Exportable CSV reports for sales meetings and executive reporting.

---

## 🚀 v4 — Autopilot (Oct 2026)

- **Autonomous cycle every 15 minutes:** discovery, enrichment, scoring, outreach, follow-ups, replies and monitoring.
- **Approvals only for exceptions:**
  - Autonomy policy per action, plus approvals with escalation.
  - In-app and email notifications with honest delivery status.
  - Incidents that pause only the affected workflow.
- **Free-first data:**
  - OpenStreetMap, UK Find a Tender and EU TED, through a provider registry with fallback.
  - Circuit breakers and budgets.
  - A cost ledger that separates actual from estimated cost.
- **Mail handling:**
  - IMAP reply and bounce sync with a built-in client.
  - One-click unsubscribe (RFC 8058) and real Message-IDs.
  - Threaded follow-ups that stop on any reply.
- **Learning and verification:**
  - A search allocator that learns from qualified leads and positive replies.
  - Setup & Health dashboard and `php artisan leadpilot:validate-live` for real-data verification.

See [docs/AUTOPILOT_V4.md](docs/AUTOPILOT_V4.md).

## 🎯 v3 — Lead Hunting Engine (Oct 2026)

- **ICP-driven hunting** via Google Places (local businesses), UK Contracts Finder (live tenders), a Greenhouse/Lever hiring watchlist, and Apollo.
- **Evidence-based website audit** with about 20 real checks, technology fingerprinting and public contact discovery.
- **Configurable heuristic scoring** with a calibration report.
- **Sales assistant:** recommends an offer, writes an email under 80 words from verified facts only, plans follow-ups, and classifies replies into 6 categories.
- **Integrity:** removed the fabricated domains, emails and fake tender data that the earlier code generated.

Details are in [docs/LEAD_HUNTING_ENGINE.md](docs/LEAD_HUNTING_ENGINE.md) and [docs/COMPETITOR_RESEARCH.md](docs/COMPETITOR_RESEARCH.md).

## 🚀 Quick Start & Installation

### 1. Requirements
- PHP 8.2 or 8.3
- MySQL 8.0+ / MariaDB 10.6+
- Composer 2+

### 2. Local Setup
```bash
# Clone and enter directory
cd Leed_generation

# Install PHP dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Configure MySQL credentials in .env (DB_DATABASE=leadpilot_ai)
# Run migrations and seed complete demo ecosystem
php artisan migrate --force
php artisan db:seed --force

# Start local server
php artisan serve
```

### 3. Default Credentials
| Role | Email | Password |
|---|---|---|
| **Administrator** | `admin@leadpilot.test` | `password123` |
| **Sales Representative** | `sales@leadpilot.test` | `password123` |

---

## 🧪 Automated Test Suite

LeadPilot AI includes a comprehensive automated test suite with **33 feature tests and 127 assertions** covering authentication, RBAC, scoring, outreach delivery, suppressions, webhooks, analytics, and REST API v1.

Run the test suite:
```bash
php artisan test
```

### Test Coverage Summary:
- `Tests\Feature\AuthAndRbacTest`: Login, invalid credentials, account deactivation, sales vs admin policy enforcement.
- `Tests\Feature\CompanyAndLeadTest`: Domain normalization, duplicate prevention, CSV import column mapping.
- `Tests\Feature\LeadScoringTest`: 100-point transparent scoring engine, signal weights, rescore endpoint.
- `Tests\Feature\CampaignOutreachTest`: Approval gates, suppression exclusion, quota postponement, killswitch, test email.
- `Tests\Feature\WebhookAndReplyTest`: Idempotency deduplication, delivery receipts, hard bounce auto-suppression, reply classification.
- `Tests\Feature\AnalyticsTest`: Zero-division guards, conversion rates, CSV report download, CRM stage transitions.
- `Tests\Feature\ApiV1Test`: REST endpoints for dashboard, paginated leads, and AI synthesis.

---

## 🛠️ Background Automation & CLI Commands

```bash
# Dispatch due campaign emails with quota and suppression checks
php artisan leadpilot:dispatch-campaigns

# Run 100-point scoring algorithm across all leads
php artisan leadpilot:score-leads --force

# Sync active lead sources
php artisan leadpilot:sync-lead-sources

# Run continuous background worker
php artisan queue:work database
```

---

## 📂 Documentation Links
- [API v1 Reference](docs/API_DOCUMENTATION.md)
- [Deployment & Operations Guide](docs/DEPLOYMENT_AND_OPERATIONS.md)
