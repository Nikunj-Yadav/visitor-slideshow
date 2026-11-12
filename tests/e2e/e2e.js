// Browser end-to-end test (OPTIONAL, development only): drives real Chromium through the PHP application.
// The application itself does not need Node.js - only this test tool does.
//   cd tests/e2e && npm install && npx playwright install chromium
//   (Apache + MySQL running, TEST database configured)   node e2e.js
// Environment: BASE_URL (default http://localhost/visitor-slideshow/public/); MW_DB_NAME must end with _test.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const { execFileSync } = require('node:child_process');

const results = [];
const check = (name, ok, extra = '') => { results.push({ name, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${ok ? '' : '  ' + extra}`); };
const shots = path.join(__dirname, '..', '..', 'docs', 'screenshots'); fs.mkdirSync(shots, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const base = (process.env.BASE_URL || 'http://localhost/visitor-slideshow/public/').replace(/\/?$/, '/');
const ADMIN_PASSWORD = 'e2e-password-123';
const fixtures = path.join(__dirname, '..', 'fixtures');
const files = [0, 1, 2].map((i) => path.join(fixtures, `pic${i}.jpg`));
const fake = path.join(fixtures, 'notes.jpg');

(async () => {
  // start from an empty TEST database and empty uploads folder
  execFileSync('php', [path.join(__dirname, '..', 'reset.php')], { stdio: 'inherit', env: process.env });

  const browser = await chromium.launch();
  const problems = [];
  const watch = (page, label) => {
    page.on('console', (m) => { if (m.type() === 'error') problems.push(`${label}: console error: ${m.text()}`); });
    page.on('pageerror', (e) => problems.push(`${label}: page error: ${e.message}`));
    page.on('requestfailed', (r) => problems.push(`${label}: request failed: ${r.url()}`));
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/api/')) problems.push(`${label}: HTTP ${r.status()} ${r.url()}`); });
  };
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const tv = await ctx.newPage(); watch(tv, 'slideshow');
  const page = await ctx.newPage(); watch(page, 'visitor');

  try {
    // ---- 1. empty slideshow ----
    await tv.goto(base + 'slideshow.php');
    await tv.waitForSelector('#empty:not([hidden])');
    check('Slideshow shows a friendly empty state', (await tv.locator('#empty').isVisible()) && (await tv.textContent('#empty')).includes('Waiting for the first photo'));
    await tv.screenshot({ path: path.join(shots, 'slideshow-empty.png') });

    // ---- 2. upload page + validation messages ----
    await page.goto(base);
    check('Root address opens the visitor page', page.url().endsWith('/public/') && (await page.textContent('h1')).includes('Share your photos'), page.url());
    await page.screenshot({ path: path.join(shots, 'upload-light.png') });
    check('Submit disabled with no photos', await page.locator('#submit').isDisabled());
    await page.setInputFiles('#file', [files[0], files[1], fake]);
    await page.waitForSelector('.tile >> nth=2');
    check('Previews are shown for selected photos', (await page.locator('.tile').count()) === 3);
    await page.click('#submit');
    check('Missing name is reported', (await page.textContent('#error')).includes('name'));
    await page.fill('#name', 'Asha Patel');
    await page.click('#submit');
    check('Missing consent is reported', (await page.textContent('#error')).toLowerCase().includes('agree'));
    await page.screenshot({ path: path.join(shots, 'upload-filled.png') });

    // ---- 3. real upload (2 valid + 1 fake) ----
    await page.check('#consent');
    await page.click('#submit');
    await page.waitForURL('**/status.php');
    const code = (await page.textContent('#code')).trim();
    check('Visitor code is issued after first upload', /^[A-Z2-9]{5}-[A-Z2-9]{5}$/.test(code), code);
    check('Success page reports 2 photos', (await page.textContent('#h')).includes('2 photos'));
    check('Invalid file is reported on the status page', (await page.textContent('#plist')).includes('notes.jpg'));
    await page.screenshot({ path: path.join(shots, 'status.png') });

    // ---- 4. slideshow updated live (polling), without reload ----
    await tv.waitForSelector('.layer.on', { timeout: 10000 });
    check('New photo appears on the open slideshow without a refresh (empty message gone, image visible)', (await tv.locator('#empty').isVisible()) === false && (await tv.locator('.layer.on').isVisible()));
    await tv.screenshot({ path: path.join(shots, 'slideshow.png') });

    // ---- 5. first-run admin setup, then settings + progression ----
    const adminPage = await ctx.newPage(); watch(adminPage, 'admin');
    await adminPage.goto(base + 'admin/index.php');
    check('First visit to /admin goes to the password setup page', adminPage.url().endsWith('admin/setup.php'), adminPage.url());
    await adminPage.screenshot({ path: path.join(shots, 'admin-setup.png') });
    await adminPage.fill('#pw', ADMIN_PASSWORD); await adminPage.fill('#pw2', 'something-else-1'); await adminPage.click('button[type=submit]');
    await adminPage.waitForSelector('#error:not([hidden])');
    check('Setup rejects mismatching passwords', (await adminPage.textContent('#error')).includes('match'));
    await adminPage.fill('#pw2', ADMIN_PASSWORD); await adminPage.click('button[type=submit]');
    await adminPage.waitForURL('**/admin/index.php');
    check('Setup creates the admin and opens the dashboard', await adminPage.locator('h1').first().textContent() === 'Dashboard');
    await adminPage.fill('#duration', '3'); await adminPage.check('#shownames'); await adminPage.click('#settings button[type=submit]');
    await adminPage.waitForSelector('.toast--success');
    const srcs = new Set();
    for (let i = 0; i < 10; i++) { srcs.add(await tv.evaluate(() => document.querySelector('.layer.on')?.getAttribute('src'))); await sleep(1000); }
    check('Slideshow advances automatically to the next photo (3 s setting)', srcs.size >= 2, [...srcs].join());
    check('Visitor first name is shown only when the admin enables it', (await tv.textContent('#caption')).trim() === 'Shared by Asha');

    // ---- 5b. sign-in for a fresh browser (no cookies) ----
    const ctxLogin = await browser.newContext(); const lp = await ctxLogin.newPage(); watch(lp, 'login');
    await lp.goto(base + 'admin/index.php');
    check('Admin page redirects to sign-in when logged out', lp.url().endsWith('admin/login.php'), lp.url());
    await lp.fill('#pw', 'wrong-password'); await lp.click('button[type=submit]');
    await lp.waitForSelector('#error:not([hidden])');
    check('Wrong admin password shows an error', (await lp.textContent('#error')).includes('Incorrect'));
    await lp.fill('#pw', ADMIN_PASSWORD); await lp.click('button[type=submit]');
    await lp.waitForURL('**/admin/index.php');
    check('Correct admin password opens the dashboard', true);
    await lp.click('#signout'); await lp.waitForURL('**/admin/login.php');
    check('Sign out returns to the sign-in page', true);
    await ctxLogin.close();

    // ---- 6. returning visitor in a fresh browser context (no saved data) ----
    const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const p2 = await ctx2.newPage(); watch(p2, 'returning');
    await p2.goto(base + 'my-photos.php');
    await p2.fill('#code', code.toLowerCase());
    await p2.click('button[type=submit]');
    await p2.waitForSelector('.photo-grid');
    check('Returning visitor sees their previous photos', (await p2.locator('.photo-card').count()) === 2);
    await p2.screenshot({ path: path.join(shots, 'my-photos.png') });
    await p2.goto(base);
    await p2.click('#mode-return'); await p2.fill('#code', code);
    await p2.setInputFiles('#file', [files[2]]); await p2.check('#consent'); await p2.click('#submit');
    await p2.waitForURL('**/status.php');
    check('Returning visitor can add another photo with the same code', (await p2.textContent('#h')).includes('on the wall'));
    check('Returning visitor does not get a new code', await p2.locator('#codecard').isHidden());
    await p2.goto(base + 'my-photos.php');
    await p2.waitForSelector('.photo-grid');
    check('Previous and new photos are together (3)', (await p2.locator('.photo-card').count()) === 3);
    await p2.goto(base);
    await p2.waitForFunction(() => document.getElementById('welcome').textContent.includes('Welcome back'));
    check('Same device recognises the returning visitor automatically', true);

    // ---- 7. admin dashboard + manage ----
    await adminPage.reload();
    await adminPage.waitForSelector('.stat .num');
    const nums = await adminPage.locator('.stat .num').allTextContents();
    check('Dashboard shows real counts (1 visitor, 3 photos)', nums[0] === '1' && nums[1] === '3', nums.join());
    await adminPage.screenshot({ path: path.join(shots, 'admin-dashboard.png'), fullPage: true });
    await adminPage.goto(base + 'admin/manage.php');
    await adminPage.waitForSelector('.photo-card');
    await adminPage.fill('#q', 'nobody');
    await adminPage.waitForSelector('.empty');
    check('Search with no match shows an empty state', true);
    await adminPage.fill('#q', 'asha');
    await adminPage.waitForSelector('.photo-card');
    await adminPage.locator('.photo-card').first().getByRole('button', { name: /Hide/ }).click();
    await adminPage.waitForSelector('.photo-card.is-hidden');
    check('Hiding a photo works and is marked as hidden', true);
    await adminPage.screenshot({ path: path.join(shots, 'admin-manage.png') });
    await adminPage.locator('.photo-card').first().getByRole('button', { name: /Delete photo/ }).click();
    await adminPage.waitForSelector('dialog[open]');
    await adminPage.screenshot({ path: path.join(shots, 'confirm-dialog.png') });
    await adminPage.getByRole('button', { name: 'Cancel' }).click();
    check('Delete needs confirmation and Cancel keeps the photo', (await adminPage.locator('.photo-card').count()) === 3);
    await adminPage.click('#tab-visitors');
    await adminPage.waitForSelector('table');
    check('Visitors table lists the visitor', (await adminPage.textContent('tbody')).includes('Asha Patel'));

    // ---- 8. withdraw consent -> slideshow empties live ----
    await p2.goto(base + 'my-photos.php');
    await p2.waitForSelector('#withdraw');
    await p2.click('#withdraw'); await p2.waitForSelector('dialog[open]');
    await p2.getByRole('button', { name: 'Delete everything' }).click();
    await tv.waitForSelector('#empty:not([hidden])', { timeout: 10000 });
    check('Withdrawing consent removes photos from the open slideshow', await tv.locator('#empty').isVisible());
    const gone = await fetch(base + 'api/visitor-lookup.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }) });
    check('Withdrawn visitor code no longer works', gone.status === 404);

    // ---- 9. theme ----
    await page.goto(base);
    await page.click('#theme-toggle');
    check('Theme toggle switches to dark', (await page.evaluate(() => document.documentElement.dataset.theme)) === 'dark');
    await page.reload();
    check('Theme preference is remembered after reload', (await page.evaluate(() => document.documentElement.dataset.theme)) === 'dark');
    await page.screenshot({ path: path.join(shots, 'upload-dark.png') });
    await adminPage.evaluate(() => localStorage.setItem('theme', 'dark'));
    await adminPage.goto(base + 'admin/index.php'); await adminPage.waitForSelector('.stat .num');
    await adminPage.screenshot({ path: path.join(shots, 'admin-dark.png'), fullPage: true });

    // ---- 10. mobile layout ----
    const mctx = await browser.newContext({ viewport: { width: 375, height: 760 }, isMobile: true, hasTouch: true });
    const m = await mctx.newPage(); watch(m, 'mobile');
    await mctx.addCookies(await ctx.cookies());
    for (const p of ['index.php', 'my-photos.php', 'status.php', 'admin/index.php', 'admin/manage.php', 'slideshow.php']) {
      await m.goto(base + p); await sleep(500);
      const overflow = await m.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      check(`Mobile (375px) has no sideways scrolling: ${p}`, overflow <= 0, `overflow ${overflow}px`);
    }
    await m.goto(base);
    await m.click('#menu-toggle');
    check('Mobile menu opens', await m.locator('#nav-links a').first().isVisible());
    await m.screenshot({ path: path.join(shots, 'mobile-menu.png') });

    // ---- 11. no console errors / broken assets ----
    await page.goto(base + 'does-not-exist');
    check('Unknown pages show a friendly 404', (await page.textContent('h1')).includes('not found'));
    const real = problems.filter((p) => !p.includes('404') && !p.includes('Failed to load resource'));
    check('No console errors, page errors or failed requests', real.length === 0, '\n  ' + real.join('\n  '));
  } catch (e) {
    check('Test run completed without an unexpected error', false, e.stack.split('\n').slice(0, 4).join(' | '));
  }
  await browser.close();
  const failed = results.filter((r) => !r.ok).length;
  console.log(`\n${results.length - failed}/${results.length} browser checks passed`);
  process.exit(failed ? 1 : 0);
})();
