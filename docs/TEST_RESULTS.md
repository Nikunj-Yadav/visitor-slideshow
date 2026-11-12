# Test results (PHP + MySQL version)

Run on 2026-11-12. These are the real outputs of the commands shown. The earlier Node.js test results belong to the previous version and are in the Git history only.

**Test environment:** Ubuntu Linux container · Apache 2.4.58 with mod_php · PHP 8.3.6 · GD 2.3.3 (JPEG, PNG, WebP) · MariaDB 10.11 (the MySQL-compatible server that XAMPP ships; `root` with an empty password like XAMPP) · Chromium 153 via Playwright. The project was served from `/var/www/html/visitor-slideshow/` (a sub-folder, like XAMPP's `htdocs`).

## Summary
| Suite | Configuration | Result |
|---|---|---|
| `php tests/run.php` (42 HTTP + database tests) | quick config: 1 MB / 5 files / 6 photos limits, test database | **42/42 / 42/42 / 42/42** in three consecutive runs |
| `php tests/run.php` (same tests) | default config: 8 MB / 10 files / 30 photos, database created from `database.sql`, different folder name, default rate limits | **42/42 / 42/42** in two consecutive runs |
| `node tests/e2e/e2e.js` (real browser) | quick config | **42/42** |
| Large photos | 12 MP and 24 MP JPEG uploads | both accepted (0.4 s and 0.6 s), no memory errors |
| Apache error log during the final runs | | 0 PHP warnings, 0 fatal errors |

**Nothing is currently failing.** Failures found and fixed during development are listed in AI_LOG.md (entries H–N).

## 1. `php tests/run.php` — quick configuration (last of three runs)
```
PASS 1. first run: admin pages redirect to the setup page; password is created once and stored hashed
PASS 2. new visitor: valid PNG accepted, code issued, photo re-saved as a clean JPEG
PASS 3. rejects a text file renamed .jpg
PASS 4. rejects a declared type that does not match the content
PASS 5. rejects a file extension that does not match the content (PNG named .jpg)
PASS 6. rejects a disallowed extension (GIF / PHP)
PASS 7. rejects corrupted images (truncated PNG and truncated JPEG)
PASS 8. rejects an image that claims an enormous size (memory-bomb guard)
PASS 9. rejects files over the size limit with 413
PASS 10. rejects too many files in one request
PASS 11. consent and name are required, and nothing is stored without them
PASS 12. uploaded filenames are never trusted (path-traversal name ignored, random names on disk)
PASS 13. WebP images are accepted
PASS 14. phone rotation (EXIF orientation 6) is applied and EXIF data is removed
PASS 15. returning visitor: code finds earlier photos, new upload adds to them, duplicates are blocked
PASS 16. unknown visitor code is rejected
PASS 17. per-visitor photo limit is enforced
PASS 18. files in one request are saved in the order they were selected (large first, tiny second)
PASS 19. withdrawing consent deletes photos from disk, database and slideshow
PASS 20. a visitor can remove one of their own photos, but not someone else's
PASS 21. slideshow hides names unless the admin turns them on
PASS 22. live update: the slideshow version changes on upload, and ?since= tells the display what is new
PASS 23. slideshow exposes only approved photos of consenting visitors and nothing sensitive
PASS 24. concurrent uploads: 20 simultaneous requests from different visitors all succeed, no duplicates
PASS 25. concurrent uploads: the SAME photo sent 8 times at once by one visitor is stored once
PASS 26. concurrent uploads never exceed the per-visitor limit
PASS 27. database consistency: files on disk match database rows; no orphans
PASS 28. database constraints exist (foreign key with cascade, unique indexes) and the database enforces them
PASS 29. visitor codes are stored only as hashes
PASS 30. SQL injection attempts are stored/handled as plain text
PASS 31. admin API and pages are protected
PASS 32. admin login: wrong password fails, correct one sets an HttpOnly SameSite=Strict cookie, CSRF token required
PASS 33. admin login is rate limited
PASS 34. admin rejects cross-site requests and missing/wrong CSRF tokens
PASS 35. admin: real stats, hide removes from slideshow, delete removes the file, settings are validated
PASS 36. admin search, filter and sort; search text cannot inject SQL
PASS 37. admin can delete a visitor and all their photos
PASS 38. admin logout ends the session
PASS 39. security: path traversal and odd photo names give 404; security headers are present
PASS 40. security: private folders and files cannot be opened directly in the browser
PASS 41. security: user input is not reflected into pages; unknown addresses show a 404 page
PASS 42. rate limiting: visitor code guessing is limited

tests 42
pass 42
fail 0
```

## 2. `php tests/run.php http://localhost/memory-wall-defaults/public/` — default configuration (last of two runs)
```
PASS 1. first run: admin pages redirect to the setup page; password is created once and stored hashed
PASS 2. new visitor: valid PNG accepted, code issued, photo re-saved as a clean JPEG
PASS 3. rejects a text file renamed .jpg
PASS 4. rejects a declared type that does not match the content
PASS 5. rejects a file extension that does not match the content (PNG named .jpg)
PASS 6. rejects a disallowed extension (GIF / PHP)
PASS 7. rejects corrupted images (truncated PNG and truncated JPEG)
PASS 8. rejects an image that claims an enormous size (memory-bomb guard)
PASS 9. rejects files over the size limit with 413
PASS 10. rejects too many files in one request
PASS 11. consent and name are required, and nothing is stored without them
PASS 12. uploaded filenames are never trusted (path-traversal name ignored, random names on disk)
PASS 13. WebP images are accepted
PASS 14. phone rotation (EXIF orientation 6) is applied and EXIF data is removed
PASS 15. returning visitor: code finds earlier photos, new upload adds to them, duplicates are blocked
PASS 16. unknown visitor code is rejected
PASS 17. per-visitor photo limit is enforced
PASS 18. files in one request are saved in the order they were selected (large first, tiny second)
PASS 19. withdrawing consent deletes photos from disk, database and slideshow
PASS 20. a visitor can remove one of their own photos, but not someone else's
PASS 21. slideshow hides names unless the admin turns them on
PASS 22. live update: the slideshow version changes on upload, and ?since= tells the display what is new
PASS 23. slideshow exposes only approved photos of consenting visitors and nothing sensitive
PASS 24. concurrent uploads: 20 simultaneous requests from different visitors all succeed, no duplicates
PASS 25. concurrent uploads: the SAME photo sent 8 times at once by one visitor is stored once
PASS 26. concurrent uploads never exceed the per-visitor limit
PASS 27. database consistency: files on disk match database rows; no orphans
PASS 28. database constraints exist (foreign key with cascade, unique indexes) and the database enforces them
PASS 29. visitor codes are stored only as hashes
PASS 30. SQL injection attempts are stored/handled as plain text
PASS 31. admin API and pages are protected
PASS 32. admin login: wrong password fails, correct one sets an HttpOnly SameSite=Strict cookie, CSRF token required
PASS 33. admin login is rate limited
PASS 34. admin rejects cross-site requests and missing/wrong CSRF tokens
PASS 35. admin: real stats, hide removes from slideshow, delete removes the file, settings are validated
PASS 36. admin search, filter and sort; search text cannot inject SQL
PASS 37. admin can delete a visitor and all their photos
PASS 38. admin logout ends the session
PASS 39. security: path traversal and odd photo names give 404; security headers are present
PASS 40. security: private folders and files cannot be opened directly in the browser
PASS 41. security: user input is not reflected into pages; unknown addresses show a 404 page
PASS 42. rate limiting: visitor code guessing is limited

tests 42
pass 42
fail 0
```

## 3. Browser end-to-end — `node tests/e2e/e2e.js`
```
Test database and uploads folder emptied.
PASS  Slideshow shows a friendly empty state
PASS  Root address opens the visitor page
PASS  Submit disabled with no photos
PASS  Previews are shown for selected photos
PASS  Missing name is reported
PASS  Missing consent is reported
PASS  Visitor code is issued after first upload
PASS  Success page reports 2 photos
PASS  Invalid file is reported on the status page
PASS  New photo appears on the open slideshow without a refresh (empty message gone, image visible)
PASS  First visit to /admin goes to the password setup page
PASS  Setup rejects mismatching passwords
PASS  Setup creates the admin and opens the dashboard
PASS  Slideshow advances automatically to the next photo (3 s setting)
PASS  Visitor first name is shown only when the admin enables it
PASS  Admin page redirects to sign-in when logged out
PASS  Wrong admin password shows an error
PASS  Correct admin password opens the dashboard
PASS  Sign out returns to the sign-in page
PASS  Returning visitor sees their previous photos
PASS  Returning visitor can add another photo with the same code
PASS  Returning visitor does not get a new code
PASS  Previous and new photos are together (3)
PASS  Same device recognises the returning visitor automatically
PASS  Dashboard shows real counts (1 visitor, 3 photos)
PASS  Search with no match shows an empty state
PASS  Hiding a photo works and is marked as hidden
PASS  Delete needs confirmation and Cancel keeps the photo
PASS  Visitors table lists the visitor
PASS  Withdrawing consent removes photos from the open slideshow
PASS  Withdrawn visitor code no longer works
PASS  Theme toggle switches to dark
PASS  Theme preference is remembered after reload
PASS  Mobile (375px) has no sideways scrolling: index.php
PASS  Mobile (375px) has no sideways scrolling: my-photos.php
PASS  Mobile (375px) has no sideways scrolling: status.php
PASS  Mobile (375px) has no sideways scrolling: admin/index.php
PASS  Mobile (375px) has no sideways scrolling: admin/manage.php
PASS  Mobile (375px) has no sideways scrolling: slideshow.php
PASS  Mobile menu opens
PASS  Unknown pages show a friendly 404
PASS  No console errors, page errors or failed requests

42/42 browser checks passed
```
Screenshots from this run are in `docs/screenshots/` (light/dark themes, upload, status, slideshow, admin setup/dashboard/manage, confirmation dialog, mobile menu).

## 4. Coverage of the required checks
| # | Required check | Where it is tested |
|---|---|---|
| 1 | Visitor registration | tests "new visitor…", e2e "Visitor code is issued" |
| 2 | Returning-visitor recognition | "returning visitor: code finds earlier photos…"; e2e "Same device recognises…" |
| 3 | Multiple photo uploads | returning-visitor test (2+2 files), e2e (3 files in one go), 20 parallel uploads |
| 4 | Invalid and oversized files | text-as-jpg, type mismatch, extension mismatch, GIF/PHP, truncated PNG/JPEG, fake huge size, over 8 MB (413), too many files |
| 5 | Per-visitor limit | "per-visitor photo limit is enforced", "concurrent uploads never exceed…", order test |
| 6 | Consent enforcement | "consent and name are required, and nothing is stored"; e2e consent message |
| 7 | Photo removal | withdraw (files, rows, slideshow, code), remove one photo, admin delete photo / visitor |
| 8 | Live slideshow updates | "live update: version changes…" (API); e2e: open slideshow shows new photo and empties after withdrawal without reload |
| 9 | Previous photos shown again | lookup shows earlier photos; e2e "Previous and new photos are together (3)" |
| 10 | Simultaneous uploads | 20 parallel visitors; same photo ×8 stored once; 8 parallel uploads never exceed the limit; no `.part` leftovers |
| 11 | Admin login and protection | setup once, hashed password, 401/302 without login, forged cookie, wrong password, rate limit, CSRF, cross-site origin, logout |
| 12 | Database consistency | files on disk = database rows, no orphans, no duplicates, foreign key CASCADE exists and refuses orphans, unique indexes exist, codes stored only as hashes, SQL-injection text stored literally |
| 13 | Responsive UI and themes | e2e: 6 pages at 375 px with no sideways scrolling, mobile menu, theme toggle and persistence |
| – | Security extras | private folders (uploads, config, includes, database.sql, tests, docs, .git) not reachable by URL; path-traversal names; security headers; no input reflection; EXIF/GPS removed; phone rotation applied |

## 5. NOT tested — please be honest about these in the viva
- **Real XAMPP on Windows.** The stack was Apache + mod_php + MariaDB on Linux, which is the same software XAMPP bundles, but Windows path handling (backslashes in `base_url()`/`upload_dir`) and XAMPP's threaded Apache were not exercised.
- **PHP versions other than 8.3** (the code avoids features newer than PHP 8.0 but 8.0–8.2 and 8.4 were not run).
- **phpMyAdmin's Import button.** `database.sql` was imported with the `mysql` command-line client (twice, to prove it is repeatable); it contains no client-specific syntax, but the phpMyAdmin screen itself was not driven.
- **Oracle MySQL** (MariaDB was used).
- Browsers other than Chromium; real phones (including iPhone HEIC → JPEG); smart-TV browsers; slow mobile networks; screen readers; a formal contrast audit.
- Load beyond 20 simultaneous uploads.
- The 3-second polling delay was observed to work in the browser test but not measured precisely.

## 6. Verification of the delivered ZIP and of `check.php`
- The ZIP was unzipped into a fresh folder under Apache, `database.sql` imported into a brand-new database, `chmod -R 777 uploads` applied (README step 5): `check.php` showed all checks OK, one real upload was accepted and listed by the slideshow API.
- `check.php` folder-protection check, observed on the same files served two ways: with `AllowOverride All` it reports OK and `uploads/.gitkeep` returns **403**; with `AllowOverride None` it reports **"NOT blocked (HTTP 200)"** and the file is indeed served. (This check was added after the second case exposed the risk; see AI_LOG entry N.)
- `check.php` with a database that does not exist reports "Fix needed" for the connection with a plain-language hint.
