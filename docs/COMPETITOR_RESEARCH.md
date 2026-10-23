# Competitor Research — B2B Lead Generation (checked 22 Oct 2026)

**Method.** I used web search and fetched official pricing, feature and API pages. Where official pages hid prices, I used third-party pricing articles and marked them **(3P)**. **Unverified** means no official source confirmed the fact. Vendor accuracy and database-size figures are the vendors' own claims.

## 1. Competitor matrix

| Product | Category | Data source | Standout feature | Entry price | Free tier | Source |
|---|---|---|---|---|---|---|
| Apollo.io | DB + sequencing | Own B2B DB | Data, sequences and deals in one tool | ~$49/user/mo annual (3P) | Free plan (3P: 75 credits/mo) | apollo.io/pricing |
| ZoomInfo | Enterprise data + intent | Own DB | Intent signals; warehouse delivery | Quote | Trial | zoominfo.com/pricing |
| Lusha | Contact DB | Own DB | Credits roll over | Not shown | 40 credits/mo | lusha.com/pricing |
| RocketReach | Contact lookup | 700M+ profiles (vendor claim) | Email + phone lookup | $329/yr (3P) | 5 lookups (3P) | salesintel.io (3P) |
| UpLead | Contact DB | Own DB | 95% email accuracy claim; 16k technologies tracked | $99/mo | 7-day trial | uplead.com/pricing |
| Cognism | EU-focused data | Own DB + Bombora intent | Verified mobile numbers | Quote | No | cognism.com/pricing |
| Seamless.AI | Real-time search | Unverified | "Autopilot" | $147/mo yearly (3P) | 50 credits (3P) | lindy.ai (3P) |
| Hunter.io | Finder + verifier | Web-sourced | Charges only for emails it finds | $49/mo | 50 credits/mo | hunter.io/pricing |
| Snov.io | All-in-one | DB + finder | 7-tier verification, warm-up | $39/mo | 50 credits/mo | snov.io/pricing |
| Leadfeeder/Dealfront | Visitor ID | IP-to-company | Intent score per company | €79/mo | 100 companies/mo | leadfeeder.com/pricing |
| Lead Forensics | Visitor ID | IP-to-company | Real-time alerts | Traffic-based | 1-week trial | leadforensics.com/pricing |
| RB2B | Person-level visitor ID | Identity graph (US only) | Slack "hot page" alerts | $79/mo | 150 company IDs/mo | rb2b.com/pricing |
| Factors.ai | Account intelligence | IP + G2/LinkedIn | Predictive account scoring | Not published | Lite tier | factors.ai/pricing |
| Clay | Enrichment orchestration | Waterfall over many providers | Claygent AI web researcher | ~$54–60/mo (its pages conflict) | 100 credits/mo | clay.com/pricing |
| Prospeo | Finder | Own DB | 50+ AI data fields | $49/mo (3P) | 100 credits (3P) | emelia.io (3P) |
| People Data Labs | Data API | Aggregated | Developer API | $98/mo | 100 credits | peopledatalabs.com/pricing |
| Dropcontact | Email enrichment | Algorithmic, "no database" | Pay only on success; GDPR | €79/mo | 50 credits | dropcontact.com/pricing |
| Instantly | Sending + leads | 450M+ DB | Unlimited mailboxes + warm-up; AI reply agent | $47/mo | "Start free" | instantly.ai/pricing |
| Smartlead | Sending | — | Mailbox rotation; white-label | $39/mo | Trial | smartlead.ai/pricing |
| lemlist | Multichannel | 650M+ DB | Email + LinkedIn + calls + WhatsApp | $55/user/mo | 14-day trial | lemlist.com/pricing |
| Reply.io | Multichannel + AI | DB credits | AI sequence generator | $166/mo (Unlimited) | 200 credits/mo | reply.io/pricing |
| Saleshandy | Sending + finder | 852M contacts | Hiring and tech-stack filters | $34/mo | 7-day trial | saleshandy.com/pricing |
| Outreach / Salesloft | Enterprise engagement | — | AI agents, forecasting | Quote | No | outreach.ai, salesloft.com |
| Outscraper | Maps scraper | Scrapes Google Maps | Cheap bulk local leads | $3 / 1,000 | 500 free | outscraper.com/pricing |
| **Google Places API (New)** | Official local data | Google | Licensed, reliable | Text Search Enterprise $35 / 1,000 | 1,000/mo (Enterprise) | developers.google.com/maps/billing-and-pricing/pricing |
| BuiltWith / Wappalyzer | Technographics | Crawls / fingerprints | Tech-filtered lead lists | $295 / $250 per mo | Limited lookups | builtwith.com/plans, wappalyzer.com/pricing |
| Amplemarket | AI multichannel | DB + signals | Job-change alerts | $600/mo (2 users) | Trial | amplemarket.com/pricing |
| LeadIQ | Prospecting | DB | Champion tracking | $200/mo | 50 credits | leadiq.com/pricing |
| Kaspr | LinkedIn extension | DB | Unlimited B2B emails on paid plans | €45/user/mo | 15 emails/mo | kaspr.io/pricing |
| D7 Lead Finder | Local-business finder | Undisclosed | Flags ads, pixel, mobile-friendliness | Not shown | 5 searches/day | d7leadfinder.com |
| HubSpot Breeze Intelligence | Enrichment + intent | HubSpot | Form shortening | **Unverified** | Unverified | — |

