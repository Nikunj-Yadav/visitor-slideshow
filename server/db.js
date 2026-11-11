// Database layer: SQLite via better-sqlite3 (synchronous, so every
// "check then insert" inside a transaction is atomic - this is what makes
// concurrent uploads safe without any locking code of our own).
const Database = require('better-sqlite3');

const SCHEMA = `
CREATE TABLE IF NOT EXISTS visitors (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  code_hash    TEXT NOT NULL UNIQUE,      -- SHA-256 of the visitor code, never the code itself
  consent_at   TEXT,                      -- when display consent was given (NULL = no consent)
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS photos (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor_id   INTEGER NOT NULL REFERENCES visitors(id) ON DELETE CASCADE,
  filename     TEXT NOT NULL UNIQUE,      -- random server-generated name
  sha256       TEXT NOT NULL,             -- hash of the original upload, used to block duplicates
  bytes        INTEGER NOT NULL,
  status       TEXT NOT NULL DEFAULT 'approved' CHECK (status IN ('approved','hidden')),
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (visitor_id, sha256)
);
CREATE INDEX IF NOT EXISTS idx_photos_status ON photos(status, id);
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY, value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS activity (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`;

const DEFAULT_SETTINGS = { durationSeconds: '8', showNames: 'false' };

function openDb(file) {
  const db = new Database(file);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  db.exec(SCHEMA);
  const seed = db.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
  for (const [k, v] of Object.entries(DEFAULT_SETTINGS)) seed.run(k, v);
  return db;
}

function getSettings(db) {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  const s = Object.fromEntries(rows.map(r => [r.key, r.value]));
  return { durationSeconds: Number(s.durationSeconds), showNames: s.showNames === 'true' };
}

function logActivity(db, message) {
  db.prepare('INSERT INTO activity (message) VALUES (?)').run(message);
  // keep the log small
  db.prepare('DELETE FROM activity WHERE id <= (SELECT MAX(id) - 200 FROM activity)').run();
}

module.exports = { openDb, getSettings, logActivity };
