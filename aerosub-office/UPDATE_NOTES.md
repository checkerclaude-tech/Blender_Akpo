# Aerosub Office: update notes (second update)

## Installing
1. Upload the files in `aerosub-office-update-2.zip` over your site (same folders). `api/config.php` is untouched.
2. Sign in as Super Admin and open `https://YOUR-SITE/upgrade.php` once (safe to repeat).
3. Make sure your cPanel cron runs `cron.php` (it now also deletes archived users after 60 days).
4. Needs the PHP **zip** extension (normally on in cPanel) for Excel files and PowerPoint reading.

## 1. Sign in
- Every password box has a show / hide eye.
- **Forgot your password?** emails a one-hour link to the account's address and sends all Super Admins a notification (they can also set a temporary password under Admin → Edit). The reply is always the same, so nobody can discover which emails exist. Email is sent with the account set up under Campaigns → Settings (or the server's own mail if that is not set up).

## 2. Archiving users
Admin → Edit user → **Archive user**. They lose access at once, the account is kept for **60 days** (Admin → Archived users, with Restore), then it is deleted for good by `cron.php`. Their tasks stay where they are; work they created is handed to a Super Admin when the account is finally deleted. You cannot archive yourself or the last Super Admin.

## 3. Documents and reports
- **Invoice** (A4 portrait) and **Commercial** (A4 landscape, with cover page) print layouts follow your two Word templates. Use *Print / save PDF*.
- Invoice numbers are automatic and sequential (the next one is 100026706, continuing from your sample). S/N rows and the commercial's A, B, C… categories are numbered automatically. Amount in words is written out from the total.
- The forms now ask for everything the templates have: Bill to, Attention, Customer ID, Sales rep, Terms & instructions, Customer RFQ number, Payment terms, Ship to, Shipping method, Delivery lead-time, Quote expiration; and for commercials: project title, note, number of days, categories, UOM, frequency, rate. "Day rate" lines are multiplied by the days; "As incurred / Optional / As applicable" lines are shown but not counted. Your sample works out to exactly $104,000.
- Finance → Company details now holds bank name, account name, account number, VAT reg, RC number, website, and the next invoice number.
- **Weekly report** (Reports → Weekly report): the ASL-HR-WR-001 sheet, filled in from the person's tasks and daily reports, editable on screen, then **Download Excel** (same layout as your template) or print.

## 4. Courses (Learn → Courses)
- The Super Admin creates courses: picture (upload or link), description, status (draft / published), departments (none ticked = everyone).
- Lessons: text, YouTube or Vimeo links (played inside the page), links, and PowerPoint **.pptx** files (slides, tables and pictures are read and shown with Previous / Next). Old .ppt files must be saved as .pptx first.
- Tests: objective questions with 2 to 5 answers, optional picture and hint. Add them by hand or import an Excel / CSV file with the columns **Questions, Correct Answer, Image Link, Answer A–E, Hint** (button *Download template*). Each test can draw e.g. 50 questions at random from a bigger bank; pass mark, time limit, shuffling and "show right answers" are settings.
- Retakes: set how many attempts each person gets (or unlimited); *Allow retake* in Grades gives one person another attempt.
- Grades: you and the Founder see everyone's. Tick other people per course under Settings → "Who sees everyone's grades", and choose whether department heads and managers see their own department. Everyone else sees only their own results. Export CSV is available.
- Video embeds need the security policy in `.htaccess` (updated: allows youtube-nocookie.com and player.vimeo.com only).

## Files
New: `api/lib/{accounts,xlsx,courses}.php`, `assets/js/{finance,courses}.js`, `assets/css/{finance,courses}.css`, `assets/img/*`.
Changed: `api/index.php`, `api/lib/{core,migrate,reports,finance,pipeline,taskfiles,agent_owner_views}.php`, `assets/app.js`, `assets/app.css`, `assets/js/reports.js`, `assets/css/reports.css`, `index.html`, `sw.js`, `cron.php`, `upgrade.php`, `.htaccess`.
