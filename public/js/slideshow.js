/* Live slideshow.
   - Fetches the approved photo list, then listens for "photos-changed" events (Server-Sent Events).
   - New photos jump the queue so they show next; otherwise photos play in upload order and loop.
   - Two stacked <img> layers cross-fade. The next image is fully loaded before the fade starts. */
(function () {
  const $ = (id) => document.getElementById(id);
  const layers = [$('img-a'), $('img-b')];
  let front = 0;                 // index of the layer currently visible
  let photos = [], seen = null, priority = [], pos = -1, currentId = null;
  let durationMs = 8000, timer = null, failures = 0;

  $('where').textContent = location.host + '/';

  async function refresh() {
    let data;
    try { data = await (await fetch('/api/slideshow', { cache: 'no-store' })).json(); } catch (e) { return; } // keep playing on network errors
    durationMs = Math.min(120, Math.max(3, data.settings.durationSeconds)) * 1000;
    const ids = new Set(data.photos.map((p) => p.id));
    if (seen === null) seen = new Set(ids);                              // first load: nothing is "new"
    else for (const p of data.photos) if (!seen.has(p.id)) { priority.push(p.id); seen.add(p.id); }
    priority = priority.filter((id) => ids.has(id));
    photos = data.photos;
    if (currentId !== null && ids.has(currentId)) pos = photos.findIndex((p) => p.id === currentId);
    $('empty').hidden = photos.length > 0;
    if (!photos.length) { clearTimeout(timer); timer = null; currentId = null; layers.forEach((l) => l.classList.remove('on')); $('caption').hidden = true; return; }
    if (!timer) next();                                                   // start (or restart after being empty)
  }

  function pick() {
    while (priority.length) { const p = photos.find((x) => x.id === priority.shift()); if (p) return p; }
    pos = (pos + 1) % photos.length;
    return photos[pos];
  }

  function next() {
    clearTimeout(timer);
    if (!photos.length) { timer = null; return; }
    const p = pick();
    if (p.id === currentId && photos.length === 1) { timer = setTimeout(next, durationMs); return; } // only one photo: just keep it
    const loader = new Image();
    loader.onload = () => { failures = 0; show(p); };
    loader.onerror = () => {                                              // broken image: skip it, never get stuck
      failures++;
      timer = setTimeout(failures >= photos.length ? () => { failures = 0; refresh(); next(); } : next, failures >= photos.length ? 10000 : 200);
    };
    loader.src = p.url;
  }

  function show(p) {
    const back = layers[1 - front];
    back.src = p.url; back.alt = '';
    back.classList.add('on'); layers[front].classList.remove('on');
    front = 1 - front; currentId = p.id; pos = photos.findIndex((x) => x.id === p.id);
    const cap = $('caption');
    if (p.name) { cap.textContent = `Shared by ${p.name}`; cap.hidden = false; } else cap.hidden = true;
    timer = setTimeout(next, durationMs);
  }

  // ---------- Live updates ----------
  const es = new EventSource('/api/events');
  es.addEventListener('photos-changed', refresh);
  setInterval(refresh, 60000);                                            // safety net if the live connection drops
  refresh();

  // ---------- Small conveniences for a TV / kiosk ----------
  let idle;
  const wake = () => { document.body.classList.add('active'); clearTimeout(idle); idle = setTimeout(() => document.body.classList.remove('active'), 3000); };
  ['mousemove', 'touchstart', 'keydown'].forEach((e) => addEventListener(e, wake));
  const toggleFs = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.());
  $('fs').onclick = toggleFs;
  addEventListener('keydown', (e) => { if (e.key === 'f' || e.key === 'F') toggleFs(); });
  const keepAwake = async () => { try { await navigator.wakeLock?.request('screen'); } catch (e) { /* not supported */ } };
  keepAwake(); document.addEventListener('visibilitychange', () => { if (!document.hidden) keepAwake(); });
})();
