# Aerosub Insight → Office executive dashboard (API key)

Office reads Insight's own Analytics API, as described in the engineer's *Aerosub Insight Analytics API: Integration Guide*.

- **URL:** `GET https://insight.aerosub.co/api/v1/integrations/analytics`
- **Header:** `x-api-key: <key>` (no login, no token exchange, no parameters)
- **Limit:** 100 requests a minute, shared by every caller. Office makes **one** request per refresh (every 30 minutes by default) and shares the answer between all 16 numbers.
- The key is only ever used by the Office **server** (stored encrypted); it never reaches the browser.

## What Office shows

| Office number | Insight field |
|---|---|
| Users, Portfolios, Projects (platform totals) | `totalUsers`, `totalPortfolios`, `totalProjects` |
| Reports (all / generated / in draft / failed) | `reportsByOrg[]`: `total`, `generated`, `draft`, `failed` |
| UT plans (all / generated / in draft) | `utPlansByOrg[]`: `total`, `active`, `draft` (Insight's dashboard calls `active` "Generated") |
| Timelines (all / published / in draft / archived) | `timelinesByOrg[]`: `total`, `published`, `draft`, `archived` |
| Storage used (MB), Files stored | `storageByOrg[]`: `total_mb`, `file_count` |

Per-organisation numbers are matched by organisation name (`org_name`). Organisations with `org_status: "archived"` are left out; suspended ones are kept. An organisation missing from a list has zero of that thing. Unknown extra fields are ignored. Only numbers and organisation names are stored.

## Set up

Executive → Manage sources → **Connect Insight**, paste the API key, Save. (If Insight was connected before this update, `/upgrade.php` moves it to this address and these numbers; the saved key is kept.)

If Insight answers 401, the key is missing or wrong; 429 means the rate limit was hit (Office retries at the next refresh); any other error keeps the last numbers on screen.
