# SEO tab for Aerosub Office

Adds **SEO** to the menu, right after Campaigns, for everyone who can open Campaigns. It shows what the daily SEO run does on aerosub.co:

- **Approvals:** changes the SEO run wants to make. A manager, the Founder or the Super Admin presses Approve or Reject. A rejection needs a short reason, which the SEO run reads.
- **Changes made:** every change the SEO run made to the website, with a link.
- **Rankings:** position changes for search terms.
- **Reports:** the weekly report.
- **Competitors & schedule:** add or remove competitors, set the daily run time and pick the weekly report day. The SEO run reads these at the start of every run. A new run time applies from the next day.

## Install (about 5 minutes)

1. **Back up first.** In cPanel File Manager, download `office/api/index.php`, `office/api/lib/agent_token.php`, `office/api/lib/agent_admin.php`, `office/upgrade.php` and `office/index.html`. These are the only existing files this update changes.
2. Upload the files in this zip into the `office` folder, keeping the same folder structure. `api/config.php` is not in the zip and is not touched.
3. Sign in to Office as Super Admin and open `https://office.aerosub.co/upgrade.php` once. It ends with "SEO autopilot feed ready." It is safe to run again.
4. Open **SEO** and click **Connect autopilot**, then **Create key**. Copy the key; it is shown only once. Only the Super Admin named as the agent owner in config can create it.
5. Give the key to the SEO run. In the Claude project settings, under the cloud environment, add an environment variable `OFFICE_SEO_TOKEN` with the key as its value. Also add `office.aerosub.co` to Allowed domains.

To switch it off: open SEO, click Connect autopilot, and revoke the key. To remove the tab, put back the 5 backed-up files. The two new tables (`seo_entries`, `seo_settings`) can stay.

## Files

New: `api/lib/seo.php`, `api/lib/migrate_seo.php`, `assets/js/seo.js`, `assets/css/seo.css`, `SEO_SETUP.md`.
Changed: `api/index.php` (routes `seo` and `seo-agent`), `api/lib/agent_token.php` and `api/lib/agent_admin.php` (allow key scope `seo`), `upgrade.php` (runs `migrate_seo`), `index.html` (loads the new script and style).
Database: new tables `seo_entries` and `seo_settings`. `agent_tokens.scope` also accepts `seo`.

## Security

- The SEO key works only on `/api/seo-agent/...`. The research and personal agent routes refuse it, and the SEO key can't reach anything else in Office. Like the other agent keys, it is stored only as a hash and is rate-limited.
- The SEO run can add entries, read decisions and settings, and mark an approved item as done. It cannot approve anything itself.
- Signed-in routes use the normal session, CSRF check and Campaigns access rules, including "read only".

## API for the SEO run (header `X-Agent-Token`)

| Call | What it does |
|---|---|
| `POST /api/seo-agent/entries` `{kind, title, body?, url?, data?, ext_id?}` | `kind` is `ranking`, `change`, `approval` or `report`. Sending the same `ext_id` again does not create a duplicate. |
| `GET /api/seo-agent/decisions?since=N` | Approvals decided after decision number N |
| `POST /api/seo-agent/entries/{id}/done` `{note?}` | Marks an approved change as applied |
| `GET /api/seo-agent/settings` | Competitors, run time, report day |

Tested on a copy of this Office version with MariaDB 10.11 and PHP 8.3: 32 API checks covering access, keys, approvals and settings, plus a browser check on desktop and phone with no script errors.
