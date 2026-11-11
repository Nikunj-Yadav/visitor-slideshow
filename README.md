# Memory Wall — Visitor Photo Slideshow

Visitors scan a link, upload photos from their phone, and the photos appear on a shared TV or computer display within seconds. Returning visitors can use a personal code to see, add to, or delete their photos.

## Features
- **Visitor upload page** — name, drag-and-drop or tap to choose photos, previews, per-photo progress, clear validation messages, required consent checkbox.
- **Live slideshow** (`/slideshow`) — full-screen, cross-fading, configurable time per photo, new photos jump the queue without a refresh, friendly empty state.
- **Returning visitors** — a personal visitor code (shown once after the first upload and remembered on the same device).
- **Consent and removal** — photos are only shown with consent; visitors can delete single photos or withdraw consent (deletes everything).
- **Admin** (`/admin`, password protected) — real statistics, display settings, hide/show/delete photos, delete visitors, search, filter and sort.
- **Neumorphic UI** with light and dark themes, keyboard focus states, reduced-motion support, responsive down to phones.

## Technology and why
| Part | Choice | Why |
|---|---|---|
| Server | Node.js + Express | Small, widely known, easy to explain. |
| Database | SQLite (`better-sqlite3`) | A real SQL database in one file. Synchronous transactions make concurrent uploads safe without extra locking. |
| Uploads | `multer` (memory) + `sharp` | Validates, fully decodes and re-encodes every image (strips GPS/EXIF, fixes rotation, resizes for TV). |
| Real-time | Server-Sent Events | One-way push from server to slideshow; built into browsers, no library. |
| Security | `helmet`, `express-rate-limit`, signed cookie | Standard headers and CSP, brute-force protection, simple admin login. |
| Frontend | Plain CSS, vanilla JS, inline SVG | As required — no frameworks or templates. Font: Manrope (self-hosted). |

## Setup
Requires **Node.js 20 or newer** (developed on Node 22).

```bash
npm install
cp .env.example .env      # then edit .env: set ADMIN_PASSWORD and SESSION_SECRET
npm start
```
Generate a secret with `openssl rand -hex 32`. The server refuses to start while the placeholder values are in `.env`.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | 3000 | HTTP port |
| `ADMIN_PASSWORD` | — (required) | Admin login password |
| `SESSION_SECRET` | — (required) | Signs the admin cookie |
| `DATA_DIR` | `./data` | Database and uploaded photos |
| `MAX_FILE_MB` | 8 | Largest accepted file |
| `MAX_FILES_PER_REQUEST` | 10 | Files per upload request |
| `MAX_PHOTOS_PER_VISITOR` | 30 | Total photos one visitor can have |
| `COOKIE_SECURE` | false | Set `true` behind HTTPS |
| `TRUST_PROXY` | false | Set `true` behind a reverse proxy (so rate limits see real IPs) |

## How to use
1. **Display:** open `http://<server>:3000/slideshow` on the TV/computer browser and press **F** for full screen. Move the mouse to reveal the full-screen button.
2. **Visitors:** open `http://<server>:3000/` on their phone (put this link in a QR code). Enter a name, choose photos, tick the consent box, press **Upload photos**. They receive a code such as `K7M4Q-9XWBD` — keep it.
3. **Coming back:** same device → recognised automatically. Another device → *I have a code* tab, or the **My photos** page.
4. **Removing photos:** **My photos** → remove one photo, or **Withdraw consent** to delete everything.
5. **Admin:** `http://<server>:3000/admin` → sign in. Dashboard shows statistics and display settings; **Manage** hides/deletes photos and visitors.

## Tests
```bash
npm test                  # 29 automated API tests (no browser needed)
npm i --no-save playwright && npx playwright install chromium
node tests/e2e.js         # 37 real-browser checks; writes screenshots to docs/screenshots
```
Results of the actual runs are in [docs/TEST_RESULTS.md](docs/TEST_RESULTS.md).

## Deployment
No deployed link exists yet — this must be done by the project owner. Any host that runs a long-lived Node process with a **persistent disk** works (a small VPS, Render, Railway, Fly.io). Steps: set the environment variables above, set `DATA_DIR` to the persistent disk, use HTTPS (`COOKIE_SECURE=true`, `TRUST_PROXY=true`), and make sure the proxy does not buffer `/api/events` (the app sends `X-Accel-Buffering: no`). Run **one** instance only (SQLite + in-memory live updates). Platforms with ephemeral disks (e.g. free tiers without a volume) will lose photos on restart.

## Documentation
[ASSUMPTIONS.md](ASSUMPTIONS.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [AI_LOG.md](AI_LOG.md) · [docs/TEST_RESULTS.md](docs/TEST_RESULTS.md)