## 2. Features ranked for LeadPilot

| # | Feature | Value | Complexity | Op. cost | Status in LeadPilot |
|---|---|---|---|---|---|
| 1 | Local discovery via Places API, deduplicated on place_id, "no website" flag | H | M | M | **Built** |
| 2 | Website-weakness audit (mobile, HTTPS, CTA, tech, PageSpeed) | H | M | L | **Built** |
| 3 | Email waterfall (site crawl → pattern → SMTP verify) | H | H | M | Site crawl **built**. Pattern guessing deliberately **not** built (it fabricates addresses). Verification via existing Hunter integration. |
| 4 | Hiring-signal feed | H | M | L | **Built** (Greenhouse/Lever watchlist) |
| 5 | Explainable ICP + intent score | H | M | L | **Built** (v3, configurable, calibrated) |
| 6 | AI personalisation grounded in findings | H | M | M | **Built** (facts-only, number guard) |
| 7 | Sequences: 4–7 touches, 3–4 days apart, stop on reply | H | M | L | **Built** (cadence config + follow-up planner) |
| 8 | Deliverability guardrails | H | H | M | Existing (caps, suppression, kill-switch). Mailbox rotation and warm-up **not built**. Use Instantly or Smartlead for sending at volume. |
| 9 | Unified inbox + AI reply classification | M | M | M | Classifier **built**. Inbox via existing webhooks. |
| 10 | Pay-only-for-verified credit metering | M | L | L | Not built (single-tenant tool) |
| 11 | CRM sync / CSV / webhooks | M | L | L | Existing |
| 12 | Company-level visitor ID | M | H | H | Not built (low value for an agency site; privacy-sensitive) |

## 3. Cold-email benchmarks (vendor data; each defines "reply rate" differently)

- **Instantly 2026:** "billions" of emails sent in 2025.
  - Average reply rate 3.43%; top quartile 5.5%+.
  - 58% of replies come from step 1 and 42% from follow-ups.
  - The best sequences have 4–7 touches spaced 3–4 days apart and emails under 80 words.
  - Source: instantly.ai/cold-email-benchmark-report-2026
- **lemlist** (internal data, period not stated):
  - Reply rate by step: 4.5% → 3.75% → 2.75% → 2.1%.
  - Recommends 4–9 follow-ups.
  - Keep bounces under 3% and spam complaints under 0.3%.
  - Source: lemlist.com/blog/how-many-cold-email-follow-ups
- **Woodpecker** (sample not disclosed): reply rate is 9% with no follow-up and 13% with at least one. Source: woodpecker.co/blog/follow-up-statistics
- **Gong:** no primary cold-email benchmark found (unverified).
- **What LeadPilot uses:** under 80 words; follow-ups at +3/+4/+4/+5 days; at most 5 touches; stop on any reply.

## 4. API facts used in the build

- **Google Places Text Search (New):**
  - Pricing: Pro $32 / 1,000 calls (5,000 free per month); Enterprise $35 / 1,000 (1,000 free).
  - `websiteUri`, phone and rating fields put a request in the Enterprise SKU.
  - At most 60 results per query.
  - Only place_id may be stored indefinitely; lat/lng may be cached for 30 days.
  - Sources: developers.google.com/maps/billing-and-pricing/pricing; …/places/web-service/policies
- **PageSpeed Insights API:** a key is recommended. Quota is not on Google's page; a third party reports 25,000 requests/day. Check the quota in your Cloud Console.
- **Greenhouse** (`boards-api.greenhouse.io/v1/boards/{token}/jobs`) and **Lever** (`api.lever.co/v0/postings/{site}`): public, no auth needed.
- **Remotive:** attribution required, at most about 4 fetches per day, and **using listings to collect leads breaches its terms**. Disabled by default.
- **Adzuna:** the free key is for evaluation only; commercial use needs a licence. Not used.
- **UK Contracts Finder OCDS Search:** keyless. Parameters: `publishedFrom`, `publishedTo`, `stages`, `limit` (1–100), `cursor`. On HTTP 403, wait 5 minutes. Source: contractsfinder.service.gov.uk API documentation.

## 5. Limitations

- Many pricing pages are JavaScript-rendered or hide prices; those figures are 3P.
- Vendor pages sometimes contradict themselves (Clay, Instantly, Kaspr, RB2B).
- Benchmarks are vendor-produced and mostly reflect US/EU senders. None is specific to Pakistani agencies.
- I did not do a legal review of scraping Google Maps or LinkedIn. LeadPilot uses the official APIs instead.
