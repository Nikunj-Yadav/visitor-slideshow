// Entry point: reads configuration from environment variables and starts the server.
try { process.loadEnvFile?.('.env'); } catch { /* .env is optional */ }
const path = require('node:path');
const { createApp } = require('./app');

const env = process.env;
const weak = ['change-me-to-a-long-passphrase', 'replace-with-64-random-hex-characters'];
if (!env.ADMIN_PASSWORD || !env.SESSION_SECRET || weak.includes(env.ADMIN_PASSWORD) || weak.includes(env.SESSION_SECRET)) {
  console.error('Set ADMIN_PASSWORD and SESSION_SECRET in .env (see .env.example) before starting.');
  process.exit(1);
}
const app = createApp({
  dataDir: path.resolve(env.DATA_DIR || './data'),
  adminPassword: env.ADMIN_PASSWORD,
  sessionSecret: env.SESSION_SECRET,
  maxFileMb: Number(env.MAX_FILE_MB) || 8,
  maxFilesPerRequest: Number(env.MAX_FILES_PER_REQUEST) || 10,
  maxPhotosPerVisitor: Number(env.MAX_PHOTOS_PER_VISITOR) || 30,
  cookieSecure: env.COOKIE_SECURE === 'true',
  trustProxy: env.TRUST_PROXY === 'true',
});
const port = Number(env.PORT) || 3000;
const server = app.listen(port, () => console.log(`Slideshow running:  http://localhost:${port}\nDisplay page:       http://localhost:${port}/slideshow\nAdmin:              http://localhost:${port}/admin`));
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => { app.locals.close(); server.close(() => process.exit(0)); setTimeout(() => process.exit(0), 1500).unref(); });
