# Memory Wall — Visitor Photo Slideshow (Core PHP + MySQL)

Visitors open a link on their phone, upload photos, and the photos appear within seconds on a shared TV or computer display. Returning visitors use a personal code to see, add to, or delete their photos.

This is the **PHP + MySQL** version, running on **XAMPP** (Apache + MySQL + phpMyAdmin). It was migrated from an earlier Node.js/SQLite version; the interface and features are the same. No Node.js, npm or terminal is needed to run it.

## Features
- **Visitor upload page** — name, drag-and-drop or tap to choose photos, previews, per-photo progress, clear validation messages, required consent checkbox.
- **Live slideshow** (`slideshow.php`) — full-screen, cross-fading, configurable seconds per photo. New photos appear automatically (the page asks the server for changes every 3 seconds). Friendly empty state.
- **Returning visitors** — a personal visitor code (shown once after the first upload, remembered on that device).
- **Consent and removal** — photos show only with consent; visitors can delete single photos or withdraw consent (deletes everything).
- **Admin area** — real statistics, display settings, hide/show/delete photos, delete visitors, search, filter, sort, confirmation dialogs.
- **Neumorphic UI**, light and dark themes, keyboard focus states, reduced-motion support, responsive down to phones.

## Technology
| Part | Choice |
|---|---|
| Backend | Core PHP 8 (no framework), one small file per endpoint |
| Database | MySQL / MariaDB through **PDO prepared statements** |
| Images | **PHP GD** (checks, rotates, resizes, re-saves as clean JPEG) |
| Frontend | HTML5, CSS3, vanilla JavaScript, inline SVG icons (unchanged from the original UI) |
| Live updates | JavaScript polling (see ARCHITECTURE.md for why) |
| Server | Apache (XAMPP) |

---

## Install and run with XAMPP (step by step)

### 1. Install XAMPP and start the servers
1. Download and install **XAMPP for PHP 8.x** from apachefriends.org.
2. Open the **XAMPP Control Panel** and press **Start** next to **Apache** and **MySQL**. Both should turn green.

### 2. Put the project in `htdocs`
Copy the whole `visitor-slideshow` folder to
- Windows: `C:\xampp\htdocs\visitor-slideshow`
- macOS: `/Applications/XAMPP/htdocs/visitor-slideshow`
- Linux: `/opt/lampp/htdocs/visitor-slideshow`

### 3. Create the database with phpMyAdmin
1. Open **http://localhost/phpmyadmin**.
2. Click the **Import** tab (top menu, with no database selected).
3. **Choose file** → select `database.sql` from the project folder → **Import** (button at the bottom).
4. You should now see a database called **`visitor_slideshow`** with 6 tables (`visitors`, `photos`, `settings`, `activity`, `admin_users`, `rate_limit_hits`).

(The file creates the database itself. It is safe to import twice.)

### 4. Database settings
A fresh XAMPP uses user `root` with an **empty password**, and the project is already set up for that — **nothing to change**.
If you set a MySQL password: copy `config/config.example.php` to `config/config.local.php` and edit it. (`config.local.php` is never published to Git.)

