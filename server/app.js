// Express application factory. Keeping this separate from index.js lets the
// automated tests start a fresh app with its own temporary database.
const express = require('express');
const multer = require('multer');
const helmet = require('helmet');
const { rateLimit } = require('express-rate-limit');
const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');

const { openDb, getSettings, logActivity } = require('./db');
const { validateAndClean } = require('./images');
const auth = require('./auth');
const events = require('./events');

// Visitor codes avoid look-alike characters (0/O, 1/I) so they are easy to type.
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const generateCode = () => {
  let c = '';
  for (let i = 0; i < 10; i++) c += ALPHABET[crypto.randomInt(ALPHABET.length)];
  return `${c.slice(0, 5)}-${c.slice(5)}`;
};
const normalizeCode = (s) => String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const hashCode = (s) => crypto.createHash('sha256').update(normalizeCode(s)).digest('hex');
const cleanName = (s) => String(s || '').replace(/[\u0000-\u001f\u007f]/g, '').replace(/\s+/g, ' ').trim().slice(0, 40);
const iso = (d) => (d ? d.replace(' ', 'T') + 'Z' : null); // SQLite UTC -> ISO string
const photoUrl = (filename) => `/photos/${filename}`;
const PHOTO_FILE = /^[a-f0-9-]{36}\.jpg$/;

