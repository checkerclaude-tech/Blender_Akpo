/* Aerosub Office: Access control and Logs (Root Admin only) and My Documents (each person's private files).
 * The server decides who may do what; these screens only show it. Loaded after app.js. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';
  const ROLE = { super_admin: 'Super Admin', founder: 'Founder', manager: 'Manager', employee: 'Employee' };
  const when = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const day = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); };
  const size = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  const I = {
    lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
    folder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
    log: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
  };

  /* ---------- menu ---------- */
  const prevNav = X.nav;
  X.nav = (items, icons) => {
    if (prevNav) prevNav(items, icons); const tb = S.user.tabs || {};
    if (tb.myfiles) { const at = items.findIndex(x => x[0] === 'learn'); items.splice(at < 0 ? items.length : at, 0, ['myfiles', 'My Documents', I.folder]); }
    if (S.user.is_root) { const a = items.findIndex(x => x[0] === 'admin'); items.splice(a < 0 ? items.length : a + 1, 0, ['access', 'Access', I.lock], ['logs', 'Logs', I.log]); }
  };
  const prevMore = X.more;
  X.more = items => { if (prevMore) prevMore(items); const tb = S.user.tabs || {}; if (tb.myfiles) items.push(['myfiles', 'My Documents', null]); if (S.user.is_root) items.push(['access', 'Access control', null], ['logs', 'Logs', null]); };

  const TAB_OF = { reports: 'reports', pipeline: 'pipeline', mail: 'mail', schedule: 'schedule', games: 'games', campaigns: 'campaigns', team: 'team', finance: 'finance', learn: 'learn', competition: 'competition', events: 'events', research: 'research', projects: 'projects', products: 'products', news: 'news', myfiles: 'myfiles', documents: 'documents', executive: 'executive' };
  X.afterView = name => {
    const old = $('#ro-banner'); if (old) old.remove(); const tab = TAB_OF[name]; if (!tab || !(S.user.ro_tabs || []).includes(tab)) return;
    const v = $('#view'); if (v) v.insertAdjacentHTML('afterbegin', '<div id="ro-banner" class="banner noprint" role="note" style="background:var(--mist);color:var(--ink);border:1px solid var(--line)"><span>🔒 <b>Read only.</b> You can open and use this section but not create, change or delete anything in it. Ask the Root Admin if you need to edit.</span></div>');
  };

  /* ---------- Access control: the Root Admin chooses who may open which tab ---------- */
  views.access = async () => {
    if (!S.user.is_root) { location.hash = '#/home'; return; }
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const D = await api('access'); const pick = +(location.hash.split('/')[2] || 0);
    v.innerHTML = '<div class="top"><div><h1>Access control</h1><div class="muted">Choose who may open which tab. “Default” follows the person’s role and department. The server enforces these choices; they are not only hidden menu items.</div></div></div>' +
      '<div class="two ac-two"><div class="card"><input id="acq" placeholder="Search people" aria-label="Search people" style="margin-bottom:8px"><div class="list" id="acl">' +
      D.users.map(u => '<a class="item ac-user' + (pick === +u.id ? ' on' : '') + '" href="#/access/' + u.id + '" data-name="' + esc(u.name.toLowerCase()) + '" style="color:inherit"><div class="grow"><b>' + esc(u.name) + '</b>' + (+u.is_root ? ' <span class="tag in_progress">Root Admin</span>' : '') + '<div class="muted small">' + esc(ROLE[u.role] || u.role) + (u.department ? ' · ' + esc(u.department) : '') + '</div></div>' + (+u.overrides ? '<span class="tag deferred" title="Custom choices set">' + u.overrides + ' custom</span>' : '') + '</a>').join('') + '</div></div><div id="acp"><div class="card empty">Pick a person on the left.</div></div></div>';
    $('#acq').oninput = e => { const q = e.target.value.toLowerCase(); $$('#acl .ac-user').forEach(a => a.style.display = a.dataset.name.includes(q) ? '' : 'none'); };
    if (pick) await panel(pick);
  };
  async function panel(id) {
    const box = $('#acp'); box.innerHTML = '<div class="card"><div class="empty">Loading…</div></div>'; let D;
    try { D = await api('access/' + id); } catch (e) { box.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const cur = {}; D.tabs.forEach(t => cur[t.key] = t.override);
    box.innerHTML = '<form class="card" id="acf"><div class="row"><h2 class="grow">' + esc(D.user.name) + '</h2><span class="tag">' + esc(ROLE[D.user.role] || D.user.role) + '</span></div><div class="muted small" style="margin:4px 0 6px">' + esc(D.user.email) + '</div><div class="muted small" style="margin-bottom:12px"><b>Edit</b>: can use the tab and change things in it. <b>Read only</b>: can open and use it (look, search, print, take courses, play) but cannot create, change or delete. <b>Default</b> follows the role and department.</div>' +
      '<div class="ac-rows">' + D.tabs.map(t => '<div class="ac-row" data-k="' + t.key + '"><div class="grow"><b>' + esc(t.label) + '</b><div class="muted small">By default: ' + (t.default ? 'on' : 'off') + (t.locked ? ' · never available to administrators' : '') + '</div></div>' +
        '<div class="ac-seg" role="radiogroup" aria-label="' + esc(t.label) + '">' + [['default', 'Default'], ['on', 'Edit'], ['view', 'Read only'], ['off', 'Off']].map(([k, l]) => '<button type="button" role="radio" class="ac-b ' + k + (((t.override === null && k === 'default') || (t.override === true && k === 'on') || (t.override === 'view' && k === 'view') || (t.override === false && k === 'off')) ? ' sel' : '') + '" data-v="' + k + '"' + (t.locked && k === 'on' ? ' disabled' : '') + ' aria-checked="' + (((t.override === null && k === 'default') || (t.override === true && k === 'on') || (t.override === false && k === 'off')) ? 'true' : 'false') + '">' + l + '</button>').join('') + '</div>' +
        '<span class="tag ac-eff ' + (t.effective ? (t.read_only ? '' : 'done') : 'blocked') + '">' + (t.effective ? (t.read_only ? 'Read only' : 'Can edit') : 'No access') + '</span></div>').join('') + '</div>' +
      '<div class="err" id="er"></div><div class="row" style="margin-top:12px"><button class="btn" id="acs">Save access</button><button class="btn ghost" type="button" id="acr">Reset everything to default</button></div></form>';
    $$('.ac-row', box).forEach(r => $$('.ac-b', r).forEach(b => b.onclick = () => { $$('.ac-b', r).forEach(x => { x.classList.toggle('sel', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); }); cur[r.dataset.k] = b.dataset.v === 'default' ? null : b.dataset.v === 'view' ? 'view' : b.dataset.v === 'on'; }));
    const save = async data => { try { await api('access/' + id, { method: 'PATCH', data: { tabs: data } }); toast('Access saved'); X.render(); } catch (er) { $('#er', box).textContent = er.message; } };
    $('#acf', box).onsubmit = e => { e.preventDefault(); save(cur); };
    $('#acr', box).onclick = () => { if (!confirm('Put every tab back to its default for ' + D.user.name + '?')) return; const z = {}; D.tabs.forEach(t => z[t.key] = null); save(z); };
  }

  /* ---------- Logs: the Root Admin only ---------- */
  views.logs = async () => {
    if (!S.user.is_root) { location.hash = '#/home'; return; }
    const v = $('#view'); const acc = await api('access'); const F = { q: '', user: '', action: '', from: '', to: '' };
    v.innerHTML = '<div class="top"><div><h1>Logs</h1><div class="muted">Everything that is done in the office, newest first. Only the Root Admin can see this.</div></div><span class="right row"><button class="btn ghost" id="lgx">Download CSV</button></span></div>' +
      '<div class="rp-filters"><input id="lgq" placeholder="Search…" style="max-width:240px" aria-label="Search the log"><select id="lgu" aria-label="Person">' + opt('', 'Everyone', '') + acc.users.map(u => opt(u.id, u.name, '')).join('') + '</select><input id="lga" placeholder="Action (e.g. login)" style="max-width:190px" aria-label="Action"><label class="small muted">From <input type="date" id="lgf" style="width:auto"></label><label class="small muted">To <input type="date" id="lgt" style="width:auto"></label></div>' +
      '<div class="card"><div class="tablewrap"><table><thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead><tbody id="lgb"><tr><td colspan="4" class="muted">Loading…</td></tr></tbody></table></div><div class="muted small" id="lgn" style="margin-top:8px"></div></div>';
    let rows = []; const load = async () => { const q = new URLSearchParams({ limit: 500 }); Object.entries(F).forEach(([k, x]) => { if (x) q.set(k, x); });
      rows = await api('activity?' + q.toString()); $('#lgb').innerHTML = rows.length ? rows.map(r => '<tr><td class="small" style="white-space:nowrap">' + esc(when(r.created_at)) + '</td><td><b>' + esc(r.name || 'System') + '</b></td><td>' + esc(String(r.action).replace(/_/g, ' ')) + '</td><td class="small muted">' + esc(r.detail || '') + '</td></tr>').join('') : '<tr><td colspan="4" class="muted">Nothing matches.</td></tr>'; $('#lgn').textContent = rows.length + ' entr' + (rows.length === 1 ? 'y' : 'ies') + (rows.length >= 500 ? ' (showing the latest 500: narrow the dates to see more)' : ''); };
    let tm; const go = () => { clearTimeout(tm); tm = setTimeout(load, 250); };
    $('#lgq').oninput = e => { F.q = e.target.value.trim(); go(); }; $('#lgu').onchange = e => { F.user = e.target.value; go(); }; $('#lga').oninput = e => { F.action = e.target.value.trim(); go(); }; $('#lgf').onchange = e => { F.from = e.target.value; go(); }; $('#lgt').onchange = e => { F.to = e.target.value; go(); };
    $('#lgx').onclick = () => { const csv = 'When,Who,Action,Details\n' + rows.map(r => [r.created_at, r.name || 'System', r.action, r.detail || ''].map(x => '"' + String(x).replace(/"/g, '""') + '"').join(',')).join('\n'); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'Aerosub_logs.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500); };
    await load();
  };

  /* ---------- My Documents ---------- */
  const MY = { folder: '', q: '' };
  const b64 = f => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1] || ''); r.onerror = rej; r.readAsDataURL(f); });
  const ico = it => it.kind === 'note' ? '📝' : it.kind === 'link' ? '🔗' : /pdf/.test(it.mime || '') ? '📕' : /word|officedocument\.word|msword/.test(it.mime || '') ? '📘' : /sheet|excel|csv/.test(it.mime || '') ? '📗' : /presentation|powerpoint/.test(it.mime || '') ? '📙' : /zip|compressed/.test(it.mime || '') ? '🗜️' : /^video/.test(it.mime || '') ? '🎞️' : /^audio/.test(it.mime || '') ? '🎧' : '📄';
  views.myfiles = async () => {
    const v = $('#view'); if (!(S.user.tabs && S.user.tabs.myfiles)) { v.innerHTML = '<div class="card empty">My Documents is for staff accounts. Administrators do not have personal storage here.</div>'; return; }
    v.innerHTML = '<div class="empty">Loading…</div>'; const q = new URLSearchParams(); if (MY.folder) q.set('folder', MY.folder); if (MY.q) q.set('q', MY.q); const D = await api('myfiles' + (q.toString() ? '?' + q.toString() : ''));
    const pct = Math.min(100, Math.round(D.used * 100 / D.quota));
    v.innerHTML = '<div class="top"><div><h1>My Documents</h1><div class="muted"><span aria-hidden="true">🔒</span> Only you can see these. Not even administrators can open them. Keep your IDs, certificates, payslips and notes here for quick access.</div></div></div>' +
      '<div class="card my-bar"><div class="row"><label class="btn" style="cursor:pointer;margin:0">⬆ Upload files<input type="file" id="myf" multiple hidden></label><label class="btn ghost" style="cursor:pointer;margin:0">📷 Take a photo<input type="file" id="myc" accept="image/*" capture="environment" hidden></label><button class="btn ghost" id="mynote">+ Note</button><button class="btn ghost" id="mylink">+ Link</button>' +
      '<input id="myq" class="right" placeholder="Search my documents" aria-label="Search my documents" value="' + esc(MY.q) + '" style="max-width:260px"></div><div class="row" style="margin-top:10px"><div class="rp-bar grow" role="img" aria-label="' + pct + ' percent of your storage is used"><i style="width:' + pct + '%"></i></div><span class="small muted">' + size(D.used) + ' of ' + size(D.quota) + ' used · files up to ' + size(D.max_file) + '</span></div>' +
      '<div class="chips" style="margin-top:10px"><span class="chip' + (MY.folder === '' ? ' on' : '') + '" data-f="" role="button" tabindex="0">All</span>' + D.folders.map(f => '<span class="chip' + (MY.folder === f.folder ? ' on' : '') + '" data-f="' + esc(f.folder) + '" role="button" tabindex="0">' + (f.folder === '-' ? 'Unfiled' : esc(f.folder)) + ' (' + f.n + ')</span>').join('') + '</div></div>' +
      (D.items.length ? '<div class="my-grid">' + D.items.map(it => '<div class="card my-item" data-id="' + it.id + '"><div class="my-thumb">' + (it.image ? '<img src="api/myfiles/' + it.id + '/file?view=1" alt="" loading="lazy" data-rm="1"><span class="my-ph" aria-hidden="true">' + ico(it) + '</span>' : '<span class="my-ph" aria-hidden="true">' + ico(it) + '</span>') + '</div><div class="my-body"><b class="my-title" title="' + esc(it.title) + '">' + esc(it.title) + '</b><div class="muted small">' + (it.folder ? esc(it.folder) + ' · ' : '') + (it.kind === 'file' ? size(it.size) + ' · ' : '') + esc(day(it.updated_at)) + '</div>' + (it.kind === 'note' ? '<div class="small my-snip">' + esc((it.body || '').slice(0, 110)) + '</div>' : it.kind === 'link' ? '<div class="small my-snip">' + esc(it.body) + '</div>' : '') +
        '<div class="row" style="margin-top:8px;gap:6px"><button class="btn sm" data-open="' + it.id + '">' + (it.kind === 'file' ? 'Open' : it.kind === 'link' ? 'Visit' : 'Read') + '</button>' + (it.kind === 'file' ? '<a class="btn ghost sm" href="api/myfiles/' + it.id + '/file" download>Download</a>' : '') + '<button class="btn ghost sm" data-edit="' + it.id + '">Edit</button><button class="btn ghost sm" data-del="' + it.id + '" style="color:var(--bad)" aria-label="Delete ' + esc(it.title) + '">✕</button></div></div></div>').join('') + '</div>'
        : '<div class="card empty">' + (MY.q || MY.folder ? 'Nothing matches.' : 'Nothing here yet. Upload a file, take a photo or add a note.') + '</div>');
    const items = Object.fromEntries(D.items.map(i => [i.id, i]));
    const up = async files => { if (!files.length) return; const folder = MY.folder && MY.folder !== '-' ? MY.folder : ''; let ok = 0; toast('Uploading ' + files.length + ' file' + (files.length > 1 ? 's' : '') + '…');
      for (const f of files) { try { if (f.size > D.max_file) throw new Error(f.name + ' is larger than ' + size(D.max_file)); await api('myfiles', { method: 'POST', data: { kind: 'file', title: f.name.replace(/\.[^.]+$/, ''), folder, name: f.name, type: f.type, data: await b64(f) } }); ok++; } catch (er) { toast(er.message); } }
      if (ok) toast(ok + ' file' + (ok > 1 ? 's' : '') + ' saved'); X.render(); };
    $('#myf').onchange = e => up([...e.target.files]); $('#myc').onchange = e => up([...e.target.files]);
    let tm; $('#myq').oninput = e => { MY.q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(() => X.render(), 350); };
    $$('[data-f]', v).forEach(c => { const go = () => { MY.folder = c.dataset.f; X.render(); }; c.onclick = go; c.onkeydown = e => { if (e.key === 'Enter') go(); }; });
    $('#mynote').onclick = () => itemForm(null, 'note'); $('#mylink').onclick = () => itemForm(null, 'link');
    $$('[data-edit]', v).forEach(b => b.onclick = () => itemForm(items[b.dataset.edit], items[b.dataset.edit].kind));
    $$('[data-del]', v).forEach(b => b.onclick = async () => { if (!confirm('Delete “' + items[b.dataset.del].title + '”? This cannot be undone.')) return; try { await api('myfiles/' + b.dataset.del, { method: 'DELETE' }); X.render(); } catch (er) { toast(er.message); } });
    $$('[data-open]', v).forEach(b => b.onclick = () => { const it = items[b.dataset.open];
      if (it.kind === 'file') { if (it.image) { const m = modal('<div class="row"><h2 class="grow">' + esc(it.title) + '</h2><button class="btn ghost sm" id="cx">Close</button></div><img src="api/myfiles/' + it.id + '/file?view=1" alt="" style="max-width:100%;border-radius:10px;margin-top:10px">'); $('#cx', m).onclick = () => m.close(); } else window.open('api/myfiles/' + it.id + '/file', '_blank', 'noopener'); }
      else if (it.kind === 'link') window.open(it.body, '_blank', 'noopener,noreferrer');
      else { const m = modal('<div class="row"><h2 class="grow">' + esc(it.title) + '</h2><button class="btn ghost sm" id="cx">Close</button></div><p style="white-space:pre-wrap;margin-top:10px">' + esc(it.body) + '</p>'); $('#cx', m).onclick = () => m.close(); } });
    function itemForm(it, kind) {
      const folders = D.folders.filter(f => f.folder !== '-').map(f => f.folder);
      const m = modal('<h2>' + (it ? 'Edit' : 'New') + ' ' + (kind === 'note' ? 'note' : kind === 'link' ? 'link' : 'document') + '</h2><form id="mf" style="margin-top:12px"><div class="field"><label>Name</label><input name="title" maxlength="200" ' + (kind === 'file' ? 'required ' : '') + 'value="' + esc(it ? it.title : '') + '"></div><div class="field"><label>Folder (optional)</label><input name="folder" list="myfl" maxlength="80" value="' + esc(it ? it.folder || '' : MY.folder && MY.folder !== '-' ? MY.folder : '') + '" placeholder="e.g. ID, Certificates, Payslips"><datalist id="myfl">' + folders.map(f => '<option value="' + esc(f) + '">').join('') + '</datalist></div>' +
        (kind === 'note' ? '<div class="field"><label>Note</label><textarea name="body" rows="8" required maxlength="20000">' + esc(it ? it.body : '') + '</textarea></div>' : kind === 'link' ? '<div class="field"><label>Link (https://…)</label><input name="body" type="url" required value="' + esc(it ? it.body : '') + '"></div>' : '') + '<div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
      $('#cx', m).onclick = () => m.close(); $('#mf', m).onsubmit = async e => { e.preventDefault(); const d = Object.fromEntries(new FormData(e.target)); if (!it) d.kind = kind;
        try { await api('myfiles' + (it ? '/' + it.id : ''), { method: it ? 'PATCH' : 'POST', data: d }); m.close(); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
    }
  };
})();
