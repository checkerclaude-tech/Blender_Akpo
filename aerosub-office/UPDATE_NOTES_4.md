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

## iPhone layout fix
- Content no longer slides under the iPhone status bar or notch: the page now leaves room for the safe areas at the top, sides and bottom, so "+ New task" and the header are tappable.
- Nothing can be wider than the screen (this was making the bar at the bottom float and the right side get cut off). The bottom tab bar always fits the screen width.
- Course header stacks on phones (picture on top, text below) instead of squeezing the text into a thin column.
- Fields use 16px text on phones so iOS no longer zooms the whole page when you tap one.
- After installing: on iPhone, remove the app from the home screen and add it again (or Settings > Safari > Clear History and Website Data), because iOS keeps the old files.

## Requisition approval chain (internal spending)
A requisition now runs through three signatures, in order: the **requester signs**, then the **finance head signs**, then the **top head approves and signs**.
- "Request approval" suggests the chain automatically (requester = whoever raised it, Finance head = head of the Finance department, top head = the Founder/CEO). Every step can be changed, and any active person can be chosen. Each person is notified only when it is their turn.
- A person cannot sign before the people ahead of them. Anyone who signed can take their signature back, and any edit clears the signatures so they are signed again.
- The printed requisition now has three signature boxes (Requested by, Finance head, Approved by top head). Each signature appears in its own box with the signer's name, title and date.
- To use "Finance head" automatically, set the head of the Finance department under Departments.

