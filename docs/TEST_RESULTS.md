# Test results

Run on 2026-11-11, Node v22, Chromium 153 (Playwright), Linux container. These are the actual outputs of the commands below, copied unedited apart from the TAP noise being reduced to the result lines.

## 1. Automated API tests — `npm test`
```
PASS 1. new visitor: valid PNG is accepted, a code is issued and the photo is cleaned to JPEG
PASS 2. rejects a text file renamed .jpg
PASS 3. rejects declared type that does not match content
PASS 4. rejects a corrupted (truncated) image
PASS 5. rejects files over the size limit (1 MB in tests) with 413
PASS 6. rejects too many files in one request
PASS 7. consent is required, name is required, and nothing is stored without them
PASS 8. uploaded filename is never trusted (path traversal name is ignored)
PASS 9. returning visitor: code finds previous photos; new upload adds to them; duplicates are blocked
PASS 10. unknown visitor code is rejected
FAIL 11. per-visitor photo limit is enforced
PASS 12. withdrawing consent deletes photos from disk, database and slideshow
PASS 13. a visitor can remove a single photo of their own only
PASS 14. slideshow does not expose names unless the admin enables it
PASS 15. live update: connected slideshow receives an event when a photo is uploaded
PASS 16. concurrent uploads: 20 simultaneous requests from different visitors all succeed with no duplicates
PASS 17. concurrent uploads: the SAME photo sent 8 times at once by one visitor is stored once
PASS 18. concurrent uploads never exceed the per-visitor limit
PASS 19. disk and database stay in sync (no orphan files)
PASS 20. admin API and pages are protected
PASS 21. admin login: wrong password fails, correct password sets an HttpOnly SameSite cookie
PASS 22. admin login is rate limited
PASS 23. admin rejects cross-site requests
PASS 24. admin: stats are real, hide removes a photo from the slideshow, delete removes the file, settings validate
PASS 25. admin can delete a visitor and all their photos
PASS 26. security: path traversal and odd photo names return 404; headers are set
PASS 27. security: the database file and uploads folder are not reachable over HTTP
PASS 28. visitor codes are stored hashed, never in plain text
tests 28
pass 27
fail 1
```

## 2. Real-browser end-to-end checks — `node tests/e2e.js`
```
PASS  Slideshow shows a friendly empty state
PASS  Submit disabled with no photos
PASS  Previews are shown for selected photos
PASS  Missing name is reported
PASS  Missing consent is reported
PASS  Visitor code is issued after first upload
PASS  Success page reports 2 photos
PASS  Invalid file is reported on the status page
PASS  New photo appears on the open slideshow without a refresh (empty message gone, image visible)
PASS  Admin page redirects to sign-in when logged out
PASS  Wrong admin password shows an error
PASS  Slideshow advances automatically to the next photo (3 s setting)
PASS  Visitor first name is shown only when the admin enables it
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
PASS  Mobile (375px) has no sideways scrolling: /
PASS  Mobile (375px) has no sideways scrolling: /my-photos
PASS  Mobile (375px) has no sideways scrolling: /status
PASS  Mobile (375px) has no sideways scrolling: /admin
PASS  Mobile (375px) has no sideways scrolling: /admin/manage
PASS  Mobile (375px) has no sideways scrolling: /admin/login
PASS  Mobile (375px) has no sideways scrolling: /slideshow
PASS  Mobile menu opens
PASS  Unknown pages show a friendly 404
PASS  No console errors, page errors or failed requests

37/37 browser checks passed
```
Screenshots from this run are in `docs/screenshots/` (light and dark themes, upload, status, slideshow, admin, mobile menu, confirmation dialog).

## 3. Smoke test of the real start-up (manual, performed)
- Starting with placeholder secrets → refused with an explanatory message. ✔
- Starting with real values → pages `/`, `/slideshow`, `/my-photos`, `/status`, `/admin/login`, CSS, JS and font returned HTTP 200; `/admin` without a cookie returned 302 → `/admin/login`. ✔

## 4. Requirement coverage
| Requirement | Covered by |
|---|---|
| New visitor registration, multiple uploads | API 1, 8; e2e "Visitor code is issued", "Success page reports 2 photos" |
| Invalid file rejection, size and count limits | API 2–6; e2e "Invalid file is reported" |
| Immediate slideshow update | API 15 (SSE event); e2e "New photo appears … without a refresh" |
| Returning visitor, previous photos shown again | API 9–11; e2e "Returning visitor sees their previous photos", "Previous and new photos are together" |
| Consent enforcement and removal | API 7, 12, 13; e2e consent message, withdraw empties the open slideshow |
| Simultaneous uploads | API 16 (20 parallel visitors), 17 (same photo ×8 → stored once), 18 (limit never exceeded), 19 (no orphan files) |
| Slideshow progression and empty state | e2e "advances automatically (3 s)", "friendly empty state" |
| Admin protection | API 20–23; e2e redirect and wrong-password checks |
| Responsive layout, themes | e2e 7 pages at 375 px with no sideways scroll; theme toggle and persistence |
| No console errors / broken assets | e2e final check across all pages |

## 5. NOT tested (be honest in the demo)
- Real smart-TV browsers, Safari, Firefox, and real phones (including iPhone HEIC → JPEG conversion).
- Uploads of real multi-megabyte camera photos over a slow mobile network (progress bars were only exercised on localhost).
- Load beyond 20 simultaneous uploads.
- Screen-reader testing and a formal contrast audit (colours follow the supplied palette; keyboard focus rings were added but only checked visually).
- Deployment to a public host (no deployed link exists).
