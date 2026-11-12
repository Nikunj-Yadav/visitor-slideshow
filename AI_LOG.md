# AI development log

**How to read this file.** The project was built in two AI-assisted sessions: **Part 1** built the application in Node.js/Express/SQLite (and fixed a flaky test); **Part 2** migrated it to Core PHP + MySQL. In each session the only human prompt was the project brief; everything else is a decision or a mistake the AI made *during that session*, recorded as it happened. Nothing here is invented. If you (the student) add your own prompts or experiments, add them as new numbered entries rather than editing these.

# Part 1 — Node.js build (kept for history)

## Important interactions and decisions

1. **The brief.** Prompt: the full "Visitor Photo Slideshow — complete project build prompt" (functional requirements, neumorphic design tokens, documentation, testing, GitHub rules). Result: a plan to build a real working app first, UI second, with tests before the UI.
2. **Stack decision.** Chose Node + Express + SQLite. Compared the built-in `node:sqlite` (prints an "experimental" warning on Node 22) with `better-sqlite3`; chose `better-sqlite3` because it is stable and its synchronous transactions make the concurrent-upload logic simple to explain.
3. **Image safety decision.** Instead of only checking file extensions or MIME types, every upload is sniffed (magic bytes), fully decoded by `sharp`, and re-encoded as JPEG. Reason: this catches corrupt files and also strips GPS metadata, which matters for a public display.
4. **Upload design.** The page sends one photo per request. Reason: `multer` rejects a whole request if one file is too big, so per-file requests give per-photo progress and per-photo error messages. The first photo goes alone (it creates the visitor and returns the code); the rest go three at a time.
5. **Returning-visitor design.** A random hashed code instead of email/login, saved in `localStorage` for same-device recognition. Trade-offs are listed in ASSUMPTIONS.md.
6. **Test-first backend.** Wrote 28 API tests (29 after the fix described in G) (validation, consent, returning visitors, concurrency, admin protection, live events) and ran them before building any page.
7. **Browser test.** Wrote `tests/e2e.js` (Chromium) to drive the real pages, including a live slideshow open in a second tab, and then looked at the screenshots instead of trusting the pass/fail output.
8. **Fixing what the tests found** — see below.

## Where the AI got it wrong (all real, all caught during this session)

| # | What the AI produced | How it was noticed | Fix |
|---|---|---|---|
| A | `"test": "node --test tests/"` in `package.json`. | `npm test` failed with `Cannot find module '.../tests'` on Node 22 (a directory argument is not accepted there). | Changed to `node --test tests/*.test.js`. |
| B | An SSE test asserted `content-type` equals exactly `text/event-stream`. | Test failed: Express returns `text/event-stream; charset=utf-8`. The *test* was too strict, not the server. | First "fix" (a `sed` replacement) wrote a broken regular expression with a doubled backslash; the next run would have failed. Noticed by re-reading the edited line, replaced with `startsWith('text/event-stream')`. |
| C | The app had no `404.html`, yet the 404 handler sent that file. | Test "database and uploads folder are not reachable over HTTP" expected 404 but got **500**. | Added a real 404 page. (The security behaviour was right; the error page was missing.) |
| D | Used inline `style="..."` attributes in three places while the server sends a strict CSP (`style-src 'self'`). | The browser test collected console errors: "Applying inline style violates the Content Security Policy". Unit tests could not see this. | Replaced them with CSS classes (`hint--block`, `card--form`, `input--auto`). The e2e test now fails on any console error. |
| E | Slideshow CSS gave `.waiting` `display: grid`, which silently overrides the HTML `hidden` attribute. | The e2e check "new photo appears without refresh" failed. The first version of the empty-state check only looked at the attribute, so it had passed while the "Waiting for the first photo" text would have been drawn **on top of live photos**. | Added `[hidden] { display: none !important; }` and changed the tests to check real visibility (`isVisible()`) in both directions. |
| F | `fmtBytes(0)` returned "1 KB" (a `Math.max(1, …)` meant for tiny files). | Seen in the dark-theme dashboard screenshot: "Storage used: 1 KB" on an empty system. | Return "0 KB" for zero bytes. |

| G | Validated the files of one request in parallel and stored them in *completion* order, so file order (and which file got the last free slot) was random. | Not caught by my own runs (the test passed in full suites). A reviewer reported it failing; running that test alone in a loop reproduced it (failed on run 4 of 12). | Sort validated files back into selection order. Added a regression test. My first version of that regression test was itself wrong (precondition failed: image bigger than the 1 MB test limit), which I only found because it still failed after the fix; re-measured properly (19/20 fail without fix, 0/30 with). |

