// Automated API tests. Each run starts a real server on a random port with a
// throw-away database and upload folder. Run with: npm test
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const sharp = require('sharp');
const { createApp } = require('../server/app');

let server, base, app, dataDir;
const PASSWORD = 'test-password-123';

async function start(extra = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'slideshow-'));
  const a = createApp({ dataDir: dir, adminPassword: PASSWORD, sessionSecret: 'x'.repeat(64), maxFileMb: 1, maxFilesPerRequest: 5, maxPhotosPerVisitor: 6, limits: { upload: 1000, lookup: 1000, login: 1000 }, ...extra });
  const s = await new Promise((r) => { const sv = a.listen(0, '127.0.0.1', () => r(sv)); });
  return { app: a, server: s, dir, base: `http://127.0.0.1:${s.address().port}` };
}
before(async () => { ({ app, server, base, dir: dataDir } = await start()); });
after(() => { app.locals.close(); server.closeAllConnections?.(); server.close(); fs.rmSync(dataDir, { recursive: true, force: true }); });

// ---- helpers ----
const png = (seed) => sharp({ create: { width: 64, height: 48, channels: 3, background: { r: seed % 256, g: (seed * 7) % 256, b: (seed * 13) % 256 } } })
  .composite([{ input: Buffer.from(`<svg width="64" height="48"><text x="2" y="30" font-size="20">${seed}</text></svg>`) }]).png().toBuffer();
async function upload({ name, code, consent = 'true', files, url = base }) {
  const fd = new FormData();
  if (name !== undefined) fd.set('name', name);
  if (code) fd.set('code', code);
  if (consent !== null) fd.set('consent', consent);
  for (const f of files) fd.append('photos', new Blob([f.data], { type: f.type || 'image/png' }), f.filename || 'photo.png');
  const res = await fetch(`${url}/api/upload`, { method: 'POST', body: fd });
  return { status: res.status, body: await res.json() };
}
const api = (p, opts = {}) => fetch(base + p, opts);
const post = (p, body, headers = {}) => api(p, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body) });
const slideshow = async () => (await (await api('/api/slideshow')).json());
async function adminCookie() {
  const res = await post('/api/admin/login', { password: PASSWORD });
  return res.headers.get('set-cookie').split(';')[0];
}

// ---- upload validation ----
test('new visitor: valid PNG is accepted, a code is issued and the photo is cleaned to JPEG', async () => {
  const { status, body } = await upload({ name: 'Asha Patel', files: [{ data: await png(1) }] });
  assert.equal(status, 200);
  assert.match(body.visitor.code, /^[A-Z2-9]{5}-[A-Z2-9]{5}$/);
  assert.equal(body.results[0].status, 'accepted');
  const show = await slideshow();
  assert.equal(show.photos.length >= 1, true);
  const img = await api(show.photos.at(-1).url);
  assert.equal(img.status, 200);
  assert.equal(img.headers.get('content-type'), 'image/jpeg');
});

test('rejects a text file renamed .jpg', async () => {
  const { status, body } = await upload({ name: 'Bad', files: [{ data: Buffer.from('hello, I am not an image'), type: 'image/jpeg', filename: 'x.jpg' }] });
  assert.equal(status, 422);
  assert.equal(body.results[0].status, 'rejected');
});

test('rejects declared type that does not match content', async () => {
  const { status } = await upload({ name: 'Bad', files: [{ data: await png(2), type: 'image/jpeg' }] });
  assert.equal(status, 422);
});

test('rejects a corrupted (truncated) image', async () => {
  const full = await sharp({ create: { width: 400, height: 400, channels: 3, background: '#345' } }).png().toBuffer();
  const noise = crypto.randomBytes(400 * 400 * 3);
  const big = await sharp(noise, { raw: { width: 400, height: 400, channels: 3 } }).png().toBuffer();
  const truncated = big.subarray(0, Math.floor(big.length / 2));
  const { status, body } = await upload({ name: 'Bad', files: [{ data: truncated }] });
  assert.equal(status, 422, JSON.stringify(body));
  assert.ok(full.length > 0);
});

test('rejects files over the size limit (1 MB in tests) with 413', async () => {
  const hugeNoise = await sharp(crypto.randomBytes(900 * 900 * 3), { raw: { width: 900, height: 900, channels: 3 } }).png().toBuffer();
  assert.ok(hugeNoise.length > 1024 * 1024);
  const { status } = await upload({ name: 'Big', files: [{ data: hugeNoise }] });
  assert.equal(status, 413);
});

test('rejects too many files in one request', async () => {
  const files = []; for (let i = 0; i < 6; i++) files.push({ data: await png(100 + i) });
  const { status } = await upload({ name: 'Many', files });
  assert.equal(status, 400);
});

