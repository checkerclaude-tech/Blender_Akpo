/* Aerosub Office: Executive dashboard. Anonymous totals (users, earnings…) from the other Aerosub apps, per organization and combined.
 * The server never stores or sends personal details; this screen only shows what it is given. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18M6 16v-5M11 16V6M16 16v-8M21 16v-3"/></svg>';
  const can = () => S.user && ((S.user.tabs && S.user.tabs.executive) || S.user.is_root || S.user.role === 'super_admin');
  const prevNav = X.nav; X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); if (can()) { const at = items.findIndex(x => x[0] === 'reports'); const v = S.user.tabs && S.user.tabs.executive; items.splice(at < 0 ? 1 : at + 1, 0, [v ? 'executive' : 'execsetup', v ? 'Executive' : 'Executive setup', ICON]); } };
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (can()) items.push(S.user.tabs && S.user.tabs.executive ? ['executive', 'Executive dashboard', null] : ['execsetup', 'Executive setup', null]); };

  const num = (v, m) => {
    if (v === null || v === undefined) return '–'; const big = Math.abs(v) >= 100000;
    try { if (m.kind === 'money') return new Intl.NumberFormat(undefined, { style: 'currency', currency: m.currency || 'NGN', notation: big ? 'compact' : 'standard', maximumFractionDigits: big ? 1 : 2 }).format(v); } catch {}
    if (m.kind === 'percent') return (Math.round(v * 10) / 10) + '%';
    return new Intl.NumberFormat(undefined, { notation: big ? 'compact' : 'standard', maximumFractionDigits: big ? 1 : 2 }).format(v);
  };
  const ago = t => { if (!t) return 'never'; const s = (Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 1000; if (isNaN(s)) return ''; if (s < 90) return 'just now'; if (s < 5400) return Math.round(s / 60) + ' min ago'; if (s < 129600) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' days ago'; };
  const delta = (v, p, m) => {
    if (v === null || p === null || p === undefined) return ''; const d = v - p; if (Math.abs(d) < 1e-9) return '<span class="ex-d flat">no change</span>';
    const pct = p !== 0 ? Math.abs(d / p * 100) : null, up = d > 0, good = (m.good === 'down') ? !up : up;
    return '<span class="ex-d ' + (good ? 'good' : 'bad') + '" title="Compared with about a week ago">' + (up ? '▲' : '▼') + ' ' + (pct === null ? num(Math.abs(d), m) : (pct >= 100 ? Math.round(pct) : pct.toFixed(1)) + '%') + '</span>';
  };
  const spark = (series) => {
    const pts = series.map((v, i) => [i, v]).filter(p => p[1] !== null); if (pts.length < 2) return '<svg class="ex-spark" viewBox="0 0 100 34" aria-hidden="true"></svg>';
    const mn = Math.min(...pts.map(p => p[1])), mx = Math.max(...pts.map(p => p[1])), n = series.length - 1, r = mx - mn || 1;
    const xy = pts.map(p => [(p[0] / n * 100).toFixed(1), (30 - (p[1] - mn) / r * 26).toFixed(1)]); const line = xy.map(p => p.join(',')).join(' ');
    return '<svg class="ex-spark" viewBox="0 0 100 34" preserveAspectRatio="none" aria-hidden="true"><path d="M' + xy[0][0] + ',34 L' + xy.map(p => p.join(',')).join(' L') + ' L' + xy[xy.length - 1][0] + ',34 Z"/><polyline points="' + line + '" vector-effect="non-scaling-stroke"/></svg>';
  };
  const kpi = (m, sub) => '<div class="card ex-kpi"><div class="lab">' + esc(m.label) + '</div><div class="val">' + esc(num(m.value, m)) + '</div><div class="row" style="gap:8px;flex-wrap:wrap">' + delta(m.value, m.prev, m) + (sub ? '<span class="sub">' + sub + '</span>' : '') + '</div>' + spark(m.series) + '</div>';

  views.executive = async () => {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let D;
    try { D = await api('exec'); } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const head = '<div class="top"><div><h1>' + (D.can_view ? 'Executive dashboard' : 'Executive setup') + '</h1><div class="muted">Totals from the other Aerosub platforms, per organization and combined.</div></div><div class="right row">' +
      (D.can_view ? '<button class="btn ghost" id="exr">Refresh now</button>' : '') + (D.can_manage ? '<button class="btn" id="exm">Manage sources</button>' : '') + '</div></div>' +
      '<div class="ex-priv"><span aria-hidden="true">🔒</span><span>Anonymous totals only. Names, e-mail addresses, phone numbers and other personal details of the people using these platforms are never collected or shown.</span></div>';
    let body = '';
    if (!D.can_view) body = '<div class="card empty" style="margin-top:14px">You can set up sources here. The Root Admin chooses who may view the numbers (Access → Executive dashboard).</div>';
    else if (!D.sources.length) body = '<div class="card empty" style="margin-top:14px">No platforms are connected yet.' + (D.can_manage ? ' Choose <b>Manage sources</b> to connect Insight or another app.' : ' Ask the Root Admin or a Super Admin to connect one.') + '</div>';
    else {
      if (D.sources.length > 1 || D.overview.length) body += '<div class="ex-src"><h2>All platforms combined</h2><div class="muted small">Same kind of number added up across ' + D.sources.length + ' platform' + (D.sources.length === 1 ? '' : 's') + '.</div><div class="ex-grid">' + D.overview.map(o => kpi(o, o.orgs ? o.orgs + ' organization' + (o.orgs === 1 ? '' : 's') : '')).join('') + '</div></div>';
      body += D.sources.map(s => {
        const ms = s.metrics.filter(m => m.value !== null); const orgs = {}; ms.forEach(m => m.orgs.forEach(o => { (orgs[o.org] = orgs[o.org] || {})[m.id] = o; }));
        const names = Object.keys(orgs).sort((a, b) => ((orgs[b][ms[0] && ms[0].id] || { value: 0 }).value) - ((orgs[a][ms[0] && ms[0].id] || { value: 0 }).value));
        const err = s.status && s.status !== 'ok';
        return '<div class="ex-src"><h2><span class="ex-dot' + (err ? ' err' : '') + '" aria-hidden="true"></span>' + esc(s.name) + '<span class="muted small" style="font-weight:400">updated ' + esc(ago(s.last_fetch)) + '</span></h2>' + (err ? '<div class="small" style="color:var(--bad);margin-top:4px">Last update problem: ' + esc(s.status) + '</div>' : '') +
          (ms.length ? '<div class="ex-grid">' + ms.map(m => kpi(m, m.derived && m.orgs.length ? m.orgs.length + ' organizations' : '')).join('') + '</div>' : '<div class="card empty" style="margin-top:12px">No numbers received from this platform yet.</div>') +
          (names.length ? '<div class="card" style="margin-top:14px"><h3 style="margin:0">By organization</h3><div class="ex-wrap"><table class="ex-tbl"><thead><tr><th>Organization</th>' + ms.map(m => '<th>' + esc(m.label) + '</th>').join('') + '</tr></thead><tbody>' +
            names.map(n => '<tr><td>' + esc(n) + '</td>' + ms.map(m => { const o = orgs[n][m.id]; return '<td>' + (o ? esc(num(o.value, m)) + ' ' + delta(o.value, o.prev, m) : '–') + '</td>'; }).join('') + '</tr>').join('') +
            '</tbody><tfoot><tr><td>Combined</td>' + ms.map(m => '<td>' + esc(num(m.value, m)) + '</td>').join('') + '</tr></tfoot></table></div></div>' : '') + '</div>';
      }).join('');
    }
    v.innerHTML = head + body;
    const r = $('#exr'); if (r) r.onclick = async () => { r.disabled = true; r.textContent = 'Refreshing…'; try { await api('exec/refresh', { method: 'POST', data: {} }); } catch (e) { toast(e.message); } views.executive(); };
    const m = $('#exm'); if (m) m.onclick = manage;
  };

  views.execsetup = () => views.executive();

  /* ---------- set up sources (Root Admin / Super Admin) ---------- */
  async function manage() {
    const D = await api('exec/sources');
    const m = modal('<div class="row"><h2 class="grow">Platforms</h2><button class="btn ghost sm" id="mx">Close</button></div><div class="muted small" style="margin:6px 0 10px">Each platform is connected once. Only numbers and organization names are read.</div><div class="list" id="sl">' +
      (D.sources.map(s => '<div class="item" data-id="' + s.id + '"><div class="grow"><b>' + esc(s.name) + '</b><div class="muted small">' + (s.mode === 'pull' ? 'Fetched from ' + esc(s.base_url) : 'Sends numbers to us') + ' · ' + s.metrics.length + ' number' + (s.metrics.length === 1 ? '' : 's') + (s.active ? '' : ' · paused') + (s.status && s.status !== 'ok' ? ' · <span style="color:var(--bad)">' + esc(s.status) + '</span>' : '') + '</div></div><button class="btn ghost sm" data-edit="' + s.id + '">Edit</button></div>').join('') || '<div class="empty">None yet</div>') +
      '</div><div class="row" style="margin-top:12px;gap:8px;flex-wrap:wrap"><button class="btn" id="mi">Connect Insight</button><button class="btn ghost" id="mo">Connect another platform</button></div>');
    $('#mx', m).onclick = () => { m.close(); views.executive(); };
    $$('[data-edit]', m).forEach(b => b.onclick = () => { m.close(); edit(D.sources.find(s => s.id === +b.dataset.edit), D); });
    $('#mi', m).onclick = () => { m.close(); edit({ name: 'Insight', mode: 'pull', base_url: 'https://insight.aerosub.co', auth_header: 'Authorization', refresh_min: 30, active: 1, metrics: [{ label: 'Users', kind: 'number', agg: 'sum', good: 'up' }, { label: 'Earnings', kind: 'money', currency: 'NGN', agg: 'sum', good: 'up' }] }, D); };
    $('#mo', m).onclick = () => { m.close(); edit({ name: '', mode: 'pull', base_url: 'https://', auth_header: 'Authorization', refresh_min: 30, active: 1, metrics: [] }, D); };
  }
  const row = (mt, i) => '<div class="ex-mrow" data-i="' + i + '"' + (mt.id ? ' data-mid="' + mt.id + '"' : '') + '><div><label>Name</label><input data-k="label" value="' + esc(mt.label || '') + '" maxlength="80"></div>' +
    '<div><label>Type</label><select data-k="kind"><option value="number"' + (mt.kind === 'number' ? ' selected' : '') + '>Number</option><option value="money"' + (mt.kind === 'money' ? ' selected' : '') + '>Money</option><option value="percent"' + (mt.kind === 'percent' ? ' selected' : '') + '>Percent</option></select></div>' +
    '<div><label>Currency</label><input data-k="currency" value="' + esc(mt.currency || '') + '" maxlength="3" placeholder="NGN"></div>' +
    '<div class="ex-pull"><label>Address on that site</label><input data-k="endpoint" value="' + esc(mt.endpoint || '') + '" placeholder="/api/summary"></div>' +
    '<div class="ex-pull"><label>List of organizations</label><input data-k="list_path" value="' + esc(mt.list_path || '') + '" placeholder="organizations"></div>' +
    '<div class="ex-pull"><label>Organization name field</label><input data-k="org_path" value="' + esc(mt.org_path || '') + '" placeholder="name"></div>' +
    '<div class="ex-pull"><label>Number field</label><input data-k="path" value="' + esc(mt.path || '') + '" placeholder="users"></div>' +
    '<div><label>Combine</label><select data-k="agg"><option value="sum"' + (mt.agg === 'sum' ? ' selected' : '') + '>Add up</option><option value="avg"' + (mt.agg === 'avg' ? ' selected' : '') + '>Average</option><option value="max"' + (mt.agg === 'max' ? ' selected' : '') + '>Highest</option></select></div>' +
    '<div><label>A rise is</label><select data-k="good"><option value="up"' + (mt.good !== 'down' ? ' selected' : '') + '>Good</option><option value="down"' + (mt.good === 'down' ? ' selected' : '') + '>Bad</option></select></div>' +
    '<button class="btn ghost sm" data-del type="button" aria-label="Remove">✕</button></div>';
  function edit(s, D) {
    const isNew = !s.id; let keyShown = '';
    const m = modal('<div class="row"><h2 class="grow">' + (isNew ? 'Connect a platform' : 'Edit ' + esc(s.name)) + '</h2><button class="btn ghost sm" id="ex">Close</button></div>' +
      '<form id="sf" style="margin-top:10px"><div class="field"><label>Platform name</label><input name="name" value="' + esc(s.name) + '" required maxlength="80"></div>' +
      '<div class="field"><label>How do we get its numbers?</label><select name="mode"' + (isNew ? '' : ' disabled') + '><option value="pull"' + (s.mode === 'pull' ? ' selected' : '') + '>We fetch them from the platform</option><option value="push"' + (s.mode === 'push' ? ' selected' : '') + '>The platform sends them to us</option></select></div>' +
      '<div class="ex-pullbox"><div class="field"><label>Platform address</label><input name="base_url" value="' + esc(s.base_url || '') + '" placeholder="https://insight.aerosub.co"></div>' +
      '<div class="two"><div class="field"><label>Token header</label><input name="auth_header" value="' + esc(s.auth_header || 'Authorization') + '"></div><div class="field"><label>Token ' + (s.has_token ? '(saved; leave blank to keep)' : '') + '</label><input name="token" type="password" autocomplete="off" placeholder="' + (s.has_token ? '••••••••' : 'e.g. Bearer abc123') + '"></div></div>' +
      '<div class="field"><label>Check for new numbers every (minutes)</label><input name="refresh_min" type="number" min="5" max="1440" value="' + (s.refresh_min || 30) + '"></div></div>' +
      '<div class="ex-pushbox"><div class="ex-priv">The platform sends a small message to <b>' + esc(D.push_url) + '</b> with the key below in the <code>X-Ingest-Key</code> header:</div><code class="ex-code">{"totals": {"users": 1234},\n "organizations": {"Acme Ltd": {"users": 120, "earnings": 5600.5}}}</code>' +
      '<div class="row" style="margin-top:8px"><span class="muted small grow">Key: <b>' + esc(s.key_hint || 'created when you save') + '</b> (shown in full once)</span>' + (isNew ? '' : '<button class="btn ghost sm" type="button" id="nk">Make a new key</button>') + '</div><div id="kb"></div></div>' +
      '<h3 style="margin:16px 0 0">Numbers to show</h3><div class="muted small">Totals only. Fields that look personal (e-mail, phone, name…) are refused.</div><div id="mr">' + (s.metrics || []).map(row).join('') + '</div>' +
      '<div class="row" style="margin-top:8px"><button class="btn ghost sm" type="button" id="am">+ Add a number</button><button class="btn ghost sm ex-pull" type="button" id="tst">Test connection</button></div><div id="tr" class="muted small" style="margin-top:8px"></div>' +
      '<label class="row" style="margin-top:10px;gap:8px"><input type="checkbox" name="active" style="width:auto"' + (s.active !== 0 ? ' checked' : '') + '> Active</label>' +
      '<div class="row" style="margin-top:14px"><button class="btn" type="submit">Save</button>' + (isNew ? '' : '<button class="btn ghost" type="button" id="rm" style="margin-left:auto;color:var(--bad)">Remove platform</button>') + '</div><div id="er" class="small" style="color:var(--bad);margin-top:6px"></div></form>');
    const f = $('#sf', m); const vis = () => { const pull = f.mode.value === 'pull'; $$('.ex-pull,.ex-pullbox', m).forEach(e => e.style.display = pull ? '' : 'none'); $('.ex-pushbox', m).style.display = pull ? 'none' : ''; }; vis(); f.mode.onchange = vis;
    $('#ex', m).onclick = () => { m.close(); manage(); };
    const wire = () => $$('[data-del]', m).forEach(b => b.onclick = async () => { const r = b.closest('.ex-mrow'); if (r.dataset.mid) { try { await api('exec/metrics/' + r.dataset.mid, { method: 'DELETE' }); } catch (e) { return toast(e.message); } } r.remove(); });
    wire(); $('#am', m).onclick = () => { $('#mr', m).insertAdjacentHTML('beforeend', row({ kind: 'number', agg: 'sum', good: 'up' }, Date.now())); vis(); wire(); };
    const nk = $('#nk', m); if (nk) nk.onclick = async () => { try { const r = await api('exec/sources/' + s.id + '/key', { method: 'POST', data: {} }); $('#kb', m).innerHTML = '<code class="ex-code" style="margin-top:6px">' + esc(r.key) + '</code><div class="small muted">Copy it now. The old key has stopped working.</div>'; } catch (e) { toast(e.message); } };
    const mv = r => { const o = {}; $$('[data-k]', r).forEach(i => o[i.dataset.k] = i.value); return o; };
    const tst = $('#tst', m); if (tst) tst.onclick = async () => {
      if (isNew) return $('#tr', m).textContent = 'Save the platform first, then test it.'; const first = $$('.ex-mrow', m).map(mv).find(x => x.endpoint); if (!first) return $('#tr', m).textContent = 'Add a number with an address first.';
      $('#tr', m).textContent = 'Testing…'; try { const r = await api('exec/sources/' + s.id + '/test', { method: 'POST', data: { endpoint: first.endpoint, list_path: first.list_path, path: first.path } });
        $('#tr', m).innerHTML = r.error ? esc(r.error) : (r.ok ? '<b style="color:var(--ok)">Connected.</b> ' : '<b style="color:var(--bad)">The platform answered ' + r.status + '.</b> ') + (r.organizations_found !== null && r.organizations_found !== undefined ? r.organizations_found + ' organizations found. ' : '') + (r.value !== null && r.value !== undefined ? 'First value: <b>' + esc(String(r.value)) + '</b>. ' : '') + (r.lists && r.lists.length ? '<div>Lists: ' + r.lists.map(esc).join(', ') + '</div>' : '') + (r.numbers && r.numbers.length ? '<div style="margin-top:4px">Numbers found:<br>' + r.numbers.slice(0, 25).map(x => '<code>' + esc(x) + '</code>').join('<br>') + '</div>' : ''); } catch (e) { $('#tr', m).textContent = e.message; } };
    const rm = $('#rm', m); if (rm) rm.onclick = async () => { if (!confirm('Remove ' + s.name + ' and all its saved numbers?')) return; await api('exec/sources/' + s.id, { method: 'DELETE' }); toast('Removed'); m.close(); manage(); };
    f.onsubmit = async e => {
      e.preventDefault(); const er = $('#er', m); er.textContent = ''; const d = Object.fromEntries(new FormData(f)); const body = { name: d.name, base_url: d.base_url, auth_header: d.auth_header, refresh_min: +d.refresh_min || 30, active: f.active.checked };
      if (d.token) body.token = d.token; if (isNew) body.mode = f.mode.value;
      try {
        let id = s.id; if (isNew) { const r = await api('exec/sources', { method: 'POST', data: body }); id = r.id; if (r.key) keyShown = r.key; } else await api('exec/sources/' + id, { method: 'PATCH', data: body });
        for (const r of $$('.ex-mrow', m)) { const o = mv(r); if (!o.label.trim()) continue; if (r.dataset.mid) await api('exec/metrics/' + r.dataset.mid, { method: 'PATCH', data: o }); else { const x = await api('exec/sources/' + id + '/metrics', { method: 'POST', data: o }); r.dataset.mid = x.id; } }
        if (keyShown) { $('#kb', m).innerHTML = '<code class="ex-code" style="margin-top:6px">' + esc(keyShown) + '</code><div class="small muted">Copy this key now: it is only shown once.</div>'; toast('Saved. Copy the key.'); $('#sf button[type=submit]', m).textContent = 'Done'; f.onsubmit = ev => { ev.preventDefault(); m.close(); manage(); }; return; }
        toast('Saved'); m.close(); if (!isNew) { try { await api('exec/refresh', { method: 'POST', data: { id } }); } catch {} } views.executive();
      } catch (er2) { er.textContent = er2.message; }
    };
  }
})();
