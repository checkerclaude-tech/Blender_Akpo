# Daily reports, department heads and task statuses

## Installing the update
1. Upload the files from `aerosub-office-reports-update.zip` over your site (same folder structure). Nothing else changes: `api/config.php` is untouched.
2. Sign in as Super Admin and open `https://YOUR-SITE/upgrade.php` once. It adds the new tables and columns and is safe to run again.
3. Open **Departments** (new menu item, Super Admin only). Pick a **Head of department** for each department and add the people who belong to it.

## What's new
**Task statuses** (the existing field, so every old task keeps working)

| You asked for | In the app | Notes |
|---|---|---|
| Open / ongoing | Open / ongoing | was "In progress" |
| Not yet started | Not yet started | was "To do" |
| Deferred | Deferred | |
| Canceled | Canceled | only the person who gave the task, a department head, or the admin can cancel |
| Completed | Completed | was "Done" |
| Transferred | Transferred | set by the **Transfer** button: the task is closed as Transferred and a fresh copy opens for the new person |
| (kept) Blocked | Blocked | existing status, still available |

**Reports** (menu: Reports)
- Everyone: submit a daily report on a task assigned to them (status, % progress, hours, summary, blockers). Submitting again the same day updates it. A report can move the task to Deferred (needs a new date), Blocked (needs the reason) or Completed.
- Dashboard: status cards, the day's reports, who has **not reported yet**, a 7-day trend, per-person table, date picker, department and person filters, Print.
- **Department head** (set by the Super Admin): sees every report in their department, can comment on and share reports, and can give tasks to, transfer tasks between, and cancel tasks of their department's people.
- Managers see the same for their own departments. Super Admin and Founder see everything (Founder is read-only).
- Anyone a report is shared with can read it and add comments.

## Files
New: `api/lib/reports.php`, `assets/js/reports.js`, `assets/css/reports.css`.
Changed: `api/index.php`, `api/lib/{core,migrate,pipeline,taskfiles,agent_owner_views}.php`, `upgrade.php`, `assets/app.js`, `index.html`, `sw.js`.
New tables: `daily_reports`, `report_comments`, `report_shares`. New columns: `departments.head_id`; `tasks.status_reason`, `tasks.transferred_to_id`, `tasks.transferred_from_id`.