### 5. Upload folder permissions
- **Windows:** nothing to do.
- **macOS / Linux:** open a terminal in the project folder and run `chmod -R 777 uploads` (XAMPP's web server runs as a different user and must be allowed to write there).

### 6. Check that everything is ready
Open **http://localhost/visitor-slideshow/public/check.php**. Every line should say **OK** (including *Private folders are blocked from the web*). If something says *Fix needed*, the page tells you what to do (for example enabling the GD extension in `php.ini`, or starting MySQL). This page only works on the same computer.

### 7. Use the application
| What | Address |
|---|---|
| Visitor page (upload) | http://localhost/visitor-slideshow/ |
| Slideshow (put this on the TV, press **F** for full screen) | http://localhost/visitor-slideshow/public/slideshow.php |
| My photos (returning visitors) | http://localhost/visitor-slideshow/public/my-photos.php |
| Admin | http://localhost/visitor-slideshow/public/admin/index.php |

**First time in the admin area:** you are asked to create the admin password (at least 10 characters). It is stored only as a secure hash. Afterwards you sign in with that password.
**Forgot the admin password?** In phpMyAdmin open table `admin_users`, delete the single row, then visit the admin address again to create a new password.

**Visitors on phones (same Wi-Fi):** find your computer's IP address (Windows: `ipconfig`), then visitors open `http://192.168.x.x/visitor-slideshow/`. Allow Apache through the Windows firewall when asked.

### How a visitor uses it
1. Open the visitor page, enter a name, choose photos, tick the consent box, press **Upload photos**.
2. They get a code such as `K7M4Q-9XWBD`. Keep it.
3. Coming back on the same device: recognised automatically. On another device: **I have a code** tab or the **My photos** page.
4. **My photos** → remove a photo, or **Withdraw consent** to delete everything.

### Troubleshooting
| Problem | Fix |
|---|---|
| "Connection refused" / "Something went wrong" | MySQL is not started, or `database.sql` was not imported. Open `check.php`. |
| Photos are rejected with "could not be processed" | The GD extension is off. In XAMPP: Apache → Config → `php.ini` → make sure `extension=gd` has no `;` in front → restart Apache. |
| "Upload failed" for every photo (macOS/Linux) | Run `chmod -R 777 uploads`. |
| `check.php` says private folders are **NOT blocked** | Apache is ignoring the `.htaccess` files. In `xampp/apache/conf/httpd.conf` the `<Directory "…/htdocs">` block needs `AllowOverride All` (this is the XAMPP default). Fix it and restart Apache — until then anyone could open `uploads/` and `config/` by typing the address. |
| Error details | `xampp/apache/logs/error.log` (the app logs real errors there and shows visitors only a friendly message). |
| Photo larger than 8 MB | Limit is set in `config/config.php` (`max_file_mb`); PHP itself allows up to 12 MB via `public/.htaccess`. |

## Settings
`config/config.php` lists every setting with its default; override any of them in `config/config.local.php`: database login, `max_file_mb` (8), `max_files_per_request` (10), `max_photos_per_visitor` (30), `session_hours` (8), rate limits, `cookie_secure` (set `true` on HTTPS).

## Tests
Tests run against a **separate test database** so real data is never touched.
```text
1. Copy the project to htdocs/visitor-slideshow-test
2. In that copy create config/config.local.php containing:
      <?php return ['db_name' => 'visitor_slideshow_test'];
3. cd into that copy, then:
      php tests/create-test-db.php        (creates the test database)
      php tests/run.php http://localhost/visitor-slideshow-test/public/
```
On Windows use `C:\xampp\php\php.exe` instead of `php`. `tests/run.php` runs 42 tests over HTTP and directly checks MySQL. An optional browser test (Playwright; the only part that needs Node.js) is in `tests/e2e/`. Real results: [docs/TEST_RESULTS.md](docs/TEST_RESULTS.md).

## Project structure
```
config/     config.php (settings loader)  config.example.php  (database login etc.)
includes/   bootstrap.php  helpers.php  db.php  auth.php  ratelimit.php  images.php (GD)  layout.php
public/     visitor and admin pages (*.php), photo.php, check.php, css/ js/ fonts/
public/api/        JSON endpoints used by the pages (upload, lookup, slideshow, ...)
public/api/admin/  admin endpoints (login, stats, photos, visitors, settings, ...)
uploads/    photo files (blocked from direct web access)
database.sql   MySQL schema      tests/   automated tests      docs/   test results, screenshots
```
Only `public/` is meant to be opened in a browser; `config/`, `includes/`, `uploads/`, `tests/` and `docs/` are blocked by `.htaccess` files. On a real server, point the web server's document root at `public/`.

## Documentation
[ASSUMPTIONS.md](ASSUMPTIONS.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [AI_LOG.md](AI_LOG.md) · [docs/TEST_RESULTS.md](docs/TEST_RESULTS.md)
