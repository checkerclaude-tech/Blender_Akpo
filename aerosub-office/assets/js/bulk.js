/* Aerosub Office: select and delete many things at once, and "keep a copy" of a task that was deleted on you.
 * A small "Select" pill appears at the bottom of any list that supports it (tasks, research, RFQs, finance documents, contacts, companies,
 * vendors, prospects). In select mode a click on a row ticks it instead of opening it. Finance documents: only drafts and void ones can go.
 * No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._bulkLoaded) return; X._bulkLoaded = 1;
  const { api, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const href = r => ((r.getAttribute('href') || '').match(/tasks\/(\d+)/) || [])[1];
  const CFG = [
    { kind: 'tasks', row: '#tl .item[data-id]', id: r => r.dataset.id, noun: 'task' },
    { kind: 'tasks', row: '#rl a.item', id: href, noun: 'research item' },
    { kind: 'rfqs', row: 'table.rq tr[data-r]', id: r => r.dataset.r, noun: 'RFQ' },
    { kind: 'docs', row: '#fr tr[data-d]', id: r => r.dataset.d, noun: 'document', note: 'Only drafts and void documents can be deleted.' },
    { kind: 'contacts', row: '#kt tr[data-k]', id: r => r.dataset.k, noun: 'contact', from: 'contacts', moves: () => [['regulatory', 'Regulatory contacts'], ['customer', 'Customer contacts']] },
    { kind: 'companies', row: '#ct tr[data-co]', id: r => r.dataset.co, noun: 'company', from: 'companies', moves: () => [['vendor', 'Vendors'], ['partner', 'Partners'], ['regulator', 'Regulatory bodies']], note: 'Companies are deleted with their contacts. Only managers and admins can do this.' },
    { kind: 'vendors', row: '#vt tr[data-v]', id: r => r.dataset.v, noun: 'vendor', from: 'vendors', moves: r0 => [['company', 'Companies'], ['vendor', 'Vendors'], ['partner', 'Partners'], ['regulator', 'Regulatory bodies']].filter(m => !r0 || ({ vendors: 'vendor', partners: 'partner', regulators: 'regulator' })[r0.dataset.grp] !== m[0]), note: 'Only managers and admins can delete vendors.' },
    { kind: 'signals', row: '#prl [data-sg]', id: r => r.dataset.sg, noun: 'prospect' },
  ];
  let mode = false, sel = new Set(), cfg = null, bar = null, tm = null;
  const active = () => CFG.find(c => $(c.row)) || null;
  const rows = () => cfg ? $$(cfg.row) : [];
  const paint = () => {
    $$('.bk-row').forEach(r => r.classList.remove('bk-row', 'bk-sel')); document.body.classList.toggle('bk-mode', mode);
    if (mode) rows().forEach(r => { r.classList.add('bk-row'); if (sel.has(String(cfg.id(r)))) r.classList.add('bk-sel'); });
    draw();
  };
  const draw = () => {
    if (!bar) { bar = document.createElement('div'); bar.id = 'bk-bar'; bar.className = 'noprint'; document.body.appendChild(bar); }
    cfg = mode ? cfg : active(); if (!cfg || (S.user && S.user.role === 'founder')) { bar.hidden = true; return; } bar.hidden = false;
    if (!mode) { bar.innerHTML = '<button type="button" class="btn ghost sm" id="bk-on">☑ Select to delete</button>'; $('#bk-on', bar).onclick = () => { mode = true; sel = new Set(); paint(); }; return; }
    bar.innerHTML = '<span class="bk-n">' + sel.size + ' selected</span><button type="button" class="btn ghost sm" id="bk-all">' + (sel.size && sel.size === rows().length ? 'Clear' : 'Select all') + '</button>' + (cfg.moves ? '<select id="bk-mv" aria-label="Move the selected to"' + (sel.size ? '' : ' disabled') + '><option value="">Move to…</option>' + cfg.moves(rows()[0]).map(([v, l]) => '<option value="' + v + '">' + l + '</option>').join('') + '</select>' : '') + '<button type="button" class="btn sm" id="bk-del"' + (sel.size ? '' : ' disabled') + '>🗑 Delete</button><button type="button" class="btn ghost sm" id="bk-off">Done</button>';
    $('#bk-all', bar).onclick = () => { if (sel.size === rows().length) sel = new Set(); else rows().forEach(r => sel.add(String(cfg.id(r)))); paint(); };
    $('#bk-off', bar).onclick = () => { mode = false; sel = new Set(); paint(); };
    $('#bk-del', bar).onclick = remove; const mv = $('#bk-mv', bar); if (mv) mv.onchange = () => move(mv.value, mv.options[mv.selectedIndex].text);
  };
  async function remove() {
    if (!sel.size) return; const n = sel.size, nn = cfg.noun + (n === 1 ? '' : 's'), shared = cfg.kind === 'tasks' ? '\n\nPeople a task was assigned or shared to are told and can keep a copy for themselves.' : '';
    if (!confirm('Delete ' + n + ' ' + nn + ' for good?' + (cfg.note ? '\n\n' + cfg.note : '') + shared)) return;
    const b = $('#bk-del', bar); b.disabled = true; b.textContent = 'Deleting…';
    try { const r = await api('bulk/del', { method: 'POST', data: { kind: cfg.kind, ids: [...sel].map(Number) } }); mode = false; sel = new Set(); toast(r.deleted + ' deleted' + (r.skipped ? ', ' + r.skipped + ' kept' : '')); if (r.errors && r.errors.length) alert('Not deleted:\n\n' + r.errors.join('\n')); X.render(); }
    catch (e) { toast(e.message); b.disabled = false; b.textContent = '🗑 Delete'; }
  }
  async function move(to, label) {
    if (!to || !sel.size) return; const n = sel.size;
    if (!confirm('Move ' + n + ' ' + cfg.noun + (n === 1 ? '' : 's') + ' to ' + label + '?' + (to === 'company' ? '\n\nA vendor moved to Companies keeps its people, but its catalogue items lose their vendor.' : cfg.from === 'companies' ? '\n\nThe company leaves Companies; its people go with it.' : ''))) { draw(); return; }
    try { const r = await api('vendors/move', { method: 'POST', data: { from: cfg.from, ids: [...sel].map(Number), to } }); mode = false; sel = new Set(); toast(r.moved + ' moved to ' + label + (r.skipped ? ', ' + r.skipped + ' not moved' : '')); if (r.errors && r.errors.length) alert('Not moved:\n\n' + r.errors.join('\n')); X.render(); }
    catch (e) { toast(e.message); draw(); }
  }
  // in select mode a click ticks the row instead of opening it
  document.addEventListener('click', e => {
    if (!mode || !cfg || e.target.closest('#bk-bar')) return; const row = e.target.closest(cfg.row); if (!row) return;
    e.preventDefault(); e.stopPropagation(); const id = String(cfg.id(row)); sel.has(id) ? sel.delete(id) : sel.add(id); row.classList.toggle('bk-sel', sel.has(id)); draw();
  }, true);
  window.addEventListener('hashchange', () => { mode = false; sel = new Set(); cfg = null; });
  new MutationObserver(ms => { if (bar && ms.every(m => bar.contains(m.target) || m.target === bar)) return; clearTimeout(tm); tm = setTimeout(() => { if (mode && !rows().length) { mode = false; sel = new Set(); } if (mode) { const known = new Set(rows().map(r => String(cfg.id(r)))); sel = new Set([...sel].filter(i => known.has(i))); } paint(); }, 150); }).observe(document.body, { childList: true, subtree: true });

  /* ---- a task that was deleted: keep a copy for yourself ---- */
  X.views.kept = async () => {
    const v = $('#view'), id = +(location.hash.split('/')[2] || 0); v.innerHTML = '<div class="empty">Loading…</div>'; let k;
    try { k = (await api('bulk/keeps/' + id)).keep; } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const pr = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };
    v.innerHTML = '<div class="top"><div><h1>A task was deleted</h1><div class="muted">' + esc(k.deleted_by) + ' deleted a task that was assigned or shared to you.</div></div></div><div class="card" style="max-width:640px"><h2 style="margin:0 0 6px">' + esc(k.title) + '</h2><div class="muted small" style="margin-bottom:8px">' + esc(pr[k.priority] || '') + ' priority' + (k.due_date ? ' · was due ' + esc(k.due_date) : '') + '</div>' + (k.description ? '<p style="white-space:pre-wrap;margin:0 0 12px">' + esc(k.description) + '</p>' : '') +
      (k.state === 'kept' ? '<div class="banner">You already kept a copy of this task.</div><div class="row" style="margin-top:10px"><a class="btn" href="#/tasks">Open my tasks</a></div>' : k.state === 'dismissed' ? '<div class="muted">You chose not to keep it.</div>' : '<div class="muted small" style="margin-bottom:10px">Keeping it makes a new task for you alone, with this title and description. Comments and files are not copied.</div><div class="row" style="gap:8px"><button class="btn" id="kp">Keep a copy for myself</button><button class="btn ghost" id="kd">No thanks</button></div>') + '<div class="err" id="er" role="alert"></div></div>';
    const kp = $('#kp', v); if (kp) { kp.onclick = async () => { kp.disabled = true; try { const r = await api('bulk/keeps/' + id + '/keep', { method: 'POST', data: {} }); toast('Kept: it is now your task'); location.hash = '#/tasks/' + r.task_id; } catch (e) { $('#er', v).textContent = e.message; kp.disabled = false; } }; $('#kd', v).onclick = async () => { try { await api('bulk/keeps/' + id + '/dismiss', { method: 'POST', data: {} }); } catch {} location.hash = '#/tasks'; }; }
  };
})();