function createApp(config) {
  const {
    dataDir, adminPassword, sessionSecret,
    maxFileMb = 8, maxFilesPerRequest = 10, maxPhotosPerVisitor = 30,
    cookieSecure = false, trustProxy = false, limits = {},
  } = config;
  if (!adminPassword || !sessionSecret) throw new Error('ADMIN_PASSWORD and SESSION_SECRET are required.');

  const uploadsDir = path.join(dataDir, 'uploads');
  fs.mkdirSync(uploadsDir, { recursive: true });
  const db = openDb(path.join(dataDir, 'app.db'));

  // Remove leftovers of uploads that were interrupted by a crash.
  for (const f of fs.readdirSync(uploadsDir)) if (f.endsWith('.part')) fs.rmSync(path.join(uploadsDir, f), { force: true });

  const app = express();
  if (trustProxy) app.set('trust proxy', 1);
  app.disable('x-powered-by');
  app.use(helmet({ contentSecurityPolicy: { directives: {
    defaultSrc: ["'self'"], scriptSrc: ["'self'"], styleSrc: ["'self'"],
    imgSrc: ["'self'", 'data:', 'blob:'], fontSrc: ["'self'"], connectSrc: ["'self'"],
    objectSrc: ["'none'"], frameAncestors: ["'none'"], upgradeInsecureRequests: null,
  } } }));
  app.use(express.json({ limit: '10kb' }));

  const limiter = (name, def) => rateLimit({
    windowMs: 15 * 60 * 1000, limit: limits[name] ?? def, standardHeaders: true, legacyHeaders: false,
    message: { error: 'Too many requests. Please wait a few minutes and try again.' },
  });
  const uploadLimiter = limiter('upload', 200);
  const lookupLimiter = limiter('lookup', 60);
  const loginLimiter = limiter('login', 10);

  const removeFiles = (names) => Promise.all(names.map((n) =>
    fs.promises.unlink(path.join(uploadsDir, n)).catch((e) => { if (e.code !== 'ENOENT') console.error(e); })));
  const isAdmin = (req) => auth.verifyToken(auth.readCookie(req, auth.COOKIE), sessionSecret);
  const bad = (res, code, error, extra = {}) => res.status(code).json({ error, ...extra });

  // ---------- Public: config, slideshow, live events ----------
  app.get('/api/config', (req, res) => res.json({
    maxFileMb, maxFilesPerRequest, maxPhotosPerVisitor, allowedTypes: ['image/jpeg', 'image/png', 'image/webp'],
  }));

  app.get('/api/slideshow', (req, res) => {
    const settings = getSettings(db);
    const rows = db.prepare(`SELECT p.id, p.filename, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id
      WHERE p.status = 'approved' AND v.consent_at IS NOT NULL ORDER BY p.id`).all();
    res.set('Cache-Control', 'no-store').json({
      settings,
      photos: rows.map((r) => ({ id: r.id, url: photoUrl(r.filename), name: settings.showNames ? r.name.split(' ')[0] : null })),
    });
  });

  app.get('/api/events', (req, res) => events.subscribe(req, res));

  // Photos are served through a route (not static) so that hidden photos stay private.
  app.get('/photos/:file', (req, res) => {
    const file = req.params.file;
    if (!PHOTO_FILE.test(file)) return res.sendStatus(404);
    const row = db.prepare(`SELECT p.status, v.consent_at FROM photos p JOIN visitors v ON v.id = p.visitor_id WHERE p.filename = ?`).get(file);
    if (!row) return res.sendStatus(404);
    const publicOk = row.status === 'approved' && row.consent_at;
    if (!publicOk && !isAdmin(req)) return res.sendStatus(404);
    res.set('Cache-Control', publicOk ? 'public, max-age=3600' : 'private, no-store');
    res.type('image/jpeg').sendFile(path.join(uploadsDir, file), (err) => { if (err && !res.headersSent) res.sendStatus(404); });
  });

  // ---------- Public: upload ----------
  const multerUpload = multer({
    storage: multer.memoryStorage(),
    limits: { fileSize: maxFileMb * 1024 * 1024, files: maxFilesPerRequest, fields: 10, fieldSize: 1024 },
  }).array('photos', maxFilesPerRequest);

  const findVisitorByCode = (code) => {
    const n = normalizeCode(code);
    return n.length === 10 ? db.prepare('SELECT * FROM visitors WHERE code_hash = ?').get(hashCode(n)) : null;
  };

  app.post('/api/upload', uploadLimiter, (req, res, next) => {
    multerUpload(req, res, (err) => {
      if (!err) return next();
      if (err.code === 'LIMIT_FILE_SIZE') return bad(res, 413, `A photo is larger than the ${maxFileMb} MB limit.`);
      if (err.code === 'LIMIT_FILE_COUNT' || err.code === 'LIMIT_UNEXPECTED_FILE') return bad(res, 400, `You can upload up to ${maxFilesPerRequest} photos at a time.`);
      return bad(res, 400, 'The upload could not be read. Please try again.');
    });
  }, async (req, res, next) => {
    try {
      const files = req.files || [];
      if (!files.length) return bad(res, 400, 'Choose at least one photo.');
      if (req.body.consent !== 'true') return bad(res, 400, 'Please agree to the public display of your photos.');

      const code = String(req.body.code || '').trim();
      const name = cleanName(req.body.name);
      let existing = null;
      if (code) {
        existing = findVisitorByCode(code);
        if (!existing) return bad(res, 404, 'We do not recognise that visitor code.');
      } else if (!name) return bad(res, 400, 'Please enter your name.');

      // 1) Validate + clean every file BEFORE anything is saved or any visitor is created.
      const results = new Array(files.length);
      const good = [];
      await Promise.all(files.map(async (f, i) => {
        const label = String(f.originalname || 'photo').slice(0, 80);
        const v = await validateAndClean(f.buffer, f.mimetype);
        if (!v.ok) { results[i] = { name: label, status: 'rejected', reason: v.reason }; return; }
        good.push({ i, label, data: v.data, sha: crypto.createHash('sha256').update(f.buffer).digest('hex') });
      }));
      // Validation ran in parallel and finished in arbitrary order: restore the visitor's selection order,
      // so photos are saved (and limits applied) first-selected-first.
      good.sort((x, y) => x.i - y.i);
      if (!good.length) return res.status(422).json({ error: 'None of the photos could be accepted.', results });

      // 2) Write to *.part files, then rename: a half-written file can never be served.
      for (const g of good) {
        g.filename = `${crypto.randomUUID()}.jpg`;
        await fs.promises.writeFile(path.join(uploadsDir, g.filename + '.part'), g.data);
        await fs.promises.rename(path.join(uploadsDir, g.filename + '.part'), path.join(uploadsDir, g.filename));
      }

      // 3) One atomic transaction: find/create visitor, enforce limits, skip duplicates, insert.
      const commit = db.transaction(() => {
        let visitor = null, newCode = null;
        if (existing) {
          visitor = db.prepare('SELECT * FROM visitors WHERE id = ?').get(existing.id);
          if (!visitor) return null; // removed while uploading
          if (!visitor.consent_at) db.prepare("UPDATE visitors SET consent_at = datetime('now') WHERE id = ?").run(visitor.id);
        } else {
          newCode = generateCode();
          const info = db.prepare("INSERT INTO visitors (name, code_hash, consent_at) VALUES (?, ?, datetime('now'))").run(name, hashCode(newCode));
          visitor = { id: info.lastInsertRowid, name };
        }
        let count = db.prepare('SELECT COUNT(*) c FROM photos WHERE visitor_id = ?').get(visitor.id).c;
        let accepted = 0;
        for (const g of good) {
          const dup = db.prepare('SELECT 1 FROM photos WHERE visitor_id = ? AND sha256 = ?').get(visitor.id, g.sha);
          if (dup) { results[g.i] = { name: g.label, status: 'duplicate', reason: 'You already uploaded this photo.' }; continue; }
          if (count >= maxPhotosPerVisitor) { results[g.i] = { name: g.label, status: 'rejected', reason: `Limit of ${maxPhotosPerVisitor} photos reached.` }; continue; }
          db.prepare('INSERT INTO photos (visitor_id, filename, sha256, bytes) VALUES (?, ?, ?, ?)').run(visitor.id, g.filename, g.sha, g.data.length);
          g.saved = true; count++; accepted++;
          results[g.i] = { name: g.label, status: 'accepted' };
        }
        if (accepted) logActivity(db, `${visitor.name} added ${accepted} photo${accepted > 1 ? 's' : ''}`);
        return { visitor, newCode, count, accepted };
      });
      const outcome = commit();
      await removeFiles(good.filter((g) => !g.saved).map((g) => g.filename)); // clean up unused files
      if (!outcome) return bad(res, 404, 'That visitor no longer exists.');
      if (outcome.accepted) events.broadcast();
      res.json({ visitor: { name: outcome.visitor.name, code: outcome.newCode || undefined, photoCount: outcome.count }, results });
    } catch (e) { next(e); }
  });

  // ---------- Public: returning visitor ----------
  const visitorFromBody = (req, res) => {
    const v = findVisitorByCode(req.body?.code);
    if (!v) { bad(res, 404, 'We do not recognise that visitor code.'); return null; }
    return v;
  };

  app.post('/api/visitor/lookup', lookupLimiter, (req, res) => {
    const v = visitorFromBody(req, res); if (!v) return;
    const photos = db.prepare('SELECT id, filename, status, created_at FROM photos WHERE visitor_id = ? ORDER BY id DESC').all(v.id);
    res.json({
      name: v.name, consent: !!v.consent_at, maxPhotosPerVisitor,
      photos: photos.map((p) => ({ id: p.id, url: photoUrl(p.filename), status: p.status, createdAt: iso(p.created_at) })),
    });
  });

  app.post('/api/visitor/photo/delete', lookupLimiter, async (req, res, next) => {
    try {
      const v = visitorFromBody(req, res); if (!v) return;
      const p = db.prepare('SELECT id, filename FROM photos WHERE id = ? AND visitor_id = ?').get(Number(req.body.photoId), v.id);
      if (!p) return bad(res, 404, 'That photo was not found.');
      db.prepare('DELETE FROM photos WHERE id = ?').run(p.id);
      await removeFiles([p.filename]);
      logActivity(db, `${v.name} removed a photo`);
      events.broadcast();
      res.json({ ok: true });
    } catch (e) { next(e); }
  });

  // Withdraw consent = delete every photo AND the visitor record. Nothing is kept.
  app.post('/api/visitor/withdraw', lookupLimiter, async (req, res, next) => {
    try {
      const v = visitorFromBody(req, res); if (!v) return;
      const names = db.prepare('SELECT filename FROM photos WHERE visitor_id = ?').all(v.id).map((r) => r.filename);
      db.prepare('DELETE FROM visitors WHERE id = ?').run(v.id); // photos rows cascade
      await removeFiles(names);
      logActivity(db, `A visitor withdrew consent and removed ${names.length} photo${names.length === 1 ? '' : 's'}`);
      events.broadcast();
      res.json({ ok: true, removed: names.length });
    } catch (e) { next(e); }
  });

  // ---------- Admin ----------
  const sameOrigin = (req, res, next) => {
    const o = req.headers.origin;
    if (o && !['GET', 'HEAD'].includes(req.method)) {
      try { if (new URL(o).host !== req.headers.host) return bad(res, 403, 'Cross-site request blocked.'); }
      catch { return bad(res, 403, 'Cross-site request blocked.'); }
    }
    next();
  };
  const requireAdmin = (req, res, next) => (isAdmin(req) ? next() : bad(res, 401, 'Please sign in as administrator.'));
  const admin = express.Router();
  app.use('/api/admin', sameOrigin);

  app.post('/api/admin/login', loginLimiter, (req, res) => {
    if (typeof req.body?.password === 'string' && auth.passwordMatches(req.body.password, adminPassword)) {
      res.cookie(auth.COOKIE, auth.makeToken(sessionSecret), { httpOnly: true, sameSite: 'strict', secure: cookieSecure, maxAge: auth.TTL_MS, path: '/' });
      return res.json({ ok: true });
    }
    bad(res, 401, 'Incorrect password.');
  });
  app.post('/api/admin/logout', (req, res) => { res.clearCookie(auth.COOKIE, { path: '/' }); res.json({ ok: true }); });

  admin.use(requireAdmin);

  admin.get('/stats', (req, res) => {
    const one = (sql) => db.prepare(sql).get();
    const recent = db.prepare(`SELECT p.id, p.filename, p.status, p.created_at, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id ORDER BY p.id DESC LIMIT 8`).all();
    res.json({
      visitors: one('SELECT COUNT(*) c FROM visitors').c,
      approvedPhotos: one("SELECT COUNT(*) c FROM photos WHERE status = 'approved'").c,
      hiddenPhotos: one("SELECT COUNT(*) c FROM photos WHERE status = 'hidden'").c,
      last24h: one("SELECT COUNT(*) c FROM photos WHERE created_at >= datetime('now','-1 day')").c,
      storageBytes: one('SELECT COALESCE(SUM(bytes),0) s FROM photos').s,
      recentUploads: recent.map((r) => ({ id: r.id, url: photoUrl(r.filename), status: r.status, name: r.name, createdAt: iso(r.created_at) })),
      activity: db.prepare('SELECT message, created_at FROM activity ORDER BY id DESC LIMIT 10').all().map((a) => ({ message: a.message, createdAt: iso(a.created_at) })),
      settings: getSettings(db),
    });
  });

  admin.get('/photos', (req, res) => {
    const sorts = { newest: 'p.id DESC', oldest: 'p.id ASC', name: 'v.name COLLATE NOCASE ASC, p.id DESC', size: 'p.bytes DESC' };
    const status = ['approved', 'hidden'].includes(req.query.status) ? req.query.status : null;
    const rows = db.prepare(`SELECT p.id, p.filename, p.status, p.bytes, p.created_at, v.id visitor_id, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id
      WHERE (? = '' OR instr(lower(v.name), lower(?)) > 0) AND (? IS NULL OR p.status = ?)
      ORDER BY ${sorts[req.query.sort] || sorts.newest} LIMIT 500`).all(String(req.query.q || '').trim(), String(req.query.q || '').trim(), status, status);
    res.json({ photos: rows.map((r) => ({ id: r.id, url: photoUrl(r.filename), status: r.status, bytes: r.bytes, visitorId: r.visitor_id, name: r.name, createdAt: iso(r.created_at) })) });
  });

  admin.get('/visitors', (req, res) => {
    const sorts = { newest: 'v.id DESC', name: 'v.name COLLATE NOCASE ASC', photos: 'photos DESC, v.id DESC' };
    const q = String(req.query.q || '').trim();
    const rows = db.prepare(`SELECT v.id, v.name, v.created_at, v.consent_at, COUNT(p.id) photos, MAX(p.created_at) last_upload
      FROM visitors v LEFT JOIN photos p ON p.visitor_id = v.id WHERE (? = '' OR instr(lower(v.name), lower(?)) > 0)
      GROUP BY v.id ORDER BY ${sorts[req.query.sort] || sorts.newest} LIMIT 500`).all(q, q);
    res.json({ visitors: rows.map((r) => ({ id: r.id, name: r.name, photos: r.photos, joinedAt: iso(r.created_at), lastUpload: iso(r.last_upload), consent: !!r.consent_at })) });
  });

  admin.post('/photos/:id/status', (req, res) => {
    const status = req.body?.status;
    if (!['approved', 'hidden'].includes(status)) return bad(res, 400, 'Invalid status.');
    const info = db.prepare('UPDATE photos SET status = ? WHERE id = ?').run(status, Number(req.params.id));
    if (!info.changes) return bad(res, 404, 'Photo not found.');
    logActivity(db, `Admin ${status === 'hidden' ? 'hid' : 'approved'} a photo`);
    events.broadcast();
    res.json({ ok: true });
  });

  admin.delete('/photos/:id', async (req, res, next) => {
    try {
      const p = db.prepare('SELECT filename FROM photos WHERE id = ?').get(Number(req.params.id));
      if (!p) return bad(res, 404, 'Photo not found.');
      db.prepare('DELETE FROM photos WHERE id = ?').run(Number(req.params.id));
      await removeFiles([p.filename]);
      logActivity(db, 'Admin deleted a photo');
      events.broadcast();
      res.json({ ok: true });
    } catch (e) { next(e); }
  });

  admin.delete('/visitors/:id', async (req, res, next) => {
    try {
      const id = Number(req.params.id);
      const v = db.prepare('SELECT name FROM visitors WHERE id = ?').get(id);
      if (!v) return bad(res, 404, 'Visitor not found.');
      const names = db.prepare('SELECT filename FROM photos WHERE visitor_id = ?').all(id).map((r) => r.filename);
      db.prepare('DELETE FROM visitors WHERE id = ?').run(id);
      await removeFiles(names);
      logActivity(db, `Admin deleted visitor ${v.name} and ${names.length} photo${names.length === 1 ? '' : 's'}`);
      events.broadcast();
      res.json({ ok: true });
    } catch (e) { next(e); }
  });

  admin.put('/settings', (req, res) => {
    const d = Number(req.body?.durationSeconds);
    if (!Number.isInteger(d) || d < 3 || d > 120) return bad(res, 400, 'Display time must be a whole number from 3 to 120 seconds.');
    if (typeof req.body.showNames !== 'boolean') return bad(res, 400, 'Invalid names setting.');
    const set = db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    db.transaction(() => { set.run('durationSeconds', String(d)); set.run('showNames', String(req.body.showNames)); })();
    events.broadcast();
    res.json({ settings: getSettings(db) });
  });
  app.use('/api/admin', admin);
  app.use('/api', (req, res) => bad(res, 404, 'Not found.'));

  // ---------- Pages ----------
  const view = (file) => (req, res) => res.set('Cache-Control', 'no-store').sendFile(path.join(__dirname, '..', 'views', file));
  const guarded = (file) => (req, res) => (isAdmin(req) ? view(file)(req, res) : res.redirect('/admin/login'));
  app.get('/admin/login', (req, res) => (isAdmin(req) ? res.redirect('/admin') : view('login.html')(req, res)));
  app.get('/admin', guarded('admin.html'));
  app.get('/admin/manage', guarded('manage.html'));
  app.use(express.static(path.join(__dirname, '..', 'public'), { extensions: ['html'], maxAge: '5m' }));

  app.use((req, res) => res.status(404).sendFile(path.join(__dirname, '..', 'public', '404.html')));
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    console.error(err);
    bad(res, 500, 'Something went wrong on our side. Please try again.');
  });

  app.locals.close = () => { events.closeAll(); db.close(); };
  return app;
}

module.exports = { createApp };
