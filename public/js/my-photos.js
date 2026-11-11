/* Returning visitor page: look up photos by code, remove one, or withdraw consent entirely. */
(function () {
  const $ = (id) => document.getElementById(id);
  App.mountNav('mine'); App.footer();
  $('code').value = localStorage.getItem('mw_code') || '';
  let code = '';

  async function load() {
    const err = $('error'); err.hidden = true;
    code = $('code').value.trim();
    try {
      const v = await App.api('api/visitor-lookup.php', { method: 'POST', body: { code } });
      localStorage.setItem('mw_code', code);
      render(v);
    } catch (e) { err.textContent = e.message; err.hidden = false; $('result').hidden = true; }
  }
  $('form').addEventListener('submit', (e) => { e.preventDefault(); load(); });

  function render(v) {
    const r = $('result'); r.hidden = false; r.innerHTML = '';
    const head = document.createElement('div'); head.className = 'row row--between';
    const h = document.createElement('h2'); h.textContent = `Welcome back, ${v.name}`;
    const actions = document.createElement('div'); actions.className = 'row';
    actions.innerHTML = `<a class="btn" href="index.php">${App.icon('upload')} Add photos</a><button class="btn btn--danger" id="withdraw">${App.icon('shield')} Withdraw consent</button>`;
    head.append(h, actions); r.appendChild(head);
    const info = document.createElement('p'); info.className = 'muted'; info.style.margin = '8px 0 24px';
    info.textContent = `${v.photos.length} of ${v.maxPhotosPerVisitor} photos. Photos marked “Hidden” were paused by a staff member.`;
    r.appendChild(info);

    if (!v.photos.length) {
      r.insertAdjacentHTML('beforeend', `<div class="card empty">${App.icon('image')}<h3>No photos yet</h3><p>Add some and they will show up here.</p></div>`);
    } else {
      const grid = document.createElement('div'); grid.className = 'photo-grid';
      for (const p of v.photos) {
        const c = document.createElement('div'); c.className = 'photo-card' + (p.status === 'hidden' ? ' is-hidden' : '');
        const img = document.createElement('img'); img.src = p.url; img.alt = 'One of your photos'; img.loading = 'lazy';
        const row = document.createElement('div'); row.className = 'row row--between';
        const badge = document.createElement('span'); badge.className = 'badge ' + (p.status === 'approved' ? 'badge--ok' : 'badge--warn'); badge.textContent = p.status === 'approved' ? 'On display' : 'Hidden';
        const del = document.createElement('button'); del.className = 'btn btn--sm btn--danger'; del.innerHTML = App.icon('trash') + ' Remove';
        del.onclick = async () => {
          if (!await App.confirm({ title: 'Remove this photo?', message: 'It will disappear from the display and be deleted permanently.', confirmText: 'Remove photo', danger: true })) return;
          try { await App.api('api/visitor-photo-delete.php', { method: 'POST', body: { code, photoId: p.id } }); App.toast('Photo removed.', 'success'); load(); }
          catch (e) { App.toast(e.message, 'error'); }
        };
        row.append(badge, del); c.append(img, row); grid.appendChild(c);
      }
      r.appendChild(grid);
    }
    $('withdraw').onclick = async () => {
      if (!await App.confirm({ title: 'Withdraw consent?', message: 'All your photos, your name and your code will be permanently deleted. This cannot be undone.', confirmText: 'Delete everything', danger: true })) return;
      try { await App.api('api/visitor-withdraw.php', { method: 'POST', body: { code } }); localStorage.removeItem('mw_code'); $('code').value = ''; r.hidden = true; App.toast('Your photos and details have been deleted.', 'success'); }
      catch (e) { App.toast(e.message, 'error'); }
    };
  }
  if ($('code').value) load();
})();
