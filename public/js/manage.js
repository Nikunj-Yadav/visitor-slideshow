/* Management page: search / filter / sort photos and visitors; hide, show, delete with confirmation. */
(function () {
  const $ = (id) => document.getElementById(id);
  App.mountNav('manage', 'admin');
  $('search-icon').innerHTML = App.icon('search');
  let tab = 'photos', timer;
  const SORTS = { photos: [['newest', 'Newest first'], ['oldest', 'Oldest first'], ['name', 'Visitor name'], ['size', 'Largest first']], visitors: [['newest', 'Newest first'], ['name', 'Name'], ['photos', 'Most photos']] };
  const guard = (e) => { if (e.status === 401) location.href = '/admin/login'; else App.toast(e.message, 'error'); };

  function setTab(t) {
    tab = t;
    $('tab-photos').setAttribute('aria-pressed', t === 'photos'); $('tab-visitors').setAttribute('aria-pressed', t === 'visitors');
    $('status').hidden = t !== 'photos';
    $('sort').innerHTML = SORTS[t].map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
    load();
  }
  $('tab-photos').onclick = () => setTab('photos'); $('tab-visitors').onclick = () => setTab('visitors');
  $('q').oninput = () => { clearTimeout(timer); timer = setTimeout(load, 250); };
  $('status').onchange = load; $('sort').onchange = load;

  async function load() {
    const qs = new URLSearchParams({ q: $('q').value, sort: $('sort').value });
    if (tab === 'photos' && $('status').value) qs.set('status', $('status').value);
    try {
      const data = await App.api(`/api/admin/${tab}?${qs}`);
      tab === 'photos' ? renderPhotos(data.photos) : renderVisitors(data.visitors);
    } catch (e) { guard(e); }
  }

  const empty = (title, text) => `<div class="card empty">${App.icon('image')}<h3>${title}</h3><p>${text}</p></div>`;
  const filtered = () => $('q').value || (tab === 'photos' && $('status').value);

  function renderPhotos(list) {
    const c = $('content'); c.innerHTML = '';
    if (!list.length) { c.innerHTML = filtered() ? empty('No matches', 'Try a different name or clear the filters.') : empty('No photos yet', 'Photos appear here as soon as visitors upload them.'); return; }
    const grid = document.createElement('div'); grid.className = 'photo-grid';
    for (const p of list) {
      const card = document.createElement('div'); card.className = 'photo-card' + (p.status === 'hidden' ? ' is-hidden' : '');
      const img = document.createElement('img'); img.src = p.url; img.alt = `Photo by ${p.name}`; img.loading = 'lazy';
      const meta = document.createElement('div');
      const who = document.createElement('strong'); who.textContent = p.name;
      const sub = document.createElement('div'); sub.className = 'hint'; sub.textContent = `${App.fmtDate(p.createdAt)} · ${App.fmtBytes(p.bytes)}`;
      meta.append(who, sub);
      const row = document.createElement('div'); row.className = 'row row--between';
      const badge = document.createElement('span'); badge.className = 'badge ' + (p.status === 'approved' ? 'badge--ok' : 'badge--warn'); badge.textContent = p.status === 'approved' ? 'On display' : 'Hidden';
      const acts = document.createElement('div'); acts.className = 'row';
      const tog = document.createElement('button'); tog.className = 'btn btn--sm'; tog.innerHTML = App.icon(p.status === 'approved' ? 'eyeoff' : 'eye') + (p.status === 'approved' ? ' Hide' : ' Show');
      tog.onclick = async () => { try { await App.api(`/api/admin/photos/${p.id}/status`, { method: 'POST', body: { status: p.status === 'approved' ? 'hidden' : 'approved' } }); App.toast(p.status === 'approved' ? 'Photo hidden from the display.' : 'Photo is back on the display.', 'success'); load(); } catch (e) { guard(e); } };
      const del = document.createElement('button'); del.className = 'btn btn--sm btn--danger'; del.setAttribute('aria-label', `Delete photo by ${p.name}`); del.innerHTML = App.icon('trash');
      del.onclick = async () => {
        if (!await App.confirm({ title: 'Delete this photo?', message: 'The file is removed permanently and cannot be recovered.', confirmText: 'Delete photo', danger: true })) return;
        try { await App.api(`/api/admin/photos/${p.id}`, { method: 'DELETE' }); App.toast('Photo deleted.', 'success'); load(); } catch (e) { guard(e); }
      };
      acts.append(tog, del); row.append(badge, acts);
      card.append(img, meta, row); grid.appendChild(card);
    }
    c.appendChild(grid);
  }

  function renderVisitors(list) {
    const c = $('content'); c.innerHTML = '';
    if (!list.length) { c.innerHTML = filtered() ? empty('No matches', 'Try a different name.') : empty('No visitors yet', 'Visitors are listed here after their first upload.'); return; }
    const wrap = document.createElement('div'); wrap.className = 'card table-wrap';
    wrap.innerHTML = '<table><thead><tr><th>Name</th><th>Photos</th><th>Joined</th><th>Last upload</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody></tbody></table>';
    const body = wrap.querySelector('tbody');
    for (const v of list) {
      const tr = document.createElement('tr');
      const cells = [v.name, String(v.photos), App.fmtDate(v.joinedAt), App.fmtDate(v.lastUpload)];
      cells.forEach((t, i) => { const td = document.createElement('td'); td.textContent = t; if (i === 0) td.style.fontWeight = 700; tr.appendChild(td); });
      const td = document.createElement('td'); td.style.textAlign = 'right';
      const del = document.createElement('button'); del.className = 'btn btn--sm btn--danger'; del.innerHTML = App.icon('trash') + ' Delete'; del.setAttribute('aria-label', `Delete visitor ${v.name}`);
      del.onclick = async () => {
        if (!await App.confirm({ title: `Delete ${v.name}?`, message: `This removes the visitor and their ${v.photos} photo${v.photos === 1 ? '' : 's'} permanently. Use this for removal requests.`, confirmText: 'Delete visitor', danger: true })) return;
        try { await App.api(`/api/admin/visitors/${v.id}`, { method: 'DELETE' }); App.toast('Visitor deleted.', 'success'); load(); } catch (e) { guard(e); }
      };
      td.appendChild(del); tr.appendChild(td); body.appendChild(tr);
    }
    c.appendChild(wrap);
  }
  setTab('photos');
})();
