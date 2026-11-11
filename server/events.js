// Server-Sent Events hub: the slideshow keeps one open connection and the
// server pushes a tiny "photos-changed" message whenever the photo set changes.
const clients = new Set();

function subscribe(req, res) {
  res.set({ 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache, no-transform', Connection: 'keep-alive', 'X-Accel-Buffering': 'no' });
  res.flushHeaders();
  res.write('retry: 3000\n\n');
  clients.add(res);
  req.on('close', () => clients.delete(res));
}

function broadcast(type = 'photos-changed') {
  for (const res of clients) res.write(`event: ${type}\ndata: ${Date.now()}\n\n`);
}

// heartbeat comment keeps proxies from closing idle connections
const timer = setInterval(() => { for (const res of clients) res.write(': ping\n\n'); }, 25000);
timer.unref();

module.exports = { subscribe, broadcast, closeAll: () => { for (const r of clients) r.end(); clients.clear(); } };
