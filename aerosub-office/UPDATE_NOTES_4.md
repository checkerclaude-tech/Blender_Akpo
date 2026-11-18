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
