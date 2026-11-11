# AI development log

**How to read this file.** This project was built in one AI-assisted session. The only human prompt is the project brief (entry 1); everything else below is a decision or a mistake the AI made *during that session*, recorded as it happened. Nothing here is invented. If you (the student) add your own prompts or experiments, add them as new numbered entries rather than editing these.

## Important interactions and decisions

1. **The brief.** Prompt: the full "Visitor Photo Slideshow — complete project build prompt" (functional requirements, neumorphic design tokens, documentation, testing, GitHub rules). Result: a plan to build a real working app first, UI second, with tests before the UI.
2. **Stack decision.** Chose Node + Express + SQLite. Compared the built-in `node:sqlite` (prints an "experimental" warning on Node 22) with `better-sqlite3`; chose `better-sqlite3` because it is stable and its synchronous transactions make the concurrent-upload logic simple to explain.
3. **Image safety decision.** Instead of only checking file extensions or MIME types, every upload is sniffed (magic bytes), fully decoded by `sharp`, and re-encoded as JPEG. Reason: this catches corrupt files and also strips GPS metadata, which matters for a public display.
4. **Upload design.** The page sends one photo per request. Reason: `multer` rejects a whole request if one file is too big, so per-file requests give per-photo progress and per-photo error messages. The first photo goes alone (it creates the visitor and returns the code); the rest go three at a time.
5. **Returning-visitor design.** A random hashed code instead of email/login, saved in `localStorage` for same-device recognition. Trade-offs are listed in ASSUMPTIONS.md.
6. **Test-first backend.** Wrote 28 API tests (validation, consent, returning visitors, concurrency, admin protection, live events) and ran them before building any page.
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

Lesson recorded: passing tests are only as good as what they assert (E), and browser-level tests find problems that API tests cannot (D, E, F).