## @mentions in tasks
- In a task's **Details** and in **comments**, type **@** and pick a person, a department, or **everyone**. Arrow keys and Enter/Tab choose, Esc closes; on a phone just tap.
- A tagged **person** is added to the task (so they can open it) and gets a notification with a link. A tagged **department** notifies all its members and its head. **@everyone** notifies every active person; it is offered only to the Super Admin, Founder, managers, department heads and the Root Admin (others' @everyone is treated as plain text).
- When you edit the details, only people **newly** tagged are told. You are never notified about your own tag, and the assignee is not told twice. Email addresses (name@company.co) are never mistaken for tags.
- Tags show highlighted in the task and its comments.

## Finance documents: suggestions, stored items, company codes, attachments
**Open `/upgrade.php` once** (adds the company code/address, the quotation code and attachments).
- **Suggestions as you type** in every finance document: the client/company/customer/payee box suggests companies, contacts and earlier clients; the Attention box suggests contacts; each line's description suggests products and services (with unit and price); the "Proforma / quotation code" box on an invoice suggests earlier proformas and commercials. Picking one fills the other boxes (code, address, email, contact, payment terms, ship-to, sales rep...). **Everything stays editable.**
- **Everything typed is stored.** A new client becomes a company in the Pipeline (with code and address) and its contact is saved; new line items are saved in Products & services (with unit and price) for next time. Vendors on a requisition are not added to your sales companies; their items are still stored.
- **Company code.** Add a **Company code** (and address) when creating or editing a company (Pipeline). It fills the **Customer ID** on every document for that company by itself, and the "Customer (CODE) RFQ Number" heading uses it.
- **Proforma / quotation code on invoices.** An invoice made from a proforma or commercial carries its number automatically, shown on the invoice as "Proforma/Quotation: PRO-…". On a new invoice you can type or pick the code.
- **Attach documents** when creating or editing any finance document, including a requisition (vendor quotes, scans, supporting files; up to 15 per document, 8 MB each). They show on the document page (not on the printout) and can be downloaded or removed by the people working on the document.

## Screen sleep: each person's own settings (More > Screen sleep)
- **Start after:** Never, 15 s, 30 s, 1, 2, 3, 5, 10, 15 or 30 minutes. It now starts by itself even if you never touch the phone after opening the app.
- **Theme:** Ocean, Midnight, Sunrise, Forest, Graphite or Daylight.
- **Wallpaper:** upload your own **picture** (scaled to fit) or a short **video** that plays on a loop (live wallpaper; MP4 works on iPhone and Android; keep it under 60 MB, 10 to 20 seconds is best). The wallpaper is kept on that phone only and is never uploaded. **Dim** slider keeps the words readable.
- **What to show:** the task you are on, the latest notification, the news bar, seconds, and 12/24-hour clock.
- **Preview now** shows it at once.

## Prospecting: market signals with Exa (Campaigns > Prospecting)
**Open `/upgrade.php` once.** Then, as Super Admin, open Campaigns > Prospecting > Settings and paste your Exa API key (free account at exa.ai > Dashboard > API keys). The key is stored encrypted and never shown again. Your server must be allowed to make outside connections to `api.exa.ai`.
- **Search by signal.** Enter keywords (e.g. "ROV inspection, subsea survey") and a region. Five kinds of signal are searched: **contracts and tenders**, **trade shows and events**, **local news**, **new hires and appointments** (LinkedIn) and **social posts** (LinkedIn, X, Facebook: "looking for…", "we need…"). Each result is scored (kind of signal, keywords in the title, how recent) and shown as Intent High / Medium / Low.
- **Add as lead.** Creates the company in Pipeline (or adds to the existing one, with the signal and link in its notes), saves the contact if you know one, gives Marketing a research task due in 3 days, and can add the contact to a Campaigns list.
- **Save / Dismiss** to keep the list clean. Signals already seen are never shown twice.
- **Watch this every day.** A saved search runs once a day from `cron.php`, and tells you how many new signals it found.
- Each search uses a few of your Exa credits (one search per kind of signal). The screen shows how many searches were made this month. Exa returns pages, not email addresses: add the contact's name and email yourself, or use the contact form on the lead.

## Requisition numbers, document viewer and new requisition template
- Requisition numbers are now **REQ-Year-Month-N** (for example `REQ-2026-11-7`). N is a running count of requisitions made, it does not restart each month.
- Attached documents on a finance document can be **viewed** in the page (images and PDFs open in a window, with "Open in a new tab" and Download). Other file types still download.
- The requisition uses the new **AEROSUB REQUISITION** template: three signature boxes (requester, finance head, top head) and account details. Each person's signature appears in their own box as they sign.

## Prospecting: dig deeper
- Each signal has a **Dig deeper** button. It reads the page and the company's own website and shows: emails and phone numbers (company emails first, tap to mail or call), named people with their role, the **closing date** of an RFQ or tender with a days-left counter, and "Details you can use" grouped as Value & budget, Requirements, How to bid, Scope & timing and Who has it now.
- **Closing soon** filter lists open tenders, soonest first. Tenders closing within 30 days are ranked higher.
- "Add as lead" is prefilled from what was found (name, email, phone, position) and the follow-up task is due two days before the closing date.
- It only reports what is written on the pages. Exa does not sell email addresses, so some companies will show none.

## Campaigns tab redesign
- The tabs are grouped as steps: **Start here**, 1 Find people, 2 Prepare, 3 Send, Setup. "Start here" shows live counts and a "Do next" list.

## Test your emails (mail-tester.com)
- Campaigns > Test your emails. Open mail-tester.com, copy the address it shows, paste it, choose the sample message or one of your campaigns, press Send test. The score out of 10 appears here with the full report link. Limited to 10 tests an hour (Mail-Tester's free tier also limits daily tests). A button checks SPF, DKIM, DMARC and MX for your sending domain.

After copying the files, open `/upgrade.php` as Super Admin once (adds the new columns and the test history table), then hard-refresh.

## Requisition template
- Requisitions use the previous layout again (logo header, request details table, items, three signature boxes), now with the **requisition code (REQ-Year-Month-N)** under the title and a **footer** (address and "Better Data. Better Insights. Better Decisions." band).

- Requisition numbers are now REQ-YEAR-MONTH-SN with a 3-digit serial, e.g. REQ-2026-11-001. This needs api/lib/finance.php from this zip (the zip holds every file changed in the last two rounds, so extract all of it).

## Commercials, products and services, history, comments (this update)
This update does not replace `index.html`, `sw.js`, `upgrade.php` or `api/lib/migrate.php`, so nothing you added to those files elsewhere is overwritten. The database changes (product / service type, optional signatures, comments table) are made automatically the first time Finance is opened. After copying, press Ctrl+Shift+R twice, or close and reopen the app, to load the new screens.
- **Product, service or both.** Every line on an invoice, proforma, requisition or commercial is a Product or a Service. A selector at the top of the form ("This document is for") sets all lines at once; each line can still be changed, and the document then counts as "Products and services together". The Finance list has filters (All kinds, Services, Products, Products & services) and a badge on each row; the printed document says what it covers.
- **Commercial template** now follows `Commercial_Template__Service.xlsx`: sections A, B, C…, an "Estimated N-Day Total" for day-rate sections, "Sub Total" for fixed ones, the estimated total for the scope window, then Standby (as incurred) and Optional lines, then TOTAL.
- **Export to Excel** on every document (button at the top). The commercial opens in the same layout with working formulas, so you can edit and recalculate it. **Download PDF** (same button as Print) saves the finished document as a PDF.
- **Client and company:** click the client box and the companies used most recently appear; type to search; pick one and its details fill in (all still editable); or just type a new name, which is saved for next time.
- **History:** you see everything between you and the people next to you in the approval chain. Everybody else only sees that it was passed from one person to the next, with no comments, send-backs or edits. The Root Admin sees everything.
- **Requisition signatures are optional.** When asking for approval there is a tick box "Signatures are required". Untick it and each person just presses Approve. The document then shows "Approved by …" under each name.
- **Comments fixed.** The message you write when asking for approval is now saved on the document and shown to the person it was sent to. There is a new Comments box on every document: a full text area, to everyone on the document or privately to one person, with replies, a notification and an optional email.

- SEO tab restored (merged with this update): the SEO files are included again. Open /upgrade.php once; it ends with "SEO autopilot feed ready."

- Commercial printing: the table now fills the A4 landscape page from side to side and continues on further pages when it is long (header row repeated, aerosub.co at the foot of each page). A blank page after the cover is gone. Use Print / Download PDF, or Ctrl+P.

## Commercials, lines, vendors, layout and prospecting (latest update)
- **Commercial totals:** every line is quantity x unit rate. On a day rate the quantity is the number of days, so 5 days at $500 is $2,500. The "scope window" field is gone. Each category has ONE subtotal row, "Sub Total for X", with "(Estimated N Days)" added for day-rate categories, where N is the longest line: people working the same days side by side are never added up. The extra "Estimated N-Day Total" rows are removed; TOTAL adds the category subtotals. Standby and optional sections are shown after the counted ones and are not added. Text is aligned: descriptions left, unit, quantity and frequency centred, money right, subtotal and total labels right. The Excel copy follows the same rules. Note: older commercials that relied on the scope window will now total quantity x rate.
- **Lines:** every line has Duplicate and a drag handle (hold and drag, or use the up and down arrow keys on the handle). A line with only a category is kept (fill in the description later). Parts with nothing in them (notes, terms, empty request details, expiry or lead time, bank rows, company line) are no longer printed.
- **Vendors:** Products & services > Vendors lists your suppliers. Choose a vendor on each product or service (or type a new one). Vendors show on the cards, can be found when you type in a document, and picking a product on a requisition fills in its vendor as the payee.
- **Customize layout** (button at the top of every document, Finance): click any part of the document, or pick it from the list, and change font, size, weight, colour, background, alignment, line height, letter spacing, space inside and around it, and for tables the line colour and thickness. Save for this document or for all documents of that type. It shows in print and PDF.
- **Prospecting** also looks for Partners, Vendors and suppliers, Franchise and licensing, and Opportunities. A vendor found can be saved straight into the vendor list.

- Commercial subtotals: the label is centred and the amount sits centred across the Unit Rate and Total columns; subtotals, the green line and TOTAL are bold. A green line above TOTAL lists the categories that make it up (e.g. Data Collection + Data Processing & Analysis + Data Reporting) with their sum. Same in the Excel copy.

- Commercial money is laid out like an accounting sheet: the currency sign sits at the left edge of each cell and the number at the right (unit rates, line totals, subtotals, the green line and TOTAL); the labels Discount, VAT and TOTAL are centred. The Excel copy uses the same accounting format.
- Templates: open a document and press Save as template (lines, categories, notes, terms, tax and, if you tick it, the look; never the client, number or dates). Saving under the same name updates the template, so you can edit and re-save. New document > Start from a saved template. Finance > Templates renames, deletes and starts a document from one.

- Fixed: "Database needs an upgrade" when saving a look, a template or a vendor. An older copy of the finance upgrade left in api/lib/migrate.php was hiding the new one. The new upgrade has its own name now and runs by itself, and /upgrade.php runs it too.

- The "Services / Products" line under the heading of a commercial is no longer printed (the type is already clear from the document).

## Letters (Word documents on letterheads) and sharing
- **New document is now a menu:** Commercial, Proforma invoice, Invoice, Requisition and **Letter (Word)**. Finance also has a **Letters** button (list, search, open, download, share).
- **Letters:** write on a page that shows the Aerosub letterhead with its padding (default: top 52 mm, bottom 44 mm, sides 22 mm, taken from AEROSUB_LETTERS.docx). Bold, italic, underline, colour, font and size, alignment, bullets and numbers, indent, links and tables. Letters autosave.
- **Word file:** "Word" downloads a real .docx you can keep editing in Word. The letterhead picture sits behind the text on every page and the padding is the page margin, so the writing never runs into the artwork. **Print / PDF** does the same on paper, with the top and bottom padding repeated on every page.
- **Letterheads:** Finance > Letters > Letterheads. Add as many as you like (upload the full-page PNG/JPG, set the four paddings with a live preview showing where the writing goes, choose the default for new letters).
- **Share a letter:** by email (the .docx attached; from your own mailbox or the Aerosub office sender) or onto a task (attached to the task with a note; the people on the task are notified).
- **My Documents > Share:** each item has a Share button. Share with colleagues (they are notified, see it under "Shared with me", and you can stop sharing at any time) or email it as an attachment. Nothing else in My Documents becomes visible; administrators still cannot open anyone's documents.
- Upgrade: open /upgrade.php once (adds fin_letterheads, fin_letters, vault_shares; it also runs by itself the first time Finance is opened).

## News feeds (outside RSS / Atom)
- **News > News feeds** (managers and admins): press "+ Add a feed", paste the RSS/Atom address, press **Check** to see which stories match, then Save.
- **Keywords:** only stories whose headline or summary mention one of your words are kept (commas; empty = everything). "Skip stories that mention" removes unwanted ones. Changing the words restarts that feed's list.
- **It keeps changing, it does not pile up:** each feed keeps only its newest N stories (5-30), drops stories older than your chosen days (1 day to 1 month), and checks for new ones every 30 minutes to once a day. A rotating headline above every page cycles through the latest stories.
- **Who sees it:** everyone, or one department (managers can add feeds for their own departments). Anyone can hide a story for themselves; managers can delete one.
- **Safety:** only public http(s) addresses are read (never anything inside our own network), size and time are capped, and the story text is stripped of markup.
- **Cron:** cron.php reads feeds that are due (the same cPanel cron as before). Without cron, opening News keeps them fresh. Upgrade: open /upgrade.php once (adds news_feeds and news_items; it also runs by itself on first use).

## The task horn 📯
- Open a task you gave (or any task, as a manager or admin) and press **📯 Horn**. Write a message (default "Please check this task!"), add an emoji if you like, and blow it.
- On the screen of the person the task is assigned to, a pop-up appears at once with a trumpet call, your **message in big bold**, and the task name (a link to open it). It calls again every 25 seconds, up to six times, until they answer. Phones also get the usual push notification and a vibration.
- They answer with one tap: **On it 💪**, **Paused ⏸️**, **😠**, **Almost done! 🏁**, or **Snooze** (5, 15, 30 minutes or 1 hour; it comes back when the time is up). You are notified of every answer, and the Horn box shows the earlier horns and replies on that task.
- Limits: you can't horn yourself, a closed task, or the same task twice within 20 seconds, and no more than 15 horns an hour. A new horn replaces the old unanswered one.
- The sound is made inside the browser (no sound files). Browsers keep a page silent until the person has touched it once since opening; if nobody has, the pop-up still appears and says "Tap anywhere to let the horn sound".
- Upgrade: open /upgrade.php once (adds task_horns; it also runs by itself on first use).

## Task status fix, dependent tasks, and Google Drive folders
- **Fix:** anyone added to a task (not just the person it is assigned to) can now update its status. They still cannot edit the task itself.
- **Dependent tasks:** + New task now starts with a choice: *Normal task* or *Dependent tasks (plan with steps)*. A plan has steps; each step has a main person, optional extra people, a time frame, and says which earlier steps it waits for (one, several, or none, so steps can also run side by side). A waiting step shows "Waiting" and cannot be started until the steps before it are done; when they are, the people on it are notified ("Your turn"). When every step is done the plan completes itself.
- **Time frames:** per step, either one window for everyone on it, or a separate window for each person. Leave the start empty and the step starts the day after the steps it waits for finish; give "days needed" and the end is worked out.
- **Timeline:** open the plan (or any step) and press "See the timeline": a chart with a bar per step (a bar per person when they have their own time frames), arrows for what waits for what, today's line, progress, and "+ Add a step". Click a step to open it. In a step, "Edit step" changes its people, dates and what it waits for (circular waits are refused).
- **Sub-tasks:** every task, step and plan now has a Sub-tasks list inside it (tick them off, add more); steps show how many are done on the timeline.
- Upgrade: open /upgrade.php once (adds task columns kind, parent_id, start_date, window_scope and tables task_deps, task_windows, task_subs; it also runs by itself on first use).
- **Google Drive in My Documents:** press "🟢 Google Drive folder", paste the link of a folder that is shared as "Anyone with the link" (Viewer). It appears as a folder. Open it to go into sub-folders; each file can be downloaded (Google Docs, Sheets and Slides come as Word, Excel and PowerPoint), emailed as an attachment (up to 10 MB) or shared with colleagues (a link to it is kept in My Documents and shared like any other item). No Google sign-in and no Google Cloud/API settings. Only folders shared by link can be read, and nothing in Drive is ever changed. Google can change how its public folder pages look, which can stop the listing from working until the reader is updated.

## Floating assistant, two-way horn, accepted transfers
- **Assistant bubble (✨, bottom right, drag it anywhere):** click to ask. It can summarise your day or week, explain a task, answer from our own records (research tasks and notes, competitors, companies in the pipeline, trade events, the product catalogue, your calendar and tasks), search the web, and prepare a **task or an event/appointment as a card** that you confirm with "Create". It never creates anything by itself and only sees what the signed-in person may see (the same access rules as the tabs).
- **Set-up (Super Admin):** open the bubble, press ⚙, paste an **Anthropic API key**, switch it on, choose the model, daily allowance per person and optional extra instructions, and press "Save and test". The key is stored sealed and never shown again. **A Claude Pro/Max chat subscription cannot power this**; the API has its own key and pay-as-you-go billing (console.anthropic.com). Web search must also be enabled for the organisation in the Anthropic console.
- Conversations are private to each person and trimmed automatically.
- **Horn both ways:** the person who has a task can now blow the horn back at the person who gave it (and the giver, managers and admins can still blow it at the assignee).
- **Transfers must be answered:** moving a task now makes an offer. It stays with its current person; the new person sees Accept / Decline (with an optional reason) at the top of the task; the offerer can take the offer back. Everyone involved is notified of the answer.
- Upgrade: open /upgrade.php once (adds ai_settings, ai_chats, ai_messages, ai_usage and tasks.transfer_state; it also runs by itself on first use).
