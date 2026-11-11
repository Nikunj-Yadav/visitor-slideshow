/* Admin dashboard: real statistics from the database + display settings. */
(async function () {
  const $ = (id) => document.getElementById(id);
  App.mountNav('dashboard', 'admin');
  const guard = (e) => { if (e.status === 401) location.href = '/admin/login'; else App.toast(e.message, 'error'); };

  async function load() {
    let s;
    try { s = await App.api('/api/admin/stats'); } catch (e) { return guard(e); }
    const cards = [['Visitors', s.visitors], ['Photos on display', s.approvedPhotos], ['Hidden photos', s.hiddenPhotos], ['Added in 24 hours', s.last24h], ['Storage used', App.fmtBytes(s.storageBytes)]];
    $('stats').innerHTML = '';
    for (const [cap, num] of cards) {
      const c = document.createElement('div'); c.className = 'card stat';
      c.innerHTML = '<div class="num"></div><div class="cap"></div>'; c.firstChild.textContent = num; c.lastChild.textContent = cap;
      $('stats').appendChild(c);
    }
    const r = $('recent'); r.innerHTML = '';
    if (!s.recentUploads.length) r.innerHTML = `<div class="empty">${App.icon('image')}<h3>No photos yet</h3><p>Uploads will appear here as soon as visitors share them.</p></div>`;
    else {
      const t = document.createElement('div'); t.className = 'thumbs';
      for (const p of s.recentUploads) { const i = document.createElement('img'); i.src = p.url; i.alt = `Photo from ${p.name}`; i.title = p.name; i.loading = 'lazy'; t.appendChild(i); }
      r.appendChild(t);
    }
    const a = $('activity'); a.innerHTML = '';
    if (!s.activity.length) a.innerHTML = `<div class="empty">${App.icon('info')}<h3>Nothing yet</h3><p>Visitor and admin actions will be listed here.</p></div>`;
    else {
      const ul = document.createElement('ul'); ul.className = 'list';
      for (const x of s.activity) { const li = document.createElement('li'); const m = document.createElement('span'); m.textContent = x.message; const t = document.createElement('time'); t.textContent = App.fmtDate(x.createdAt); li.append(m, t); ul.appendChild(li); }
      a.appendChild(ul);
    }
    $('duration').value = s.settings.durationSeconds; $('shownames').checked = s.settings.showNames;
  }

  $('settings').addEventListener('submit', async (e) => {
    e.preventDefault(); $('serror').hidden = true;
    try {
      await App.api('/api/admin/settings', { method: 'PUT', body: { durationSeconds: Number($('duration').value), showNames: $('shownames').checked } });
      App.toast('Settings saved. The display updates automatically.', 'success');
    } catch (err) { if (err.status === 401) return guard(err); $('serror').textContent = err.message; $('serror').hidden = false; }
  });
  load();
  setInterval(load, 20000);
})();
