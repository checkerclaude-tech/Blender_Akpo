# Aerosub Insight → Office executive dashboard (API key)

The Executive dashboard in Aerosub Office reads the numbers from Insight's **Analytics** page. It authenticates with an **API key** (not a login token). Only numbers and organisation names are read; nothing personal is stored.

## 1. In Insight: one read-only address

`GET https://insight.aerosub.co/api/exec/analytics`
Header: `X-API-Key: <the key you create in Insight>` (give the key read access to this address only; Office keeps it encrypted).

Answer (JSON; the numbers are exactly the ones on the Analytics screen):

```json
{
  "generated_at": "2026-11-25T10:00:00Z",
  "totals": {
    "organizations_total": 7, "organizations_active": 3, "organizations_suspended": 4, "org_users": 13,
    "feedback_submissions": 0, "bug_reports": 0, "feature_requests": 0, "feedback_resolved": 0,
    "sla_breaches": 0, "oldest_open_query_days": 0,
    "storage_total_gb": 9.8, "storage_managed_orgs": 2, "storage_byos_orgs": 1, "storage_byos_unsynced": 0,
    "campaigns_active": 13, "campaigns_dormant_orgs": 1, "orgs_gone_quiet": 2,
    "reports_total": 78, "ut_plans_total": 8, "generation_failed": 23, "user_activation_rate": 84.6
  },
  "organizations": [
    { "name": "Total Energies", "storage_used_mb": 8908.8, "files": 2554, "open_queries": 0,
      "campaigns_total": 10, "campaigns_published": 8, "campaigns_draft": 2,
      "users_total": 13, "users_activated": 11, "days_since_last_activity": 0,
      "reports_generated": 55, "reports_draft": 0, "ut_plans_generated": 8, "ut_plans_draft": 0 }
  ]
}
```
Every field is optional; a missing one is simply left out. Organisation names that look like an e-mail address or phone number are masked by Office.

## 2. In Office

Executive → Manage sources → **Connect Insight**. The API key header is already `X-API-Key`; paste the key, check the address (`https://insight.aerosub.co`), and Save. Office reads Insight every 30 minutes (changeable) and keeps 30 days of history for the trend lines.

## Alternative: Insight pushes instead

If you would rather have Insight send the numbers: choose "They send us numbers" when adding the platform. Office shows a push key once. Insight then POSTs the same `totals` / `organizations` JSON to `https://office.aerosub.co/api/exec/ingest` with the header `X-Ingest-Key: <push key>`.
