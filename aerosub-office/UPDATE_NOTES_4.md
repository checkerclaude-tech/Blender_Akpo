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

## Team performance now counts weekly-report entries
* Items people enter in their weekly reports are counted alongside tasks: **Delivered** = completed, **In view** = in progress, **Nil/Lost** = lost. Rows that are simply a task from the task list are not counted twice, and an item repeated in several weeks counts once. The leaderboard has a new "From weekly reports" column, and a note shows how many were added. Drafts count too.
* Fixed: the "‹ Reports" button on the weekly report page now goes back to Reports.

## Weekly report ⇄ tasks
* Rows typed into a weekly report that are not on the task list become **real tasks** of the person when the report is **submitted** (and when a submitted report is edited later). They show in Tasks, the daily-report pickers and the analytics. Report status follows for tasks created this way (Delivered = done, In view = in progress, Nil/Lost = canceled); tasks that already existed are linked, never changed or duplicated.
* **Completed tasks appear in the report:** tasks assigned to you that were completed in the week, and tasks you assigned to others that they completed (shown with "Assigned to NAME"). Tasks completed after you last saved are brought into the saved sheet.
* Creators can edit their own reports: a daily report you wrote can be edited at any time; only brand-new reports are limited to the last 3 days.

## Approvals and signatures (Finance documents)
* Open any commercial, proforma/quotation or invoice → **Approval & signature** → **Request approval…**. Choose the chain: reviewers first, then the signer(s). The suggestion follows the chain of command (your department head, the Finance head, then the Founder/CEO).
* Each person acts in turn and gets a notification and an e-mail from the requester's name with a link: **Approve review** (or **Approve and request signature**), **Sign** (draw, type your name, or use your saved signature) or **Revert** with a reason, which goes back to the author. The author can fix and ask again (history is kept) or withdraw the request.
* The document is **locked** while in the chain and after signing. A fingerprint of the content is checked at every step, so what is signed is exactly what was reviewed. Signatures are printed on the "For Aerosub" line of invoices/proformas and at the end of commercials, with name, title, date and reference.
* Approvers do not need the Finance tab. A new **Approvals** page lists what waits for you, your requests and what you did. The Root Admin's Access page can still set Finance to Read only: that blocks requesting but not reviewing/signing.

## Sharing tasks
* The note box is now a full text area (up to 8,000 characters) with **attachments** (images and documents, also kept on the task) and **links**. The message, links and files show in the task's activity.
* E-mails show the sender's real name on the office no-reply address (replies go to the sender), include the message, links, attachments and a link to the task or report. The receiver also gets the in-app notification. Weekly-report e-mails use the same sender name.

## Access: Edit or Read only
* On the Root Admin's **Access** page each tab is now Default / **Edit** / **Read only** / Off. Read only means the person can open and use the tab (look, search, print, download, take courses, play games) but creating, changing or deleting is refused by the server, and a note is shown at the top of the tab.

## Staying signed in (web and installed app)
* People stay signed in for **48 hours after they last used the app**, and are asked to sign in again only after 48 hours of not opening it. Each use renews the 48 hours. The setting `'session_hours' => 48` can go in `api/config.php`; add `'session_absolute' => true` to count 48 hours from the moment of sign-in instead.
* Two things carry it: a persistent session cookie and a remember-me token (stored hashed in the database, `HttpOnly`, `Secure` on https, `SameSite=Lax`, replaced with a new value every time it is used). If the phone or browser drops the session when the app is closed, the token signs the person straight back in.
* Signing out removes it. Changing or resetting a password signs out every other device (the one you are on stays). A deactivated or archived account cannot be restored. A token that is replayed after it was already used wipes that person's tokens.
* Run `/upgrade.php` once (adds the `remember_tokens` table).

## Reports analytics: weekly items, range, filters, search
* **Weekly-report items count in the Reports dashboard** next to tasks: Delivered = Completed, In view = Open / ongoing, Lost = Canceled (items that are not already tasks; once a report is submitted its rows become tasks and are counted as tasks). Tapping a status card lists the tasks and, under "From weekly reports", the items (with person, week and a link to that report).
* **Department heads see it per member:** the People table has a new "Weekly" column (delivered ✓ / in view ⏳ / lost ✗) for every member, and the person filter shows one member's tasks, daily reports and weekly items. Heads can see members' saved weekly reports, including drafts. Staff see only their own.
* **New controls on Reports:** time range (a single day, last 7 or 30 days, this week, this month, or a custom from–to range), a **search** box (tasks, reports, people, weekly items), and filters for department, person, priority and "Tasks only / Weekly report items only", with a "Clear filters" button. The daily activity chart follows the range.
* **Team performance** also has a custom date range (up to a year) and a people search.

