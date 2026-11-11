# Assumptions and requirement gaps

Each item says what the brief left open, what was decided, and why.

## Returning-visitor identification
- **Decision:** a random **visitor code** (10 characters from an alphabet without look-alikes, shown as `XXXXX-XXXXX`, ~50 bits of randomness) is created on the first upload. No email, phone number or login account is collected.
- **How it works:** the server stores only the SHA-256 hash of the code. The browser also saves the code in `localStorage`, so the same device is recognised automatically ("Welcome back"). On a different device the visitor types the code.
- **Why:** privacy-friendly (no personal data beyond a first name), simple to explain, and works on phones without accounts.
- **Limitations:** a lost code cannot be recovered (we cannot read it back from the hash). Anyone who knows the code can manage that visitor's photos. On a shared device the saved code is available to the next person (use *Withdraw consent* or clear site data). Lookups are rate-limited (60 per 15 minutes per IP) to block guessing.

## Photo display duration and order
- Default **8 seconds**, admin-configurable from 3 to 120 s. Clamped again in the display page.
- Photos play in upload order and loop. A **newly uploaded photo is shown next** (before the loop continues), so visitors see theirs within one slide duration.
- With one photo, it stays on screen. If one image fails to load it is skipped; if all fail, the page retries after 10 s. If the live connection drops, the page re-checks every 60 s and the browser reconnects automatically.

## Consent and removal
- Consent is an **explicit, unticked checkbox** required for every upload. The server rejects uploads without it, and records the time of consent.
- Photos are **only visible** (slideshow and file URL) while the visitor has consent and the photo is not hidden.
- **Withdrawal** = permanently deleting all the visitor's photo files, database rows, name and code in one step. Nothing is retained, so the code stops working. Visitors can also delete single photos. Staff can delete a visitor on request (e.g. someone who lost their code).
- **Names** are shown on the display only if an admin turns it on, and then **first name only**. Default: off.
- The privacy text on the upload page says photos may be shown publicly. A full legal privacy policy (retention period, controller contact details) is outside this project and should be added by the event organiser.

## Moderation
- **Post-moderation:** photos appear immediately (as required) and staff can hide or delete them afterwards. There is **no automatic content (nudity/violence) scanning**, so for a public screen someone should watch the admin page. This is the main residual risk.

## Upload limits and formats
- JPG, PNG and WebP, up to **8 MB** per file, **10 files** per request, **30 photos** per visitor (all configurable). The page sends one photo per request, so the 10-file limit only matters for direct API use.
- Type is checked three ways: browser type, magic bytes (first bytes of the file), and a full decode by `sharp`. Filename and declared type are never trusted. A declared type that disagrees with the content is rejected.
- Every accepted photo is **re-encoded as JPEG** (max 2560 px, quality 85). This removes EXIF metadata such as GPS location. PNG transparency becomes white. Animated images are not supported.
- iPhone HEIC photos are not accepted directly. iOS normally converts to JPEG when a page asks for JPG/PNG/WebP; this was **not tested on a real iPhone**.
- Images over 60 megapixels are rejected (decompression-bomb guard).

## Concurrent uploads and duplicates
- The app runs as **one Node process**. SQLite transactions are synchronous, so "check the limit → check for duplicate → insert" cannot interleave between simultaneous requests.
- Files are written as `*.part` then renamed, and only inserted into the database after that; the slideshow reads the database, so **incomplete uploads never appear**. Leftover `.part` files are deleted at start-up. If a photo is not saved (duplicate or limit), its file is deleted.
- **Duplicate** = the same original file (SHA-256) uploaded by the same visitor twice. Different visitors may upload the same picture.
- Running several server instances is not supported (live updates are in memory; SQLite file shared).

## Admin
- A single shared admin password from `.env` (no user accounts). Sessions last 8 hours in a signed, HttpOnly, SameSite=Strict cookie. Login is limited to 10 attempts per 15 minutes per IP. A changed `SESSION_SECRET` logs everyone out.
- The admin HTML pages are served only to signed-in users; their scripts are public but hold no secrets and every admin API call is checked server-side.

## Other
- "Recent uploads" and statistics are computed from the database; "Added in 24 hours" uses UTC timestamps.
- Photos are served with a 1-hour cache; a hidden/deleted photo may stay in a TV browser cache up to an hour (the slideshow itself stops showing it at once).
- No email, analytics, or third-party requests; the font is hosted locally.
- Browsers tested: Chromium only (see docs/TEST_RESULTS.md).
