/* Agents area: shared plumbing. Loaded first; every other file in assets/agent/ adds to window.AA.
 * Uses only what app.js exposes on window.OfficeExt. Nothing here caches agent content in the browser: the office app's own
 * api() helper saves every GET in local storage and signs the person out on a 401, which is wrong for private agent content
 * and for the authenticator step, so this area has its own request helper. */
(() => {
  'use strict';
  const X = window.OfficeExt;
  if (!X) return;
  const AA = (window.AA = { X, esc: X.esc, state: { owner: undefined, enrolled: false, unlocked: false, badge: 0 } });
  const $ = (s, r = document) => r.querySelector(s);
  AA.$ = $;

  /** Request helper for /api/agent-owner/* and /api/agent-auth/*. Never cached, never signs the person out. */
  AA.call = async (path, { method = 'GET', data } = {}) => {
    const headers = { 'Content-Type': 'application/json' };
    if (X.S.csrf) headers['X-CSRF-Token'] = X.S.csrf;
    let r;
    try { r = await fetch('api/' + path, { method, headers, credentials: 'same-origin', cache: 'no-store', body: data === undefined ? undefined : JSON.stringify(data) }); }
    catch { throw Object.assign(new Error("Couldn't reach the server. Check your connection and try again."), { offline: true }); }
    const j = await r.json().catch(() => ({}));
    if (!r.ok) {
      if (r.status === 401 && j.step === 'code') AA.state.unlocked = false;
      throw Object.assign(new Error(j.error || 'Request failed'), { status: r.status, step: j.step, data: j });
    }
    return j;
  };

  /** Run a screen. If the code step has expired, show it again instead of an error. */
  AA.guard = async fn => {
    try { return await fn(); }
    catch (e) { if (e.step === 'code' || e.status === 401) { AA.go(); return; } throw e; }
  };

  /* ---------- human wording and formats (design spec section 8) ---------- */
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const toDate = v => { const d = new Date(String(v || '').replace(' ', 'T')); return isNaN(d) ? null : d; };
  AA.date = v => { const d = toDate(v); if (!d) return ''; return MONTHS[d.getMonth()] + ' ' + d.getDate() + (d.getFullYear() === new Date().getFullYear() ? '' : ', ' + d.getFullYear()); };
  AA.rel = v => {
    const d = toDate(v); if (!d) return 'never';
    const m = Math.round((Date.now() - d.getTime()) / 60000);
    if (m < 1) return 'just now'; if (m < 60) return m + (m === 1 ? ' minute ago' : ' minutes ago');
    const h = Math.floor(m / 60); if (h < 24) return h + (h === 1 ? ' hour ago' : ' hours ago');
    const days = Math.floor(h / 24); return days === 1 ? 'Yesterday' : days < 7 ? days + ' days ago' : AA.date(v);
  };
  AA.humanise = s => { const t = String(s || '').replace(/[_-]+/g, ' ').trim(); return t.charAt(0).toUpperCase() + t.slice(1); };
  AA.plural = (n, one, many) => n + ' ' + (n === 1 ? one : many || one + 's');

  /* ---------- small pieces ---------- */
  const E = AA.esc;
  AA.icon = {
    back: '<svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/></svg>',
    lock: '<svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>',
    upload: '<svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>',
    clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  };
  AA.tag = (tone, text, title) => '<span class="ag-tag ' + E(tone) + '"' + (title ? ' title="' + E(title) + '"' : '') + '>' + E(text) + '</span>';
  /** Status with an icon and words, never colour alone. */
  AA.chip = (tone, icon, text, title) => '<span class="ag-tag ' + E(tone) + '"' + (title ? ' title="' + E(title) + '"' : '') + '><span aria-hidden="true">' + icon + '</span> ' + E(text) + '</span>';
  AA.dot = online => '<span class="ag-dot' + (online ? '' : ' off') + '" aria-hidden="true"></span>';
  AA.statusChip = st => st
    ? (st.online ? '<span class="ag-tag ok">' + AA.dot(true) + ' Laptop online</span>' : '<span class="ag-tag">' + AA.dot(false) + ' Offline' + (st.last_seen_at ? ' · last on ' + E(AA.rel(st.last_seen_at)) : '') + '</span>')
    : '<span class="ag-tag">' + AA.dot(false) + ' Status unknown</span>';
  AA.score = n => n === null || n === undefined
    ? '<span class="muted small">Not scored</span>'
    : '<span class="ag-score" role="img" aria-label="Score ' + (+n) + ' out of 100" style="--p:' + Math.max(0, Math.min(100, +n)) + '"><span>' + (+n) + '</span></span>';
  AA.banner = (tone, icon, html) => '<div class="ag-banner ' + tone + '" role="status">' + icon + '<div>' + html + '</div></div>';
  AA.skeleton = n => '<div class="ag-skel-wrap" aria-busy="true" aria-label="Loading">' + '<div class="ag-skel"></div>'.repeat(n || 3) + '</div>';
  AA.errorBox = (what, retryId) => '<div class="card empty" role="alert">' + E("Couldn't load " + what + '.') + ' <button class="btn ghost sm" id="' + retryId + '">Retry</button></div>';
  AA.empty = text => '<div class="card empty">' + E(text) + '</div>';

  /** Bottom sheet on phones, centred dialog on wide screens. Escape or a tap outside closes it. */
  AA.sheet = (html, onMount) => {
    const opener = document.activeElement;
    const s = document.createElement('div'); s.className = 'ag-scrim';
    s.innerHTML = '<div class="ag-sheet" role="dialog" aria-modal="true"><div class="ag-grab" aria-hidden="true"></div>' + html + '</div>';
    const close = () => { s.remove(); document.removeEventListener('keydown', onKey); if (opener && opener.focus) opener.focus(); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    s.addEventListener('mousedown', e => { if (e.target === s) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(s); s.close = close;
    const f = s.querySelector('input,select,button,textarea'); if (f) f.focus();
    if (onMount) onMount(s);
    return s;
  };

  /** Header used by every agent screen: back arrow, title, status chip. */
  AA.header = (title, { back, right } = {}) =>
    '<div class="ag-bar">' + (back ? '<a class="ag-back" href="' + E(back) + '" aria-label="Back">' + AA.icon.back + '</a>' : '') +
    '<h1 class="ag-title grow">' + E(title) + '</h1>' + (right || '') + '</div>';

  AA.tabs = (items, current) => '<nav class="ag-tabs" aria-label="Sections">' + items.map(([k, label, href, badge]) =>
    '<a class="ag-tab' + (k === current ? ' on' : '') + '" href="' + E(href) + '"' + (k === current ? ' aria-current="page"' : '') + '>' + E(label) + (badge ? ' <span class="badge">' + (+badge) + '</span>' : '') + '</a>').join('') + '</nav>';

  /** Tell the bottom bar how many things need the owner, without redrawing the page. */
  AA.setBadge = n => {
    AA.state.badge = n || 0;
    document.querySelectorAll('.tabbar a[href="#/agents"], .nav a[href="#/agents"]').forEach(a => {
      let b = a.querySelector('.badge');
      if (!AA.state.badge) { if (b) b.remove(); return; }
      if (!b) { b = document.createElement('span'); b.className = 'badge'; a.appendChild(b); }
      b.textContent = AA.state.badge;
    });
  };
})();
