/* Upload page: pick photos, preview, validate in the browser (the server validates again),
   then upload one request per photo so each tile gets its own progress and result. */
(async function () {
  const $ = (id) => document.getElementById(id);
  App.mountNav('share'); App.footer();
  $('drop-icon').innerHTML = App.icon('upload', 'icon--lg');

  const cfg = await App.api('api/config.php');
  $('limits').textContent = `JPG, PNG or WebP · up to ${cfg.maxFileMb} MB each · ${cfg.maxPhotosPerVisitor} photos per visitor`;

  let mode = 'new';          // 'new' or 'return'
  let items = [];            // {id, file, url, state, text}
  let busy = false;
  let nextId = 1;

  // ---------- Mode switch (new visitor / returning with code) ----------
  function setMode(m) {
    mode = m;
    $('mode-new').setAttribute('aria-pressed', m === 'new');
    $('mode-return').setAttribute('aria-pressed', m === 'return');
    $('new-fields').hidden = m !== 'new';
    $('return-fields').hidden = m !== 'return';
    refresh();
  }
  $('mode-new').onclick = () => setMode('new');
  $('mode-return').onclick = () => setMode('return');

  // Remembered on this device from an earlier visit -> recognise the visitor.
  const savedCode = localStorage.getItem('mw_code');
  if (savedCode) {
    $('code').value = savedCode;
    setMode('return');
    App.api('api/visitor-lookup.php', { method: 'POST', body: { code: savedCode } })
      .then((v) => { $('welcome').textContent = `Welcome back, ${v.name}. You have ${v.photos.length} photo${v.photos.length === 1 ? '' : 's'} on the wall.`; })
      .catch(() => { localStorage.removeItem('mw_code'); $('code').value = ''; $('welcome').textContent = 'That saved code is no longer valid. Enter your code or start as a new visitor.'; });
  }

  // ---------- Choosing files ----------
  const drop = $('drop'), input = $('file');
  drop.onclick = () => input.click();
  drop.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } };
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));
  input.onchange = () => { addFiles(input.files); input.value = ''; };

  function addFiles(list) {
    for (const file of list) {
      if (!cfg.allowedTypes.includes(file.type)) { App.toast(`“${file.name}” is not a JPG, PNG or WebP image.`, 'error'); continue; }
      if (file.size > cfg.maxFileMb * 1024 * 1024) { App.toast(`“${file.name}” is larger than ${cfg.maxFileMb} MB.`, 'error'); continue; }
      if (items.length >= cfg.maxPhotosPerVisitor) { App.toast(`You can add up to ${cfg.maxPhotosPerVisitor} photos.`, 'error'); break; }
      items.push({ id: nextId++, file, url: URL.createObjectURL(file), state: 'ready', text: '' });
    }
    refresh();
  }

  function refresh() {
    const box = $('previews');
    box.innerHTML = '';
    for (const it of items) {
      const t = document.createElement('div'); t.className = 'tile';
      const img = document.createElement('img'); img.src = it.url; img.alt = `Preview of ${it.file.name}`;
      img.onerror = () => { img.alt = ''; img.classList.add('is-broken'); }; // file the browser cannot draw; the server will report it
      const name = document.createElement('div'); name.className = 'name'; name.textContent = it.file.name;
      t.append(img, name);
      if (it.state === 'ready' && !busy) {
        const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'remove'; rm.setAttribute('aria-label', `Remove ${it.file.name}`); rm.innerHTML = App.icon('close');
        rm.onclick = () => { URL.revokeObjectURL(it.url); items = items.filter((x) => x !== it); refresh(); };
        t.appendChild(rm);
      } else {
        const s = document.createElement('div'); s.className = `state state--${it.state === 'done' ? 'ok' : it.state === 'failed' ? 'bad' : it.state === 'dup' ? 'warn' : ''}`;
        s.textContent = it.text || (it.state === 'uploading' ? 'Uploading…' : '');
        t.appendChild(s);
      }
      box.appendChild(t);
    }
    const ready = items.filter((i) => i.state === 'ready').length;
    $('count').textContent = ready ? `${ready} photo${ready === 1 ? '' : 's'} selected` : 'No photos selected';
    $('submit').disabled = busy || !ready;
  }

  // ---------- Upload one file with progress ----------
  function sendOne(it, fields) {
    return new Promise((resolve) => {
      const fd = new FormData();
      for (const [k, v] of Object.entries(fields)) if (v) fd.set(k, v);
      fd.set('consent', 'true');
      fd.append('photos[]', it.file);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload.php');
      xhr.responseType = 'json';
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) { it.loaded = e.loaded; updateOverall(); } };
      xhr.onload = () => resolve({ status: xhr.status, body: xhr.response || {} });
      xhr.onerror = () => resolve({ status: 0, body: { error: 'Network problem. Please try again.' } });
      xhr.send(fd);
    });
  }
  function updateOverall() {
    const total = items.reduce((n, i) => n + i.file.size, 0);
    const sent = items.reduce((n, i) => n + (i.state === 'done' || i.state === 'dup' || i.state === 'failed' ? i.file.size : (i.loaded || 0)), 0);
    const pct = total ? Math.min(100, Math.round((sent / total) * 100)) : 0;
    $('overall').firstElementChild.style.width = pct + '%';
    $('overall').setAttribute('aria-valuenow', pct);
  }

  // ---------- Submit ----------
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('error'); err.hidden = true;
    const fail = (m) => { err.textContent = m; err.hidden = false; };
    const name = $('name').value.trim(), code = $('code').value.trim();
    if (mode === 'new' && !name) return fail('Please enter your name.');
    if (mode === 'return' && !code) return fail('Please enter your visitor code.');
    if (!$('consent').checked) return fail('Please tick the box to agree to the public display.');
    const queue = items.filter((i) => i.state === 'ready');
    if (!queue.length) return fail('Choose at least one photo.');

    busy = true; $('overall').hidden = false; refresh();
    let visitor = { name, code: mode === 'return' ? code : '' };
    let fatal = null, newCode = null;

    async function run(it) {
      it.state = 'uploading'; it.loaded = 0; refresh();
      const { status, body } = await sendOne(it, { name: visitor.name, code: visitor.code });
      const r = body.results && body.results[0];
      if (status === 200 && r) {
        it.state = r.status === 'accepted' ? 'done' : r.status === 'duplicate' ? 'dup' : 'failed';
        it.text = r.status === 'accepted' ? 'Added' : r.reason;
        if (body.visitor.code) { newCode = body.visitor.code; visitor.code = newCode; }
      } else {
        it.state = 'failed'; it.text = (r && r.reason) || body.error || 'Upload failed';
        if (status === 404 || status === 429) fatal = body.error;
      }
      updateOverall(); refresh();
    }

    // First photo alone (it creates the visitor and returns the code), the rest 3 at a time.
    await run(queue[0]);
    const rest = queue.slice(1);
    const workers = Array.from({ length: Math.min(3, rest.length) }, async () => { while (rest.length && !fatal) await run(rest.shift()); });
    await Promise.all(workers);
    busy = false;

    const ok = queue.filter((i) => i.state === 'done').length;
    if (fatal && !ok) { fail(fatal); items.forEach((i) => { if (i.state === 'uploading') i.state = 'ready'; }); $('overall').hidden = true; refresh(); return; }

    const finalCode = visitor.code;
    if (finalCode && (ok || mode === 'return')) localStorage.setItem('mw_code', finalCode);
    sessionStorage.setItem('mw_result', JSON.stringify({
      name: visitor.name, code: newCode, returning: mode === 'return',
      results: queue.map((i) => ({ name: i.file.name, state: i.state, text: i.text })),
    }));
    queue.forEach((i) => URL.revokeObjectURL(i.url));
    location.href = 'status.php';
  });
  refresh();
})();
