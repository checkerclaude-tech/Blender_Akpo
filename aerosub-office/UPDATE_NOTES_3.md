# Aerosub Office: update notes (third update)

## Installing
1. Upload the files in `aerosub-office-update-3.zip` over your site (same folders). `api/config.php` is untouched.
2. Sign in as Super Admin and open `https://YOUR-SITE/upgrade.php` once. It adds the new tables (weekly sharing, games, currency signs, invoice fields) and is safe to repeat.
3. **Games stay hidden from staff until you switch them on**: Games → Settings.

## Invoice and Proforma
- **Invoice** (sent after a purchase order) now follows `INVOICE_01.docx`: invoice number, PO number, TIN, RC and date at the top right; bill-to with the attention line at the bottom of the cell; sales representative with email and phone; amount in words underlined; bank details on page 2 with the two signatures **below** them.
- **Proforma invoice / quotation** keeps the earlier layout. Fixes: the number and date show on page 1 only; the label says "Invoice Number"; the bank details come before the signatures.
- **Amount in words** was wrong for tens (30 had disappeared). Rebuilt and tested on more than 18,000 amounts in Naira, Dollars and a custom currency: every sentence converts back to exactly the same number. It is also shown under the total of commercials and on receipts.
- **Currencies**: Naira (₦) is the default, plus US dollar ($), pound (£) and euro (€). Choose "Other" to type any code and sign (for example GHS and GH₵). The sign now appears on screen, in lists and on every printed document (the old form removed any non-letter, which is why ₦ became a dollar sign). Finance → Company details sets the default.
- Invoice forms also hold: sales rep email and phone. Quote expiration and delivery lead-time are asked for proformas only.

## Weekly report
- **Share…**: pick team members and top members (your department head, managers, admins, founder) with one click each. They get a notification, and optionally an email with the Excel file attached. The report is saved so they can open it later (Reports → Weekly report → "Shared with me"). Email is sent with the account set up under Campaigns → Settings.
- **Team performance** (Reports → Team performance, for department heads, managers, admins and the Founder): activity over time, tasks by status, workload per person, reporting consistency and a sortable leaderboard (completed, on-time %, reports, hours, blocked, overdue) for 7 to 90 days, filterable by department.

## Look and feel
- The side menu can be **minimised** to icons (arrow at the top of the menu); your choice is remembered.
- **Dark mode** was checked on 29 screens by measuring text contrast: every pair now meets the readable standard (white-on-blue buttons were also too faint in light mode and are fixed).
- **Themes**: Christmas, Monday office, Cookie, Cyber Punk, Broken PC and Tetris. Try one in the Games tab, press "Use this as my app theme" to apply it everywhere, or change it back under My profile → App theme. Each theme was contrast-checked.

## Games
Chess (all rules), Ludo (2 to 4), Scrabble (2 to 4) and Crazy Eights (2 to 6). Anyone can start a game and invite colleagues; invited people get a notification and accept or decline.
- **Pop-ups**: while Aerosub Office is open in a browser tab (it can be in the background), a pop-up appears on the computer when an invitation arrives or it is your turn, so you can carry on with other work. Click "Turn on pop-ups" in the Games tab once. They do not arrive if the browser is closed.
- **Admin control** (Games → Settings, Super Admin only): hide Games from everyone, show to everyone, or show to selected people; open all the time, at midday (12:00 to 14:00), at close of work (17:00 to 22:00) or at times and days you choose; and switch individual games on or off. You can always play.
- Scrabble does not check words against a dictionary. Instead any player can challenge a word; the person who played it takes it back or keeps it (a wrong challenge costs the challenger a turn).
- Every move is checked by the server, and each player only receives what they may see (their own cards and tiles).

## Files
New: `api/lib/{game_chess,game_ludo,game_scrabble,game_cards,games}.php`, `assets/js/{games,perf,skins}.js`, `assets/css/{games,skins}.css`.
Changed: `api/index.php`, `api/lib/{core,migrate,reports,finance,accounts}.php`, `upgrade.php`, `assets/app.js`, `assets/app.css`, `assets/agent/agent.css`, `assets/js/{finance,reports}.js`, `assets/css/{finance,reports,courses}.css`, `index.html`, `sw.js`.
