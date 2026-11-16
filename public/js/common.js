/* Shared helpers: theme, icons, navigation, toasts, confirm dialog, API calls.
   Loaded in <head> (no defer) so the saved theme is applied before first paint. */
(function () {
  // ---------- Theme (remembered in localStorage) ----------
  let saved = null;
  try { saved = localStorage.getItem('theme'); } catch (e) { /* storage may be blocked */ }
  const theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.dataset.theme = theme;

  // ---------- Inline SVG icons (stroke style, 24x24) ----------
  const ICONS = {
    logo: '<rect x="3" y="4" width="18" height="14" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m21 15-4.5-4.5L7 18"/><path d="M8 21h8"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    close: '<path d="M6 6l12 12M18 6 6 18"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeoff: '<path d="M3 3l18 18M10.6 5.1A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.9M6.6 6.6A16.6 16.6 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
    play: '<rect x="3" y="4" width="18" height="13" rx="2.5"/><path d="M10 8.5v5l4-2.5zM8 21h8"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    logout: '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 8l4 4-4 4M20 12H9"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2.5"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/>',
    shield: '<path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
    dash: '<rect x="3" y="3" width="7.5" height="9" rx="2"/><rect x="13.5" y="3" width="7.5" height="5" rx="2"/><rect x="13.5" y="11" width="7.5" height="10" rx="2"/><rect x="3" y="15" width="7.5" height="6" rx="2"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
  };
  const icon = (name, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[name]}</svg>`;

  // ---------- Safe text + formatting ----------
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (iso) => (iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '—');
  const fmtBytes = (n) => (n === 0 ? '0 KB' : n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : n < 1024 ** 3 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${(n / 1024 ** 3).toFixed(2)} GB`);

  // ---------- API helper: JSON in, JSON out, readable errors ----------
  async function api(url, { method = 'GET', body } = {}) {
    let res;
    const headers = {};
    if (body) headers['Content-Type'] = 'application/json';
    const csrf = document.querySelector('meta[name="csrf-token"]');   // admin pages only
    if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf.content;
    try {
      res = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined });
    } catch (e) { throw new Error('Cannot reach the server. Check your connection and try again.'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { const err = new Error(data.error || 'Something went wrong.'); err.status = res.status; err.data = data; throw err; }
    return data;
  }

  // ---------- Toasts ----------
  let toastBox;
  function toast(message, type = 'info') {
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'toasts'; toastBox.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastBox);
    }
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.innerHTML = icon(type === 'success' ? 'check' : type === 'error' ? 'alert' : 'info');
    const span = document.createElement('span'); span.textContent = message; el.appendChild(span);
    toastBox.appendChild(el);
    setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 300); }, type === 'error' ? 6000 : 3500);
  }

  // ---------- Confirmation dialog (replaces window.confirm) ----------
  function confirmDialog({ title, message, confirmText = 'Confirm', danger = false }) {
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.setAttribute('aria-labelledby', 'dlg-title');
      d.innerHTML = `<h2 id="dlg-title"></h2><p></p><div class="actions"><button class="btn" data-v="0">Cancel</button><button class="btn ${danger ? 'btn--danger' : 'btn--primary'}" data-v="1"></button></div>`;
      d.querySelector('h2').textContent = title; d.querySelector('p').textContent = message;
      d.querySelector('[data-v="1"]').textContent = confirmText;
      d.addEventListener('click', (e) => { const v = e.target.closest('[data-v]'); if (v) { d.returnValue = v.dataset.v; d.close(); } });
      d.addEventListener('close', () => { resolve(d.returnValue === '1'); d.remove(); });
      document.body.appendChild(d); d.showModal();
      d.querySelector('[data-v="0"]').focus();
    });
  }

  // ---------- Navigation ----------
   function mountNav(active, mode = 'visitor') {
    const links = mode === 'admin'
      ? [['dashboard', 'admin/index.php', 'dash', 'Dashboard'], ['manage', 'admin/manage.php', 'list', 'Manage'], ['slideshow', 'slideshow.php', 'play', 'Slideshow'], ['visitor', 'index.php', 'upload', 'Visitor Page', true]]
      : [['share', 'index.php', 'upload', 'Share photos'], ['mine', 'my-photos.php', 'image', 'My photos'], ['slideshow', 'slideshow.php', 'play', 'Slideshow']];
    const nav = document.createElement('nav');
    nav.className = mode === 'admin' ? 'nav nav--admin' : 'nav'; nav.setAttribute('aria-label', 'Main');
    nav.innerHTML = `
      <a class="brand" href="${mode === 'admin' ? 'admin/index.php' : 'index.php'}">${icon('logo')}<span>Memory Wall</span></a>
      <button class="icon-btn" id="menu-toggle" aria-label="Open menu" aria-expanded="false">${icon('menu')}</button>
      <div class="nav-links" id="nav-links">
        ${links.map(([id, href, ic, label, newTab]) => `<a href="${href}" ${id === active ? 'aria-current="page"' : ''} ${newTab ? 'target="_blank" rel="noopener noreferrer"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('')}
        ${mode === 'admin' ? `<button id="signout">${icon('logout')}<span>Sign out</span></button>` : ''}
        <button class="icon-btn" id="theme-toggle" aria-label="Switch colour theme"></button>
      </div>`;
    document.body.prepend(nav);
    const tbtn = nav.querySelector('#theme-toggle');
    const paint = () => { const dark = document.documentElement.dataset.theme === 'dark'; tbtn.innerHTML = icon(dark ? 'sun' : 'moon'); tbtn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme'); };
    paint();
    tbtn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* ignore */ }
      paint();
    });
    const mt = nav.querySelector('#menu-toggle');
    mt.addEventListener('click', () => { const open = nav.classList.toggle('open'); mt.setAttribute('aria-expanded', open); mt.innerHTML = icon(open ? 'close' : 'menu'); });
    nav.querySelector('#signout')?.addEventListener('click', async () => { await api('api/admin/logout.php', { method: 'POST' }); location.href = 'admin/login.php'; });
  }

  const footer = (admin = false) => {
    const f = document.createElement('footer');
    f.className = 'footer';
    f.innerHTML = admin ? '' : '<a href="admin/index.php">Staff sign in</a>';
    document.body.appendChild(f);
  };

  window.App = { icon, esc, fmtDate, fmtBytes, api, toast, confirm: confirmDialog, mountNav, footer };
})();
