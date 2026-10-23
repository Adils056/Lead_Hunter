# LeadPilot AI — REST API v1 Specification

Welcome to the **LeadPilot AI v1 REST API**. This API provides programmatic access to companies, leads, buying intent signals, automated AI research synthesis, campaigns, and integration telemetry.

All API routes are prefixed with `/api/v1` and return JSON formatted responses conforming to consistent status and payload structures.

---

## 1. Authentication & Headers

Requests should supply the following headers:
```http
Accept: application/json
Content-Type: application/json
```
*Note: Public endpoints in demo environment are open; production environments support Sanctum Bearer tokens (`Authorization: Bearer <token>`).*

---

## 2. API Endpoints

### 2.1 Analytics & Dashboard
#### `GET /api/v1/dashboard`
Returns high-level platform KPIs calculated directly from the live database.

**Response Example (`200 OK`):**
```json
{
  "status": "success",
  "data": {
    "total_companies": 14,
    "total_leads": 38,
    "qualified_leads": 24,
    "running_campaigns": 2,
    "buying_signals_active": 19
  }
}
```

---

### 2.2 Companies
#### `GET /api/v1/companies`
Retrieve paginated list of prospect companies.
- Query Parameters:
  - `per_page` (int, default: 15)
  - `page` (int, default: 1)

#### `GET /api/v1/companies/{id}`
Retrieve a single company profile with associated leads and buying signals.

**Response Example (`200 OK`):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "Apex Cloud Systems",
    "domain": "apexcloud.io",
    "country": "United States",
    "industry": "Financial Technology",
    "size_range": "51-200",
    "tech_stack": ["Laravel", "Vue.js", "AWS", "PostgreSQL"],
    "leads": [...],
    "buying_signals": [...]
  }
}
```

---

### 2.3 Leads & Timeline
#### `GET /api/v1/leads`
Retrieve paginated prospect leads with optional filtering.
- Query Parameters:
  - `country` (string, e.g. `United States`)
  - `lifecycle_stage` (string, e.g. `qualified`)
  - `min_score` (int, e.g. `70`)
  - `per_page` (int, default: 15)

#### `GET /api/v1/leads/{id}/timeline`
Retrieve comprehensive chronological history for a specific lead, including scoring history, outreach attempts, tasks, meetings, and inbound replies.

#### `POST /api/v1/leads/{id}/rescore`
Trigger a real-time recalculation of the 100-point transparent scoring model for a lead.

**Response Example (`200 OK`):**
```json
{
  "status": "success",
  "data": {
    "lead_id": 4,
    "overall_score": 89,
    "priority": "high",
    "breakdown": {
      "explicit_request": 28,
      "technical_hiring": 18,
      "verified_growth_evidence": 8,
      "decision_maker_identified": 15,
      "service_relevance": 10,
      "contact_verification_freshness": 10
    }
  }
}
```

---

### 2.4 Buying Intent Signals
#### `GET /api/v1/buying-signals`
Retrieve active market intent signals (developer hiring, RFPs, funding events, tech migrations).

**Response Example (`200 OK`):**
```json
{
  "status": "success",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "signal_type": "project_requirements",
        "title": "Turnkey Laravel SaaS Portal RFP",
        "evidence_text": "Public procurement notice seeking senior development agency partner.",
        "confidence": 95,
        "relevant_service": "Laravel and PHP development",
        "verification_status": "verified"
      }
    ]
  }
}
```

---

### 2.5 AI Research Synthesis
#### `POST /api/v1/ai/research`
Executes OpenAI GPT-4o-mini structured analysis with strict JSON schema and deterministic fallback on prospect companies.

**Request Body:**
```json
{
  "company_id": 1
}
```

**Response Example (`200 OK`):**
```json
{
  "status": "success",
  "data": {
    "business_summary": "Apex Cloud Systems provides B2B automated compliance software across North America.",
    "industry_classification": "Financial Technology",
    "potential_software_needs": [
      "Dedicated senior backend engineering bandwidth",
      "API architecture modernization and database performance tuning"
    ],
    "recommended_agency_service": "Laravel and PHP development",
    "supporting_evidence": "Based on 3 observed buying signals: Senior Laravel Developer hiring; Public RFP",
    "missing_information": "Internal engineering budget and tech lead decision-making cycle require discovery confirmation.",
    "confidence_level": "High",
    "suggested_next_action": "Initiate tailored introduction addressing identified laravel and php development requirements.",
    "personalized_outreach_angle": "Reference proven track record delivering Laravel and PHP development with zero onboarding lag.",
    "is_rule_based_fallback": false
  }
}
```

---

### 2.6 Webhook Event Ingestion
#### `POST /webhooks/{provider}`
Idempotent webhook receiver for transactional email providers (`resend`, `sendgrid`, `mailgun`, `smtp`).
- Supported event types: `delivered`, `bounce`, `hard_bounce`, `complaint`, `unsubscribe`, `reply`.
- Handled idempotently via `idempotency_key` or `event_id`.
- Automatically enforces suppressions on hard bounces and spam complaints.