Lesson recorded: passing tests are only as good as what they assert (E), browser-level tests find problems API tests cannot (D, E, F), and a test that passes in a full run can still be flaky: run suspicious tests in isolation and in loops (G).


---

# Part 2 — Migration to Core PHP + MySQL

## Important interactions and decisions
1. **The migration brief.** Prompt: "Migrate Memory Wall from Node.js to Core PHP + MySQL" (PHP + MySQL + GD + XAMPP, keep the UI and all features, PDO, CSRF, `database.sql`, tests, docs, preserve Git history). Followed by the instruction "go ahead" after the AI had inspected the project. Result: migrate, do not redesign.
2. **How to test PHP honestly.** Instead of only `php -S` (one process, cannot show real concurrency or `.htaccess` behaviour), the AI installed PHP 8.3, MariaDB (the engine XAMPP ships) and Apache with mod_php in the sandbox, and served the project from a sub-folder like `htdocs`. Reason: the brief's tests include simultaneous uploads and protected folders.
3. **Live updates.** Chose polling over PHP Server-Sent Events: an open SSE connection holds an Apache worker for the whole event. Implemented a cheap `?since=<version>` check backed by a `settings.data_version` counter bumped inside each transaction.
4. **URLs.** Replaced absolute paths (`/api/...`) with relative ones plus a `<base href>` computed from the script location, so the app works from any `htdocs` sub-folder without Apache rewriting.
5. **Concurrency design.** `SELECT … FOR UPDATE` on the visitor row + READ COMMITTED + UNIQUE constraints + deadlock retry, replacing the single-threaded guarantees of the Node/SQLite version.
6. **Admin password.** A first-run setup page that stores `password_hash()` in MySQL (no password in files; no terminal needed), instead of the Node `.env` password.
7. **Tests.** Ported the 29 Node API tests to a PHP/curl runner and extended them to 42 (CSRF, SQL injection, EXIF rotation, database constraints, protected folders…). The runner reads the app's limits from `api/config.php`, so it was also run against a second, default-limits configuration in a differently named folder. The browser test was ported (images from PHP GD instead of a Node library).

## Where the AI got it wrong (all real, all found by running things)

| # | What the AI produced | How it was noticed | Fix |
|---|---|---|---|
| H | `DELETE … WHERE id <= (SELECT MAX(id) - 200 …)` in the activity log. On an unsigned column this overflows while fewer than 200 rows exist. | First full test run: **25 of 42 failing**, mostly HTTP 500. The Apache error log said `BIGINT UNSIGNED value is out of range`. This would have broken **every upload on a fresh XAMPP**, because each upload writes an activity row. | `CAST(MAX(id) AS SIGNED) - 200`. |
| I | In the test, `substr($location, -18) === 'admin/setup.php'` (the string has 15 characters). | The "first run" test failed, which left no admin account and made all eight admin tests fail as well. Only the first failure message was the real problem. | Corrected to `-15`. |
| J | The test HTTP helper prepended the site address to photo URLs that the API already returns as absolute paths. | Three tests failed with 404. Worse, the withdraw test's "photo returns 404" **passed for the wrong reason**. | Helper fixed; the withdraw test now first asserts the photo returns **200** before withdrawal. |
| K | `$o['consent'] ?? 'true'` in the test helper: `??` treats an explicit `null` as missing, so "no consent field" silently sent `consent=true`. | Test expected 400, got 200. | `array_key_exists`. |
| L | Rejected truncated JPEGs by looking for a GD warning ("premature end of JPEG file"). | Test expected 422, got 200. A 10-line script showed this GD build emits **no warning at all** for a cut-off JPEG. | Explicit check that a JPEG contains its end marker `FF D9` near the end of the file (new JPEGs always have it; truncated ones cannot). |
| M | `photo.php` cast `$_GET['f']` to string without checking its type. | PHP warning "Array to string conversion" in the test output for `?f[]=x` (the response was still 404, but noisy). | `is_string()` check first. |

Environment note (not an application bug): the sandbox stopped the MariaDB process between commands, which produced "Connection refused" errors. The application behaved as designed (visitors saw only a friendly message; the real error was in Apache's log), and a small start-up script was added for the test environment.

Lessons: a passing "404" test can be passing for the wrong reason (J); do not assume a library reports an error — check (L); read the *first* failure, not the cascade (I).