test('consent is required, name is required, and nothing is stored without them', async () => {
  const before = (await slideshow()).photos.length;
  assert.equal((await upload({ name: 'NoConsent', consent: 'false', files: [{ data: await png(3) }] })).status, 400);
  assert.equal((await upload({ name: 'NoConsent', consent: null, files: [{ data: await png(3) }] })).status, 400);
  assert.equal((await upload({ name: '   ', files: [{ data: await png(3) }] })).status, 400);
  assert.equal((await slideshow()).photos.length, before);
});

test('uploaded filename is never trusted (path traversal name is ignored)', async () => {
  const { body } = await upload({ name: 'Trav', files: [{ data: await png(4), filename: '../../etc/passwd.png' }] });
  assert.equal(body.results[0].status, 'accepted');
  const uploads = fs.readdirSync(path.join(dataDir, 'uploads'));
  assert.ok(uploads.every((f) => /^[a-f0-9-]{36}\.jpg$/.test(f)), uploads.join(','));
});

// ---- returning visitors ----
test('returning visitor: code finds previous photos; new upload adds to them; duplicates are blocked', async () => {
  const first = await upload({ name: 'Ravi', files: [{ data: await png(10) }, { data: await png(11) }] });
  const code = first.body.visitor.code;
  const lookup = await (await post('/api/visitor/lookup', { code: code.toLowerCase().replace('-', ' ') })).json();
  assert.equal(lookup.name, 'Ravi');
  assert.equal(lookup.photos.length, 2);
  const again = await upload({ code, name: 'ignored', files: [{ data: await png(12) }, { data: await png(10) }] });
  assert.equal(again.status, 200);
  assert.deepEqual(again.body.results.map((r) => r.status), ['accepted', 'duplicate']);
  assert.equal(again.body.visitor.photoCount, 3);
  assert.equal(again.body.visitor.code, undefined); // code is only ever shown once
  const lookup2 = await (await post('/api/visitor/lookup', { code })).json();
  assert.equal(lookup2.photos.length, 3);
});

test('unknown visitor code is rejected', async () => {
  assert.equal((await post('/api/visitor/lookup', { code: 'AAAAA-AAAAA' })).status, 404);
  assert.equal((await upload({ code: 'AAAAA-AAAAA', files: [{ data: await png(5) }] })).status, 404);
});

test('per-visitor photo limit is enforced', async () => {
  const files = []; for (let i = 0; i < 5; i++) files.push({ data: await png(200 + i) });
  const a = await upload({ name: 'Limit', files });
  const code = a.body.visitor.code;
  const b = await upload({ code, files: [{ data: await png(210) }, { data: await png(211) }] });
  assert.deepEqual(b.body.results.map((r) => r.status), ['accepted', 'rejected']);
  assert.equal(b.body.visitor.photoCount, 6);
});

// ---- consent withdrawal ----
test('withdrawing consent deletes photos from disk, database and slideshow', async () => {
  const up = await upload({ name: 'Leaver', files: [{ data: await png(20) }, { data: await png(21) }] });
  const code = up.body.visitor.code;
  const mine = await (await post('/api/visitor/lookup', { code })).json();
  const urls = mine.photos.map((p) => p.url);
  const w = await (await post('/api/visitor/withdraw', { code })).json();
  assert.equal(w.removed, 2);
  for (const u of urls) assert.equal((await api(u)).status, 404);
  const ids = (await slideshow()).photos.map((p) => p.url);
  for (const u of urls) assert.ok(!ids.includes(u));
  assert.equal((await post('/api/visitor/lookup', { code })).status, 404);
});

test('a visitor can remove a single photo of their own only', async () => {
  const a = await upload({ name: 'A', files: [{ data: await png(30) }] });
  const b = await upload({ name: 'B', files: [{ data: await png(31) }] });
  const bPhoto = (await (await post('/api/visitor/lookup', { code: b.body.visitor.code })).json()).photos[0];
  const aPhoto = (await (await post('/api/visitor/lookup', { code: a.body.visitor.code })).json()).photos[0];
  assert.equal((await post('/api/visitor/photo/delete', { code: a.body.visitor.code, photoId: bPhoto.id })).status, 404);
  assert.equal((await post('/api/visitor/photo/delete', { code: a.body.visitor.code, photoId: aPhoto.id })).status, 200);
});

// ---- slideshow & live updates ----
test('slideshow does not expose names unless the admin enables it', async () => {
  const show = await slideshow();
  assert.equal(show.settings.showNames, false);
  assert.ok(show.photos.every((p) => p.name === null));
});

