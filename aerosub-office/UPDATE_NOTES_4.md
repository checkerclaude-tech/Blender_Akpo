# Aerosub Office – Update 4 (privileges, My Documents, install as app)

Install: unzip over the site (keep your `api/config.php`), sign in as Super Admin / Root Admin, open `/upgrade.php` once.
Check its output: it must say `Root Admin account: dev.aaerosub@…`. If it says "NO Root Admin yet", add
`'root_admin_email' => 'your.root@address',` to `api/config.php` (the account must be a Super Admin) and open `/upgrade.php` again.

## Root Admin and privileges
* The Root Admin (dev.aaerosub) has a new **Access** tab: per person, every tab is Default / On / Off. The server enforces it, not just the menu.
* **Team performance** is off for everybody except the Root Admin until the Root Admin turns it on for a person.
* **Logs** (activity log) are visible to the Root Admin only (Logs tab with search, filters and CSV). The Root Admin and Super Admins can both add and archive/remove users. Only the Root Admin can change or remove the Root Admin account.
* **Documents**: Super Admins only (Root Admin can still switch it on for a person, except see My Documents).
## Reports privacy
* Daily and weekly reports: a person sees their own; a department head sees their department; Super Admin/Founder see all; anything else only when it was shared with them. A manager role alone no longer opens other people's reports.
## My Documents (new tab)
* A private vault for each person: files (12 MB each, 300 MB total), notes and links, folders, search, camera capture on phones. Nobody else can open it, including Super Admins and the Root Admin (no admin route exists). Files are deleted with a purged account.
## Install as an app + notifications
* Android/Chrome/desktop: an "Install Aerosub Office" banner; iPhone/iPad: a Share → Add to Home Screen guide (Safari). Both also on My profile.
* After sign-in people are asked to turn on notifications. With permission, the server sends real Web Push (task assigned, report shared, game turn…) even when the app is closed. iPhone needs iOS 16.4+ and the app added to the Home Screen first. Profile has a "Send a test notification" button.
* Requires HTTPS (needed by every browser for installs/push) and PHP openssl + curl.

## Fix: question spreadsheet import
* Importing the "ROV Essentials 50 Questions" sheet only added 2: in 48 rows the correct answer was typed in the *Correct Answer* column but was not repeated under Answer A–E, so the import could not tell which choice was right. The import now adds such an answer as one of the choices (spread over A–E; if all five were already full, the last wrong choice is dropped) and tells you how many questions it adjusted. 50/50 import.

## Executive dashboard (new: C-suite view of the other Aerosub platforms)
* **Who sees it:** the Founder/CEO by default, plus anyone the Root Admin switches on in Access → "Executive dashboard (C-suite)" (for example the CFO, COO). Super Admins can set it up (menu: "Executive setup") but see no numbers unless the Root Admin also gives them the tab.
* **What it shows:** anonymous totals only (for example users and earnings), **per organization** and **combined** for each platform, and **all platforms combined**. 30-day trend lines and change against a week ago.
* **Privacy:** only numbers and organization names are ever stored. Fields that look personal (e-mail, phone, name, address, password, card, salary…) are refused when you set up a number; organization names that look like an e-mail or phone number are shown as "Unnamed organization"; the connection test never shows the raw answer of the other platform; the access token is stored encrypted and is never sent to a browser.
* **Connect Insight:** Executive → Manage sources → Connect Insight (https://insight.aerosub.co). Two ways, pick what Insight supports:
  1. *We fetch:* give the address, token and where the numbers are in Insight's JSON answer (address, list of organizations, name field, number field). "Test connection" lists the numbers it can see.
  2. *Insight sends:* Insight posts `{"organizations": {"Acme Ltd": {"users": 120, "earnings": 5600.5}}}` to `/api/exec/ingest` with the key shown once when you save.
  Insight needs a small read-only summary endpoint (or the push call) that returns counts per organization and no personal records. If cron.php runs on your site, sources also refresh on schedule.
* Needs HTTPS to the other platform. Run `/upgrade.php` once after unzipping.

## Weekly reports: auto-save, Submit, and department-head review
* **Auto-save:** everything typed on the weekly sheet is saved automatically (about 1.5 seconds after you stop, when you change week, and when you leave the page). Reopening the week brings back exactly what you wrote ("Rebuild from tasks" is available with `?fresh=1`). A status line shows "Draft saved…", "Submitted…" or "Unsaved changes…".
* **Submit report:** when the week is done, press **Submit report**. The department head(s) of the person's department(s) get a notification with a link. After submitting you can still fix things; changes keep saving. Sharing a report also counts as submitting it.
* **Department heads:** open "Submitted by my team" (or the notification), read the report, write **comments and the Management review** column (auto-saved, with a Save button), **share** it with others and download Excel/print. They cannot change the person's tasks, status or other cells, and the person is notified of the comments. The person's later edits never erase a management review.
* **Privacy:** heads of department (and administrators) can open a member's saved report any time, with no need for it to be shared; a draft is read-only to them and they can comment once it is submitted. Colleagues and managers who do not head the department cannot. Founder accounts stay read-only.

## Heads can pick a member, and combine reports
* On Reports → Weekly report, a head chooses a member's name from the person list and sees what that member has filled in for the week (draft or submitted).
* **Combine team reports…** (heads/administrators): tick the members and press Create. Their reports (or, for people who have not saved one, their tasks) are merged into ONE weekly report, each row starting with the person's name. The head can edit it (auto-saved), **share** it (notification + email with the Excel file) and download or print it as one sheet. It is stored separately, so it never overwrites anyone's own weekly report. Lists: "Combined by me", "My team's reports".
* A head can share a member's own report only once the member has submitted it.