## Work week (set by the Super Admin) and counting weeks
* **Admin → Work week** (Super Admin or Root Admin): choose the day a week **starts**, the day it **ends**, and which days are **working days** (for example Sunday to Thursday with Sun–Thu as working days). Until it is changed it is Monday to Friday.
* It drives: the weekly report's week (start and end dates), "This week" in the Reports range selector, combined team reports, the number of working days used for reporting consistency, and who is expected to report on a given day (nobody is expected, and nothing is listed as "not reported", on a day off).
* **Weeks are counted:** Reports and Team performance show the number of weeks in the chosen period, and for every member how many weekly reports they submitted ("Weeks": 3 / 4). 
* Reports already saved keep the week they were written for; the new week applies from now on. Run `/upgrade.php` once (adds the setting).

## Meetings: invite a whole department, calendar invites (.ics) with alerts
* **New task → Type: Meeting** now has **Location / meeting link** and **Invite people**. One button adds a whole department ("+ Whole Engineering team"), its **head included**; people can also be searched and ticked one by one. Anyone (not only managers) can invite colleagues to a meeting they create.
* Everyone invited gets an **email with an `invite.ics` attached** (from the organizer's real name, replies go to them) and an **"Add to my calendar" link**, plus the in-app notification. Opening the invite adds the meeting to Google, Apple or Outlook calendar on a phone or computer, with **alerts 1 hour and 10 minutes before**. Times are sent in UTC so every calendar shows the right local time.
* **Changes follow:** moving the time, changing the place or title sends an **updated invite** (same meeting, calendar replaces the old one); cancelling a meeting, or deleting one that has not happened, sends a **cancellation** that removes it from calendars. Adding someone later invites only that person. Sharing a meeting task also attaches the invite.
* On the meeting itself: **Add to my calendar**, **Copy calendar link**, and **Subscribe to all my meetings** (a personal calendar feed that keeps the phone calendar in sync). These links work without signing in (the calendar app cannot sign in); each is personal and cannot be guessed.
* Run `/upgrade.php` once. Emails need your mail set up (SMTP in Campaigns settings, or the server's mail).

## Courses: modules, sections, progress, learning cards, short quizzes, better exams, image signatures

**After unzipping, open `/upgrade.php` once as Super Admin.** Your existing flat lessons are moved automatically into a module called "Lessons" (one section per lesson). Nothing is lost.

- **Course > Modules > Sections.** The Super Admin adds modules, then sections, and fills each section with parts: notes, pictures, YouTube/Vimeo video, links, PowerPoint slides, **learning cards** (flip cards, shuffle, keyboard friendly) and **short quizzes** (instant feedback and an explanation). Everything can be edited, deleted and moved up/down.
- **Progress counters.** Every module shows "2 / 3 sections", every section shows its parts and quiz status, and the course shows an overall percentage with a Continue button. A section is completed with "Mark complete"; a section with a short quiz needs the quiz answered first.
- **Exam.** Live counters (answered / not answered / flagged), a **question map** (tap a number to jump to that question, colours show the state), filters (All, Not answered, Flagged, Answered), a flag button on each question, and a warning on submit that lists the unanswered question numbers. Answers autosave and resume.
- **Editable questions of every kind.** Each question can have extra text, a picture (link or upload), a video and a hint. Types: multiple choice, **puzzle: put in order** (2–8 steps) and **puzzle: match pairs** (2–6 pairs). Questions can be edited, duplicated and re-ordered. Results show puzzles with the correct order / pairs.
- **Signatures.** When signing a document you can now also **upload a PNG** of your signature (a transparent PNG is best; white backgrounds are made transparent). It can be saved as your signature for next time.

## Speed
- `.htaccess` now compresses pages, scripts, styles and API answers, and lets the browser keep static files for an hour (replace your `.htaccess` with this one).
- Read-only API calls release the PHP session lock immediately, so the screen's parallel requests no longer queue behind each other.
- Opening a course no longer runs a database schema lookup.

## Sharing reports: rich messages, pictures, documents, links and email
**Open `/upgrade.php` once** (adds the tables for share messages).
- Sharing a daily or weekly report now has a message box with **bold, italic, underline, lists, headings and links**, **pictures inside the text** (insert, paste or drag one in), **attached documents** (PDF, Word, Excel, PowerPoint, text, CSV, zip, images; up to 8, 8 MB each, 14 MB together) and **links**.
- **Email:** tick "Also email it". The email shows the sender's name, the report, the formatted message with pictures shown inline, the attached documents (and the Excel file for weekly reports) and a link back. Replies go to the sender. Recipients also get an in-app notification.
- Recipients see the messages inside the report (daily: report window, weekly: under the sheet), can open pictures and download documents, and the sender (or a Super Admin) can remove a message.
- Only the sender, the recipients, the report owner and Super Admins can read a message or download its files. Pasted text is cleaned so scripts cannot get in.

## Finance, mobile and printing round
**Open `/upgrade.php` once** (adds the Requisition document type).
- **Mail notification blank screen fixed.** A new email used to rebuild the page frame without redrawing the screen. Now only the little number on the menu changes.
- **Finance on mobile.** It now appears under More for everyone who has Finance access (before, only Super Admin and Founder saw it).
- **Take back my signature.** A signee can remove their own signature at any time (Approval card > "Take back my signature…"), with an optional reason and a choice to tell the others.
- **Edit finance documents.** The author, Finance and everyone in the approval chain can edit and save, even after review or signing. Saving a change clears existing signatures (they are given on the old text), renews the fingerprint, and records it in the history. The editor can add a note. The others are told about the change; a **signee** can choose to stay quiet.
- **Requisitions.** New document type with its own tab in Finance (All · Commercials · Proformas · Invoices · Receipts · Requisitions), numbered REQ-YYYY-NNNN, printable, and it goes through the same review and signature chain.
- **New Proforma Invoice template.** New page art (title in the header), "Sales Rep ID", SUBTOTAL / Discount / SUBTOTAL / VAT / TOTAL, "Leadtime", bank details then the two signature lines on page 2.
- **Screen sleep (phones only).** After a quiet moment: clock, the task you are on, the latest notification and a scrolling news bar. It follows the phone when rotated; touch to wake. Set the delay or switch it off under More > Screen sleep.
- **Printing fixed.** Team performance (and the daily reports page) printed blank because a print rule hid everything except finance documents. They print properly now.
- Speed files from the previous round are included.

## Inbox automation (Campaigns > Inbox automation)
**Open `/upgrade.php` once** (adds the tables). Connect the info@ mailbox under Mail first.
- **Scan inbox now** reads the mailbox. Each new email is sorted (quote request, service enquiry, meeting, invoice/payment, support, general) with keyword rules you can edit. Automatic mail (no-reply, newsletters, mailing lists, out-of-office, our own team) is skipped and never answered.
- **CRM and funnel:** the sender becomes a contact, and a company is created from the email domain (free mailboxes such as gmail get an "individual" entry). Business enquiries move the company forward in the Pipeline (never backwards).
- **Project tracker:** action tasks are created per category (e.g. Send proposal, Prepare and send proforma, Send company profile, Send pitch deck, Schedule follow-up), linked to the company and contact, assigned to the Marketing head or the company owner.
- **Approval:** a draft reply is written and Marketing gets a task to approve it. "Review & reply" lets you edit it, tick documents to attach and send it from the info@ mailbox (a copy goes to its Sent folder). Or close it out without replying.
- **Documents:** reply attachments come from **Documents** and **My Documents** (files whose names match "profile", "pitch", "deck" are suggested and pre-ticked), or add a file from your device.
- **Document creation:** each business email has + Proforma / + Invoice / + Commercial buttons that open the finance form already filled with the sender's company, email and name, linked to the approval task.
- **Optional instant acknowledgement** ("we received your email") for business enquiries, off by default, once per sender per day.
- **Settings & rules:** categories, keywords, tasks, funnel stage, suggested documents and reply text are all editable; also who approves, which mailbox the scheduled task (`cron.php`) scans, and a never-process list.
- Sorting is rule based (keywords), so it costs nothing and nothing leaves your server. It can be switched to an AI model later if you want deeper reading of emails.
