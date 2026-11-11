// Admin authentication: one password (from .env) -> a signed, expiring,
// HttpOnly cookie. No user table needed for a single-operator event display.
const crypto = require('node:crypto');

const COOKIE = 'admin_session';
const TTL_MS = 8 * 60 * 60 * 1000; // 8 hours

const sha = (s) => crypto.createHash('sha256').update(String(s)).digest();

function passwordMatches(input, expected) {
  // hash both so lengths match, then compare in constant time
  return crypto.timingSafeEqual(sha(input), sha(expected));
}

function sign(payload, secret) {
  return crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}

function makeToken(secret) {
  const payload = Buffer.from(JSON.stringify({ exp: Date.now() + TTL_MS })).toString('base64url');
  return `${payload}.${sign(payload, secret)}`;
}

function verifyToken(token, secret) {
  if (!token || !token.includes('.')) return false;
  const [payload, sig] = token.split('.');
  const good = sign(payload, secret);
  if (sig.length !== good.length || !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(good))) return false;
  try { return JSON.parse(Buffer.from(payload, 'base64url').toString()).exp > Date.now(); }
  catch { return false; }
}

function readCookie(req, name) {
  const header = req.headers.cookie || '';
  for (const part of header.split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}

module.exports = { COOKIE, TTL_MS, passwordMatches, makeToken, verifyToken, readCookie };