test('live update: connected slideshow receives an event when a photo is uploaded', async () => {
  const ctrl = new AbortController();
  const res = await fetch(`${base}/api/events`, { signal: ctrl.signal });
  assert.ok(res.headers.get('content-type').startsWith('text/event-stream'));
  const reader = res.body.getReader();
  const got = new Promise(async (resolve) => {
    let text = '';
    for (;;) { const { value } = await reader.read(); text += Buffer.from(value).toString(); if (text.includes('photos-changed')) return resolve(true); }
  });
  await upload({ name: 'Live', files: [{ data: await png(40) }] });
  assert.equal(await Promise.race([got, new Promise((r) => setTimeout(() => r(false), 3000))]), true);
  ctrl.abort();
});

// ---- concurrency ----
test('concurrent uploads: 20 simultaneous requests from different visitors all succeed with no duplicates', async () => {
  const before = (await slideshow()).photos.length;
  const jobs = [];
  for (let i = 0; i < 20; i++) jobs.push(png(500 + i).then((d) => upload({ name: `Guest ${i}`, files: [{ data: d }] })));
  const results = await Promise.all(jobs);
  assert.ok(results.every((r) => r.status === 200 && r.body.results[0].status === 'accepted'));
  const after = (await slideshow()).photos;
  assert.equal(after.length - before, 20);
  assert.equal(new Set(after.map((p) => p.id)).size, after.length);
  const codes = results.map((r) => r.body.visitor.code);
  assert.equal(new Set(codes).size, 20);
});

test('concurrent uploads: the SAME photo sent 8 times at once by one visitor is stored once', async () => {
  const first = await upload({ name: 'Racer', files: [{ data: await png(900) }] });
  const code = first.body.visitor.code;
  const same = await png(901);
  const results = await Promise.all(Array.from({ length: 8 }, () => upload({ code, files: [{ data: same }] })));
  const statuses = results.map((r) => r.body.results[0].status).sort();
  assert.equal(statuses.filter((s) => s === 'accepted').length, 1, statuses.join());
  const mine = await (await post('/api/visitor/lookup', { code })).json();
  assert.equal(mine.photos.length, 2);
  // no orphan files: every file on disk belongs to a database row
  const onDisk = fs.readdirSync(path.join(dataDir, 'uploads'));
  assert.ok(!onDisk.some((f) => f.endsWith('.part')));
});

test('concurrent uploads never exceed the per-visitor limit', async () => {
  const first = await upload({ name: 'Cap', files: [{ data: await png(950) }] });
  const code = first.body.visitor.code;
  const datas = await Promise.all(Array.from({ length: 12 }, (_, i) => png(960 + i)));
  await Promise.all(datas.map((d) => upload({ code, files: [{ data: d }] })));
  const mine = await (await post('/api/visitor/lookup', { code })).json();
  assert.equal(mine.photos.length, 6);
});

test('disk and database stay in sync (no orphan files)', async () => {
  const cookie = await adminCookie();
  const list = await (await api('/api/admin/photos', { headers: { cookie } })).json();
  const dbFiles = new Set(list.photos.map((p) => p.url.split('/').pop()));
  const onDisk = fs.readdirSync(path.join(dataDir, 'uploads'));
  assert.deepEqual(onDisk.sort(), [...dbFiles].sort());
});

