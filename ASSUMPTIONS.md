# Assumptions and design decisions (PHP + MySQL version)

Each item says what the brief left open, what was decided, and why. Items marked **(changed)** differ from the earlier Node.js version.

## Migration scope
- The interface (HTML structure, CSS, JavaScript behaviour) was kept. JavaScript was changed only where PHP integration required it: URLs, a CSRF header for admin calls, `POST` instead of `PUT/DELETE`, and polling instead of Server-Sent Events.
- The Node.js code, `package.json` and SQLite were removed from the working tree; they remain in the Git history.
- No sample data is shipped: photos need real files, and an empty start shows the real empty states. The first visitor you create is your demo data.

## Returning-visitor identification (unchanged)
- A random **visitor code** (10 characters, no look-alikes, shown as `XXXXX-XXXXX`, about 50 bits) is created at the first upload. No e-mail, phone number or account.
- MySQL stores only the **SHA-256 hash** of the code (`visitors.code_hash`, unique). The browser also keeps the code in `localStorage`, so the same device is recognised automatically.
- **Limitations:** a lost code cannot be recovered; anyone who knows the code can manage that visitor's photos; a shared device keeps the saved code until site data is cleared or consent is withdrawn. Lookups are rate-limited (60 per 15 minutes per IP).

## Photo display duration and order
- Default **8 seconds**, admin-configurable from 3 to 120 seconds (`settings` table).
- Photos play in upload order and loop. A newly uploaded photo plays next. A single photo stays on screen. A photo that fails to load is skipped.

## Live updates **(changed: polling instead of Server-Sent Events)**
- The slideshow asks `api/slideshow.php?since=<version>` every 3 seconds. The server answers `{"unchanged":true}` unless the number `settings.data_version` has increased, so most requests cost one tiny query.
- Why not SSE/WebSockets in PHP: each open connection occupies an Apache worker and a PHP process for as long as the TV is on; XAMPP's Apache/PHP and typical proxies are not built for that, and a student machine could run out of workers. Polling is simple, works everywhere and survives restarts. **Cost:** a new photo shows up to about 3 seconds later, plus the time until the current slide ends.
- `data_version` is increased in the same database transaction as every change (upload, delete, hide, withdraw, settings), so the display never sees a version number before the data is committed.

## Consent and removal (unchanged behaviour)
- An **unticked consent checkbox** is required for every upload; the server refuses the upload without it and stores the time of consent.
- Photos are public (slideshow list **and** file URL) only while the visitor has consent and the photo is not hidden.
- **Withdrawal** = permanently deleting all photo files, photo rows, the name and the code. Single photos can be deleted by their owner. Staff can delete a visitor on request.
- Names appear on the display only if an admin turns it on, and then first name only (default: off).
- A full legal privacy policy (retention period, controller contact) is the organiser's responsibility.

## Moderation
- **Post-moderation:** photos appear immediately; staff can hide or delete them. There is **no automatic nudity/violence scanning**, so someone should watch the admin page at a public event.

## Upload limits and formats
- JPG, PNG, WebP; **8 MB** per file, **10 files** per request, **30 photos** per visitor (all in `config/config.php`). The page sends one photo per request.
- Checks, in order: size → magic bytes (first bytes of the file) → browser-declared type must agree → file extension must be jpg/jpeg/png/webp **and** agree with the content → pixel count ≤ 40 megapixels → full decode with GD.
- Damaged files are rejected. GD does not report cut-off JPEGs, so a JPEG without its end marker (FF D9) near the end is rejected explicitly (found by testing).
- Every accepted photo is **re-saved as a new JPEG** (max 2560 px, quality 85): this removes EXIF metadata such as GPS, applies the phone's rotation (EXIF orientation 1–8), and flattens transparency onto white. Animated images are not supported.
- iPhone HEIC photos are not accepted directly; iOS normally converts to JPEG for web uploads. **Not tested on a real iPhone.**
- `public/.htaccess` and `public/.user.ini` raise PHP's own upload limits (12 MB per file) so the app's limit, not PHP's default, decides.

## Concurrent uploads and duplicates **(changed: MySQL locking)**
- Every upload ends in **one InnoDB transaction** (isolation level READ COMMITTED). For a returning visitor it starts with `SELECT … FOR UPDATE` on the visitor row, so simultaneous uploads by the *same* visitor are processed one after another; limits and duplicate checks are therefore exact.
- The database enforces the rules too: `UNIQUE (visitor_id, sha256)` blocks duplicate photos, `UNIQUE (code_hash)` and `UNIQUE (filename)`, and a **foreign key with ON DELETE CASCADE** keeps photos and visitors consistent.
- If MySQL reports a deadlock, the transaction is retried (up to 3 times).
- Files are first written as `*.part`, renamed when complete, and only referenced from the database after that; **incomplete uploads never appear**. Files that were not stored (duplicate, limit, error) are deleted again.
- Because PHP processes the files of one request one after the other, they are saved in the order the visitor selected them.

## Admin **(changed: password set in the browser, hashed in MySQL)**
- There is no password in a configuration file. On first visit to the admin area a **setup page** creates the admin password (≥ 10 characters) and stores it with `password_hash()`. Only one admin row can exist (primary key is always 1). Until then, anyone who can open the admin address could create it — fine on a classroom PC, but on a public server do this step yourself right after installing.
- Forgotten password: delete the row in `admin_users` (phpMyAdmin) and set a new one.
- Sessions: HttpOnly, SameSite=Strict cookie scoped to the app folder, new session id after login, 8-hour idle timeout. Every admin write needs a **CSRF token** (header `X-CSRF-Token`) and a same-origin `Origin` header. Login and setup are limited to 10 attempts per 15 minutes per IP.
- Visitor endpoints use no cookies (the code travels in the request), so they cannot be abused by CSRF; they accept JSON only.

## Files and URLs
- Photos live in `uploads/` (not inside `public/`), blocked by `.htaccess`, and are served only through `photo.php`, which checks the database (approved + consent, or admin).
- All page and API URLs are **relative** to a `<base>` tag computed from where the project is installed, so it works in any `htdocs` sub-folder. Links use real file names (`index.php`) so nothing depends on Apache URL rewriting; rewriting is used only for the friendly 404 page and is skipped if `mod_rewrite` is off.
- Blocking `uploads/`, `config/`, `includes/`, `tests/`, `docs/` relies on Apache reading the `.htaccess` files (`AllowOverride All`, the XAMPP default). `check.php` tests this by requesting a file in `uploads/` and warns if it is reachable. On a real server, point the document root at `public/` so these folders are not in the web root at all.
- The setup page `check.php` works only from `localhost`.

## Other
- Times are stored in UTC (`SET time_zone = '+00:00'` per connection) and shown in the browser's local time.
- Tested on PHP 8.3, MariaDB 10.11 (the database engine XAMPP ships) and Apache 2.4 with mod_php. PHP 8.0+ syntax only was used.
- The e2e browser test needs Node.js; the application does not.