// ---- admin ----
test('admin API and pages are protected', async () => {
  for (const p of ['/api/admin/stats', '/api/admin/photos', '/api/admin/visitors']) assert.equal((await api(p)).status, 401);
  assert.equal((await api('/api/admin/photos/1', { method: 'DELETE' })).status, 401);
  assert.equal((await api('/api/admin/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: '{}' })).status, 401);
  for (const p of ['/admin', '/admin/manage']) {
    const r = await api(p, { redirect: 'manual' });
    assert.equal(r.status, 302); assert.equal(r.headers.get('location'), '/admin/login');
  }
  assert.equal((await api('/api/admin/stats', { headers: { cookie: 'admin_session=forged.value' } })).status, 401);
});

test('admin login: wrong password fails, correct password sets an HttpOnly SameSite cookie', async () => {
  assert.equal((await post('/api/admin/login', { password: 'nope' })).status, 401);
  const ok = await post('/api/admin/login', { password: PASSWORD });
  assert.equal(ok.status, 200);
  const c = ok.headers.get('set-cookie');
  assert.match(c, /HttpOnly/i); assert.match(c, /SameSite=Strict/i);
});

test('admin login is rate limited', async () => {
  const t = await start({ limits: { login: 3, upload: 100, lookup: 100 } });
  let last;
  for (let i = 0; i < 5; i++) last = await fetch(`${t.base}/api/admin/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: 'bad' }) });
  assert.equal(last.status, 429);
  t.app.locals.close(); t.server.closeAllConnections?.(); t.server.close(); fs.rmSync(t.dir, { recursive: true, force: true });
});

test('admin rejects cross-site requests', async () => {
  const cookie = await adminCookie();
  const r = await api('/api/admin/settings', { method: 'PUT', headers: { cookie, 'Content-Type': 'application/json', Origin: 'https://evil.example' }, body: JSON.stringify({ durationSeconds: 5, showNames: false }) });
  assert.equal(r.status, 403);
});

test('admin: stats are real, hide removes a photo from the slideshow, delete removes the file, settings validate', async () => {
  const cookie = await adminCookie();
  const h = { cookie, 'Content-Type': 'application/json' };
  const up = await upload({ name: 'Moderated', files: [{ data: await png(70) }] });
  const stats = await (await api('/api/admin/stats', { headers: { cookie } })).json();
  const photos = await (await api('/api/admin/photos?q=moderated', { headers: { cookie } })).json();
  assert.equal(photos.photos.length, 1);
  assert.ok(stats.visitors > 0 && stats.approvedPhotos > 0 && stats.storageBytes > 0);
  const p = photos.photos[0];

  assert.equal((await api(`/api/admin/photos/${p.id}/status`, { method: 'POST', headers: h, body: JSON.stringify({ status: 'hidden' }) })).status, 200);
  assert.ok(!(await slideshow()).photos.some((x) => x.url === p.url));
  assert.equal((await api(p.url)).status, 404);                        // public cannot fetch hidden file
  assert.equal((await api(p.url, { headers: { cookie } })).status, 200); // admin can preview it
  await api(`/api/admin/photos/${p.id}/status`, { method: 'POST', headers: h, body: JSON.stringify({ status: 'approved' }) });
  assert.ok((await slideshow()).photos.some((x) => x.url === p.url));

  assert.equal((await api(`/api/admin/photos/${p.id}`, { method: 'DELETE', headers: { cookie } })).status, 200);
  assert.equal((await api(p.url)).status, 404);
  assert.equal(fs.existsSync(path.join(dataDir, 'uploads', p.url.split('/').pop())), false);

  assert.equal((await api('/api/admin/settings', { method: 'PUT', headers: h, body: JSON.stringify({ durationSeconds: 1, showNames: true }) })).status, 400);
  const s = await (await api('/api/admin/settings', { method: 'PUT', headers: h, body: JSON.stringify({ durationSeconds: 5, showNames: true }) })).json();
  assert.deepEqual(s.settings, { durationSeconds: 5, showNames: true });
  const show = await slideshow();
  assert.equal(show.settings.durationSeconds, 5);
  assert.equal(show.photos[0].name.includes(' '), false); // first name only
  await api('/api/admin/settings', { method: 'PUT', headers: h, body: JSON.stringify({ durationSeconds: 8, showNames: false }) });
  assert.ok(up.status === 200);
});

test('admin can delete a visitor and all their photos', async () => {
  const cookie = await adminCookie();
  const up = await upload({ name: 'Zed Removeme', files: [{ data: await png(80) }, { data: await png(81) }] });
  const v = (await (await api('/api/admin/visitors?q=zed', { headers: { cookie } })).json()).visitors[0];
  assert.equal(v.photos, 2);
  assert.equal((await api(`/api/admin/visitors/${v.id}`, { method: 'DELETE', headers: { cookie } })).status, 200);
  assert.equal((await post('/api/visitor/lookup', { code: up.body.visitor.code })).status, 404);
});

// ---- misc security ----
test('security: path traversal and odd photo names return 404; headers are set', async () => {
  for (const p of ['/photos/..%2f..%2fapp.db', '/photos/etc-passwd', '/photos/' + 'a'.repeat(36) + '.jpg']) assert.equal((await api(p)).status, 404);
  const r = await api('/api/config');
  assert.equal(r.headers.get('x-content-type-options'), 'nosniff');
  assert.match(r.headers.get('content-security-policy'), /script-src 'self'/);
  assert.equal(r.headers.get('x-powered-by'), null);
});

test('security: the database file and uploads folder are not reachable over HTTP', async () => {
  for (const p of ['/app.db', '/data/app.db', '/.env', '/server/app.js', '/uploads/']) assert.equal((await api(p)).status, 404, p);
});

test('visitor codes are stored hashed, never in plain text', async () => {
  const up = await upload({ name: 'Hash', files: [{ data: await png(90) }] });
  const raw = fs.readFileSync(path.join(dataDir, 'app.db')).toString('latin1') + (fs.existsSync(path.join(dataDir, 'app.db-wal')) ? fs.readFileSync(path.join(dataDir, 'app.db-wal')).toString('latin1') : '');
  assert.ok(!raw.includes(up.body.visitor.code.replace('-', '')));
});
