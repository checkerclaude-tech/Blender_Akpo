(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const S = { user: null, csrf: null, users: [], depts: [], companies: [], unread: 0, offline: false, mail: false, mailUnread: 0, mailTick: 0 };
const ROLE = { super_admin: 'Super Admin', founder: 'Founder', manager: 'Manager', employee: 'Employee' };
const STATUS = { todo: 'Not yet started', in_progress: 'Open / ongoing', blocked: 'Blocked', done: 'Completed', deferred: 'Deferred', canceled: 'Canceled', transferred: 'Transferred' };
const STATUS_PICK = Object.entries(STATUS).filter(([k]) => k !== 'transferred'); // Transferred is set by the Transfer button, not picked
const CLOSED = ['done', 'canceled', 'transferred'];
const PRI = { urgent: 'Urgent', high: 'High', medium: 'Medium', low: 'Low' };
const I = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>',
  tasks: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 9l2 2 4-4M8 16h8"/></svg>',
  cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
  team: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c2 .6 3.2 2.4 3.5 5.2"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15zM10 21h4"/></svg>',
  book: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 21V5M9 7h6"/></svg>',
  gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></svg>',
  pipe: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>',
  camp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 001 1h2l5 4V6L6 10H4a1 1 0 00-1 1z"/><path d="M15 9a4 4 0 010 6M18 6a8 8 0 010 12"/></svg>',
  mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
  more: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/></svg>',
};

/* ---------- storage helpers (never assume available) ---------- */
const ls = {
  get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} },
  del(k) { try { localStorage.removeItem(k); } catch {} },
};
const cacheKey = p => 'ac:' + (S.user ? S.user.id : 0) + ':' + p;

/* ---------- API with offline queue ---------- */
async function api(path, { method = 'GET', data } = {}) {
  const opt = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
  if (S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
  if (data !== undefined) opt.body = JSON.stringify(data);
  let r;
  try { r = await fetch('api/' + path, opt); }
  catch (fe) {
    S.offline = true; S.failed = { path, why: String((fe && fe.message) || fe || ''), online: navigator.onLine, at: new Date().toLocaleTimeString() }; paintOffline();
    if ((/^(mail|campaigns|competitors|trade-events|research|projects|finance|catalog|news|invites?)(\/|$)/.test(path) || /^tasks\/\d+\/(files|share|defer|updates|report)/.test(path)) && method !== 'GET') throw new Error('You are offline. Connect to the internet to do this.');
    if (method === 'GET') { const c = (path !== 'me' && !path.startsWith('invite') && !path.startsWith('mail/') && !path.startsWith('campaigns/')) ? ls.get(cacheKey(path)) : undefined; if (c !== undefined) return c; throw new Error('You are offline and this page has not been saved yet.'); }
    const q = ls.get('aq:' + (S.user ? S.user.id : 0), []); q.push({ path, method, data }); ls.set('aq:' + (S.user ? S.user.id : 0), q);
    toast('Saved offline — will sync when you are back online'); return { queued: true };
  }
  S.offline = false; paintOffline();
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (r.status === 401 && path !== 'login' && path !== 'me') { S.user = null; render(); }
    throw Object.assign(new Error(j.error || 'Request failed'), { status: r.status, data: j });
  }
  if (method === 'GET' && path !== 'me' && !path.startsWith('invite') && !path.startsWith('mail/') && !path.startsWith('campaigns/')) ls.set(cacheKey(path), j);
  return j;
}
async function flushQueue() {
  if (!S.user) return;
  const key = 'aq:' + S.user.id; let q = ls.get(key, []); if (!q.length) return;
  const left = [];
  for (const it of q) {
    try { await api(it.path, { method: it.method, data: it.data }); }
    catch (e) { if (!e.status) { left.push(it); } else if (e.status === 419) { left.push(it); } }
  }
  ls.set(key, left);
  if (q.length > left.length) { toast('Synced ' + (q.length - left.length) + ' offline change(s)'); render(); }
}
window.addEventListener('online', async () => { try { const m = await api('me'); S.csrf = m.csrf; } catch {} flushQueue(); });
window.addEventListener('offline', () => { S.offline = true; paintOffline(); });
// A commercial is printed from a clean copy placed straight under <body>: inside the app's layout boxes the browser prints its table half as wide as the page.
window.__prePrint = () => { const sh = document.querySelector('#view .fin-sheet.comm'); if (!sh || document.getElementById('printroot')) return; const p = document.createElement('div'); p.id = 'printroot'; p.appendChild(sh.cloneNode(true)); document.body.appendChild(p); document.documentElement.classList.add('printing'); };
window.__postPrint = () => { const p = document.getElementById('printroot'); if (p) p.remove(); document.documentElement.classList.remove('printing'); };
window.addEventListener('beforeprint', window.__prePrint); window.addEventListener('afterprint', window.__postPrint);

/* ---------- ui helpers ---------- */
function toast(msg) { const t = document.createElement('div'); t.className = 'toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 3200); }
function paintOffline() { let b = $('#offbar'); if (S.offline && !b) { b = document.createElement('div'); b.id = 'offbar'; b.className = 'offline'; document.body.prepend(b); b.style.cursor = 'pointer'; b.title = 'Click to try again'; b.onclick = async () => { try { const m = await api('me'); S.csrf = m.csrf; flushQueue(); render(); } catch {} }; }
  if (S.offline && b) { const f = S.failed; b.textContent = (navigator.onLine === false || !f ? 'Offline — showing saved data. Changes will sync automatically.' : 'Could not reach the server for “' + f.path + '” (' + (f.why || 'no reply') + ', ' + f.at + '). Showing saved data. Click here to try again.'); } else if (!S.offline && b) b.remove(); }
function modal(html, onMount) {
  const m = document.createElement('div'); m.className = 'scrim'; m.innerHTML = '<div class="modal" role="dialog" aria-modal="true">' + html + '</div>';
  const close = () => { m.remove(); if (location.hash.startsWith('#/tasks/')) history.replaceState(null, '', '#/tasks'); };
  m.addEventListener('mousedown', e => { if (e.target === m) close(); });
  document.body.appendChild(m); m.close = close; if (onMount) onMount(m); return m;
}
const avUrl = id => 'api/avatar/' + id + '?v=' + (S.avv || 0);
const avatar = (id, name, big) => '<span class="avatar' + (big ? ' big' : '') + '"><span>' + esc(initials(name)) + '</span><img src="' + avUrl(id) + '" alt="" loading="lazy" data-rm="1"></span>';
const initials = n => (n || '?').split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
const fmtDate = d => { if (!d) return ''; const x = new Date(d.length === 10 ? d + 'T00:00:00' : d.replace(' ', 'T')); return x.toLocaleDateString(undefined, x.getFullYear() === new Date().getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' }); };
const fmtTime = d => new Date(d.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const ago = d => { if (!d) return 'never'; const s = (Date.now() - new Date(d.replace(' ', 'T')).getTime()) / 1000; if (s < 90) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; };
const opts = (arr, sel, blank) => (blank ? '<option value="">' + esc(blank) + '</option>' : '') + arr.map(([v, l]) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
const canManage = () => S.user && ['super_admin', 'manager'].includes(S.user.role);
const readOnly = () => S.user && S.user.role === 'founder';

async function loadRefs() {
  try { [S.users, S.depts] = await Promise.all([api('users'), api('departments')]); } catch {}
  try { S.companies = await api('companies'); } catch { S.companies = []; }
}

/* ---------- shell ---------- */
function navItems() {
  const u = S.user, n = [['home', 'Home', I.home], ['tasks', 'Tasks', I.tasks], ['pipeline', 'Pipeline', I.pipe], ['mail', 'Mail', I.mail], ['schedule', 'Schedule', I.cal]];
  if (u.campaigns) n.push(['campaigns', 'Campaigns', I.camp]);
  if (['super_admin', 'founder', 'manager'].includes(u.role)) n.push(['team', u.role === 'founder' ? 'Snapshot' : 'Team', I.team]);
  n.push(['alerts', 'Alerts', I.bell], ['learn', 'Learn', I.book], ['competition', 'Competition', I.team], ['events', 'Events', I.cal], ['research', 'Research', I.book], ['projects', 'Projects', I.pipe], ['products', 'Products', I.pipe], ['news', 'News', I.bell]);
  if (u.finance || ['founder', 'super_admin'].includes(u.role)) n.splice(n.findIndex(x => x[0] === 'alerts'), 0, ['finance', 'Finance', I.gear]);
  if (u.role === 'super_admin') n.push(['admin', 'Admin', I.gear]);
  if (window.OfficeExt && window.OfficeExt.nav) window.OfficeExt.nav(n, I); // Agent platform hook 2 of 3: adds Documents
  const tb = u.tabs || {};
  return n.filter(x => !(x[0] in tb) || tb[x[0]]);
}
function shell() {
  const u = S.user, cur = (location.hash.split('/')[1] || 'home'), nav = navItems();
  const link = ([k, l, ic], bar) => '<a href="#/' + k + '" class="' + (cur === k ? 'on' : '') + '" title="' + esc(l) + '">' + ic + '<span>' + l + '</span>' + (k === 'alerts' && S.unread ? '<span class="badge">' + S.unread + '</span>' : '') + (k === 'mail' && S.mailUnread ? '<span class="badge">' + S.mailUnread + '</span>' : '') + (S.badges && S.badges[k] ? '<span class="badge">' + S.badges[k] + '</span>' : '') + '</a>';
  const tabs = ['home', 'tasks', 'pipeline', 'mail', 'alerts'].map(k => nav.find(x => x[0] === k)).filter(Boolean).concat([['more', 'More', I.more]]);
  if (window.OfficeExt && window.OfficeExt.tabs) window.OfficeExt.tabs(tabs); // Agents area hook 4 of 6: the owner's bar shows Agents in place of Mail
  $('#root').innerHTML = '<div id="app"><aside class="side"><div class="side-top"><div class="brand">AEROSUB<small>Office</small></div><button type="button" class="side-toggle" id="sidetog" aria-label="Minimise the menu" title="Minimise the menu"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button></div><nav class="nav">' + nav.map(x => link(x)).join('') +
    '</nav><a class="me" href="#/me" style="color:#fff;display:flex;gap:10px;align-items:center">' + avatar(u.id, u.name) + '<span><b>' + esc(u.name) + '</b>' + esc(ROLE[u.role]) + (u.department ? ' · ' + esc(u.department) : '') + '</span></a></aside>' +
    '<main class="main"><div id="banners"></div><div id="view"></div></main></div><nav class="tabbar">' + tabs.map(x => link(x)).join('') + '</nav>';
  const tg = $('#sidetog'); if (tg) tg.onclick = () => { const on = document.documentElement.classList.toggle('nav-min'); ls.set('navmin', on ? 1 : 0); tg.setAttribute('aria-label', on ? 'Expand the menu' : 'Minimise the menu'); tg.title = on ? 'Expand the menu' : 'Minimise the menu'; };
}

/* ---------- views ---------- */
const views = {};
/* Agent platform hook 1 of 3 (office-app-changes/README.md): lets assets/js/agent-pipeline.js use the helpers above. */
window.OfficeExt = { api, modal, toast, esc, S, views, readOnly, render, today, campGo: t => { CP.tab = t; ls.set('cptab', t); if (location.hash !== '#/campaigns') location.hash = '#/campaigns'; else render(); } };

views.home = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  const t0 = today(), end = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10) + ' 23:59:59';
  const [tasks, events] = await Promise.all([api('tasks?assignee=me'), api('events?from=' + t0 + ' 00:00:00&to=' + encodeURIComponent(end))]);
  const open = tasks.filter(t => !CLOSED.includes(t.status)), od = open.filter(t => t.due_date && t.due_date < t0), dt0 = open.filter(t => t.due_date === t0);
  const done7 = tasks.filter(t => t.status === 'done' && t.completed_at && Date.now() - new Date(t.completed_at.replace(' ', 'T')) < 7 * 864e5).length;
  const hr = new Date().getHours(), greet = hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
  v.innerHTML = '<div class="top"><div><h1>' + greet + ', ' + esc(S.user.name.split(' ')[0]) + '</h1><div class="muted">' + new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) + '</div></div>' +
    (readOnly() ? '' : '<button class="btn right" id="nt">+ New task</button>') + '</div>' +
    '<div class="cards"><div class="card stat"><span class="muted small">Open</span><b>' + open.length + '</b></div><div class="card stat warn"><span class="muted small">Due today</span><b>' + dt0.length + '</b></div>' +
    '<div class="card stat bad"><span class="muted small">Overdue</span><b>' + od.length + '</b></div><div class="card stat ok"><span class="muted small">Done · 7 days</span><b>' + done7 + '</b></div></div>' +
    '<div class="two"><div class="card"><div class="row"><h2>My focus</h2><a class="right small" href="#/tasks">All tasks</a></div><div class="list" id="focus"></div></div>' +
    '<div class="card"><div class="row"><h2>Next 7 days</h2><a class="right small" href="#/schedule">Schedule</a></div><div class="list" id="evs"></div></div></div>';
  const focus = [...od, ...dt0, ...open.filter(t => !od.includes(t) && !dt0.includes(t))].slice(0, 8);
  $('#focus').innerHTML = focus.length ? focus.map(taskRow).join('') : '<div class="empty">Nothing on your plate. 🎉</div>';
  bindTaskRows($('#focus'));
  $('#evs').innerHTML = events.length ? events.slice(0, 8).map(e => '<div class="item"><div class="grow"><b>' + esc(e.title) + '</b><div class="muted small">' + fmtDate(e.starts_at) + (e.all_day == 1 ? '' : ' · ' + fmtTime(e.starts_at)) + (e.location ? ' · ' + esc(e.location) : '') + '</div></div><span class="tag">' + esc(e.kind) + '</span></div>').join('') : '<div class="empty">No events this week.</div>';
  const nt = $('#nt'); if (nt) nt.onclick = () => taskForm();
};

function taskRow(t) {
  const od = !CLOSED.includes(t.status) && t.due_date && t.due_date < today();
  return '<div class="item" data-id="' + t.id + '"><button class="check ' + (t.status === 'done' ? 'done' : '') + '" data-toggle="' + t.id + '" data-s="' + t.status + '" aria-label="Toggle done"></button>' +
    '<div class="grow"><div class="title ' + (t.status === 'done' ? 'done' : '') + '">' + esc(t.title) + (t.transfer_state === 'pending' ? ' <span class="tag">Offered to you</span>' : t.transfer_state === 'offered' ? ' <span class="tag">Transfer waiting</span>' : '') + (t.kind === 'flow' ? ' <span class="tag">Plan</span>' : t.kind === 'step' ? ' <span class="tag">Step</span>' + (t.status === 'blocked' && /^Waiting/.test(t.status_reason || '') ? ' <span class="tag blocked">Waiting</span>' : '') : '') + '</div><div class="muted small">' +
    (t.assignee_name ? esc(t.assignee_name) : 'Unassigned') + (t.company_name ? ' · ' + esc(t.company_name) : (t.department ? ' · ' + esc(t.department) : '')) + (t.due_date ? ' · <span class="' + (od ? 'overdue' : '') + '">' + (od ? 'Overdue ' : 'Due ') + fmtDate(t.due_date) + '</span>' : '') + '</div></div>' +
    (t.task_type && t.task_type !== 'general' ? '<span class="tag">' + TTYPE[t.task_type] + '</span>' : '') + (+t.file_count ? '<span class="muted small" title="Files">📎' + t.file_count + '</span>' : '') + (+t.share_count ? '<span class="muted small" title="Shared">👥' + t.share_count + '</span>' : '') +
    (t.status !== 'done' && t.status !== 'todo' ? '<span class="tag ' + t.status + '">' + STATUS[t.status] + '</span>' : '') + '<span class="tag ' + t.priority + '">' + PRI[t.priority] + '</span>' + (readOnly() ? '' : '<button class="btn ghost sm" data-share="' + t.id + '" title="Share this task">Share</button>') + '</div>';
}
function bindTaskRows(root) {
  root.querySelectorAll('.item[data-id]').forEach(el => el.onclick = e => {
    const shb = e.target.closest('[data-share]'); if (shb) { e.stopPropagation(); shareTask(+shb.dataset.share); return; }
    const tg = e.target.closest('[data-toggle]');
    if (tg) { e.stopPropagation(); const ns = tg.dataset.s === 'done' ? 'todo' : 'done'; if (readOnly()) return toast('Founder accounts are read-only'); api('tasks/' + tg.dataset.toggle, { method: 'PATCH', data: { status: ns } }).then(() => render()).catch(er => toast(er.message)); return; }
    location.hash = '#/tasks/' + el.dataset.id;
  });
}

let TF = { scope: 'mine', status: '' };
views.tasks = async () => {
  const v = $('#view'), id = (location.hash.split('/')[2]) || '';
  const wide = !['employee'].includes(S.user.role) || (S.user.head_of || []).length > 0;
  if (!wide && TF.scope === 'all') TF.scope = 'mine';
  v.innerHTML = '<div class="top"><h1>Tasks</h1>' + (readOnly() ? '' : '<button class="btn right" id="nt">+ New task</button>') + '</div>' +
    '<div class="row" style="margin-bottom:14px"><div class="chips" id="fs">' + '<span class="chip ' + (TF.scope === 'mine' ? 'on' : '') + '" data-sc="mine">Mine</span><span class="chip ' + (TF.scope === 'shared' ? 'on' : '') + '" data-sc="shared">Shared with me</span>' + '<span class="chip ' + (TF.scope === 'given' ? 'on' : '') + '" data-sc="given" title="Tasks you gave to other people">Given by me</span><span class="chip ' + (TF.scope === 'sharedby' ? 'on' : '') + '" data-sc="sharedby" title="Tasks you shared with other people">Shared by me</span>' + (wide ? '<span class="chip ' + (TF.scope === 'all' ? 'on' : '') + '" data-sc="all">Everyone</span>' : '') + '</div>' +
    '<div class="chips right" id="fst">' + [['', 'All'], ...Object.entries(STATUS)].map(([k, l]) => '<span class="chip ' + (TF.status === k ? 'on' : '') + '" data-st="' + k + '">' + l + '</span>').join('') + '</div></div>' +
    '<div class="card"><div class="list" id="tl"><div class="empty">Loading…</div></div></div>';
  const qs = []; if (TF.scope === 'mine') qs.push('assignee=me'); if (TF.scope === 'shared') qs.push('shared=1'); if (TF.scope === 'given') qs.push('creator=me'); if (TF.scope === 'sharedby') qs.push('shared_by=me'); if (TF.status) qs.push('status=' + TF.status);
  const rows = await api('tasks' + (qs.length ? '?' + qs.join('&') : ''));
  $('#tl').innerHTML = rows.length ? rows.map(taskRow).join('') : '<div class="empty">No tasks here yet.</div>'; bindTaskRows($('#tl'));
  v.querySelectorAll('[data-sc]').forEach(c => c.onclick = () => { TF.scope = c.dataset.sc; render(); });
  v.querySelectorAll('[data-st]').forEach(c => c.onclick = () => { TF.status = c.dataset.st; render(); });
  const nt = $('#nt'); if (nt) nt.onclick = () => taskForm();
  if (id && /^\d+$/.test(id)) taskDetail(+id);
};

function assigneeOptions(sel) {
  let us = S.users.filter(u => u.active == 1 && u.role !== 'founder');
  // employees assign to themselves; managers and department heads can also assign to the people in their departments
  const md = [...(S.user.head_of || []), ...(S.user.role === 'manager' ? (S.user.department_ids || []) : [])];
  if (S.user.role === 'employee' || S.user.role === 'manager') us = us.filter(u => u.id == S.user.id || (u.department_ids || []).some(d => md.includes(d)));
  return opts(us.map(u => [u.id, u.name + (u.department ? ' — ' + u.department : '')]), sel ?? S.user.id);
}
function taskForm(t, preset) {
  preset = preset || {};
  const m = modal('<h2>' + (t ? 'Edit task' : 'New task') + '</h2>' + (t ? '' : '<div class="chips" id="tmode" role="group" aria-label="Kind of task" style="margin-top:8px"><span class="chip on" data-md="n" role="button" tabindex="0">Normal task</span><span class="chip" data-md="f" role="button" tabindex="0" title="A plan of steps that wait for each other">Dependent tasks (plan with steps)</span></div>') + '<form id="tf" style="margin-top:14px"><div class="field"><label>Title</label><input name="title" required maxlength="200" value="' + esc(t?.title) + '"></div>' +
    '<div class="grid2"><div class="field"><label>Type</label><select name="task_type" id="tty">' + opts(Object.entries(TTYPE).filter(x => x[0] !== 'rfq' || t?.task_type === 'rfq'), t?.task_type || preset.task_type || 'general') + '</select></div><div class="field" id="mtf" style="display:none"><label>Meeting starts</label><input type="datetime-local" name="starts_at" value="' + esc((t?.starts_at || '').replace(' ', 'T').slice(0, 16)) + '"></div></div><div class="field" id="mte" style="display:none"><label>Meeting ends</label><input type="datetime-local" name="ends_at" value="' + esc((t?.ends_at || '').replace(' ', 'T').slice(0, 16)) + '"></div>' +
    '<div id="mmx" style="display:none"><div class="field"><label>Location or meeting link (optional)</label><input name="meeting_place" maxlength="190" placeholder="Boardroom, or a Teams / Meet link" value="' + esc(t?.meeting_place || '') + '"></div>' +
    '<div class="field"><label>Invite people <span class="muted small" id="mcnt"></span></label><div class="muted small" style="margin-bottom:6px">They get a calendar invite by email (it adds to the phone or computer calendar and alerts before the meeting) and a notification here.</div><div class="row" id="mdp" style="flex-wrap:wrap;gap:6px;margin-bottom:6px"></div><input id="mq" placeholder="Find a person…" style="margin-bottom:6px"><div id="ml" style="max-height:190px;overflow:auto;display:grid;gap:2px"></div></div></div>' +
    '<div class="field"><label>Details</label><textarea name="description" placeholder="Type @ to tag a person, a department or everyone">' + esc(t?.description) + '</textarea></div><div class="grid2"><div class="field"><label>Assign to</label><select name="assignee_id">' + assigneeOptions(t ? t.assignee_id : S.user.id) + '</select></div>' +
    '<div class="field"><label>Due date</label><input type="date" name="due_date" value="' + esc(t?.due_date || '') + '"></div></div><div class="grid2"><div class="field"><label>Priority</label><select name="priority">' + opts(Object.entries(PRI).reverse(), t?.priority || 'medium') + '</select></div>' +
    '<div class="field"><label>Status</label><select name="status">' + opts(STATUS_PICK, t?.status || 'todo') + '</select></div></div><div class="field"><label>Related company (optional)</label><select name="company_id">' + opts(S.companies.map(c => [c.id, c.name]), t?.company_id ?? preset.company_id, '— none —') + '</select></div>' + (t ? '' : '<div class="field"><label>Attach files (optional)</label><input type="file" id="tnf" multiple><div class="muted small">Up to 8 MB each. You can add more later from the task.</div></div>') + '<div class="err" id="er"></div>' +
    '<div class="row"><button class="btn" type="submit">' + (t ? 'Save' : 'Create task') + '</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  const tty = $('#tty', m), mtog = () => { const mt = tty.value === 'meeting'; $('#mtf', m).style.display = mt ? '' : 'none'; $('#mte', m).style.display = mt ? '' : 'none'; $('#mmx', m).style.display = mt ? '' : 'none'; $('[name=starts_at]', m).required = mt; }; tty.onchange = mtog; mtog();
  { // who to invite: pick people, or a whole department (its head is part of it)
    const have = new Set((t?.shares || []).map(x => +x.id)), ppl = S.users.filter(u => u.active == 1 && u.id != S.user.id), heads = new Set(S.depts.filter(d => d.head_id).map(d => +d.head_id));
    const mine = S.user.role === 'super_admin' ? S.depts : S.depts.filter(d => (S.user.department_ids || []).includes(+d.id) || (S.user.head_of || []).includes(+d.id));
    $('#mdp', m).innerHTML = mine.map(d => { const n = ppl.filter(u => (u.department_ids || []).includes(+d.id)).length; return n ? '<button type="button" class="btn ghost sm" data-dept="' + d.id + '">+ Whole ' + esc(d.name) + ' team (' + n + ')</button>' : ''; }).join('') + '<button type="button" class="btn ghost sm" id="mnone">Clear</button>';
    $('#ml', m).innerHTML = ppl.map(u => '<label class="item" style="cursor:pointer" data-n="' + esc((u.name + ' ' + (u.department || '')).toLowerCase()) + '"><input type="checkbox" value="' + u.id + '" style="width:auto"' + (have.has(+u.id) ? ' checked disabled' : '') + '><span class="grow"><b>' + esc(u.name) + '</b> <span class="muted small">' + esc(u.department || '') + '</span></span>' + (heads.has(+u.id) ? '<span class="tag">Head</span>' : '') + (have.has(+u.id) ? '<span class="tag done">Invited</span>' : '') + '</label>').join('');
    const count = () => { const n = m.querySelectorAll('#ml input:checked:not(:disabled)').length; $('#mcnt', m).textContent = n ? '· ' + n + ' selected' : ''; };
    m.querySelectorAll('[data-dept]').forEach(b => b.onclick = () => { ppl.filter(u => (u.department_ids || []).includes(+b.dataset.dept)).forEach(u => { const c = m.querySelector('#ml input[value="' + u.id + '"]'); if (c && !c.disabled) c.checked = true; }); count(); });
    $('#mnone', m).onclick = () => { m.querySelectorAll('#ml input:not(:disabled)').forEach(c => c.checked = false); count(); }; $('#ml', m).onchange = count;
    $('#mq', m).oninput = e => { const q = e.target.value.toLowerCase(); m.querySelectorAll('#ml [data-n]').forEach(l => l.style.display = l.dataset.n.includes(q) ? '' : 'none'); };
  }
  $('#tf', m).onsubmit = async e => {
    e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.assignee_id = f.assignee_id || null; f.company_id = f.company_id || null;
    if (f.task_type === 'meeting') f.attendees = [...m.querySelectorAll('#ml input:checked:not(:disabled)')].map(i => +i.value);
    try { const rs = await api(t ? 'tasks/' + t.id : 'tasks', { method: t ? 'PATCH' : 'POST', data: f }); const nf = !t && $('#tnf', m) ? [...$('#tnf', m).files] : []; let upErr = ''; for (const fl of nf) { try { await api('tasks/' + rs.id + '/files', { method: 'POST', data: await fileB64(fl) }); } catch (ue) { upErr = ue.message; } } if (upErr) toast('Task created, but a file was not added: ' + upErr); m.close(); if (rs && rs.invited) toast('Meeting created. Calendar invites emailed to ' + rs.invited + ' ' + (rs.invited === 1 ? 'person' : 'people')); else if (t && f.attendees && f.attendees.length) toast('Invites sent'); render(); } catch (er) { $('#er', m).textContent = er.message; }
  };
}
function meetingCal(m, t) {   // add it to a phone or desktop calendar (with alerts), or subscribe to every meeting
  const h = [...m.querySelectorAll('h3')].find(x => /Comments/.test(x.textContent)); if (!h) return;
  h.insertAdjacentHTML('beforebegin', '<div class="card" style="margin:12px 0;padding:10px 12px;box-shadow:none;background:var(--mist)"><div class="row" style="flex-wrap:wrap;gap:8px"><b class="grow">📅 Calendar alert</b><a class="btn sm" href="api/tasks/' + t.id + '/ics">Add to my calendar</a><button class="btn ghost sm" id="mcl" type="button">Copy calendar link</button><button class="btn ghost sm" id="mcf" type="button">Subscribe to all my meetings</button></div>' +
    (t.meeting_place ? '<div class="small" style="margin-top:6px">📍 ' + esc(t.meeting_place) + '</div>' : '') + '<div class="muted small" style="margin-top:6px">Add it once and your phone or computer calendar alerts you before it starts. An invite was also emailed to everyone invited. "Subscribe" keeps your calendar up to date with every meeting.</div></div>');
  const cp = async key => { try { const l = await api('tasks/' + t.id + '/ics-links'); await navigator.clipboard.writeText(l[key]); toast('Link copied'); } catch (e) { toast(e.message || 'Could not copy the link'); } };
  $('#mcl', m).onclick = () => cp('https'); $('#mcf', m).onclick = () => cp('feed');
}
async function taskDetail(id) {
  let t; try { t = await api('tasks/' + id); } catch (e) { toast(e.message); return; }
  const m = modal('<div class="row"><h2 class="grow">' + esc(t.title) + '</h2><button class="btn ghost sm" id="cl">Close</button></div>' +
    '<div class="row" style="margin:10px 0">' + (t.task_type !== 'general' ? '<span class="tag">' + TTYPE[t.task_type] + '</span>' : '') + '<span class="tag ' + t.status + '">' + STATUS[t.status] + '</span><span class="tag ' + t.priority + '">' + PRI[t.priority] + '</span>' + (t.due_date ? '<span class="tag">Due ' + fmtDate(t.due_date) + '</span>' : '') + '</div>' +
    '<p style="white-space:pre-wrap;margin:8px 0">' + ((window.OfficeExt.mnHtml || (x => x))(esc(t.description)) || '<span class="muted">No details.</span>') + '</p><div class="muted small">Assigned to <b>' + esc(t.assignee_name || 'nobody') + '</b> · created by ' + esc(t.creator_name) + (t.company_name ? ' · re <b>' + esc(t.company_name) + '</b>' : '') + '</div>' +
    (readOnly() ? '' : '<div class="row" style="margin:14px 0"><select id="ns" style="width:auto"' + (t.status === 'transferred' ? ' disabled' : '') + '>' + opts(t.status === 'transferred' ? Object.entries(STATUS) : STATUS_PICK, t.status) + '</select>' + (t.can_edit ? '<button class="btn ghost sm" id="ed">Edit</button><button class="btn ghost sm" id="rm" style="color:var(--bad)">Delete</button>' : '') + '</div>') +
    '<h3 style="margin-top:12px">Comments</h3><div class="list" id="cm">' + (t.comments.length ? t.comments.map(c => '<div style="padding:9px 0"><b>' + esc(c.name) + '</b> <span class="muted small">' + ago(c.created_at) + '</span><div style="white-space:pre-wrap">' + (window.OfficeExt.mnHtml || (x => x))(esc(c.body)) + '</div></div>').join('') : '<div class="muted small" style="padding:8px 0">No comments yet.</div>') + '</div>' +
    '<form id="cf" class="row" style="margin-top:10px;flex-wrap:nowrap"><input name="body" placeholder="Write a comment… (type @ to tag someone)" required autocomplete="off"><button class="btn sm">Send</button></form>');
  $('#cl', m).onclick = () => m.close(); taskExtras(m, t); taskFinance(m, t); if (t.task_type === 'meeting' && t.starts_at) meetingCal(m, t);
  if (window.OfficeExt && window.OfficeExt.onTask) window.OfficeExt.onTask(m, t); // Agents area hook 7 of 7: research button and badge on RFQ tasks
  const ns = $('#ns', m); if (ns) ns.onchange = async () => { try { await api('tasks/' + id, { method: 'PATCH', data: { status: ns.value } }); m.close(); render(); } catch (e) { toast(e.message); } };
  const ed = $('#ed', m); if (ed) ed.onclick = () => { m.close(); taskForm(t); };
  const rm = $('#rm', m); if (rm) rm.onclick = async () => { if (!confirm('Delete this task?')) return; await api('tasks/' + id, { method: 'DELETE' }); m.close(); render(); };
  $('#cf', m).onsubmit = async e => { e.preventDefault(); try { await api('tasks/' + id + '/comments', { method: 'POST', data: { body: new FormData(e.target).get('body') } }); m.close(); taskDetail(id); } catch (er) { toast(er.message); } };
}

/* ---------- Task upgrade: types, files, sharing, defer, research, build, field reports ---------- */
const TTYPE = { general: 'Task', research: 'Research', build: 'Build', operations: 'Operations', field: 'Field task', rfq: 'RFQ', meeting: 'Meeting' };
const TACT = { research: 'Start research', build: 'Show progress', operations: 'Log operations', field: 'Enter report' };
const RTYPES = ['Market', 'Competitor', 'Client / company', 'Product or service', 'Technical', 'Regulatory', 'Other'];
const REPORT_FORMS = {
  ut: ['UT form', [['asset', 'Asset / equipment tested', 'text'], ['location', 'Location / site', 'text'], ['date', 'Date', 'date'], ['instrument', 'Instrument and probe', 'text'], ['calibration', 'Calibration block / reference', 'text'], ['nominal', 'Nominal thickness (mm)', 'number'], ['minimum', 'Minimum reading (mm)', 'number'], ['readings', 'Readings (one per line: point, value)', 'textarea'], ['result', 'Result', 'select:Pass,Fail,Monitor'], ['remarks', 'Remarks', 'textarea']]],
  drone: ['Drone readiness form', [['site', 'Site', 'text'], ['date', 'Date', 'date'], ['pilot', 'Pilot in command', 'text'], ['drone_id', 'Drone ID', 'text'], ['c_battery', 'Batteries charged', 'check'], ['c_props', 'Propellers and frame inspected', 'check'], ['c_firmware', 'Firmware and software up to date', 'check'], ['c_weather', 'Weather within limits', 'check'], ['c_airspace', 'Airspace permission obtained', 'check'], ['c_emergency', 'Emergency plan briefed', 'check'], ['decision', 'Decision', 'select:Go,No-go'], ['remarks', 'Remarks', 'textarea']]],
  deployment: ['Deployment form', [['site', 'Site', 'text'], ['date', 'Date', 'date'], ['team', 'Team on site', 'text'], ['equipment', 'Equipment deployed', 'textarea'], ['start_time', 'Start time', 'time'], ['end_time', 'End time', 'time'], ['status', 'Status', 'select:Completed,Partial,Postponed'], ['issues', 'Issues encountered', 'textarea'], ['remarks', 'Remarks', 'textarea']]],
  hse: ['Health and safety form', [['site', 'Site', 'text'], ['date', 'Date', 'date'], ['c_toolbox', 'Toolbox talk held', 'check'], ['c_ppe', 'PPE worn by everyone', 'check'], ['hazards', 'Hazards identified', 'textarea'], ['incidents', 'Incidents or near-misses', 'textarea'], ['actions', 'Actions taken', 'textarea'], ['remarks', 'Remarks', 'textarea']]],
  closeout: ['Close-out report', [['summary', 'Summary of work done', 'textarea'], ['deliverables', 'Deliverables handed over', 'textarea'], ['outstanding', 'Outstanding items', 'textarea'], ['signoff', 'Client sign-off (name)', 'text'], ['lessons', 'Lessons learned', 'textarea']]],
};
const fileB64 = f => new Promise((res, rej) => { if (f.size > 8e6) return rej(new Error(f.name + ' is larger than 8 MB')); const r = new FileReader(); r.onload = () => res({ name: f.name, type: f.type || 'application/octet-stream', data: String(r.result).split(',')[1] || '' }); r.onerror = () => rej(new Error('Could not read ' + f.name)); r.readAsDataURL(f); });
const fsize = n => n > 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
const taskUrl = id => location.origin + location.pathname + '#/tasks/' + id;

async function shareTask(id) {
  let t; try { t = await api('tasks/' + id); } catch (e) { return toast(e.message); } await loadRefs();
  const us = S.users.filter(u => u.active == 1 && u.id != S.user.id), have = new Set(t.shares.map(s => +s.id)); const files = [], links = [];
  const m = modal('<div class="row"><h2 class="grow">Share task</h2><button class="btn ghost sm" id="cx">✕</button></div><p class="muted small" style="margin:4px 0 10px">' + esc(t.title) + '</p><input id="shq" placeholder="Find a person…" style="margin-bottom:8px"><div id="shl" style="max-height:200px;overflow:auto;display:grid;gap:2px">' +
    us.map(u => '<label class="item" style="cursor:pointer" data-n="' + esc(u.name.toLowerCase()) + '"><input type="checkbox" value="' + u.id + '" style="width:auto"' + (have.has(+u.id) ? ' checked disabled' : '') + '><span class="grow"><b>' + esc(u.name) + '</b> <span class="muted small">' + esc(u.department || '') + '</span></span>' + (have.has(+u.id) ? '<span class="tag done">Shared</span>' : '') + '</label>').join('') + '</div>' +
    '<div class="field" style="margin-top:12px"><label>Message (optional)</label><textarea id="shn" rows="7" maxlength="8000" placeholder="Write as much as you need: context, instructions, what you expect…" style="min-height:140px;resize:vertical"></textarea><div class="muted small" id="shc" style="text-align:right">0 / 8000</div></div>' +
    '<div class="row" style="gap:8px;flex-wrap:wrap;margin-bottom:6px"><label class="btn ghost sm" style="cursor:pointer;margin:0">📎 Attach images or documents<input type="file" id="shf" multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip"></label><input id="shl2" placeholder="Paste a link and press Add" style="flex:1;min-width:180px"><button class="btn ghost sm" type="button" id="sha">Add link</button></div>' +
    '<div id="shx" style="display:grid;gap:4px;margin-bottom:6px"></div>' +
    '<label class="small" style="display:block;margin:6px 0"><input type="checkbox" id="shm" style="width:auto" checked> Also email them. It shows your name, includes the message and a link to the task (they get a notification too).</label>' +
    '<div class="err" id="er"></div><div class="row"><button class="btn" id="go">Share</button><button class="btn ghost" id="cx2">Cancel</button></div>');
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#shq', m).oninput = e => { const q = e.target.value.toLowerCase(); m.querySelectorAll('[data-n]').forEach(l => l.style.display = l.dataset.n.includes(q) ? '' : 'none'); };
  $('#shn', m).oninput = e => { $('#shc', m).textContent = e.target.value.length + ' / 8000'; };
  const paint = () => { $('#shx', m).innerHTML = files.map((f, i) => '<div class="item" style="padding:6px 10px">' + (f.preview ? '<img src="' + f.preview + '" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;margin-right:8px">' : '<span style="margin-right:8px">📄</span>') + '<span class="grow">' + esc(f.name) + ' <span class="muted small">' + fsize(f.size) + '</span></span><button class="btn ghost sm" type="button" data-rf="' + i + '" aria-label="Remove">✕</button></div>').join('') +
    links.map((l, i) => '<div class="item" style="padding:6px 10px"><span style="margin-right:8px">🔗</span><span class="grow" style="overflow-wrap:anywhere">' + esc(l) + '</span><button class="btn ghost sm" type="button" data-rl="' + i + '" aria-label="Remove">✕</button></div>').join('');
    m.querySelectorAll('[data-rf]').forEach(b => b.onclick = () => { files.splice(+b.dataset.rf, 1); paint(); }); m.querySelectorAll('[data-rl]').forEach(b => b.onclick = () => { links.splice(+b.dataset.rl, 1); paint(); }); };
  $('#shf', m).onchange = async e => { for (const f of e.target.files) { try { if (files.length >= 8) throw new Error('You can attach up to 8 files'); const b = await fileB64(f); b.size = f.size; if (/^image\//.test(f.type)) b.preview = URL.createObjectURL(f); files.push(b); } catch (er) { $('#er', m).textContent = er.message; } } e.target.value = ''; paint(); };
  const addLink = () => { const v = $('#shl2', m).value.trim(); if (!v) return; if (links.length >= 10) { $('#er', m).textContent = 'Up to 10 links'; return; } links.push(/^https?:\/\//i.test(v) ? v : 'https://' + v); $('#shl2', m).value = ''; paint(); };
  $('#sha', m).onclick = addLink; $('#shl2', m).onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); addLink(); } };
  $('#go', m).onclick = async () => {
    const ids = [...m.querySelectorAll('#shl input:checked:not(:disabled)')].map(i => +i.value); if (!ids.length) { $('#er', m).textContent = 'Choose at least one person'; return; }
    addLink(); $('#go', m).disabled = true; $('#go', m).textContent = 'Sharing…';
    try { const shr = await api('tasks/' + id + '/share', { method: 'POST', data: { user_ids: ids, note: $('#shn', m).value, links, files: files.map(f => ({ name: f.name, type: f.type, data: f.data })), email: $('#shm', m).checked } }); m.close();
      toast('Shared. They have been notified' + (shr.emailed ? ' and emailed (' + shr.emailed + ')' : '')); render(); location.hash.startsWith('#/tasks/') && taskDetail(id); }
    catch (er) { $('#er', m).textContent = er.message; $('#go', m).disabled = false; $('#go', m).textContent = 'Share'; }
  };
}
function deferTask(t) {
  const m = modal('<h2>Defer task</h2><p class="muted small" style="margin:4px 0 10px">' + esc(t.title) + (t.due_date ? ' · currently due ' + fmtDate(t.due_date) : '') + '</p><form id="df2"><div class="field"><label>New due date</label><input type="date" name="due_date" required min="' + today() + '"></div><div class="field"><label>Reason (optional)</label><input name="reason" maxlength="300"></div><div class="err" id="er"></div><div class="row"><button class="btn">Defer</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#df2', m).onsubmit = async e => { e.preventDefault(); try { await api('tasks/' + t.id + '/defer', { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); toast('Task deferred'); render(); location.hash.startsWith('#/tasks/') && taskDetail(t.id); } catch (er) { $('#er', m).textContent = er.message; } };
}
const filePick = (id, label) => '<label class="btn ghost sm" style="cursor:pointer;margin:0">' + label + '<input type="file" id="' + id + '" multiple hidden></label>';
function researchDialog(t) {
  const m = modal('<div class="row"><h2 class="grow">' + (t ? 'Research' : 'New research') + '</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="rf" style="margin-top:10px">' + (t ? '' : '<div class="field"><label>Title</label><input name="title" required maxlength="200" placeholder="What are you researching?"></div>') +
    '<div class="grid2"><div class="field"><label>Research type</label><select name="research_type">' + opts(RTYPES.map(x => [x, x]), 'Market') + '</select></div><div class="field"><label>Product or service offering</label><input name="offering" maxlength="500"></div></div>' +
    '<div class="grid2"><div class="field"><label>Contact person</label><input name="contact_name" maxlength="200"></div><div class="field"><label>Company</label><input name="contact_company" maxlength="200"></div></div><div class="grid2"><div class="field"><label>Contact email</label><input type="email" name="contact_email"></div><div class="field"><label>Contact phone</label><input name="contact_phone" maxlength="60"></div></div>' +
    '<div class="field"><label>Notes</label><textarea name="notes" rows="6" placeholder="Type your findings here…"></textarea></div><div class="row">' + filePick('rfl', '📎 Import PDF, Word or Excel files') + '<span id="rfs" class="row small muted"></span></div>' +
    '<label class="small" style="display:block;margin:12px 0"><input type="checkbox" name="complete" style="width:auto"> Mark this research as completed</label><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  const files = []; const paint = () => { $('#rfs', m).innerHTML = files.map((f, i) => '<span class="chip">' + esc(f.name) + ' <a href="#" data-x="' + i + '" style="color:var(--bad)">✕</a></span>').join(''); $('#rfs', m).querySelectorAll('[data-x]').forEach(a => a.onclick = e => { e.preventDefault(); files.splice(+a.dataset.x, 1); paint(); }); };
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#rfl', m).onchange = async e => { try { for (const f of e.target.files) files.push(await fileB64(f)); } catch (er) { toast(er.message); } e.target.value = ''; paint(); };
  $('#rf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); const complete = !!f.complete; const title = f.title; delete f.complete; delete f.title; const b = e.target.querySelector('.btn'); b.disabled = true;
    try { if (!t) { const r = await api('research', { method: 'POST', data: { title, data: f, complete, files } }); m.close(); toast('Research saved'); location.hash = '#/tasks/' + r.id; render(); }
      else { const ids = []; for (const fl of files) { const r = await api('tasks/' + t.id + '/files', { method: 'POST', data: fl }); ids.push(r.id); } f.files = ids; await api('tasks/' + t.id + '/updates', { method: 'POST', data: { kind: 'research', data: f, complete } }); m.close(); toast('Research saved'); render(); location.hash.startsWith('#/tasks/') && taskDetail(t.id); } }
    catch (er) { $('#er', m).textContent = er.message; b.disabled = false; } };
}
function progressDialog(t) {
  const m = modal('<div class="row"><h2 class="grow">Show progress</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="pf2" style="margin-top:10px"><div class="field"><label>Link (website, prototype, document…)</label><input name="url" placeholder="https://"></div><div class="field"><label>Screenshot or picture</label><input type="file" id="pim" accept="image/*"></div>' +
    '<div class="field"><label>Where are you? Explain your stage</label><textarea name="stage" rows="3"></textarea></div><div class="field"><label>Project timeline (optional)</label><div id="ms"></div><button type="button" class="btn ghost sm" id="addm">+ Add milestone</button></div>' +
    '<label class="small" style="display:block;margin:12px 0"><input type="checkbox" name="complete" style="width:auto"> This build is complete</label><div class="err" id="er"></div><div class="row"><button class="btn">Save update</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  const addM = () => { const d = document.createElement('div'); d.className = 'row'; d.style.cssText = 'margin-bottom:6px;flex-wrap:nowrap'; d.innerHTML = '<input placeholder="Milestone" data-t maxlength="160"><input type="date" data-d style="width:auto"><button type="button" class="btn ghost sm" data-r>✕</button>'; d.querySelector('[data-r]').onclick = () => d.remove(); $('#ms', m).appendChild(d); }; addM();
  $('#addm', m).onclick = addM; $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#pf2', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); const b = e.target.querySelector('.btn.btn:not(.ghost), button:not(.ghost):not([type=button])'); const btn = $('#pf2 button:not(.ghost)', m); btn.disabled = true;
    try { let image = 0; const file = $('#pim', m).files[0]; if (file) { const r = await api('tasks/' + t.id + '/files', { method: 'POST', data: await fileB64(file) }); image = r.id; }
      const milestones = [...m.querySelectorAll('#ms .row')].map(r => ({ title: r.querySelector('[data-t]').value.trim(), deadline: r.querySelector('[data-d]').value || null })).filter(x => x.title);
      await api('tasks/' + t.id + '/updates', { method: 'POST', data: { kind: 'progress', data: { url: f.url, stage: f.stage, image, milestones }, complete: !!f.complete } }); m.close(); toast('Progress saved'); render(); location.hash.startsWith('#/tasks/') && taskDetail(t.id); }
    catch (er) { $('#er', m).textContent = er.message; btn.disabled = false; } };
}
function opsDialog(t) {
  const m = modal('<div class="row"><h2 class="grow">Operations log</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="of" style="margin-top:10px"><div class="field"><label>What was done?</label><textarea name="text" rows="7" required placeholder="Type your operations update…"></textarea></div><label class="small" style="display:block;margin:6px 0 12px"><input type="checkbox" name="complete" style="width:auto"> Mark this task as done</label><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#of', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); try { await api('tasks/' + t.id + '/updates', { method: 'POST', data: { kind: 'operations', data: { text: f.text }, complete: !!f.complete } }); m.close(); toast('Saved'); render(); location.hash.startsWith('#/tasks/') && taskDetail(t.id); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function reportDialog(t) {
  let pr; try { pr = (await api('projects')).projects.filter(p => p.status === 'active'); } catch (e) { return toast(e.message); }
  if (!pr.length) return toast('You have not been added to a project yet. Ask your manager to add you to one.');
  const m = modal('<div class="row"><h2 class="grow">Enter field report</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="frf" style="margin-top:10px"><div class="grid2"><div class="field"><label>Project</label><select name="project_id">' + opts(pr.map(p => [p.id, p.name + (p.client ? ' — ' + p.client : '')]), t.project_id) + '</select></div><div class="field"><label>Report type</label><select name="type">' + opts(Object.entries(REPORT_FORMS).map(([k, v]) => [k, v[0]]), 'ut') + '</select></div></div><div id="ff"></div><label class="small" style="display:block;margin:12px 0"><input type="checkbox" name="complete" style="width:auto"> Mark this field task as done</label><div class="err" id="er"></div><div class="row"><button class="btn">Submit report</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  const drawF = () => { const ty = $('[name=type]', m).value; $('#ff', m).innerHTML = REPORT_FORMS[ty][1].map(([k, l, kind]) => kind === 'check' ? '<label class="small" style="display:block;margin:6px 0"><input type="checkbox" data-k="' + k + '" style="width:auto"> ' + l + '</label>' : '<div class="field"><label>' + l + '</label>' + (kind === 'textarea' ? '<textarea data-k="' + k + '" rows="3"></textarea>' : kind.startsWith('select:') ? '<select data-k="' + k + '">' + opts(kind.slice(7).split(',').map(x => [x, x]), '', '—') + '</select>' : '<input type="' + kind + '" data-k="' + k + '"' + (kind === 'number' ? ' step="any"' : '') + '>') + '</div>').join(''); };
  drawF(); $('[name=type]', m).onchange = drawF; $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#frf', m).onsubmit = async e => { e.preventDefault(); const data = {}; m.querySelectorAll('#ff [data-k]').forEach(i => { data[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value; });
    try { await api('tasks/' + t.id + '/report', { method: 'POST', data: { project_id: +$('[name=project_id]', m).value, type: $('[name=type]', m).value, data, complete: $('[name=complete]', m).checked } }); m.close(); toast('Report saved to the project'); render(); location.hash.startsWith('#/tasks/') && taskDetail(t.id); } catch (er) { $('#er', m).textContent = er.message; } };
}
function reportView(r) {
  const [label, fields] = REPORT_FORMS[r.type] || [r.type, []]; const m = modal('<div class="row"><h2 class="grow">' + esc(label) + '</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">' + esc((r.user_name || '') + ' · ' + new Date(r.created_at.replace(' ', 'T')).toLocaleString()) + '</div>' +
    fields.map(([k, l, kind]) => { const v = r.data[k]; if (v === undefined || v === '' || v === false) return ''; return '<div style="margin:8px 0"><div class="muted small">' + esc(l) + '</div><div style="white-space:pre-wrap">' + (v === true ? '✓ Yes' : esc(v)) + '</div></div>'; }).join('') + '<div class="row" style="margin-top:12px"><button class="btn ghost" id="pr">Print</button><button class="btn ghost" id="cx2">Close</button></div>');
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close(); $('#pr', m).onclick = () => window.print();
}
function updateHtml(x, t) {
  let d = {}; try { d = JSON.parse(x.data || '{}'); } catch {} const who = '<span class="muted small">' + esc(x.user_name || '') + ' · ' + ago(x.created_at) + '</span>', fl = id => (t.files.find(f => f.id == id) || {});
  let body = '';
  if (x.kind === 'research') body = '<b>Research' + (d.research_type ? ' · ' + esc(d.research_type) : '') + '</b>' + [d.contact_name, d.contact_company, d.contact_email, d.contact_phone].filter(Boolean).map(esc).join(' · ').replace(/^/, '<div class="small">').concat('</div>') + (d.offering ? '<div class="small">Offering: ' + esc(d.offering) + '</div>' : '') + (d.notes ? '<div style="white-space:pre-wrap;margin-top:4px">' + esc(d.notes) + '</div>' : '') + ((d.files || []).length ? '<div class="small muted">Files: ' + d.files.map(i => esc(fl(i).name || 'file')).join(', ') + '</div>' : '');
  else if (x.kind === 'progress') body = '<b>Progress</b>' + (d.stage ? '<div style="white-space:pre-wrap">' + esc(d.stage) + '</div>' : '') + (safeUrl(d.url) ? '<div class="small"><a href="' + esc(safeUrl(d.url)) + '" target="_blank" rel="noopener">' + esc(d.url) + '</a></div>' : '') + (+d.image ? '<a href="api/tasks/' + t.id + '/files/' + (+d.image) + '" target="_blank"><img src="api/tasks/' + t.id + '/files/' + (+d.image) + '?view=1" alt="" style="max-width:100%;max-height:220px;border-radius:10px;margin-top:6px"></a>' : '') + ((d.milestones || []).length ? '<div class="small" style="margin-top:6px">' + d.milestones.map(mm => '• ' + esc(mm.title) + (mm.deadline ? ' — due ' + fmtDate(mm.deadline) : '')).join('<br>') + '</div>' : '');
  else if (x.kind === 'operations') body = '<b>Operations</b><div style="white-space:pre-wrap">' + esc(d.text) + '</div>';
  else if (x.kind === 'report') body = '<b>Field report filed</b> · ' + esc((REPORT_FORMS[d.type] || [d.type])[0]) + ' <a href="#" data-rep="' + d.report_id + '" data-pr="' + d.project_id + '">View report</a>';
  else if (x.kind === 'defer') body = '<b>Deferred</b> ' + (d.from ? 'from ' + fmtDate(d.from) + ' ' : '') + 'to ' + fmtDate(d.to) + (d.reason ? ' — ' + esc(d.reason) : '');
  else if (x.kind === 'transfer') body = '<b>Transferred</b>' + (d.from ? ' from ' + esc(d.from) : '') + ' to ' + esc(d.to || '') + (d.note ? ' — ' + esc(d.note) : '');
  else if (x.kind === 'transfer_offer') body = '<b>Offered</b>' + (d.from ? ' by ' + esc(d.from) : '') + ' to ' + esc(d.to || '') + ' (waiting for an answer)' + (d.note ? ' — ' + esc(d.note) : '');
  else if (x.kind === 'share') body = '<b>Shared</b> with ' + esc((d.with || []).join(', ')) + (d.note ? '<div style="white-space:pre-wrap;margin-top:4px">' + esc(d.note) + '</div>' : '') + ((d.links || []).length ? '<div class="small" style="margin-top:4px">' + d.links.map(l => '🔗 <a href="' + esc(l) + '" target="_blank" rel="noopener noreferrer">' + esc(l) + '</a>').join('<br>') + '</div>' : '') + ((d.files || []).length ? '<div class="small muted" style="margin-top:4px">📎 ' + d.files.length + ' file' + (d.files.length === 1 ? '' : 's') + ' attached (see Files on this task)</div>' : '');
  else body = esc(d.text || '');
  return '<div style="padding:9px 0;border-top:1px solid var(--line)">' + body + '<div>' + who + '</div></div>';
}
function taskExtras(m, t) {
  const ro = readOnly(), isA = t.assignee_id == S.user.id, canAct = !ro && (isA || t.can_edit);
  const act = [];
  if (canAct && TACT[t.task_type]) act.push('<button class="btn sm" id="tact">' + TACT[t.task_type] + '</button>');
  if (canAct && t.status !== 'done') act.push('<button class="btn ghost sm" id="tdef">Defer</button>');
  if (!ro) act.push('<button class="btn ghost sm" id="tshr">Share</button>', filePick('tfl', '📎 Add file'));
  const when = t.starts_at ? '<div class="small" style="margin:6px 0"><b>Meeting:</b> ' + esc(new Date(t.starts_at.replace(' ', 'T')).toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })) + (t.ends_at ? ' – ' + esc(new Date(t.ends_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })) : '') + '</div>' : '';
  const html = when + (t.task_type !== 'general' ? '' : '') + '<div class="row" style="margin:10px 0;flex-wrap:wrap">' + act.join('') + '</div>' +
    (t.shares.length ? '<div class="small" style="margin:6px 0">' + (t.task_type === 'meeting' ? 'Invited: ' : 'Shared with: ') + t.shares.map(s => '<span class="chip">' + esc(s.name) + (t.can_edit || s.id == S.user.id ? ' <a href="#" data-us="' + s.id + '" style="color:var(--bad)">✕</a>' : '') + '</span>').join(' ') + '</div>' : '') +
    (t.files.length ? '<h3 style="margin-top:12px">Files</h3><div class="list">' + t.files.map(f => '<div class="item"><div class="grow"><a href="api/tasks/' + t.id + '/files/' + f.id + '" target="_blank" rel="noopener">📎 ' + esc(f.name) + '</a><div class="muted small">' + fsize(f.size) + ' · ' + esc(f.user_name || '') + ' · ' + ago(f.created_at) + '</div></div>' + (t.can_edit || f.user_id == S.user.id ? '<button class="btn ghost sm" data-df="' + f.id + '">✕</button>' : '') + '</div>').join('') + '</div>' : '') +
    (t.updates.length ? '<h3 style="margin-top:12px">Activity</h3><div id="upl">' + t.updates.map(x => updateHtml(x, t)).join('') + '</div>' : '');
  const anchor = [...m.querySelectorAll('h3')].find(h => h.textContent === 'Comments'); const box = document.createElement('div'); box.innerHTML = html; anchor.parentNode.insertBefore(box, anchor);
  const T = $('#tact', m); if (T) T.onclick = () => { m.close(); ({ research: researchDialog, build: progressDialog, operations: opsDialog, field: reportDialog })[t.task_type](t); };
  const D = $('#tdef', m); if (D) D.onclick = () => { m.close(); deferTask(t); };
  const S2 = $('#tshr', m); if (S2) S2.onclick = () => { m.close(); shareTask(t.id); };
  const F = $('#tfl', m); if (F) F.onchange = async e => { try { for (const f of e.target.files) await api('tasks/' + t.id + '/files', { method: 'POST', data: await fileB64(f) }); toast('File added'); m.close(); taskDetail(t.id); } catch (er) { toast(er.message); } e.target.value = ''; };
  m.querySelectorAll('[data-df]').forEach(b => b.onclick = async () => { if (!confirm('Remove this file?')) return; await api('tasks/' + t.id + '/files/' + b.dataset.df, { method: 'DELETE' }); m.close(); taskDetail(t.id); });
  m.querySelectorAll('[data-us]').forEach(a => a.onclick = async e => { e.preventDefault(); await api('tasks/' + t.id + '/share/' + a.dataset.us, { method: 'DELETE' }); m.close(); taskDetail(t.id); });
  m.querySelectorAll('[data-rep]').forEach(a => a.onclick = async e => { e.preventDefault(); try { const rs = await api('projects/' + a.dataset.pr + '/reports'); const r = rs.find(x => x.id == a.dataset.rep); r ? reportView(r) : toast('Report not found'); } catch (er) { toast(er.message); } });
}

/* ---------- Research tab ---------- */
let RS = { q: '' };
views.research = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const rows = await api('research');
  const draw = () => { const q = RS.q.toLowerCase(), list = rows.filter(r => !q || (r.title + ' ' + JSON.stringify(r.last || {})).toLowerCase().includes(q));
    $('#rl').innerHTML = list.length ? list.map(r => '<a class="item" href="#/tasks/' + r.id + '" style="color:inherit"><div class="grow"><b>' + esc(r.title) + '</b><div class="muted small">' + esc([r.last && r.last.research_type, r.last && r.last.contact_company, r.last && r.last.contact_name, r.assignee_name, r.file_count ? r.file_count + ' file' + (r.file_count == 1 ? '' : 's') : '', 'Updated ' + fmtDate(r.updated_at)].filter(Boolean).join(' · ')) + '</div></div><span class="tag ' + (r.status === 'done' ? 'done' : 'in_progress') + '">' + (r.status === 'done' ? 'Completed' : 'In progress') + '</span></a>').join('') : '<div class="empty">' + (rows.length ? 'Nothing found.' : 'No research yet. Click “New research”.') + '</div>'; };
  v.innerHTML = '<div class="top"><div><h1>Research</h1><div class="muted">Notes, imported documents and contacts — every piece of research is also a task</div></div>' + (readOnly() ? '' : '<button class="btn right" id="nr">+ New research</button>') + '</div><div class="row" style="margin-bottom:14px"><input id="rq" placeholder="Search research…" style="max-width:300px" value="' + esc(RS.q) + '"></div><div class="card"><div class="list" id="rl"></div></div>';
  draw(); $('#rq').oninput = e => { RS.q = e.target.value.trim(); draw(); }; const nr = $('#nr'); if (nr) nr.onclick = () => researchDialog(null);
};

/* ---------- Projects tab (field projects and their reports) ---------- */
views.projects = async () => {
  const parts = location.hash.split('/'); if (parts[2]) return projectPage(+parts[2]);
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; await loadRefs(); const d = await api('projects');
  v.innerHTML = '<div class="top"><div><h1>Projects</h1><div class="muted">Field projects. Reports from field tasks are stored under the project the person was added to.</div></div>' + (d.can_edit ? '<button class="btn right" id="np2">+ New project</button>' : '') + '</div><div class="card"><div class="list">' +
    (d.projects.length ? d.projects.map(p => '<a class="item" href="#/projects/' + p.id + '" style="color:inherit"><div class="grow"><b>' + esc(p.name) + '</b><div class="muted small">' + esc([p.client, p.members.length + ' member' + (p.members.length == 1 ? '' : 's'), p.report_count + ' report' + (p.report_count == 1 ? '' : 's')].filter(Boolean).join(' · ')) + '</div></div>' + (p.status === 'closed' ? '<span class="tag">Closed</span>' : '') + '</a>').join('') : '<div class="empty">' + (d.can_edit ? 'No projects yet. Create one and add the field team.' : 'You have not been added to any project yet.') + '</div>') + '</div></div>';
  const b = $('#np2'); if (b) b.onclick = () => projectForm(null);
};
function projectForm(p) {
  const sel = new Set((p ? p.members : []).map(x => +x.id));
  const m = modal('<h2>' + (p ? 'Edit project' : 'New project') + '</h2><form id="pj" style="margin-top:10px"><div class="grid2"><div class="field"><label>Project name</label><input name="name" required maxlength="160" value="' + esc(p ? p.name : '') + '"></div><div class="field"><label>Client</label><input name="client" maxlength="160" value="' + esc(p ? p.client || '' : '') + '"></div></div>' + (p ? '<div class="field"><label>Status</label><select name="status">' + opts([['active', 'Active'], ['closed', 'Closed']], p.status) + '</select></div>' : '') +
    '<div class="field"><label>Team members (they can file reports to this project)</label><div id="pm" style="display:flex;flex-wrap:wrap;gap:6px 16px">' + S.users.filter(u => u.active == 1).map(u => '<label class="small"><input type="checkbox" value="' + u.id + '" style="width:auto" ' + (sel.has(+u.id) ? 'checked' : '') + '> ' + esc(u.name) + '</label>').join('') + '</div></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button>' + (p ? '<button class="btn ghost right" type="button" id="dl" style="color:var(--bad)">Delete</button>' : '') + '</div></form>');
  $('#cx', m).onclick = () => m.close(); const dl = $('#dl', m); if (dl) dl.onclick = async () => { if (!confirm('Delete this project and all its reports?')) return; await api('projects/' + p.id, { method: 'DELETE' }); m.close(); location.hash = '#/projects'; render(); };
  $('#pj', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.members = [...m.querySelectorAll('#pm input:checked')].map(i => +i.value); try { await api(p ? 'projects/' + p.id : 'projects', { method: p ? 'PATCH' : 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function projectPage(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const d = await api('projects'); const p = d.projects.find(x => x.id == id);
  if (!p) { v.innerHTML = '<a href="#/projects" class="small">‹ Projects</a><div class="empty">Project not found.</div>'; return; }
  const rs = await api('projects/' + id + '/reports');
  v.innerHTML = '<div style="margin-bottom:6px"><a href="#/projects" class="small">‹ Projects</a></div><div class="card"><div class="row"><div class="grow"><h1 style="margin-bottom:4px">' + esc(p.name) + '</h1><div class="muted small">' + esc(p.client || '') + (p.status === 'closed' ? ' · Closed' : '') + '</div></div>' + (d.can_edit ? '<button class="btn ghost sm" id="ep">Edit</button>' : '') + '</div><div class="small" style="margin-top:10px"><span class="muted">Team:</span> ' + (p.members.map(x => esc(x.name)).join(', ') || '—') + '</div></div>' +
    '<div class="card" style="margin-top:14px"><h2 style="margin-bottom:8px">Reports</h2><div class="list">' + (rs.length ? rs.map((r, i) => '<div class="item"><div class="grow"><b>' + esc((REPORT_FORMS[r.type] || [r.type])[0]) + '</b><div class="muted small">' + esc((r.user_name || '') + ' · ' + new Date(r.created_at.replace(' ', 'T')).toLocaleString()) + '</div></div><button class="btn ghost sm" data-rv="' + i + '">View</button></div>').join('') : '<div class="empty">No reports filed yet.</div>') + '</div></div>';
  const ep = $('#ep'); if (ep) ep.onclick = () => projectForm(p); v.querySelectorAll('[data-rv]').forEach(b => b.onclick = () => reportView(rs[+b.dataset.rv]));
}

/* ---------- Email -> task ---------- */
async function mailToTask() {
  const msg = M.msg; if (!msg) return; await loadRefs();
  const us = S.users.filter(u => u.active == 1 && u.role !== 'founder');
  const m = modal('<div class="row"><h2 class="grow">Add email to tasks</h2><button class="btn ghost sm" id="cx">✕</button></div><p class="muted small" style="margin:4px 0 10px">' + esc(msg.subject || '(no subject)') + (msg.attachments.length ? ' · ' + msg.attachments.length + ' attachment' + (msg.attachments.length > 1 ? 's' : '') + ' will be copied to the task' : '') + '</p><form id="mt">' +
    '<div class="field"><label>Assign to</label><select name="assignee_id">' + opts(us.map(u => [u.id, u.name + (u.department ? ' — ' + u.department : '')]), S.user.id) + '</select></div><div class="grid2"><div class="field"><label>Type</label><select name="task_type">' + opts([['rfq', 'RFQ'], ['general', 'General task']], 'rfq') + '</select></div><div class="field"><label>Due date</label><input type="date" name="due_date"></div></div>' +
    '<div class="field"><label>Priority</label><select name="priority">' + opts(Object.entries(PRI).reverse(), 'high') + '</select></div><div class="field"><label>Note for the assignee (optional)</label><textarea name="note" rows="2"></textarea></div><div class="err" id="er"></div><div class="row"><button class="btn">Create task</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#mt', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.folder = M.folder; f.uid = msg.uid; f.assignee_id = +f.assignee_id; const b = e.target.querySelector('.btn'); b.disabled = true;
    try { const r = await mapi('mail/to-task', { method: 'POST', data: f }); m.close(); toast('Added to tasks' + (r.attachments ? ' with ' + r.attachments + ' file' + (r.attachments > 1 ? 's' : '') : '') + (r.skipped ? ' (' + r.skipped + ' too large to copy)' : '')); const btn = $('#mtask'); if (btn) { btn.textContent = '✓ In tasks'; btn.disabled = true; } }
    catch (er) { $('#er', m).textContent = er.message; b.disabled = false; } };
}

/* ---------- Finance: commercials, proformas, invoices, receipts ---------- */
const FTYPE = { commercial: 'Commercial', proforma: 'Proforma invoice', invoice: 'Invoice', receipt: 'Receipt', requisition: 'Requisition' };
const FSTAT = { draft: 'Draft', sent: 'Sent', paid: 'Paid', void: 'Void' };
const CUR_SIGN = { NGN: '₦', USD: '$', GBP: '£', EUR: '€' };
const fmoney = (n, cur, sym) => { n = Number(n || 0); const s = CUR_SIGN[String(cur || '').toUpperCase()] || sym; const body = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); return (n < 0 ? '−' : '') + (s ? s : (cur ? cur + ' ' : '')) + body; };
Object.assign(window.OfficeExt, { fmoney, fmtDate, FTYPE, loadRefs, fileObj: fileB64 }); // lets assets/js/finance.js draw the invoice and commercial templates
const fstag = s => '<span class="tag ' + (s === 'paid' ? 'done' : s === 'sent' ? 'in_progress' : s === 'void' ? 'blocked' : '') + '">' + FSTAT[s] + '</span>';
let FF = { type: '', status: '', q: '', tab: 'docs' };
views.finance = async () => {
  const parts = location.hash.split('/'); if (parts[2] && /^\d+$/.test(parts[2])) { return finDocPage(+parts[2]); }
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  let sm; try { sm = await api('finance/summary'); } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
  const can = S.user.finance, money = o => Object.keys(o).length ? Object.entries(o).map(([c, n]) => fmoney(n, c)).join(' · ') : '—';
  v.innerHTML = '<div class="top"><div><h1>Finance</h1><div class="muted">Commercials, proformas, invoices and receipts for RFQs and purchase orders</div></div>' + (can ? '<span class="right row"><button class="btn ghost" id="fset">Company details</button><button class="btn" id="fnew">+ New document</button></span>' : '') + '</div>' +
    '<div class="cards" style="margin-bottom:14px"><div class="card"><div class="muted small">Outstanding invoices</div><h2>' + esc(money(sm.outstanding)) + '</h2></div><div class="card"><div class="muted small">Received this month</div><h2>' + esc(money(sm.paid_month)) + '</h2></div><div class="card"><div class="muted small">Invoices awaiting payment</div><h2>' + sm.unpaid_invoices + '</h2></div><div class="card"><div class="muted small">Documents</div><div class="small">' + Object.entries(sm.counts).map(([k, n]) => n + ' ' + FTYPE[k].toLowerCase() + (n == 1 ? '' : 's')).join(' · ') + '</div></div></div>' +
    '<div class="chips fin-tabs" id="ftabs" role="tablist" style="margin-bottom:10px">' + [['', 'All documents'], ['commercial', 'Commercials'], ['proforma', 'Proformas'], ['invoice', 'Invoices'], ['receipt', 'Receipts'], ['requisition', 'Requisitions']].map(([k, l]) => '<button type="button" role="tab" class="chip' + (FF.type === k ? ' on' : '') + '" data-ft="' + k + '">' + l + '</button>').join('') + '</div><div class="row" style="margin-bottom:12px"><input id="fq" placeholder="Search number, client, PO, RFQ…" style="max-width:300px" value="' + esc(FF.q) + '"><select id="fs" style="width:auto">' + opts(Object.entries(FSTAT), FF.status, 'All statuses') + '</select></div><div class="card"><div class="tablewrap"><table><thead><tr><th>Number</th><th>Client</th><th>PO / RFQ</th><th>Date</th><th>Status</th><th style="text-align:right">Total</th></tr></thead><tbody id="fr"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody></table></div></div>';
  { const fk = document.createElement('div'); fk.className = 'chips fin-tabs'; fk.id = 'fscopes'; fk.style.marginBottom = '10px'; fk.innerHTML = [['', 'All kinds'], ['service', 'Services'], ['product', 'Products'], ['both', 'Products & services']].map(([k, l]) => '<button type="button" class="chip' + ((FF.scope || '') === k ? ' on' : '') + '" data-fk="' + k + '">' + l + '</button>').join(''); $('#ftabs').after(fk); }
  const load = async () => { const q = []; if (FF.q) q.push('q=' + encodeURIComponent(FF.q)); if (FF.type) q.push('type=' + FF.type); if (FF.status) q.push('status=' + FF.status); if (FF.scope) q.push('scope=' + FF.scope); const d = await api('finance/docs' + (q.length ? '?' + q.join('&') : ''));
    $('#fr').innerHTML = d.docs.length ? d.docs.map(x => '<tr style="cursor:pointer" data-d="' + x.id + '"><td><b>' + esc(x.number) + '</b><div class="muted small">' + FTYPE[x.doc_type] + (x.scope ? ' · <span class="tag">' + ({ product: 'Products', service: 'Services', both: 'Products & services' })[x.scope] + '</span>' : '') + '</div></td><td>' + esc(x.client_name) + '</td><td class="small">' + esc([x.po_number && 'PO ' + x.po_number, x.rfq_ref && 'RFQ ' + x.rfq_ref].filter(Boolean).join(' · ')) + '</td><td>' + fmtDate(x.issue_date) + '</td><td>' + fstag(x.status) + '</td><td style="text-align:right">' + fmoney(x.total, x.currency, x.currency_symbol) + '</td></tr>').join('') : '<tr><td colspan="6" class="empty">No documents found.</td></tr>';
    $('#fr').querySelectorAll('[data-d]').forEach(r => r.onclick = () => location.hash = '#/finance/' + r.dataset.d); };
  load(); $('#fscopes').querySelectorAll('[data-fk]').forEach(b => b.onclick = () => { FF.scope = b.dataset.fk; $('#fscopes').querySelectorAll('[data-fk]').forEach(x => x.classList.toggle('on', x === b)); load(); }); let tm; $('#fq').oninput = e => { FF.q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(load, 300); }; $('#ftabs').querySelectorAll('[data-ft]').forEach(b => b.onclick = () => { FF.type = b.dataset.ft; $('#ftabs').querySelectorAll('[data-ft]').forEach(x => x.classList.toggle('on', x === b)); load(); }); $('#fs').onchange = e => { FF.status = e.target.value; load(); };
  if (can) { $('#fnew').onclick = () => finForm(null, {}); $('#fset').onclick = finSettings; }
};
async function finSettings() {
  if (window.OfficeFin) return window.OfficeFin.settings();
  const { settings: s } = await api('finance/settings'); const F = (n, l, t) => '<div class="field"><label>' + l + '</label>' + (t === 'ta' ? '<textarea name="' + n + '" rows="3">' + esc(s[n] || '') + '</textarea>' : '<input name="' + n + '" value="' + esc(s[n] || '') + '">') + '</div>';
  const m = modal('<h2>Company details on documents</h2><form id="fsf" style="margin-top:10px"><div class="grid2">' + F('company_name', 'Company name') + F('tax_id', 'Tax / registration number') + '</div>' + F('address', 'Address', 'ta') + '<div class="grid2">' + F('email', 'Email') + F('phone', 'Phone') + '</div><div class="grid2">' + F('currency', 'Default currency (e.g. USD, NGN)') + F('tax_label', 'Tax name (e.g. VAT)') + '</div><div class="grid2">' + F('tax_rate', 'Default tax rate (%)') + '<div></div></div>' + F('bank_details', 'Bank / payment details', 'ta') + F('terms', 'Default terms and conditions', 'ta') + F('footer', 'Footer line') + '<div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close(); $('#fsf', m).onsubmit = async e => { e.preventDefault(); try { await api('finance/settings', { method: 'PATCH', data: Object.fromEntries(new FormData(e.target)) }); m.close(); toast('Saved'); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function finForm(d, pre) {
  if (window.OfficeFin) return window.OfficeFin.form(d, pre);
  await loadRefs(); let st = {}; try { st = (await api('finance/settings')).settings; } catch {} let cat = []; try { cat = (await api('catalog')).items.filter(i => i.published == 1); } catch {}
  const x = d || {}, cur = x.currency || st.currency || 'USD';
  const m = modal('<div class="row"><h2 class="grow">' + (d ? 'Edit ' + FTYPE[d.doc_type].toLowerCase() + ' ' + esc(d.number) : 'New document') + '</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="ff" style="margin-top:10px">' +
    (d ? '' : '<div class="grid2"><div class="field"><label>Document</label><select name="doc_type">' + opts([['commercial', 'Commercial (quotation)'], ['proforma', 'Proforma invoice'], ['invoice', 'Invoice']], pre.doc_type || 'commercial') + '</select></div><div class="field"><label>Link to RFQ task</label><input name="task_id" type="number" value="' + esc(pre.task_id || '') + '" placeholder="Task number (optional)"></div></div>') +
    '<div class="grid2"><div class="field"><label>Client</label><input name="client_name" required maxlength="190" list="fcl" value="' + esc(x.client_name || pre.client_name || '') + '"><datalist id="fcl">' + S.companies.map(c => '<option value="' + esc(c.name) + '">').join('') + '</datalist></div><div class="field"><label>Client email</label><input name="client_email" type="email" value="' + esc(x.client_email || '') + '"></div></div><div class="field"><label>Client address</label><textarea name="client_address" rows="2">' + esc(x.client_address || '') + '</textarea></div>' +
    '<div class="grid2"><div class="field"><label>PO number</label><input name="po_number" maxlength="80" value="' + esc(x.po_number || '') + '"></div><div class="field"><label>RFQ reference</label><input name="rfq_ref" maxlength="80" value="' + esc(x.rfq_ref || pre.rfq_ref || '') + '"></div></div>' +
    '<div class="grid2"><div class="field"><label>Issue date</label><input type="date" name="issue_date" value="' + (x.issue_date || today()) + '"></div><div class="field"><label>' + ((d && d.doc_type === 'commercial') || (!d && (pre.doc_type || 'commercial') === 'commercial') ? 'Valid until' : 'Due date') + '</label><input type="date" name="due_date" value="' + (x.due_date || x.valid_until || '') + '"></div></div>' +
    '<h3 style="margin:12px 0 6px">Items</h3><div id="fi"></div><div class="row" style="margin:6px 0"><button type="button" class="btn ghost sm" id="addi">+ Add line</button>' + (cat.length ? '<select id="fcat" style="width:auto"><option value="">+ From products &amp; services…</option>' + cat.map(c => '<option value="' + c.id + '">' + esc(c.name + (c.price != null ? ' — ' + c.price : '')) + '</option>').join('') + '</select>' : '') + '</div>' +
    '<div class="grid2"><div class="field"><label>Currency</label><input name="currency" maxlength="5" value="' + esc(cur) + '"></div><div class="field"><label>Discount (amount)</label><input name="discount" type="number" min="0" step="0.01" value="' + (x.discount || 0) + '"></div></div><div class="grid2"><div class="field"><label>Tax name</label><input name="tax_label" value="' + esc(x.tax_label || st.tax_label || 'VAT') + '"></div><div class="field"><label>Tax rate (%)</label><input name="tax_rate" type="number" min="0" max="100" step="0.01" value="' + (x.tax_rate ?? st.tax_rate ?? 0) + '"></div></div>' +
    '<div id="ftot" class="small" style="text-align:right;margin:6px 0;font-weight:600"></div><div class="field"><label>Notes (shown on the document)</label><textarea name="notes" rows="2">' + esc(x.notes || '') + '</textarea></div><div class="field"><label>Terms and conditions</label><textarea name="terms" rows="3">' + esc(x.terms ?? st.terms ?? '') + '</textarea></div><div class="err" id="er"></div><div class="row"><button class="btn">' + (d ? 'Save changes' : 'Create') + '</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
  m.querySelector('.modal').style.cssText += ';max-width:96vw;width:820px';
  const row = (i) => { const r = document.createElement('div'); r.className = 'frow'; r.innerHTML = '<input data-k="description" placeholder="Description" value="' + esc(i.description || '') + '"><input data-k="qty" type="number" min="0" step="any" placeholder="Qty" value="' + (i.qty ?? 1) + '"><input data-k="unit" placeholder="Unit" value="' + esc(i.unit || '') + '"><input data-k="unit_price" type="number" min="0" step="any" placeholder="Unit price" value="' + (i.unit_price ?? '') + '"><button type="button" class="btn ghost sm" data-r>✕</button>'; r.querySelector('[data-r]').onclick = () => { r.remove(); tot(); }; r.querySelectorAll('input').forEach(n => n.oninput = tot); $('#fi', m).appendChild(r); };
  const items = () => [...m.querySelectorAll('#fi .frow')].map(r => ({ description: r.querySelector('[data-k=description]').value, qty: r.querySelector('[data-k=qty]').value, unit: r.querySelector('[data-k=unit]').value, unit_price: r.querySelector('[data-k=unit_price]').value }));
  const tot = () => { const f = $('#ff', m); const sub = items().reduce((n, i) => n + (+i.qty || 0) * (+i.unit_price || 0), 0), disc = Math.min(+f.discount.value || 0, sub), tax = (sub - disc) * (+f.tax_rate.value || 0) / 100; $('#ftot', m).textContent = 'Subtotal ' + fmoney(sub, f.currency.value) + ' · Tax ' + fmoney(tax, f.currency.value) + ' · Total ' + fmoney(sub - disc + tax, f.currency.value); };
  (d && d.items.length ? d.items : [{}]).forEach(row); tot(); $('#addi', m).onclick = () => row({}); m.querySelectorAll('[name=discount],[name=tax_rate],[name=currency]').forEach(n => n.oninput = tot);
  const fc = $('#fcat', m); if (fc) fc.onchange = () => { const c = cat.find(z => z.id == fc.value); if (c) { const rr = [...m.querySelectorAll('#fi .frow')]; if (rr.length === 1 && !rr[0].querySelector('[data-k=description]').value) rr[0].remove(); row({ description: c.name + (c.description ? ' — ' + c.description.slice(0, 120) : ''), qty: 1, unit: c.unit, unit_price: c.price }); tot(); } fc.value = ''; };
  $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
  $('#ff', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.items = items().filter(i => i.description.trim()); if (f.task_id === '') delete f.task_id; if (d) { const dd = f.due_date; delete f.due_date; if (d.doc_type === 'commercial') f.valid_until = dd; else f.due_date = dd; delete f.doc_type; } else if (f.doc_type === 'commercial') { f.valid_until = f.due_date; delete f.due_date; }
    try { const r = await api(d ? 'finance/doc/' + d.id : 'finance/doc', { method: d ? 'PATCH' : 'POST', data: f }); m.close(); toast(d ? 'Saved' : r.number + ' created'); location.hash = '#/finance/' + (d ? d.id : r.id); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}
function finDocHtml(d) {
  if (window.OfficeFin && d.doc_type !== 'receipt') return window.OfficeFin.html(d);
  const s = d.seller || {}, t = d.totals, c = d.currency, rc = d.doc_type === 'receipt', lbl = FTYPE[d.doc_type].toUpperCase();
  return '<div class="fdoc"><div class="fh"><div><div class="fbrand">' + esc(s.company_name || 'Aerosub') + '</div><div class="small" style="white-space:pre-line">' + esc(s.address || '') + '</div><div class="small">' + esc([s.email, s.phone].filter(Boolean).join(' · ')) + '</div>' + (s.tax_id ? '<div class="small">Tax no: ' + esc(s.tax_id) + '</div>' : '') + '</div><div style="text-align:right"><div class="ftitle">' + lbl + '</div><div><b>' + esc(d.number) + '</b></div>' + (d.status === 'void' ? '<div class="fvoid">VOID</div>' : '') + '</div></div>' +
    '<div class="fh" style="margin-top:18px"><div><div class="muted small">' + (rc ? 'Received from' : 'Bill to') + '</div><b>' + esc(d.client_name) + '</b><div class="small" style="white-space:pre-line">' + esc(d.client_address || '') + '</div><div class="small">' + esc(d.client_email || '') + '</div></div><div class="small" style="text-align:right"><div>Date: <b>' + fmtDate(d.issue_date) + '</b></div>' + (d.due_date ? '<div>Due: <b>' + fmtDate(d.due_date) + '</b></div>' : '') + (d.valid_until ? '<div>Valid until: <b>' + fmtDate(d.valid_until) + '</b></div>' : '') + (d.po_number ? '<div>PO: <b>' + esc(d.po_number) + '</b></div>' : '') + (d.rfq_ref ? '<div>RFQ: <b>' + esc(d.rfq_ref) + '</b></div>' : '') + (d.parent ? '<div>Ref: <b>' + esc(d.parent.number) + '</b></div>' : '') + (rc ? '<div>Method: <b>' + esc(d.pay_method || '—') + '</b>' + (d.pay_ref ? ' · ' + esc(d.pay_ref) : '') + '</div>' : '') + '</div></div>' +
    '<table class="ft"><thead><tr><th>#</th><th>Description</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Amount</th></tr></thead><tbody>' + d.items.map((i, n) => '<tr><td>' + (n + 1) + '</td><td>' + esc(i.description) + '</td><td style="text-align:right">' + Number(i.qty) + (i.unit ? ' ' + esc(i.unit) : '') + '</td><td style="text-align:right">' + fmoney(i.unit_price, c, d.currency_symbol) + '</td><td style="text-align:right">' + fmoney(i.qty * i.unit_price, c, d.currency_symbol) + '</td></tr>').join('') + '</tbody></table>' +
    '<table class="ftot">' + (rc ? '' : '<tr><td>Subtotal</td><td>' + fmoney(t.subtotal, c, d.currency_symbol) + '</td></tr>' + (t.discount ? '<tr><td>Discount</td><td>−' + fmoney(t.discount, c, d.currency_symbol) + '</td></tr>' : '') + (+d.tax_rate ? '<tr><td>' + esc(d.tax_label) + ' (' + Number(d.tax_rate) + '%)</td><td>' + fmoney(t.tax, c, d.currency_symbol) + '</td></tr>' : '')) + '<tr class="g"><td>' + (rc ? 'Amount received' : 'Total') + '</td><td>' + fmoney(rc ? d.amount_paid : t.total, c, d.currency_symbol) + '</td></tr>' + (d.doc_type === 'invoice' ? '<tr><td>Paid</td><td>' + fmoney(d.paid_total, c, d.currency_symbol) + '</td></tr><tr class="g"><td>Balance due</td><td>' + fmoney(d.balance, c, d.currency_symbol) + '</td></tr>' : '') + '</table>' +
    (window.OfficeFin ? '<div class="small" style="margin-top:10px"><b>Amount in words:</b> ' + esc(window.OfficeFin.amountWords(rc ? d.amount_paid : t.total, c)) + '.</div>' : '') + (d.notes ? '<div class="small" style="margin-top:14px;white-space:pre-wrap"><b>Notes</b><br>' + esc(d.notes) + '</div>' : '') + (!rc && s.bank_details && d.doc_type !== 'commercial' ? '<div class="small" style="margin-top:12px;white-space:pre-wrap"><b>Payment details</b><br>' + esc(s.bank_details) + '</div>' : '') + (d.terms && !rc ? '<div class="small muted" style="margin-top:12px;white-space:pre-wrap"><b>Terms</b><br>' + esc(d.terms) + '</div>' : '') + (s.footer ? '<div class="small muted" style="margin-top:18px;text-align:center">' + esc(s.footer) + '</div>' : '') + '</div>';
}
async function finDocPage(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let d; try { d = await api('finance/doc/' + id); } catch (e) { v.innerHTML = '<a href="#/finance" class="small">‹ Finance</a><div class="empty">' + esc(e.message) + '</div>'; return; }
  const can = d.can_edit, conv = { commercial: ['proforma', 'invoice'], proforma: ['invoice'] }[d.doc_type] || [];
  v.innerHTML = '<div style="margin-bottom:6px" class="row noprint"><a href="#/finance" class="small">‹ Finance</a>' + (d.task_id ? '<a href="#/tasks/' + d.task_id + '" class="small">· Related task: ' + esc(d.task_title || '#' + d.task_id) + '</a>' : '') + '</div><div class="row noprint" style="margin-bottom:12px;flex-wrap:wrap">' + fstag(d.status) + (d.children.length ? '<span class="small muted">Leads to: ' + d.children.map(c => '<a href="#/finance/' + c.id + '">' + esc(c.number) + '</a>').join(', ') + '</span>' : '') + '<span class="right row" style="flex-wrap:wrap"><button class="btn ghost sm" id="fpr">' + (['signed'].includes(d.approval_status) || ['sent', 'paid'].includes(d.status) ? 'Download PDF' : 'Print preview / PDF') + '</button>' + (d.doc_type !== 'receipt' ? '<a class="btn ghost sm" id="fxl" href="api/finance/doc/' + d.id + '/export" download>Export to Excel</a>' : '') + '' + (d.client_email && S.mail ? '<button class="btn ghost sm" id="fem">Email client</button>' : '') +
    ((d.can_correspond || can) && d.status !== 'void' && d.doc_type !== 'receipt' && d.status !== 'paid' ? '<button class="btn ghost sm" id="fed">Edit</button>' : '') + (can && d.status !== 'void' ? ((d.status === 'draft' && d.doc_type !== 'receipt' ? '<button class="btn ghost sm" id="fsn">Mark as sent</button>' : '') + conv.map(t => '<button class="btn ghost sm" data-cv="' + t + '">Make ' + FTYPE[t].toLowerCase() + '</button>').join('') + (d.doc_type === 'invoice' && d.status !== 'paid' ? '<button class="btn sm" id="fpay">Record payment</button>' : '') + '<button class="btn ghost sm" id="fvd" style="color:var(--bad)">Void</button>' + (d.status === 'draft' && d.doc_type !== 'receipt' ? '<button class="btn ghost sm" id="fdl" style="color:var(--bad)">Delete</button>' : '')) : '') + '</span></div>' + finDocHtml(d);
  $('#fpr').onclick = () => window.print();
  try { if (window.OfficeExt && window.OfficeExt.finDocHook) window.OfficeExt.finDocHook(d, v, id); } catch (e) { console.error(e); }
  const act = async (path, data, msg) => { try { await api('finance/doc/' + id + path, { method: 'POST', data }); toast(msg); render(); } catch (er) { toast(er.message); } };
  const ed = $('#fed'); if (ed) ed.onclick = () => finForm(d, {}); const sn = $('#fsn'); if (sn) sn.onclick = () => act('/status', { status: 'sent' }, 'Marked as sent');
  v.querySelectorAll('[data-cv]').forEach(b => b.onclick = async () => { try { const r = await api('finance/doc/' + id + '/convert', { method: 'POST', data: { to: b.dataset.cv } }); toast(r.number + ' created'); location.hash = '#/finance/' + r.id; } catch (er) { toast(er.message); } });
  const vd = $('#fvd'); if (vd) vd.onclick = () => { if (confirm('Void ' + d.number + '? It stays on record but no longer counts.')) act('/status', { status: 'void' }, 'Voided'); };
  const dl = $('#fdl'); if (dl) dl.onclick = async () => { if (!confirm('Delete this draft?')) return; await api('finance/doc/' + id, { method: 'DELETE' }); location.hash = '#/finance'; };
  const em = $('#fem'); if (em) em.onclick = () => emailTo(d.client_email);
  const py = $('#fpay'); if (py) py.onclick = () => { const m = modal('<h2>Record payment</h2><p class="muted small" style="margin:4px 0 10px">' + esc(d.number) + ' · balance ' + fmoney(d.balance, d.currency) + '. A receipt is created automatically.</p><form id="pf3"><div class="grid2"><div class="field"><label>Amount received</label><input name="amount" type="number" step="0.01" min="0.01" required value="' + d.balance + '"></div><div class="field"><label>Date</label><input type="date" name="date" value="' + today() + '"></div></div><div class="grid2"><div class="field"><label>Method</label><input name="method" placeholder="Bank transfer, cash…"></div><div class="field"><label>Reference</label><input name="reference"></div></div><div class="err" id="er"></div><div class="row"><button class="btn">Create receipt</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close(); $('#pf3', m).onsubmit = async e => { e.preventDefault(); try { const r = await api('finance/doc/' + id + '/pay', { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); toast(r.number + ' issued'); location.hash = '#/finance/' + r.id; } catch (er) { $('#er', m).textContent = er.message; } }; };
}
/* documents on an RFQ task */
async function taskFinance(m, t) {
  let docs = []; try { docs = (await api('finance/docs?task_id=' + t.id)).docs; } catch { return; }
  const can = S.user.finance; if (!docs.length && !(can && t.task_type === 'rfq')) return;
  const anchor = [...m.querySelectorAll('h3')].find(h => h.textContent === 'Comments'); if (!anchor) return; const box = document.createElement('div');
  box.innerHTML = '<h3 style="margin-top:12px">Finance documents</h3><div class="list">' + (docs.length ? docs.map(x => '<a class="item" href="#/finance/' + x.id + '" style="color:inherit"><div class="grow"><b>' + esc(x.number) + '</b><div class="muted small">' + FTYPE[x.doc_type] + ' · ' + fmoney(x.total, x.currency, x.currency_symbol) + '</div></div>' + fstag(x.status) + '</a>').join('') : '<div class="muted small">None yet.</div>') + '</div>' + (can ? '<div class="row" style="margin-top:8px"><button class="btn ghost sm" id="tfd">+ Commercial for this RFQ</button></div>' : '');
  anchor.parentNode.insertBefore(box, anchor); const b = $('#tfd', m); if (b) b.onclick = () => { m.close(); finForm(null, { doc_type: 'commercial', task_id: t.id, rfq_ref: t.title.replace(/^RFQ:\s*/i, '').slice(0, 80), client_name: t.company_name || '' }); };
}

/* ---------- Products & services ---------- */
let PQ = { q: '', kind: '' };
views.products = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const d = await api('catalog');
  const draw = () => { const q = PQ.q.toLowerCase(), list = d.items.filter(i => (!PQ.kind || i.kind === PQ.kind) && (!q || (i.name + ' ' + (i.category || '') + ' ' + (i.description || '') + ' ' + (i.sku || '')).toLowerCase().includes(q))); const cats = [...new Set(list.map(i => i.category || 'Other'))].sort();
    $('#pl').innerHTML = list.length ? cats.map(c => '<h3 style="margin:16px 0 8px">' + esc(c) + '</h3><div class="cards">' + list.filter(i => (i.category || 'Other') === c).map(i => '<div class="card pcard" ' + (d.can_edit ? 'data-p="' + i.id + '" style="cursor:pointer"' : '') + '>' + (i.image_url ? '<img src="' + esc(i.image_url) + '" alt="" loading="lazy" referrerpolicy="no-referrer" data-rm="1">' : '') + '<div class="row"><span class="tag">' + (i.kind === 'product' ? 'Product' : 'Service') + '</span>' + (i.published != 1 ? '<span class="tag">Hidden</span>' : '') + '</div><h3 style="margin:6px 0 2px">' + esc(i.name) + '</h3>' + (i.price != null ? '<div><b>' + fmoney(i.price, i.currency) + '</b>' + (i.unit ? ' <span class="muted small">/ ' + esc(i.unit) + '</span>' : '') + '</div>' : '<div class="muted small">Price on request</div>') + (i.description ? '<div class="small muted" style="margin-top:6px;white-space:pre-line">' + esc(i.description.slice(0, 220)) + '</div>' : '') + (i.sku ? '<div class="small muted" style="margin-top:6px">SKU ' + esc(i.sku) + '</div>' : '') + '</div>').join('') + '</div>').join('') : '<div class="empty">' + (d.items.length ? 'Nothing found.' : 'No products or services listed yet.') + '</div>';
    $('#pl').querySelectorAll('[data-p]').forEach(c => c.onclick = () => prodForm(d.items.find(i => i.id == c.dataset.p))); };
  v.innerHTML = '<div class="top"><div><h1>Products &amp; services</h1><div class="muted">What Aerosub offers — used when building quotes and invoices</div></div>' + (d.can_edit ? '<button class="btn right" id="npd">+ Add</button>' : '') + '</div><div class="row" style="margin-bottom:6px"><input id="pq" placeholder="Search…" style="max-width:280px" value="' + esc(PQ.q) + '"><span class="chip ' + (PQ.kind === '' ? 'on' : '') + '" data-k="">All</span><span class="chip ' + (PQ.kind === 'product' ? 'on' : '') + '" data-k="product">Products</span><span class="chip ' + (PQ.kind === 'service' ? 'on' : '') + '" data-k="service">Services</span></div><div id="pl"></div>';
  draw(); $('#pq').oninput = e => { PQ.q = e.target.value.trim(); draw(); }; v.querySelectorAll('[data-k]').forEach(c => c.onclick = () => { PQ.kind = c.dataset.k; v.querySelectorAll('[data-k]').forEach(x => x.classList.toggle('on', x === c)); draw(); }); const n = $('#npd'); if (n) n.onclick = () => prodForm(null);
};
function prodForm(p) {
  const x = p || { kind: 'service', published: 1, currency: 'USD' }; const m = modal('<h2>' + (p ? 'Edit' : 'Add') + ' product or service</h2><form id="pdf" style="margin-top:10px"><div class="grid2"><div class="field"><label>Type</label><select name="kind">' + opts([['service', 'Service'], ['product', 'Product']], x.kind) + '</select></div><div class="field"><label>Category</label><input name="category" maxlength="80" value="' + esc(x.category || '') + '"></div></div><div class="field"><label>Name</label><input name="name" required maxlength="190" value="' + esc(x.name || '') + '"></div><div class="field"><label>Description</label><textarea name="description" rows="4">' + esc(x.description || '') + '</textarea></div>' +
    '<div class="grid2"><div class="field"><label>Price (leave empty for “on request”)</label><input name="price" type="number" min="0" step="0.01" value="' + (x.price ?? '') + '"></div><div class="field"><label>Currency</label><input name="currency" maxlength="5" value="' + esc(x.currency) + '"></div></div><div class="grid2"><div class="field"><label>Unit (day, hour, item…)</label><input name="unit" maxlength="30" value="' + esc(x.unit || '') + '"></div><div class="field"><label>SKU / code</label><input name="sku" maxlength="60" value="' + esc(x.sku || '') + '"></div></div><div class="field"><label>Picture link (https://…, optional)</label><input name="image_url" value="' + esc(x.image_url || '') + '"></div><label class="small" style="display:block;margin:6px 0 12px"><input type="checkbox" name="published" style="width:auto" ' + (x.published == 1 ? 'checked' : '') + '> Visible to everyone</label><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button>' + (p ? '<button class="btn ghost right" type="button" id="dl" style="color:var(--bad)">Delete</button>' : '') + '</div></form>');
  $('#cx', m).onclick = () => m.close(); const dl = $('#dl', m); if (dl) dl.onclick = async () => { if (!confirm('Delete this item?')) return; await api('catalog/' + p.id, { method: 'DELETE' }); m.close(); render(); };
  $('#pdf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.published = !!f.published; try { await api(p ? 'catalog/' + p.id : 'catalog', { method: p ? 'PATCH' : 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}

/* ---------- News banners ---------- */
const LVL = { info: ['#e6f4ff', '#0a5a96'], success: ['#e7f7ec', '#14753a'], warning: ['#fff4dd', '#8a5a00'] };
const dismissed = () => { try { return JSON.parse(localStorage.getItem('dism:' + (S.user ? S.user.id : 0)) || '[]'); } catch { return []; } };
async function paintBanners() {
  const host = $('#banners'); if (!host || S.offline) return; let list; try { if (!S._nw || Date.now() - S._nw.t > 60000) S._nw = { t: Date.now(), l: await api('news') }; list = S._nw.l; } catch { return; } if (!$('#banners')) return;
  const gone = dismissed(); list = list.filter(b => !gone.includes(b.id)).slice(0, 3);
  host.innerHTML = list.map(b => '<div class="banner" style="background:' + LVL[b.level][0] + ';color:' + LVL[b.level][1] + '"><div class="grow"><b>' + esc(b.title) + '</b>' + (b.body ? ' <span>' + esc(b.body.slice(0, 200)) + '</span>' : '') + (b.link ? ' <a href="' + esc(b.link) + '"' + (/^https?:/i.test(b.link) ? ' target="_blank" rel="noopener"' : '') + ' style="color:inherit;text-decoration:underline">Open</a>' : '') + '</div><button class="btn ghost sm" data-bx="' + b.id + '" title="Dismiss" style="color:inherit">✕</button></div>').join('');
  host.querySelectorAll('[data-bx]').forEach(x => x.onclick = () => { const g = dismissed(); g.push(+x.dataset.bx); try { localStorage.setItem('dism:' + S.user.id, JSON.stringify(g.slice(-100))); } catch {} x.closest('.banner').remove(); });
}
views.news = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; await loadRefs(); const list = await api('news'); const canPost = ['super_admin', 'manager'].includes(S.user.role); const mine = canPost ? await api('news/manage') : [];
  v.innerHTML = '<div class="top"><div><h1>News</h1><div class="muted">Announcements from management</div></div>' + (canPost ? '<button class="btn right" id="nn">+ Post news</button>' : '') + '</div><div class="card"><div class="list">' + (list.length ? list.map(b => '<div class="item" style="align-items:flex-start"><div class="grow"><b>' + esc(b.title) + '</b><div class="muted small">' + fmtDate(b.created_at) + ' · ' + esc(b.department || 'Everyone') + '</div>' + (b.body ? '<div style="white-space:pre-wrap;margin-top:4px">' + esc(b.body) + '</div>' : '') + (b.link ? '<div class="small"><a href="' + esc(b.link) + '"' + (/^https?:/i.test(b.link) ? ' target="_blank" rel="noopener"' : '') + '>Open link</a></div>' : '') + '</div></div>').join('') : '<div class="empty">No announcements right now.</div>') + '</div></div>' +
    (canPost ? '<div class="card" style="margin-top:14px"><h2 style="margin-bottom:8px">Manage banners</h2><div class="list">' + (mine.length ? mine.map(b => '<div class="item"><div class="grow"><b>' + esc(b.title) + '</b><div class="muted small">' + esc((b.department || 'Everyone') + (b.starts_on ? ' · from ' + fmtDate(b.starts_on) : '') + (b.ends_on ? ' · until ' + fmtDate(b.ends_on) : '') + (b.active == 1 ? '' : ' · hidden')) + '</div></div><button class="btn ghost sm" data-nb="' + b.id + '">Edit</button></div>').join('') : '<div class="empty">Nothing posted yet.</div>') + '</div></div>' : '');
  const nn = $('#nn'); if (nn) nn.onclick = () => newsForm(null); v.querySelectorAll('[data-nb]').forEach(b => b.onclick = () => newsForm(mine.find(x => x.id == b.dataset.nb)));
};
function newsForm(b) {
  const su = S.user.role === 'super_admin', mine = (S.user.department_ids || []).map(Number), dl = (su ? [['', 'Everyone']] : []).concat(S.depts.filter(d => su || mine.includes(d.id)).map(d => [d.id, d.name])); const x = b || { level: 'info', active: 1 };
  const m = modal('<h2>' + (b ? 'Edit banner' : 'Post news') + '</h2><form id="nf2" style="margin-top:10px"><div class="field"><label>Headline</label><input name="title" required maxlength="200" value="' + esc(x.title || '') + '"></div><div class="field"><label>Message (optional)</label><textarea name="body" rows="3" maxlength="2000">' + esc(x.body || '') + '</textarea></div><div class="grid2"><div class="field"><label>Who sees it</label><select name="department_id">' + opts(dl, x.department_id ?? '') + '</select></div><div class="field"><label>Style</label><select name="level">' + opts([['info', 'Information'], ['success', 'Good news'], ['warning', 'Important']], x.level) + '</select></div></div><div class="grid2"><div class="field"><label>Show from</label><input type="date" name="starts_on" value="' + (x.starts_on || '') + '"></div><div class="field"><label>Show until</label><input type="date" name="ends_on" value="' + (x.ends_on || '') + '"></div></div><div class="field"><label>Link (optional)</label><input name="link" placeholder="https://… or #/schedule" value="' + esc(x.link || '') + '"></div>' +
    '<div class="row" style="gap:16px;margin-bottom:10px"><label class="small"><input type="checkbox" name="active" style="width:auto" ' + (x.active == 1 ? 'checked' : '') + '> Visible</label>' + (b ? '' : '<label class="small"><input type="checkbox" name="notify" style="width:auto"> Also send a notification</label>') + '</div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button>' + (b ? '<button class="btn ghost right" type="button" id="dl" style="color:var(--bad)">Delete</button>' : '') + '</div></form>');
  $('#cx', m).onclick = () => m.close(); const dl2 = $('#dl', m); if (dl2) dl2.onclick = async () => { if (!confirm('Delete this banner?')) return; await api('news/' + b.id, { method: 'DELETE' }); m.close(); render(); };
  $('#nf2', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.active = !!f.active; f.notify = !!f.notify; f.department_id = f.department_id || null; try { await api(b ? 'news/' + b.id : 'news', { method: b ? 'PATCH' : 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}

let CAL = new Date();
views.schedule = async () => {
  const v = $('#view'), y = CAL.getFullYear(), mo = CAL.getMonth();
  const first = new Date(y, mo, 1), start = new Date(y, mo, 1 - ((first.getDay() + 6) % 7)), end = new Date(start.getTime() + 42 * 864e5);
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  v.innerHTML = '<div class="top"><h1>Schedule</h1><div class="row right"><button class="btn ghost sm" id="pv">‹</button><b id="ml" style="min-width:130px;text-align:center"></b><button class="btn ghost sm" id="nx">›</button><button class="btn sm" id="ne">+ Event</button></div></div><div class="card"><div class="cal" id="cal"></div></div><div class="card" style="margin-top:16px"><h2>This month</h2><div class="list" id="ag"><div class="empty">Loading…</div></div></div>';
  $('#ml').textContent = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  $('#pv').onclick = () => { CAL = new Date(y, mo - 1, 1); render(); }; $('#nx').onclick = () => { CAL = new Date(y, mo + 1, 1); render(); };
  $('#ne').onclick = () => eventForm(today());
  const [evs, tasks, meets] = await Promise.all([api('events?from=' + iso(start) + ' 00:00:00&to=' + iso(end) + ' 23:59:59'), api('tasks?assignee=me'), api('tasks?meetings=1')]);
  const byDay = {};
  evs.forEach(e => { let d = new Date(e.starts_at.slice(0, 10) + 'T00:00:00'); const last = e.ends_at.slice(0, 10); for (let i = 0; i < 31; i++) { const k = iso(d); (byDay[k] = byDay[k] || []).push({ t: e.title, k: e.kind }); if (k >= last) break; d = new Date(d.getTime() + 864e5); } });
  meets.filter(t => t.starts_at && t.status !== 'done').forEach(t => { const k = t.starts_at.slice(0, 10); (byDay[k] = byDay[k] || []).push({ t: '● ' + t.starts_at.slice(11, 16) + ' ' + t.title, k: 'meeting' }); });
  tasks.filter(t => t.status !== 'done' && t.due_date).forEach(t => (byDay[t.due_date] = byDay[t.due_date] || []).push({ t: '▸ ' + t.title, k: 'deadline' }));
  let h = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(d => '<div class="h">' + d + '</div>').join('');
  for (let i = 0; i < 42; i++) { const d = new Date(start.getTime() + i * 864e5), k = iso(d); h += '<div class="d ' + (d.getMonth() !== mo ? 'out ' : '') + (k === today() ? 'today' : '') + '" data-d="' + k + '"><span class="n">' + d.getDate() + '</span>' + (byDay[k] || []).slice(0, 3).map(e => '<span class="e ' + e.k + '">' + esc(e.t) + '</span>').join('') + '</div>'; }
  $('#cal').innerHTML = h; $('#cal').querySelectorAll('.d').forEach(el => el.onclick = () => eventForm(el.dataset.d));
  const mine = evs.filter(e => e.starts_at.slice(0, 7) === iso(first).slice(0, 7));
  $('#ag').innerHTML = mine.length ? mine.map(e => '<div class="item" data-ev="' + e.id + '"><div style="min-width:54px;text-align:center"><b>' + new Date(e.starts_at.replace(' ', 'T')).getDate() + '</b><div class="muted small">' + new Date(e.starts_at.replace(' ', 'T')).toLocaleDateString(undefined, { month: 'short' }) + '</div></div><div class="grow"><b>' + esc(e.title) + '</b><div class="muted small">' + (e.all_day == 1 ? 'All day' : fmtTime(e.starts_at) + '–' + fmtTime(e.ends_at)) + (e.location ? ' · ' + esc(e.location) : '') + ' · ' + esc(e.creator_name) + (e.attendees.length ? ' · with ' + esc(e.attendees.map(a => a.name).join(', ')) : '') + '</div></div><span class="tag">' + esc(e.kind) + '</span>' +
    (e.creator_id == S.user.id || S.user.role === 'super_admin' ? '<button class="btn ghost sm" data-del="' + e.id + '">✕</button>' : '') + '</div>').join('') : '<div class="empty">No events this month.</div>';
  const mm = meets.filter(t => t.starts_at && t.starts_at.slice(0, 7) === iso(first).slice(0, 7) && t.status !== 'done').sort((a, b) => a.starts_at.localeCompare(b.starts_at)); if (mm.length) $('#ag').insertAdjacentHTML('beforeend', mm.map(t => '<a class="item" href="#/tasks/' + t.id + '" style="color:inherit"><div style="min-width:54px;text-align:center"><b>' + new Date(t.starts_at.replace(' ', 'T')).getDate() + '</b><div class="muted small">' + t.starts_at.slice(11, 16) + '</div></div><div class="grow"><b>' + esc(t.title) + '</b><div class="muted small">Meeting' + (+t.share_count ? ' · ' + t.share_count + ' invited' : '') + '</div></div><span class="tag">Meeting</span></a>').join(''));
  $('#ag').querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (confirm('Delete this event?')) { await api('events/' + b.dataset.del, { method: 'DELETE' }); render(); } });
};
function eventForm(date) {
  const us = S.users.filter(u => u.active == 1 && u.id != S.user.id);
  const m = modal('<h2>New event</h2><form id="ef" style="margin-top:14px"><div class="field"><label>Title</label><input name="title" required maxlength="200"></div><div class="grid2"><div class="field"><label>Starts</label><input type="datetime-local" name="starts_at" required value="' + date + 'T09:00"></div><div class="field"><label>Ends</label><input type="datetime-local" name="ends_at" value="' + date + 'T10:00"></div></div>' +
    '<div class="grid2"><div class="field"><label>Type</label><select name="kind">' + opts([['meeting', 'Meeting'], ['deadline', 'Deadline'], ['travel', 'Travel'], ['leave', 'Leave'], ['other', 'Other']], 'meeting') + '</select></div><div class="field"><label>Visible to</label><select name="scope">' + opts([['private', 'Me and invitees'], ['department', 'My department'], ...(S.user.role !== 'employee' ? [['company', 'Whole company']] : [])], 'private') + '</select></div></div>' +
    '<div class="field"><label>Location / link</label><input name="location" maxlength="190"></div><div class="field"><label>Invite people</label><select name="attendees" multiple size="4">' + opts(us.map(u => [u.id, u.name])) + '</select></div><div class="err" id="er"></div><div class="row"><button class="btn">Save event</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#ef', m).onsubmit = async e => { e.preventDefault(); const fd = new FormData(e.target), f = Object.fromEntries(fd); f.attendees = fd.getAll('attendees'); try { await api('events', { method: 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}

views.team = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  const s = await api('snapshot'), T = s.totals;
  v.innerHTML = '<div class="top"><div><h1>' + (S.user.role === 'founder' ? 'Company snapshot' : 'Team') + '</h1><div class="muted">Live status of everyone’s work</div></div></div>' +
    '<div class="cards"><div class="card stat"><span class="muted small">Open tasks</span><b>' + T.open + '</b></div><div class="card stat bad"><span class="muted small">Overdue</span><b>' + T.overdue + '</b></div><div class="card stat warn"><span class="muted small">Blocked</span><b>' + T.blocked + '</b></div><div class="card stat ok"><span class="muted small">Done · 7 days</span><b>' + T.done_7d + '</b></div></div>' +
    '<div class="card"><h2 style="margin-bottom:8px">People</h2><div class="tablewrap"><table><thead><tr><th>Name</th><th>Dept</th><th>Open</th><th>In progress</th><th>Overdue</th><th>Blocked</th><th>Done 7d</th><th>Last seen</th></tr></thead><tbody>' +
    s.people.map(p => '<tr><td><div class="row" style="flex-wrap:nowrap">' + avatar(p.id || p.user_id, p.name) + '<div><b>' + esc(p.name) + '</b><div class="muted small">' + esc(p.job_title || ROLE[p.role]) + '</div></div></div></td><td>' + esc(p.department || '—') + '</td><td>' + p.open_tasks + '</td><td>' + p.in_progress + '</td><td class="' + (p.overdue ? 'overdue' : '') + '">' + p.overdue + '</td><td>' + p.blocked + '</td><td>' + p.done_7d + '</td><td class="muted small"><span class="dot ' + (p.last_seen_at && Date.now() - new Date(p.last_seen_at.replace(' ', 'T')) < 600000 ? 'on' : '') + '"></span> ' + ago(p.last_seen_at) + '</td></tr>').join('') + '</tbody></table></div></div>' +
    '<div class="two" style="margin-top:18px"><div class="card"><h2>Due in the next 7 days</h2><div class="list">' + (s.upcoming.length ? s.upcoming.map(t => '<div class="item" data-id="' + t.id + '"><div class="grow"><b>' + esc(t.title) + '</b><div class="muted small">' + esc(t.assignee || 'Unassigned') + ' · ' + fmtDate(t.due_date) + '</div></div><span class="tag ' + t.priority + '">' + PRI[t.priority] + '</span></div>').join('') : '<div class="empty">Nothing due.</div>') + '</div></div>' +
    '<div class="card"><h2>Recent activity</h2><div class="list">' + s.recent.map(a => '<div style="padding:9px 0"><b>' + esc(a.name || 'System') + '</b> <span class="muted">' + esc(a.action.replace(/_/g, ' ')) + '</span><div class="small">' + esc(a.detail) + ' <span class="muted">· ' + ago(a.created_at) + '</span></div></div>').join('') + '</div></div></div>';
  bindTaskRows(v);
};

views.alerts = async () => {
  const v = $('#view'); const rows = await api('notifications');
  v.innerHTML = '<div class="top"><h1>Alerts</h1><button class="btn ghost sm right" id="ma">Mark all read</button></div><div class="card"><div class="list" id="nl"></div></div>';
  $('#nl').innerHTML = rows.length ? rows.map(n => '<div class="item" data-n="' + n.id + '" data-l="' + esc(n.link || '') + '"><span class="dot ' + (n.read_at ? '' : 'on') + '"></span><div class="grow"><b>' + esc(n.title) + '</b><div class="muted small">' + esc(n.body || '') + ' · ' + ago(n.created_at) + '</div></div></div>').join('') : '<div class="empty">You’re all caught up.</div>';
  $('#nl').querySelectorAll('.item').forEach(el => el.onclick = async () => { await api('notifications/read', { method: 'POST', data: { id: +el.dataset.n } }).catch(() => {}); S.unread = Math.max(0, S.unread - 1); if (el.dataset.l) location.hash = el.dataset.l; else render(); });
  $('#ma').onclick = async () => { await api('notifications/read', { method: 'POST', data: {} }); S.unread = 0; render(); };
};

let LN = { q: '', f: 'all' };
views.learn = async () => {
  const parts = location.hash.split('/'); if (parts[2]) return learnPage(+parts[2]);
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; await loadRefs();
  const d = await api('learn' + (LN.q ? '?q=' + encodeURIComponent(LN.q) : '')); let pages = d.pages;
  const need = pages.filter(p => p.needs_ack).length; if (LN.f === 'review') pages = pages.filter(p => p.needs_ack);
  const mine = new Set((S.user.department_ids || []).map(Number)); const groups = [{ id: null, name: 'Company-wide' }, ...S.depts.slice().sort((a, b) => (mine.has(b.id) - mine.has(a.id)) || a.name.localeCompare(b.name))];
  v.innerHTML = '<div class="top"><div><h1>Learn</h1><div class="muted">Procedures (SOPs) and training, organised by department</div></div>' + (d.can_create ? '<button class="btn right" id="np">+ New page</button>' : '') + '</div>' +
    '<div class="row" style="margin-bottom:14px"><input id="lq" placeholder="Search procedures…" value="' + esc(LN.q) + '" style="max-width:320px"><span class="chip ' + (LN.f === 'all' ? 'on' : '') + '" data-f="all">All</span><span class="chip ' + (LN.f === 'review' ? 'on' : '') + '" data-f="review">Needs my review' + (need ? ' (' + need + ')' : '') + '</span></div>' +
    (pages.length ? groups.map(g => { const ps = pages.filter(p => p.department_id === g.id); if (!ps.length) return ''; return '<div class="card" style="margin-bottom:14px"><h2 style="margin-bottom:8px">' + esc(g.name) + (g.id && mine.has(g.id) ? ' <span class="tag done">Your department</span>' : '') + '</h2><div class="list">' + ps.map(p => '<a class="item" href="#/learn/' + p.id + '" style="color:inherit"><div class="grow"><b>' + (p.pinned == 1 ? '★ ' : '') + esc(p.title) + '</b><div class="muted small">' + esc([p.category, 'Updated ' + fmtDate(p.updated_at), p.updated_by_name].filter(Boolean).join(' · ')) + '</div></div>' + (p.status === 'draft' ? '<span class="tag">Draft</span>' : '') + (p.needs_ack ? '<span class="tag warn">Review needed</span>' : '') + '</a>').join('') + '</div></div>'; }).join('') : '<div class="empty">' + (LN.q || LN.f === 'review' ? 'Nothing found.' : 'No procedures have been added yet.' + (d.can_create ? ' Click “New page” to write the first one.' : '')) + '</div>');
  let tm; $('#lq').oninput = e => { LN.q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(() => render(), 350); }; v.querySelectorAll('[data-f]').forEach(c => c.onclick = () => { LN.f = c.dataset.f; render(); });
  const np = $('#np'); if (np) np.onclick = () => sopEditor(null);
};
async function learnPage(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let p;
  try { p = await api('learn/' + id); } catch (e) { v.innerHTML = '<div class="row" style="margin-bottom:8px"><a href="#/learn" class="small">‹ Learn</a></div><div class="empty">' + esc(e.message) + '</div>'; return; }
  v.innerHTML = '<div class="row" style="margin-bottom:6px"><a href="#/learn" class="small">‹ Learn</a></div><div class="card"><div class="row"><div class="grow"><h1 style="margin-bottom:4px">' + esc(p.title) + '</h1><div class="muted small">' + esc([p.department || 'Company-wide', p.category, 'Version ' + p.version, 'Updated ' + fmtDate(p.updated_at) + (p.updated_by_name ? ' by ' + p.updated_by_name : '')].filter(Boolean).join(' · ')) + '</div></div>' + (p.status === 'draft' ? '<span class="tag">Draft</span>' : '') +
    (p.can_edit ? '<button class="btn ghost sm" id="ed">Edit</button><button class="btn ghost sm" id="hs">History</button><button class="btn ghost sm" id="dl" style="color:var(--bad)">Delete</button>' : '') + '<button class="btn ghost sm" id="pr">Print</button></div>' +
    (p.needs_ack ? '<div class="card" style="background:var(--mist);margin:14px 0;box-shadow:none"><b>Please read this procedure,</b> then confirm below. <button class="btn sm" id="ak" style="margin-left:8px">I have read and understood this</button></div>' : (p.requires_ack == 1 && p.status === 'published' ? '<div class="small" style="margin-top:10px;color:var(--ok)">✓ You confirmed you have read this version.</div>' : '')) +
    '<div class="sop" style="margin-top:16px">' + (p.body || '<p class="muted">This page is empty.</p>') + '</div></div>' +
    (p.ack_status ? '<div class="card" style="margin-top:14px"><h3 style="margin-bottom:8px">Read confirmations (version ' + p.version + ')</h3><div class="list">' + p.ack_status.map(a => '<div class="item"><div class="grow">' + esc(a.name) + '</div>' + (a.done ? '<span class="tag done">Confirmed ' + esc(fmtDate(a.at)) + '</span>' : '<span class="tag warn">Pending</span>') + '</div>').join('') + '</div></div>' : '');
  $('#pr').onclick = () => window.print();
  const ak = $('#ak'); if (ak) ak.onclick = async () => { await api('learn/' + id + '/ack', { method: 'POST' }); toast('Thank you — confirmed'); render(); };
  if (p.can_edit) {
    $('#ed').onclick = () => sopEditor(p);
    $('#dl').onclick = async () => { if (!confirm('Delete this page and its history?')) return; await api('learn/' + id, { method: 'DELETE' }); location.hash = '#/learn'; };
    $('#hs').onclick = async () => { const vs = await api('learn/' + id + '/versions'); const m = modal('<h2>History</h2><p class="muted small" style="margin:6px 0 10px">Earlier versions of this page. Restoring one keeps the current text in the history too.</p><div class="list">' + (vs.length ? vs.map(x => '<div class="item"><div class="grow"><b>Version ' + x.version + '</b> · ' + esc(x.title) + '<div class="muted small">' + esc(fmtDate(x.saved_at) + (x.saved_by_name ? ' · ' + x.saved_by_name : '')) + '</div></div><button class="btn ghost sm" data-r="' + x.id + '">Restore</button></div>').join('') : '<div class="muted small">No earlier versions yet.</div>') + '</div><div class="row" style="margin-top:12px"><button class="btn ghost" id="cx">Close</button></div>');
      $('#cx', m).onclick = () => m.close(); m.querySelectorAll('[data-r]').forEach(b => b.onclick = async () => { if (!confirm('Restore this version?')) return; await api('learn/' + id + '/restore', { method: 'POST', data: { version_id: +b.dataset.r } }); m.close(); toast('Restored'); render(); }); };
  }
}
function sopEditor(p) {
  const su = S.user.role === 'super_admin', mine = (S.user.department_ids || []).map(Number);
  const dopts = (su ? [['', 'Company-wide (everyone)']] : []).concat(S.depts.filter(d => su || mine.includes(d.id)).map(d => [d.id, d.name]));
  const cats = [...new Set((window.__sopCats || []).concat(['Process', 'Policy', 'How-to', 'Checklist', 'Training']))];
  const m = modal('<div class="row"><h2 class="grow">' + (p ? 'Edit page' : 'New page') + '</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="sf" style="margin-top:10px"><div class="field"><label>Title</label><input name="title" required maxlength="200" value="' + esc(p ? p.title : '') + '"></div>' +
    '<div class="grid2"><div class="field"><label>Department</label><select name="department_id">' + opts(dopts, p ? (p.department_id ?? '') : (dopts[0] || [''])[0]) + '</select></div><div class="field"><label>Category (optional)</label><input name="category" list="sc" maxlength="80" value="' + esc(p ? p.category || '' : '') + '"><datalist id="sc">' + cats.map(c => '<option value="' + esc(c) + '">').join('') + '</datalist></div></div>' +
    '<div class="row" style="gap:4px;margin-bottom:6px"><button type="button" class="btn ghost sm" data-b="h2">Heading</button><button type="button" class="btn ghost sm" data-b="h3">Subheading</button><button type="button" class="btn ghost sm" data-b="p">Text</button><button type="button" class="btn ghost sm" data-c="bold"><b>B</b></button><button type="button" class="btn ghost sm" data-c="italic"><i>I</i></button><button type="button" class="btn ghost sm" data-c="insertUnorderedList">• List</button><button type="button" class="btn ghost sm" data-c="insertOrderedList">1. Steps</button><button type="button" class="btn ghost sm" data-b="blockquote">Note</button><button type="button" class="btn ghost sm" data-link="1">Link</button><button type="button" class="btn ghost sm" data-tbl="1">Table</button><label class="btn ghost sm" style="cursor:pointer;margin:0">Picture<input type="file" accept="image/*" id="sim" hidden></label></div>' +
    '<div id="sb" class="m-editor sop" contenteditable="true" style="min-height:300px;max-height:50vh">' + (p ? p.body : '') + '</div>' +
    '<div class="row" style="margin-top:12px;gap:16px"><label class="small"><input type="checkbox" name="pinned" style="width:auto" ' + (p && p.pinned == 1 ? 'checked' : '') + '> Pin to top</label><label class="small"><input type="checkbox" name="requires_ack" style="width:auto" ' + (p && p.requires_ack == 1 ? 'checked' : '') + '> Ask people to confirm they have read it</label><label class="small"><input type="checkbox" name="notify" style="width:auto"> Notify people about this change</label></div>' +
    '<div class="err" id="er"></div><div class="row" style="margin-top:12px"><button class="btn" id="pub">' + (p ? 'Save' : 'Publish') + '</button><button class="btn ghost" type="button" id="drf">Save as draft</button></div></form>');
  m.querySelector('.modal').style.cssText += ';max-width:96vw;width:860px';
  const ed = $('#sb', m); ed.focus();
  m.querySelectorAll('[data-b]').forEach(b => b.onmousedown = e => { e.preventDefault(); document.execCommand('formatBlock', false, b.dataset.b); });
  m.querySelectorAll('[data-c]').forEach(b => b.onmousedown = e => { e.preventDefault(); document.execCommand(b.dataset.c, false, null); });
  $('[data-link]', m).onmousedown = e => { e.preventDefault(); const u = prompt('Link address (https://…)'); if (u && /^https?:\/\//i.test(u)) document.execCommand('createLink', false, u); };
  $('[data-tbl]', m).onmousedown = e => { e.preventDefault(); document.execCommand('insertHTML', false, '<table><tbody><tr><th>Step</th><th>Details</th></tr><tr><td>1</td><td>&nbsp;</td></tr><tr><td>2</td><td>&nbsp;</td></tr></tbody></table><p><br></p>'); };
  $('#sim', m).onchange = async e => { const f = e.target.files[0]; if (!f) return; try { if (f.size > 1.4e6) throw new Error('Picture too large (max 1.5 MB)'); const data = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(f); }); const r = await api('learn/img', { method: 'POST', data: { data } }); ed.focus(); document.execCommand('insertHTML', false, '<img src="' + r.url + '" alt=""><p><br></p>'); } catch (er) { toast(er.message); } e.target.value = ''; };
  $('#cx', m).onclick = () => m.close();
  const save = async status => { const f = new FormData($('#sf', m)); const data = { title: f.get('title'), department_id: f.get('department_id') || null, category: f.get('category'), status, pinned: $('[name=pinned]', m).checked, requires_ack: $('[name=requires_ack]', m).checked, notify: $('[name=notify]', m).checked, body: ed.innerHTML };
    try { const r = await api(p ? 'learn/' + p.id : 'learn', { method: p ? 'PATCH' : 'POST', data }); if (r.queued) return; m.close(); toast(status === 'draft' ? 'Saved as draft' : 'Saved'); const id = p ? p.id : r.id; if (location.hash === '#/learn/' + id) render(); else location.hash = '#/learn/' + id; } catch (er) { $('#er', m).textContent = er.message; } };
  $('#sf', m).onsubmit = e => { e.preventDefault(); save('published'); }; $('#drf', m).onclick = () => save('draft');
}

/* ---------- Competition ---------- */
const THREAT = { high: ['High threat', 'bad'], medium: ['Medium', 'warn'], low: ['Low', 'done'] }, INTEL = { note: 'Note', pricing: 'Pricing', product: 'Product', win: 'We won', loss: 'We lost', news: 'News' };
const ptag = (t) => '<span class="tag ' + (THREAT[t][1] === 'done' ? 'done' : THREAT[t][1] === 'warn' ? 'warn' : '') + '" ' + (t === 'high' ? 'style="background:#fde8e8;color:#b42318"' : '') + '>' + THREAT[t][0] + '</span>';
views.competition = async () => {
  const parts = location.hash.split('/'); if (parts[2]) return compPage(+parts[2]);
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const d = await api('competitors'); let q = '';
  const draw = () => { const rows = d.competitors.filter(c => !q || (c.name + ' ' + (c.category || '')).toLowerCase().includes(q));
    $('#cl').innerHTML = rows.length ? rows.map(c => '<a class="item" href="#/competition/' + c.id + '" style="color:inherit"><div class="grow"><b>' + esc(c.name) + '</b><div class="muted small">' + esc([c.category, c.website && c.website.replace(/^https?:\/\//, ''), c.intel_count + ' intel note' + (c.intel_count == 1 ? '' : 's'), c.last_intel && 'last ' + fmtDate(c.last_intel)].filter(Boolean).join(' · ')) + '</div></div>' + ptag(c.threat) + '</a>').join('') : '<div class="empty">' + (d.competitors.length ? 'Nothing found.' : 'No competitors tracked yet.' + (d.can_edit ? ' Click “Add competitor”.' : '')) + '</div>'; };
  v.innerHTML = '<div class="top"><div><h1>Competition</h1><div class="muted">Who we compete with, what we know, and what we have seen in the field</div></div>' + (d.can_edit ? '<button class="btn right" id="nc">+ Add competitor</button>' : '') + '</div><div class="row" style="margin-bottom:14px"><input id="cq" placeholder="Search…" style="max-width:300px"></div><div class="card"><div class="list" id="cl"></div></div>';
  draw(); $('#cq').oninput = e => { q = e.target.value.trim().toLowerCase(); draw(); }; const nc = $('#nc'); if (nc) nc.onclick = () => compForm(null);
};
function compForm(c) {
  const T = (n, l, h) => '<div class="field"><label>' + l + '</label><textarea name="' + n + '" rows="3" maxlength="2000" placeholder="' + (h || '') + '">' + esc(c ? c[n] || '' : '') + '</textarea></div>';
  const m = modal('<h2>' + (c ? 'Edit competitor' : 'Add competitor') + '</h2><form id="cf" style="margin-top:12px"><div class="grid2"><div class="field"><label>Name</label><input name="name" required maxlength="160" value="' + esc(c ? c.name : '') + '"></div><div class="field"><label>Website</label><input name="website" value="' + esc(c ? c.website || '' : '') + '"></div></div><div class="grid2"><div class="field"><label>Category</label><input name="category" maxlength="80" value="' + esc(c ? c.category || '' : '') + '"></div><div class="field"><label>Threat level</label><select name="threat">' + opts([['low', 'Low'], ['medium', 'Medium'], ['high', 'High']], c ? c.threat : 'medium') + '</select></div></div>' +
    T('offering', 'What they offer') + T('pricing', 'Pricing') + T('strengths', 'Strengths') + T('weaknesses', 'Weaknesses') + T('our_edge', 'Our edge — why customers pick us', 'How we win against them') +
    '<div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#cf', m).onsubmit = async e => { e.preventDefault(); try { const r = await api(c ? 'competitors/' + c.id : 'competitors', { method: c ? 'PATCH' : 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); location.hash = '#/competition/' + (c ? c.id : r.id); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function compPage(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let c; try { c = await api('competitors/' + id); } catch (e) { v.innerHTML = '<a href="#/competition" class="small">‹ Competition</a><div class="empty">' + esc(e.message) + '</div>'; return; }
  const blk = (l, t) => t ? '<div style="margin-top:12px"><div class="muted small">' + l + '</div><div style="white-space:pre-wrap">' + esc(t) + '</div></div>' : '';
  v.innerHTML = '<div style="margin-bottom:6px"><a href="#/competition" class="small">‹ Competition</a></div><div class="card"><div class="row"><div class="grow"><h1 style="margin-bottom:4px">' + esc(c.name) + '</h1><div class="muted small">' + esc(c.category || '') + (safeUrl(c.website) ? ' · <a href="' + esc(safeUrl(c.website)) + '" target="_blank" rel="noopener">' + esc(c.website.replace(/^https?:\/\//, '')) + '</a>' : '') + '</div></div>' + ptag(c.threat) + (c.can_edit ? '<button class="btn ghost sm" id="ec">Edit</button><button class="btn ghost sm" id="dc" style="color:var(--bad)">Delete</button>' : '') + '</div>' +
    blk('What they offer', c.offering) + blk('Pricing', c.pricing) + blk('Strengths', c.strengths) + blk('Weaknesses', c.weaknesses) + blk('Our edge', c.our_edge) + '</div>' +
    '<div class="card" style="margin-top:14px"><h2 style="margin-bottom:8px">Field intel</h2>' + (c.can_note ? '<form id="nf" class="row" style="gap:8px;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap"><select name="kind" style="width:auto">' + opts(Object.entries(INTEL), 'note') + '</select><textarea name="note" rows="2" required placeholder="What did you see or hear? (price change, new product, a deal we won or lost…)" style="flex:1;min-width:220px"></textarea><input name="source_url" placeholder="Source link (optional)" style="max-width:220px"><button class="btn">Add</button></form>' : '') +
    '<div class="list">' + (c.intel.length ? c.intel.map(i => '<div class="item" style="align-items:flex-start"><div class="grow"><span class="tag">' + INTEL[i.kind] + '</span> <span style="white-space:pre-wrap">' + esc(i.note) + '</span><div class="muted small">' + esc([i.user_name, fmtDate(i.created_at)].filter(Boolean).join(' · ')) + (safeUrl(i.source_url) ? ' · <a href="' + esc(safeUrl(i.source_url)) + '" target="_blank" rel="noopener">source</a>' : '') + '</div></div>' + (c.can_edit || i.user_id == S.user.id ? '<button class="btn ghost sm" data-di="' + i.id + '">✕</button>' : '') + '</div>').join('') : '<div class="empty">No intel yet.</div>') + '</div></div>';
  const ec = $('#ec'); if (ec) { ec.onclick = () => compForm(c); $('#dc').onclick = async () => { if (!confirm('Delete this competitor and all its intel?')) return; await api('competitors/' + id, { method: 'DELETE' }); location.hash = '#/competition'; }; }
  const nf = $('#nf'); if (nf) nf.onsubmit = async e => { e.preventDefault(); try { await api('competitors/' + id + '/intel', { method: 'POST', data: Object.fromEntries(new FormData(nf)) }); render(); } catch (er) { toast(er.message); } };
  v.querySelectorAll('[data-di]').forEach(b => b.onclick = async () => { if (!confirm('Remove this note?')) return; await api('competitors/' + id + '/intel/' + b.dataset.di, { method: 'DELETE' }); render(); });
}

/* ---------- Events ---------- */
const EST = { considering: 'Considering', registered: 'Registered', attending: 'Attending', done: 'Done', skipped: 'Skipped' };
const money = n => n == null || n === '' ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });
const dr = e => fmtDate(e.starts_on) + (e.ends_on && e.ends_on !== e.starts_on ? ' – ' + fmtDate(e.ends_on) : '');
views.events = async () => {
  const parts = location.hash.split('/'); if (parts[2]) return evPage(+parts[2]);
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; const d = await api('trade-events'); const today = new Date().toISOString().slice(0, 10);
  const up = d.events.filter(e => (e.ends_on || e.starts_on) >= today && e.status !== 'skipped').sort((a, b) => a.starts_on.localeCompare(b.starts_on)), past = d.events.filter(e => !up.includes(e));
  const row = e => '<a class="item" href="#/events/' + e.id + '" style="color:inherit"><div class="grow"><b>' + esc(e.name) + '</b><div class="muted small">' + esc([dr(e), e.location, e.people_names && 'Going: ' + e.people_names].filter(Boolean).join(' · ')) + '</div></div>' + (e.i_attend == 1 ? '<span class="tag done">You</span>' : '') + (e.open_leads > 0 ? '<span class="tag warn">' + e.open_leads + ' to follow up</span>' : '') + '<span class="tag">' + EST[e.status] + '</span></a>';
  v.innerHTML = '<div class="top"><div><h1>Events</h1><div class="muted">Trade shows, conferences and meetings — who is going, what we spent, who we met</div></div>' + (d.can_edit ? '<button class="btn right" id="ne">+ New event</button>' : '') + '</div>' +
    '<div class="card" style="margin-bottom:14px"><h2 style="margin-bottom:8px">Upcoming</h2><div class="list">' + (up.length ? up.map(row).join('') : '<div class="empty">Nothing coming up.</div>') + '</div></div>' + (past.length ? '<div class="card"><h2 style="margin-bottom:8px">Past &amp; skipped</h2><div class="list">' + past.map(row).join('') + '</div></div>' : '');
  const ne = $('#ne'); if (ne) ne.onclick = () => evForm(null);
};
async function evForm(e) {
  await loadRefs(); const users = S.users.filter(x => x.active === undefined || +x.active !== 0); const sel = new Set((e ? e.people : []).map(p => +p.id));
  const m = modal('<h2>' + (e ? 'Edit event' : 'New event') + '</h2><form id="ef" style="margin-top:12px"><div class="field"><label>Name</label><input name="name" required maxlength="200" value="' + esc(e ? e.name : '') + '"></div><div class="grid2"><div class="field"><label>Type</label><input name="kind" list="ek" maxlength="40" value="' + esc(e ? e.kind : 'trade show') + '"><datalist id="ek"><option>trade show</option><option>conference</option><option>webinar</option><option>client meeting</option><option>training</option></datalist></div><div class="field"><label>Status</label><select name="status">' + opts(Object.entries(EST), e ? e.status : 'considering') + '</select></div></div>' +
    '<div class="grid2"><div class="field"><label>Starts</label><input type="date" name="starts_on" required value="' + (e ? e.starts_on : '') + '"></div><div class="field"><label>Ends</label><input type="date" name="ends_on" value="' + (e ? e.ends_on || '' : '') + '"></div></div><div class="grid2"><div class="field"><label>Location</label><input name="location" maxlength="200" value="' + esc(e ? e.location || '' : '') + '"></div><div class="field"><label>Website</label><input name="url" value="' + esc(e ? e.url || '' : '') + '"></div></div>' +
    '<div class="grid2"><div class="field"><label>Budget</label><input type="number" step="0.01" min="0" name="budget" value="' + (e && e.budget != null ? e.budget : '') + '"></div><div class="field"><label>Actual cost</label><input type="number" step="0.01" min="0" name="actual_cost" value="' + (e && e.actual_cost != null ? e.actual_cost : '') + '"></div></div>' +
    '<div class="field"><label>Goals</label><textarea name="goals" rows="2">' + esc(e ? e.goals || '' : '') + '</textarea></div><div class="field"><label>Notes</label><textarea name="notes" rows="2">' + esc(e ? e.notes || '' : '') + '</textarea></div><div class="field"><label>Recap — what we learned (after the event)</label><textarea name="recap" rows="3">' + esc(e ? e.recap || '' : '') + '</textarea></div>' +
    '<div class="field"><label>Who is going</label><div id="pp" style="display:flex;flex-wrap:wrap;gap:6px 16px">' + users.map(x => '<label class="small"><input type="checkbox" style="width:auto" value="' + x.id + '" ' + (sel.has(+x.id) ? 'checked' : '') + '> ' + esc(x.name) + '</label>').join('') + '</div></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#ef', m).onsubmit = async ev => { ev.preventDefault(); const data = Object.fromEntries(new FormData(ev.target)); data.people = [...m.querySelectorAll('#pp input:checked')].map(i => +i.value); try { const r = await api(e ? 'trade-events/' + e.id : 'trade-events', { method: e ? 'PATCH' : 'POST', data }); m.close(); location.hash = '#/events/' + (e ? e.id : r.id); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function evPage(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let e; try { e = await api('trade-events/' + id); } catch (x) { v.innerHTML = '<a href="#/events" class="small">‹ Events</a><div class="empty">' + esc(x.message) + '</div>'; return; }
  const blk = (l, t) => t ? '<div style="margin-top:12px"><div class="muted small">' + l + '</div><div style="white-space:pre-wrap">' + esc(t) + '</div></div>' : '';
  v.innerHTML = '<div style="margin-bottom:6px"><a href="#/events" class="small">‹ Events</a></div><div class="card"><div class="row"><div class="grow"><h1 style="margin-bottom:4px">' + esc(e.name) + '</h1><div class="muted small">' + esc([e.kind, dr(e), e.location].filter(Boolean).join(' · ')) + (safeUrl(e.url) ? ' · <a href="' + esc(safeUrl(e.url)) + '" target="_blank" rel="noopener">website</a>' : '') + '</div></div><span class="tag">' + EST[e.status] + '</span>' + (e.can_edit ? '<button class="btn ghost sm" id="ee">Edit</button><button class="btn ghost sm" id="de" style="color:var(--bad)">Delete</button>' : '') + '</div>' +
    '<div class="row" style="gap:28px;margin-top:12px"><div><div class="muted small">Budget</div><b>' + money(e.budget) + '</b></div><div><div class="muted small">Actual cost</div><b>' + money(e.actual_cost) + '</b></div><div><div class="muted small">Leads</div><b>' + e.leads.length + '</b></div><div><div class="muted small">Going</div><b>' + (e.people.length ? esc(e.people.map(p => p.name).join(', ')) : '—') + '</b></div></div>' + blk('Goals', e.goals) + blk('Notes', e.notes) + blk('Recap', e.recap) + '</div>' +
    '<div class="card" style="margin-top:14px"><h2 style="margin-bottom:8px">People we met</h2>' + (e.can_lead ? '<form id="lf2" class="row" style="gap:8px;flex-wrap:wrap;margin-bottom:12px"><input name="name" required placeholder="Name" style="max-width:160px"><input name="company" placeholder="Company" style="max-width:160px"><input name="email" placeholder="Email" style="max-width:200px"><input name="phone" placeholder="Phone" style="max-width:130px"><input name="note" placeholder="What did you discuss?" style="flex:1;min-width:180px"><button class="btn">Add</button></form>' : '') +
    '<div class="list">' + (e.leads.length ? e.leads.map(l => '<div class="item" style="align-items:flex-start"><div class="grow"><b>' + esc(l.name) + '</b>' + (l.company ? ' · ' + esc(l.company) : '') + '<div class="muted small">' + esc([l.email, l.phone, l.note, l.user_name && 'met by ' + l.user_name].filter(Boolean).join(' · ')) + '</div></div>' + (l.email ? '<a class="btn ghost sm" href="#" data-mail="' + esc(l.email) + '">Email</a>' : '') + '<label class="small" style="white-space:nowrap"><input type="checkbox" style="width:auto" data-fu="' + l.id + '" ' + (l.followed_up == 1 ? 'checked' : '') + '> Followed up</label>' + (e.can_edit ? '<button class="btn ghost sm" data-dl="' + l.id + '">✕</button>' : '') + '</div>').join('') : '<div class="empty">No contacts recorded yet.</div>') + '</div></div>';
  const ee = $('#ee'); if (ee) { ee.onclick = () => evForm(e); $('#de').onclick = async () => { if (!confirm('Delete this event and its contacts?')) return; await api('trade-events/' + id, { method: 'DELETE' }); location.hash = '#/events'; }; }
  const lf = $('#lf2'); if (lf) lf.onsubmit = async ev => { ev.preventDefault(); try { await api('trade-events/' + id + '/leads', { method: 'POST', data: Object.fromEntries(new FormData(lf)) }); render(); } catch (er) { toast(er.message); } };
  v.querySelectorAll('[data-fu]').forEach(c => c.onchange = async () => { try { await api('trade-events/' + id + '/leads/' + c.dataset.fu, { method: 'PATCH', data: { followed_up: c.checked } }); } catch (er) { toast(er.message); c.checked = !c.checked; } });
  v.querySelectorAll('[data-dl]').forEach(b => b.onclick = async () => { if (!confirm('Remove this contact?')) return; await api('trade-events/' + id + '/leads/' + b.dataset.dl, { method: 'DELETE' }); render(); });
}

views.more = async () => {
  const items = [['campaigns', 'Campaigns', null], ['schedule', 'Schedule', null], ['team', 'Team / Snapshot', ['super_admin', 'founder', 'manager']], ['learn', 'Learn (SOPs)', null], ['competition', 'Competition', null], ['events', 'Events', null], ['research', 'Research', null], ['projects', 'Projects', null], ['products', 'Products & services', null], ['news', 'News', null], ['finance', 'Finance', null], ['admin', 'Admin', ['super_admin']], ['me', 'My profile & settings', null]].filter(x => (!x[2] || x[2].includes(S.user.role)) && (!(S.user.tabs) || !(x[0] in S.user.tabs) || S.user.tabs[x[0]]) && (x[0] !== 'campaigns' || S.user.campaigns) && (x[0] !== 'finance' || S.user.finance || ['super_admin', 'founder'].includes(S.user.role)));
  if (window.OfficeExt && window.OfficeExt.more) window.OfficeExt.more(items); // Agents area hook 5 of 6: Mail moves here for the owner
  $('#view').innerHTML = '<h1 style="margin-bottom:16px">More</h1><div class="card list">' + items.map(x => '<a class="item" href="#/' + x[0] + '" style="color:inherit"><b>' + x[1] + '</b><span class="right muted">›</span></a>').join('') + '</div>';
};

function squarePhoto(file) {
  return new Promise((res, rej) => {
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) return rej(new Error('Choose a JPG, PNG or WebP picture.'));
    const img = new Image(), u = URL.createObjectURL(file);
    img.onload = () => { const n = Math.min(img.width, img.height), c = document.createElement('canvas'); c.width = c.height = 320; const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, 320, 320); x.drawImage(img, (img.width - n) / 2, (img.height - n) / 2, n, n, 0, 0, 320, 320); URL.revokeObjectURL(u); res(c.toDataURL('image/jpeg', 0.88)); };
    img.onerror = () => rej(new Error('That picture could not be read.')); img.src = u;
  });
}
views.me = async () => {
  const u = S.user, perm = 'Notification' in window ? Notification.permission : 'unsupported';
  $('#view').innerHTML = '<h1 style="margin-bottom:16px">My profile</h1><div class="two"><div class="card"><div class="row">' + avatar(u.id, u.name, true) + '<div><b>' + esc(u.name) + '</b><div class="muted small">' + esc(u.email) + ' · ' + ROLE[u.role] + (u.department ? ' · ' + esc(u.department) : '') + '</div></div></div>' +
    '<h3 style="margin:18px 0 8px">Profile photo</h3><div class="muted small" style="margin-bottom:8px">Shown next to your name in Aerosub Office, and you can add it to your email signature (Mail → Signatures → Add my photo).</div><div class="row"><label class="btn ghost sm" style="cursor:pointer;margin:0">Upload photo<input type="file" id="avf" accept="image/png,image/jpeg,image/webp" hidden></label>' + (S.avv ? '<button class="btn ghost sm" id="avd" style="color:var(--bad)">Remove photo</button>' : '') + '</div>' +
    '<h3 style="margin:18px 0 8px">Change password</h3><form id="pf"><div class="field"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div><div class="field"><label>New password (10+ characters)</label><input type="password" name="new" autocomplete="new-password" minlength="10" required></div><div class="err" id="er"></div><button class="btn">Update password</button></form></div>' +
    '<div class="card col"><h3>Notifications</h3><div class="muted small">Alert me on this device when something needs attention.</div>' + (perm === 'granted' ? '<span class="tag done">Enabled on this device</span>' : perm === 'unsupported' ? '<span class="muted small">Not supported by this browser.</span>' : '<button class="btn sm" id="en" style="align-self:flex-start">Enable notifications</button>') +
    '<div id="pwa-install"></div><h3 style="margin-top:10px">Install the app</h3><div class="muted small"><b>iPhone:</b> open in Safari → Share → Add to Home Screen.<br><b>Android:</b> open in Chrome → ⋮ → Install app.<br><b>Desktop:</b> Chrome/Edge → install icon in the address bar.</div><button class="btn ghost sm" id="th" style="align-self:flex-start">Switch light / dark</button><button class="btn ghost sm" id="so" style="align-self:flex-start">Sign out</button></div></div>';
  eyes($('#pf'));
  $('#pf').onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); try { await api('password', { method: 'POST', data: f }); e.target.reset(); toast('Password updated'); } catch (er) { $('#er').textContent = er.message; } };
  $('#avf').onchange = async e => { const f = e.target.files[0]; if (!f) return; try { const data = await squarePhoto(f); const r = await api('avatar', { method: 'POST', data: { data } }); if (r.queued) return; S.avv = r.av; S.user.av = r.av; toast('Photo updated'); shell(); render(); } catch (er) { toast(er.message || 'Could not use that picture'); } };
  const avd = $('#avd'); if (avd) avd.onclick = async () => { await api('avatar', { method: 'DELETE' }); S.avv = 0; S.user.av = 0; toast('Photo removed'); shell(); render(); };
  const en = $('#en'); if (en) en.onclick = async () => { if (window.OfficeExt.enablePush) await window.OfficeExt.enablePush(true); else await Notification.requestPermission(); render(); };
  if (window.OfficeExt.pwaProfile) window.OfficeExt.pwaProfile();
  $('#th').onclick = () => { const cur = document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light'); const n = cur === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = n; ls.set('theme', n); };
  $('#so').onclick = async () => { await api('logout', { method: 'POST' }).catch(() => {}); S.user = null; S.csrf = null; ls.del('lastuser'); location.hash = ''; render(); };
};

views.admin = async () => {
  if (S.user.role !== 'super_admin') { location.hash = '#/home'; return; }
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  await loadRefs(); let act = []; if (S.user.is_root) { try { act = await api('activity?limit=60'); } catch {} } const invs = (await api('invites')).filter(i => i.status !== 'used'); let arch = []; try { arch = await api('users?archived=1'); } catch {}
  v.innerHTML = '<div class="top"><h1>Admin</h1><span class="right row"><button class="btn ghost" id="iv">✉ Invite by link</button><button class="btn" id="nu">+ Add user</button></span></div><div class="card"><h2 style="margin-bottom:8px">People</h2><div class="tablewrap"><table><thead><tr><th>Name</th><th>Role</th><th>Department</th><th>Status</th><th></th></tr></thead><tbody>' +
    S.users.map(u => '<tr><td><b>' + esc(u.name) + '</b><div class="muted small">' + esc(u.email) + '</div></td><td>' + ROLE[u.role] + '</td><td>' + esc((u.department_ids || []).map(i => (S.depts.find(d => d.id === i) || {}).name).filter(Boolean).join(', ') || '—') + '</td><td>' + (u.active == 1 ? '<span class="tag done">Active</span>' : '<span class="tag">Disabled</span>') + '</td><td><button class="btn ghost sm" data-u="' + u.id + '">Edit</button></td></tr>').join('') + '</tbody></table></div></div>' +
    (arch.length ? '<div class="card" style="margin-top:18px"><h2 style="margin-bottom:4px">Archived users (' + arch.length + ')</h2><div class="muted small" style="margin-bottom:8px">Archived accounts cannot sign in. They are deleted for good 60 days after archiving. Restore one to give the person access back.</div><div class="list">' + arch.map(a => '<div class="item"><div class="grow"><b>' + esc(a.name) + '</b><div class="muted small">' + esc(a.email) + ' · ' + esc(ROLE[a.role] || a.role) + ' · archived ' + fmtDate(a.archived_at) + (a.archived_by_name ? ' by ' + esc(a.archived_by_name) : '') + '</div></div><span class="tag ' + (+a.days_left <= 7 ? 'blocked' : 'deferred') + '" title="Deleted for good on ' + esc(fmtDate(a.delete_on)) + '">' + a.days_left + ' day' + (+a.days_left === 1 ? '' : 's') + ' left</span><button class="btn ghost sm" data-restore="' + a.id + '">Restore</button></div>').join('') + '</div></div>' : '') +
    (invs.length ? '<div class="card" style="margin-top:18px"><h2 style="margin-bottom:8px">Pending invitations</h2><div class="list">' + invs.map(i => '<div class="item"><div class="grow"><b>' + esc(i.email) + '</b><div class="muted small">' + ROLE[i.role] + ' · ' + (i.status === 'expired' ? 'expired' : 'expires ' + fmtDate(i.expires_at)) + '</div></div><button class="btn ghost sm" data-ri="' + i.id + '">Revoke</button></div>').join('') + '</div></div>' : '') + '<div class="two" style="margin-top:18px"><div class="card"><h2>Departments</h2><div class="chips" style="margin:10px 0">' + S.depts.map(d => '<span class="chip">' + esc(d.name) + ' <a href="#" data-dd="' + d.id + '" style="color:var(--bad)">✕</a></span>').join('') + '</div><form id="df" class="row" style="flex-wrap:nowrap"><input name="name" placeholder="New department" required><button class="btn sm">Add</button></form></div>' +
    '<div class="card"><h2>Activity log</h2>' + (S.user.is_root ? '<div class="small" style="margin-bottom:6px"><a href="#/logs">Open the full log with search and filters ›</a></div>' : '<div class="muted small" style="padding:6px 0">The activity log is kept by the Root Admin.</div>') + '<div class="list" style="max-height:340px;overflow:auto">' + act.map(a => '<div style="padding:8px 0" class="small"><b>' + esc(a.name || 'System') + '</b> ' + esc(a.action.replace(/_/g, ' ')) + ' <span class="muted">' + esc(a.detail || '') + ' · ' + ago(a.created_at) + '</span></div>').join('') + '</div></div></div>';
  $('#nu').onclick = () => userForm(); $('#iv').onclick = () => inviteForm();
  v.querySelectorAll('[data-restore]').forEach(b => b.onclick = async () => { try { await api('users/' + b.dataset.restore + '/restore', { method: 'POST', data: {} }); toast('Account restored'); render(); } catch (er) { toast(er.message); } });
  v.querySelectorAll('[data-ri]').forEach(b => b.onclick = async () => { if (!confirm('Revoke this invitation link?')) return; await api('invites/' + b.dataset.ri, { method: 'DELETE' }); render(); });
  v.querySelectorAll('[data-u]').forEach(b => b.onclick = () => userForm(S.users.find(u => u.id == b.dataset.u)));
  $('#df').onsubmit = async e => { e.preventDefault(); await api('departments', { method: 'POST', data: { name: new FormData(e.target).get('name') } }); render(); };
  v.querySelectorAll('[data-dd]').forEach(a => a.onclick = async e => { e.preventDefault(); if (confirm('Remove this department? People in it become unassigned.')) { await api('departments/' + a.dataset.dd, { method: 'DELETE' }); render(); } });
};
function userForm(u) {
  const m = modal('<h2>' + (u ? 'Edit user' : 'Add user') + '</h2><form id="uf" style="margin-top:14px"><div class="field"><label>Full name</label><input name="name" required value="' + esc(u?.name) + '"></div>' +
    (u ? '' : '<div class="field"><label>Email</label><input type="email" name="email" required></div>') + '<div class="grid2"><div class="field"><label>Role</label><select name="role">' + opts(Object.entries(ROLE), u?.role || 'employee') + '</select></div></div><div class="field"><label>Departments <span class="muted small">(tick all that apply — e.g. Marketing gives access to Campaigns)</span></label><div class="chips" id="dpk">' + S.depts.map(d => '<label class="chip" style="cursor:pointer"><input type="checkbox" value="' + d.id + '" style="width:auto;margin-right:6px" ' + ((u?.department_ids || []).includes(d.id) ? 'checked' : '') + '>' + esc(d.name) + '</label>').join('') + '</div></div>' +
    '<div class="field"><label>Job title</label><input name="job_title" value="' + esc(u?.job_title) + '"></div><div class="field"><label>' + (u ? 'Reset password (leave blank to keep)' : 'Temporary password (10+ characters)') + '</label><input name="password" type="text" autocomplete="off" minlength="10" ' + (u ? '' : 'required') + '></div>' +
    (u ? '<div class="field"><label><input type="checkbox" name="active" style="width:auto" ' + (u.active == 1 ? 'checked' : '') + '> Account active</label></div>' : '') + '<div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button>' + (u && u.id !== S.user.id ? '<button class="btn ghost right" type="button" id="arc" style="color:var(--bad)">Archive user</button>' : '') + '</div></form>');
  $('#cx', m).onclick = () => m.close();
  const arc = $('#arc', m); if (arc) arc.onclick = async () => { if (!confirm('Archive ' + u.name + '?\n\nThey lose access straight away. The account is kept for 60 days and can be restored; after that it is deleted for good. Their tasks stay where they are, so you may want to reassign them.')) return;
    try { const r = await api('users/' + u.id, { method: 'DELETE' }); m.close(); toast('Archived' + (r.open_tasks ? ' — ' + r.open_tasks + ' open task' + (r.open_tasks > 1 ? 's' : '') + ' still assigned to them' : '') + '. Deleted on ' + fmtDate(r.delete_on)); render(); } catch (er) { toast(er.message); } };
  $('#uf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); delete f.department_id; f.department_ids = [...m.querySelectorAll('#dpk input:checked')].map(i => +i.value); if (u) { f.active = e.target.active.checked; if (!f.password) delete f.password; }
    try { await api(u ? 'users/' + u.id : 'users', { method: u ? 'PATCH' : 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}

/* ================= PIPELINE ================= */
const SL = { researching: 'Researching', contact_identified: "Contact ID'd", outreach_sent: 'Outreach Sent', in_discussion: 'In Discussion', proposal_sent: 'Proposal Sent', negotiating: 'Negotiating', won: 'Won', on_hold: 'On Hold' };
const PL = { high: 'High', medium: 'Medium', low: 'Low' };
let PT = ls.get('pt', 'overview'), PF = { q: '', priority: '', stage: '' }, CF = { q: '', email: false, linkedin: false, reg: false };

function csvParse(text) {
  const rows = []; let row = [], cur = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) { if (c === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += c; }
    else if (c === '"') q = true;
    else if (c === ',') { row.push(cur); cur = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(cur); cur = ''; if (row.some(x => x.trim() !== '')) rows.push(row); row = []; }
    else cur += c;
  }
  row.push(cur); if (row.some(x => x.trim() !== '')) rows.push(row);
  return rows;
}
const ALIAS = {
  name: ['name', 'company', 'company name', 'account', 'full name', 'contact', 'contact name'], sector: ['sector', 'industry'], location: ['location', 'city', 'country', 'region'],
  website: ['website', 'url', 'site'], stage: ['stage', 'status'], priority: ['priority'], notes: ['notes', 'note', 'comments'],
  position: ['position', 'title', 'job title', 'role'], email: ['email', 'e-mail', 'email address'], phone: ['phone', 'telephone', 'mobile', 'tel'], linkedin: ['linkedin', 'linkedin url', 'linkedin profile'],
  followup_date: ['follow-up', 'follow up', 'followup', 'follow-up date', 'followup_date'], company: ['company', 'company name', 'organisation', 'organization', 'account'],
};
function csvToObjects(text, kind) {
  const rows = csvParse(text); if (rows.length < 2) return [];
  const hdr = rows[0].map(h => h.trim().toLowerCase()), map = {};
  const want = kind === 'companies' ? ['name', 'sector', 'location', 'website', 'stage', 'priority', 'notes'] : ['name', 'company', 'position', 'email', 'phone', 'linkedin', 'location', 'followup_date', 'notes'];
  want.forEach(k => { let idx = hdr.indexOf(k); if (idx < 0) idx = hdr.findIndex(h => (ALIAS[k] || []).includes(h)); if (kind === 'contacts' && k === 'name') { const ex = hdr.findIndex(h => ['name', 'full name', 'contact', 'contact name'].includes(h)); idx = ex; } if (idx >= 0) map[k] = idx; });
  return rows.slice(1).map(r => Object.fromEntries(Object.entries(map).map(([k, i]) => [k, (r[i] || '').trim()])));
}
function importModal(kind) {
  const cols = kind === 'companies' ? 'name, sector, location, website, stage, priority, notes' : 'name, company, position, email, phone, linkedin, location, follow-up date, notes';
  const m = modal('<h2>Import ' + kind + ' from CSV</h2><p class="muted small" style="margin:8px 0 12px">First row must be column headings. Recognised columns: ' + cols + '. Duplicates are skipped. Up to 500 rows per file. Export from Excel with Save As → CSV.</p><input type="file" id="fi" accept=".csv,text/csv"><div id="pv" class="small muted" style="margin:10px 0"></div><div class="row"><button class="btn" id="go" disabled>Import</button><button class="btn ghost" id="cx">Cancel</button></div>');
  let rows = []; $('#cx', m).onclick = () => m.close();
  $('#fi', m).onchange = async e => { const f = e.target.files[0]; if (!f) return; rows = csvToObjects(await f.text(), kind); $('#pv', m).textContent = rows.length ? rows.length + ' row(s) found. First: ' + (rows[0].name || '(no name column found)') : 'No rows found — check the heading row.'; $('#go', m).disabled = !rows.length || !rows[0].name; };
  $('#go', m).onclick = async () => { $('#go', m).disabled = true; try { const r = await api(kind + '/import', { method: 'POST', data: { rows: rows.slice(0, 500) } }); toast('Added ' + r.added + ', skipped ' + r.skipped + (r.companies_created ? ', ' + r.companies_created + ' new companies' : '')); m.close(); render(); } catch (er) { toast(er.message); $('#go', m).disabled = false; } };
}

views.pipeline = async () => {
  const v = $('#view');
  const tabs = [['overview', 'Overview'], ['board', 'Board'], ['companies', 'Companies'], ['contacts', 'Contacts'], ['vendors', 'Vendors'], ['partners', 'Partners'], ['regulators', 'Regulatory bodies']];
  v.innerHTML = '<div class="top"><h1>Pipeline</h1>' + (readOnly() ? '' : '<div class="row right"><button class="btn ghost sm" id="imp">Import CSV</button><button class="btn sm" id="nc">+ Contact</button><button class="btn sm" id="nco">+ Company</button></div>') + '</div>' +
    '<div class="chips" style="margin-bottom:16px">' + tabs.map(([k, l]) => '<span class="chip ' + (PT === k ? 'on' : '') + '" data-pt="' + k + '">' + l + '</span>').join('') + '</div><div id="pb"><div class="empty">Loading…</div></div>';
  v.querySelectorAll('[data-pt]').forEach(c => c.onclick = () => { PT = c.dataset.pt; ls.set('pt', PT); render(); });
  const nco = $('#nco'); if (nco) { nco.onclick = () => companyForm(); $('#nc').onclick = () => contactForm(); $('#imp').onclick = () => { const mm = modal('<h2>Import</h2><div class="col" style="margin-top:12px"><button class="btn" id="i1">Companies (CSV)</button><button class="btn ghost" id="i2">Contacts (CSV)</button></div>'); $('#i1', mm).onclick = () => { mm.close(); importModal('companies'); }; $('#i2', mm).onclick = () => { mm.close(); importModal('contacts'); }; }; }
  await pipeViews[PT]();
};
const pipeViews = {}; window.OfficeExt.pipeViews = pipeViews;   // assets/js/vendorcrm.js adds the Vendors and Partners tabs

pipeViews.overview = async () => {
  const d = await api('pipeline'), T = d.totals, max = Math.max(1, ...Object.values(d.stages));
  $('#pb').innerHTML = '<div class="cards"><div class="card stat"><span class="muted small">Accounts</span><b>' + T.companies + '</b></div><div class="card stat"><span class="muted small">Contacts</span><b>' + T.contacts + '</b><span class="muted small">' + T.linkedin + ' on LinkedIn</span></div>' +
    '<div class="card stat warn"><span class="muted small">Open actions</span><b>' + T.open_actions + '</b></div><div class="card stat bad"><span class="muted small">Overdue actions</span><b>' + T.overdue_actions + '</b></div><div class="card stat"><span class="muted small">High-priority live</span><b>' + T.high_live + '</b></div><div class="card stat warn"><span class="muted small">Flagged</span><b>' + T.flagged + '</b></div></div>' +
    '<div class="two"><div class="col"><div class="card"><h2 style="margin-bottom:12px">Pipeline by stage</h2>' + Object.entries(d.stages).map(([k, n]) => '<div class="row" style="flex-wrap:nowrap;margin:7px 0"><span style="width:120px" class="small">' + SL[k] + '</span><div class="grow" style="background:var(--mist);border-radius:99px;height:12px"><div style="width:' + (n / max * 100) + '%;background:var(--cyan);height:12px;border-radius:99px;min-width:' + (n ? 12 : 0) + 'px"></div></div><b style="width:24px;text-align:right">' + n + '</b></div>').join('') + '</div>' +
    '<div class="card"><h2>Needs attention</h2><div class="list">' + (d.attention.length ? d.attention.map(a => '<div class="item" data-co="' + a.id + '"><div class="grow"><b>' + esc(a.name) + '</b><div class="small" style="color:var(--warn)">' + esc(a.flag_note) + '</div></div></div>').join('') : '<div class="empty">Nothing flagged.</div>') + '</div></div></div>' +
    '<div class="col"><div class="card"><h2>Open actions</h2><div class="list">' + (d.actions.length ? d.actions.map(t => { const od = t.due_date && t.due_date < today(); return '<div class="item" data-go="#/tasks/' + (+t.id) + '"><div class="grow"><b>' + esc(t.title) + '</b><div class="muted small">' + esc(t.company) + ' · ' + esc(t.assignee || 'Unassigned') + (t.due_date ? ' · <span class="' + (od ? 'overdue' : '') + '">' + fmtDate(t.due_date) + '</span>' : '') + '</div></div></div>'; }).join('') : '<div class="empty">No open actions.</div>') + '</div></div>' +
    '<div class="card"><h2>Follow-ups due</h2><div class="list">' + (d.followups.length ? d.followups.map(f => '<div class="item"><div class="grow"><b>' + esc(f.name) + '</b><div class="muted small">' + esc(f.company || '—') + ' · <span class="' + (f.followup_date < today() ? 'overdue' : '') + '">' + fmtDate(f.followup_date) + '</span></div></div></div>').join('') : '<div class="empty">No follow-ups this week.</div>') + '</div></div></div></div>';
  $('#pb').querySelectorAll('[data-co]').forEach(el => el.onclick = () => companyDetail(+el.dataset.co));
};

pipeViews.board = async () => {
  const rows = await api('companies');
  $('#pb').innerHTML = '<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:10px">' + Object.entries(SL).map(([k, l]) => {
    const cs = rows.filter(c => c.stage === k);
    return '<div class="card" data-col="' + k + '" style="min-width:230px;width:230px;padding:12px;background:var(--mist);flex:none"><div class="row" style="margin-bottom:8px"><b class="small">' + l + '</b><span class="tag right">' + cs.length + '</span></div><div class="col" style="gap:8px;min-height:40px">' +
      cs.map(c => '<div class="card" draggable="' + (!readOnly()) + '" data-co="' + c.id + '" style="padding:10px;cursor:pointer;box-shadow:none"><b class="small">' + esc(c.name) + '</b><div class="muted small">' + esc(c.sector || '') + '</div><div class="row" style="margin-top:6px;gap:6px"><span class="tag ' + c.priority + '">' + PL[c.priority] + '</span>' + (c.flag_note ? '<span class="tag high">⚑</span>' : '') + '<span class="muted small right">' + c.contact_count + ' contacts</span></div></div>').join('') + '</div></div>';
  }).join('') + '</div>' + (readOnly() ? '' : '<div class="muted small">Drag a card to another stage, or open it to change the stage.</div>');
  const pb = $('#pb'); let drag = null;
  pb.querySelectorAll('[data-co]').forEach(el => { el.onclick = () => companyDetail(+el.dataset.co); el.ondragstart = e => { drag = el.dataset.co; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', drag); } catch {} }; });
  pb.querySelectorAll('[data-col]').forEach(col => { col.ondragover = e => e.preventDefault(); col.ondrop = async e => { e.preventDefault(); if (!drag) return; try { await api('companies/' + drag, { method: 'PATCH', data: { stage: col.dataset.col } }); } catch (er) { toast(er.message); } drag = null; render(); }; });
};

pipeViews.companies = async () => {
  $('#pb').innerHTML = '<div class="row" style="margin-bottom:12px"><input id="cq" placeholder="Search companies…" value="' + esc(PF.q) + '" style="max-width:260px"><select id="cp" style="width:auto">' + opts(Object.entries(PL), PF.priority, 'All priorities') + '</select><select id="cs" style="width:auto">' + opts(Object.entries(SL), PF.stage, 'All stages') + '</select></div><div class="card"><div class="tablewrap" id="ct"></div></div>';
  const load = async () => {
    const qs = []; if (PF.q) qs.push('q=' + encodeURIComponent(PF.q)); if (PF.priority) qs.push('priority=' + PF.priority); if (PF.stage) qs.push('stage=' + PF.stage);
    const rows = await api('companies' + (qs.length ? '?' + qs.join('&') : ''));
    $('#ct').innerHTML = rows.length ? '<table><thead><tr><th>Company</th><th>Stage</th><th>Priority</th><th>Owner</th><th>Contacts</th><th>Open tasks</th></tr></thead><tbody>' + rows.map(c => '<tr data-co="' + c.id + '" style="cursor:pointer"><td><b>' + esc(c.name) + '</b>' + (c.flag_note ? ' <span class="tag high">⚑</span>' : '') + '<div class="muted small">' + esc([c.sector, c.location].filter(Boolean).join(' · ')) + '</div></td><td>' + SL[c.stage] + '</td><td><span class="tag ' + c.priority + '">' + PL[c.priority] + '</span></td><td>' + esc(c.owner_name || '—') + '</td><td>' + c.contact_count + '</td><td>' + c.open_tasks + '</td></tr>').join('') + '</tbody></table>' : '<div class="empty">No companies yet. Add one or import a CSV.</div>';
    $('#ct').querySelectorAll('[data-co]').forEach(el => el.onclick = () => companyDetail(+el.dataset.co));
  };
  let tm; $('#cq').oninput = e => { PF.q = e.target.value; clearTimeout(tm); tm = setTimeout(load, 250); };
  $('#cp').onchange = e => { PF.priority = e.target.value; load(); }; $('#cs').onchange = e => { PF.stage = e.target.value; load(); };
  load();
};

pipeViews.contacts = async () => {
  $('#pb').innerHTML = '<div class="row" style="margin-bottom:12px"><input id="kq" placeholder="Search contacts…" value="' + esc(CF.q) + '" style="max-width:260px"><span class="chip ' + (CF.email ? 'on' : '') + '" id="ke">With email</span><span class="chip ' + (CF.linkedin ? 'on' : '') + '" id="kl">With LinkedIn</span><span class="chip ' + (CF.reg ? 'on' : '') + '" id="kr" title="People at regulatory bodies">Regulatory contacts</span></div><div class="card"><div class="tablewrap" id="kt"></div></div>';
  const load = async () => {
    const qs = []; if (CF.q) qs.push('q=' + encodeURIComponent(CF.q)); if (CF.email) qs.push('has_email=1'); if (CF.linkedin) qs.push('has_linkedin=1'); if (CF.reg) qs.push('type=regulatory');
    const rows = await api('contacts' + (qs.length ? '?' + qs.join('&') : ''));
    $('#kt').innerHTML = rows.length ? '<table><thead><tr><th>Name</th><th>Company</th><th>Position</th><th>Email</th><th>Phone</th><th>Follow-up</th></tr></thead><tbody>' + rows.map(c => '<tr data-k="' + c.id + '" style="cursor:pointer"><td><b>' + esc(c.name) + '</b>' + (c.contact_type === 'regulatory' ? ' <span class="tag warn">Regulatory</span>' : '') + (safeUrl(c.linkedin) ? ' <a href="' + esc(safeUrl(c.linkedin)) + '" target="_blank" rel="noopener noreferrer" data-stop="1">in</a>' : '') + '</td><td>' + esc(c.company_name || c.org_name || '—') + '</td><td>' + esc(c.position || '') + '</td><td>' + (c.email ? '<a href="#" data-mail="' + esc(c.email) + '" title="Write an email">✉ ' + esc(c.email) + '</a>' : '') + '</td><td>' + esc(c.phone || '') + '</td><td class="' + (c.followup_date && c.followup_date < today() ? 'overdue' : '') + '">' + fmtDate(c.followup_date) + '</td></tr>').join('') + '</tbody></table>' : '<div class="empty">No contacts found.</div>';
    $('#kt').querySelectorAll('[data-k]').forEach(el => el.onclick = () => contactForm(rows.find(r => r.id == el.dataset.k)));
  };
  let tm; $('#kq').oninput = e => { CF.q = e.target.value; clearTimeout(tm); tm = setTimeout(load, 250); };
  $('#ke').onclick = () => { CF.email = !CF.email; render(); }; $('#kl').onclick = () => { CF.linkedin = !CF.linkedin; render(); }; $('#kr').onclick = () => { CF.reg = !CF.reg; render(); };
  load();
};

function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }
function companyForm(c) {
  const us = S.users.filter(u => u.active == 1 && u.role !== 'founder');
  const m = modal('<h2>' + (c ? 'Edit company' : 'New company') + '</h2><form id="cf2" style="margin-top:14px"><div class="grid2"><div class="field"><label>Company name</label><input name="name" required maxlength="190" value="' + esc(c?.name) + '"></div><div class="field"><label>Company code <span class="muted small">(fills the Customer ID on documents)</span></label><input name="code" maxlength="40" placeholder="e.g. AMNI" value="' + esc(c?.code) + '"></div></div><div class="field"><label>Address <span class="muted small">(used on documents)</span></label><textarea name="address" rows="2" maxlength="800">' + esc(c?.address) + '</textarea></div><div class="grid2"><div class="field"><label>Sector</label><input name="sector" value="' + esc(c?.sector) + '"></div><div class="field"><label>Location</label><input name="location" value="' + esc(c?.location) + '"></div></div>' +
    '<div class="grid2"><div class="field"><label>Stage</label><select name="stage">' + opts(Object.entries(SL), c?.stage || 'researching') + '</select></div><div class="field"><label>Priority</label><select name="priority">' + opts(Object.entries(PL), c?.priority || 'medium') + '</select></div></div>' +
    '<div class="grid2"><div class="field"><label>Owner</label><select name="owner_id">' + opts(us.map(u => [u.id, u.name]), c?.owner_id ?? S.user.id) + '</select></div><div class="field"><label>Website</label><input name="website" placeholder="https://" value="' + esc(c?.website) + '"></div></div>' +
    '<div class="field"><label>Pain points & challenges</label><textarea name="pain_points">' + esc(c?.pain_points) + '</textarea></div><div class="field"><label>Current solutions in place</label><textarea name="current_solution">' + esc(c?.current_solution) + '</textarea></div><div class="field"><label>Notes</label><textarea name="notes">' + esc(c?.notes) + '</textarea></div>' +
    '<div class="field"><label>Needs-attention flag (shows on the overview; leave empty for none)</label><input name="flag_note" maxlength="300" value="' + esc(c?.flag_note) + '"></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#cf2', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); try { const r = await api(c ? 'companies/' + c.id : 'companies', { method: c ? 'PATCH' : 'POST', data: f }); m.close(); await loadRefs(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}
async function companyDetail(id) {
  let c; try { c = await api('companies/' + id); } catch (e) { return toast(e.message); }
  const m = modal('<div class="row"><h2 class="grow">' + esc(c.name) + '</h2><button class="btn ghost sm" id="cl">Close</button></div><div class="row" style="margin:10px 0"><span class="tag">' + SL[c.stage] + '</span><span class="tag ' + c.priority + '">' + PL[c.priority] + '</span>' + (c.sector ? '<span class="tag">' + esc(c.sector) + '</span>' : '') + (c.location ? '<span class="tag">' + esc(c.location) + '</span>' : '') + '</div>' +
    (c.flag_note ? '<div class="card" style="padding:10px 12px;background:#fff1d6;color:#7a4b00;border-color:#f3d18a;margin:8px 0">⚑ ' + esc(c.flag_note) + '</div>' : '') +
    (safeUrl(c.website) ? '<div class="small" style="margin:6px 0"><a href="' + esc(safeUrl(c.website)) + '" target="_blank" rel="noopener noreferrer">' + esc(c.website) + '</a></div>' : '') +
    [['Pain points & challenges', c.pain_points], ['Current solutions', c.current_solution], ['Notes', c.notes]].filter(x => x[1]).map(x => '<h3 style="margin-top:12px">' + x[0] + '</h3><p style="white-space:pre-wrap;margin:4px 0">' + esc(x[1]) + '</p>').join('') +
    '<div class="muted small" style="margin-top:10px">Owner: <b>' + esc(c.owner_name || '—') + '</b></div>' +
    (readOnly() ? '' : '<div class="row" style="margin:12px 0"><select id="stg" style="width:auto"' + (c.can_edit ? '' : ' disabled') + '>' + opts(Object.entries(SL), c.stage) + '</select>' + (c.can_edit ? '<button class="btn ghost sm" id="ed">Edit</button>' : '') + (c.can_delete ? '<button class="btn ghost sm" id="rm" style="color:var(--bad)">Delete</button>' : '') + '</div>') +
    '<div class="row" style="margin-top:14px"><h3>Contacts (' + c.contacts.length + ')</h3>' + (readOnly() ? '' : '<button class="btn ghost sm right" id="ac">+ Contact</button>') + '</div><div class="list">' + (c.contacts.length ? c.contacts.map(k => '<div class="item" data-k="' + k.id + '"><div class="grow"><b>' + esc(k.name) + '</b><div class="muted small">' + esc([k.position, k.email, k.phone].filter(Boolean).join(' · ')) + '</div></div>' + (k.email ? '<a href="#" data-mail="' + esc(k.email) + '" title="Write an email" class="btn ghost sm">✉ Email</a>' : '') + (k.linkedin ? '<a href="' + esc(safeUrl(k.linkedin)) + '" target="_blank" rel="noopener noreferrer" data-stop="1">in</a>' : '') + '</div>').join('') : '<div class="muted small" style="padding:8px 0">No contacts yet.</div>') + '</div>' +
    '<div class="row" style="margin-top:14px"><h3>Actions (' + c.tasks.length + ')</h3>' + (readOnly() ? '' : '<button class="btn ghost sm right" id="at">+ Task</button>') + '</div><div class="list">' + (c.tasks.length ? c.tasks.map(t => '<div class="item" data-t="' + t.id + '"><div class="grow"><div class="title ' + (t.status === 'done' ? 'done' : '') + '">' + esc(t.title) + '</div><div class="muted small">' + esc(t.assignee_name || 'Unassigned') + (t.due_date ? ' · ' + fmtDate(t.due_date) : '') + '</div></div><span class="tag ' + t.status + '">' + STATUS[t.status] + '</span></div>').join('') : '<div class="muted small" style="padding:8px 0">No actions yet.</div>') + '</div>');
  $('#cl', m).onclick = () => m.close();
  if (window.OfficeExt && window.OfficeExt.onCompany) window.OfficeExt.onCompany(m, c); // Agent platform hook 3 of 3: research button, badge, documents
  const stg = $('#stg', m); if (stg) stg.onchange = async () => { try { await api('companies/' + id, { method: 'PATCH', data: { stage: stg.value } }); m.close(); render(); } catch (e) { toast(e.message); } };
  const ed = $('#ed', m); if (ed) ed.onclick = () => { m.close(); companyForm(c); };
  const rm = $('#rm', m); if (rm) rm.onclick = async () => { if (!confirm('Delete this company? Its contacts stay but lose the link.')) return; await api('companies/' + id, { method: 'DELETE' }); m.close(); await loadRefs(); render(); };
  const ac = $('#ac', m); if (ac) ac.onclick = () => { m.close(); contactForm(null, id); };
  const at = $('#at', m); if (at) at.onclick = () => { m.close(); taskForm(null, { company_id: id }); };
  m.querySelectorAll('[data-k]').forEach(el => el.onclick = () => { m.close(); contactForm(c.contacts.find(k => k.id == el.dataset.k), id); });
  m.querySelectorAll('[data-t]').forEach(el => el.onclick = () => { m.close(); location.hash = '#/tasks/' + el.dataset.t; });
}
function contactForm(k, companyId) {
  const m = modal('<h2>' + (k ? 'Edit contact' : 'New contact') + '</h2><form id="kf" style="margin-top:14px"><div class="field"><label>Full name</label><input name="name" required maxlength="160" value="' + esc(k?.name) + '"></div><div class="grid2"><div class="field"><label>Company</label><select name="company_id">' + opts(S.companies.map(c => [c.id, c.name]), k?.company_id ?? companyId, '— none —') + '</select></div><div class="field"><label>Position</label><input name="position" value="' + esc(k?.position) + '"></div></div>' +
    '<div class="grid2"><div class="field"><label>Email</label><input type="email" name="email" value="' + esc(k?.email) + '"></div><div class="field"><label>Phone</label><input name="phone" value="' + esc(k?.phone) + '"></div></div><div class="grid2"><div class="field"><label>LinkedIn URL</label><input name="linkedin" placeholder="https://" value="' + esc(k?.linkedin) + '"></div><div class="field"><label>Follow-up date</label><input type="date" name="followup_date" value="' + esc(k?.followup_date || '') + '"></div></div>' +
    '<div class="field"><label>Contact type</label><select name="contact_type"><option value="">Customer / general contact</option><option value="regulatory"' + (k?.contact_type === 'regulatory' ? ' selected' : '') + '>Regulatory body contact</option></select></div><div class="field"><label>Notes</label><textarea name="notes">' + esc(k?.notes) + '</textarea></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button>' + (k && canManage() ? '<button class="btn ghost" type="button" id="rm" style="color:var(--bad)">Delete</button>' : '') + '<button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  const rm = $('#rm', m); if (rm) rm.onclick = async () => { if (confirm('Delete this contact?')) { await api('contacts/' + k.id, { method: 'DELETE' }); m.close(); render(); } };
  $('#kf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.company_id = f.company_id || null; try { await api(k ? 'contacts/' + k.id : 'contacts', { method: k ? 'PATCH' : 'POST', data: f }); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } };
}

/* ================= CAMPAIGNS (email marketing; Marketing department only) ================= */
const CP = { tab: ls.get('cptab', 'overview'), timer: 0 };
const CSTAT = { draft: 'Draft', scheduled: 'Scheduled', sending: 'Sending', paused: 'Paused', sent: 'Sent' };
const VBADGE = { unchecked: ['Not checked', ''], valid: ['Valid', 'done'], risky: ['Risky', 'warn'], invalid: ['Invalid', 'bad'] };
const capi = (p, o) => api('campaigns/' + p, o);
const dtLocal = d => d ? new Date(d.replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

views.campaigns = async () => {
  if (CP.timer) { clearInterval(CP.timer); CP.timer = 0; }
  if (!S.user.campaigns) { $('#view').innerHTML = '<div class="empty">Campaigns is for the Marketing team. Ask the Super Admin to add you to the Marketing department.</div>'; return; }
  const parts = location.hash.split('/'); const v = $('#view');
  if (parts[2] === 'c' && parts[3]) return campDetail(+parts[3]);
  if (parts[2] === 'l' && parts[3]) return campList(+parts[3]);
  if (parts[2] === 'a' && parts[3]) return campAuto(+parts[3]);
  const GROUPS = [['Start', [['overview', '🏠 Start here']]], ['1 · Find people', [['prospect', '🔎 Prospecting'], ['inbox', '📥 Inbox automation']]], ['2 · Prepare', [['lists', '👥 Lists & contacts'], ['templates', '🎨 Templates']]], ['3 · Send', [['campaigns', '✉️ Campaigns'], ['autos', '↩️ Auto-responders'], ['mailtest', '✅ Test your emails']]], ['Setup', [['settings', '⚙️ Sending settings']]]];
  if (!GROUPS.some(g => g[1].some(x => x[0] === CP.tab))) CP.tab = 'overview';
  v.innerHTML = '<div class="top"><div><h1>Campaigns</h1><div class="muted">Find prospects, build lists, send and test your emails</div></div></div><div class="cpnav">' +
    GROUPS.map(([g, items]) => '<div class="cpg"><div class="cpgl">' + g + '</div><div class="cpgi">' + items.map(([k, l]) => '<span class="chip ' + (CP.tab === k ? 'on' : '') + '" data-ct="' + k + '" role="button" tabindex="0">' + l + '</span>').join('') + '</div></div>').join('') + '</div><div id="cpb"><div class="empty">Loading…</div></div>';
  v.querySelectorAll('[data-ct]').forEach(c => { c.onclick = () => { CP.tab = c.dataset.ct; ls.set('cptab', CP.tab); render(); }; c.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); c.click(); } }; });
  try { await ({ overview: () => window.OfficeExt.campOverview ? window.OfficeExt.campOverview() : Promise.resolve(), mailtest: () => window.OfficeExt.campTest ? window.OfficeExt.campTest() : Promise.resolve(), campaigns: campTabCampaigns, inbox: () => window.OfficeExt.campInbox ? window.OfficeExt.campInbox() : Promise.resolve($('#cpb').innerHTML = '<div class="empty">Loading…</div>'), prospect: () => window.OfficeExt.campProspect ? window.OfficeExt.campProspect() : Promise.resolve(), autos: campTabAutos, lists: campTabLists, templates: campTabTemplates, settings: campTabSettings }[CP.tab] || campTabCampaigns)(); } catch (e) { $('#cpb').innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; }
};

/* ---- campaigns tab ---- */
async function campTabCampaigns() {
  const [rows, lists, tpls] = await Promise.all([capi('campaigns'), capi('lists'), capi('templates')]);
  $('#cpb').innerHTML = '<div class="row" style="margin-bottom:12px"><button class="btn" id="cn">+ New campaign</button></div><div class="card"><div class="tablewrap">' + (rows.length ? '<table><thead><tr><th>Campaign</th><th>Status</th><th>List</th><th>Progress</th><th>Opened</th><th>Clicked</th><th>When</th></tr></thead><tbody>' +
    rows.map(c => '<tr data-c="' + c.id + '" style="cursor:pointer"><td><b>' + esc(c.name) + '</b><div class="muted small">' + esc(c.subject) + '</div></td><td><span class="tag ' + (c.status === 'sent' ? 'done' : c.status === 'sending' ? 'warn' : '') + '">' + CSTAT[c.status] + '</span></td><td>' + esc(c.list_name || '—') + '</td><td>' + (c.total ? c.sent + ' / ' + c.total + (c.failed ? ' <span class="overdue">(' + c.failed + ' failed)</span>' : '') : '—') + '</td><td>' + (c.sent ? Math.round(c.opens * 100 / c.sent) + '%' : '—') + '</td><td>' + (c.sent ? Math.round(c.clicks * 100 / c.sent) + '%' : '—') + '</td><td class="small">' + esc(c.status === 'scheduled' ? dtLocal(c.scheduled_at) : dtLocal(c.finished_at || c.started_at || c.created_at)) + '</td></tr>').join('') + '</tbody></table>' : '<div class="empty">No campaigns yet. Create your first one.</div>') + '</div></div>';
  $('#cpb').querySelectorAll('[data-c]').forEach(r => r.onclick = () => location.hash = '#/campaigns/c/' + r.dataset.c);
  $('#cn').onclick = () => {
    const m = modal('<h2>New campaign</h2><form id="nf" style="margin-top:12px"><div class="field"><label>Campaign name (only you see this)</label><input name="name" required maxlength="140"></div><div class="field"><label>Subject line</label><input name="subject" required maxlength="200" placeholder="e.g. News from Aerosub, {{first_name|friend}}"></div><div class="field"><label>Preview text (shown after the subject in the inbox)</label><input name="preheader" maxlength="200"></div>' +
      '<div class="grid2"><div class="field"><label>Send to list</label><select name="list_id">' + opts(lists.map(l => [l.id, l.name + ' (' + l.subscribed + ')']), '', '— choose later —') + '</select></div><div class="field"><label>Start from template</label><select name="template_id">' + opts(tpls.map(t => [t.id, t.name]), '', 'Blank starter') + '</select></div></div><div class="err" id="er"></div><div class="row"><button class="btn">Create & design</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    $('#nf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.list_id = +f.list_id || null; f.template_id = +f.template_id || null; try { const r = await capi('campaigns', { method: 'POST', data: f }); m.close(); location.hash = '#/campaigns/c/' + r.id; } catch (er) { $('#er', m).textContent = er.message; } };
  };
}

async function campDetail(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  const [c, lists, st] = await Promise.all([capi('campaigns/' + id), capi('lists'), capi('settings')]);
  const edit = ['draft', 'paused'].includes(c.status), pct = c.total ? Math.round((c.sent + c.failed) * 100 / c.total) : 0;
  v.innerHTML = '<div class="row" style="margin-bottom:6px"><a href="#/campaigns" class="small">‹ All campaigns</a></div><div class="top"><div><h1>' + esc(c.name) + '</h1><span class="tag ' + (c.status === 'sent' ? 'done' : c.status === 'sending' ? 'warn' : '') + '" id="cst">' + CSTAT[c.status] + (c.status === 'scheduled' ? ' · ' + esc(dtLocal(c.scheduled_at)) : '') + '</span></div></div>' +
    (c.last_error ? '<div class="card" style="border-color:var(--bad);margin-bottom:12px"><b>Sending stopped:</b> ' + esc(c.last_error) + '</div>' : '') +
    '<div class="two"><div class="card"><h2 style="margin-bottom:10px">Settings</h2><form id="cf"><div class="field"><label>Subject line</label><input name="subject" value="' + esc(c.subject) + '" ' + (edit ? '' : 'disabled') + ' maxlength="200"></div><div class="field"><label>Preview text</label><input name="preheader" value="' + esc(c.preheader || '') + '" ' + (edit ? '' : 'disabled') + ' maxlength="200"></div><div class="field"><label>Send to list</label><select name="list_id" ' + (edit ? '' : 'disabled') + '>' + opts(lists.map(l => [l.id, l.name + ' (' + l.subscribed + ' subscribed)']), c.list_id, '— choose a list —') + '</select></div>' +
    '<div class="grid2"><div class="field"><label>From name</label><input name="from_name" placeholder="' + esc(st.from_name || '') + '" value="' + esc(c.from_name || '') + '" ' + (edit ? '' : 'disabled') + '></div><div class="field"><label>From email</label><input name="from_email" placeholder="' + esc(st.from_email || '') + '" value="' + esc(c.from_email || '') + '" ' + (edit ? '' : 'disabled') + '></div></div>' +
    '<div class="field"><label><input type="checkbox" name="include_risky" style="width:auto" ' + (c.include_risky == 1 ? 'checked' : '') + ' ' + (edit ? '' : 'disabled') + '> Also send to “risky” addresses (shared mailboxes like info@). Invalid addresses are never sent to.</label></div>' + (edit ? '<div class="row"><button class="btn">Save settings</button><button class="btn ghost" type="button" id="dz">Design the email</button></div>' : '') + '</form></div>' +
    '<div class="card"><h2 style="margin-bottom:10px">Send</h2><div id="prog"></div><div class="row" id="acts" style="margin-top:12px"></div><div class="muted small" style="margin-top:12px">Emails go out through the sending account in Settings, in small batches. Every message has an unsubscribe link, and people who unsubscribe are never emailed again.</div></div></div>' +
    '<div class="card" id="stc" style="margin-top:16px;display:none"></div><div class="card" style="margin-top:16px"><div class="row"><h2 class="grow">Email preview</h2><span class="chip on" data-pv="600">Desktop</span><span class="chip" data-pv="375">Phone</span></div><div style="margin-top:12px;text-align:center"><iframe id="pvf" sandbox="" style="width:100%;max-width:100%;height:560px;border:1px solid var(--line);border-radius:12px;background:#fff"></iframe></div></div>';
  const paintProg = d => { $('#cst').textContent = CSTAT[d.status] + (d.status === 'scheduled' ? ' · ' + dtLocal(d.scheduled_at) : ''); const p = d.total ? Math.round((d.sent + d.failed) * 100 / d.total) : 0;
    $('#prog').innerHTML = d.total ? '<div style="background:var(--mist);border-radius:99px;height:12px;overflow:hidden"><div style="height:100%;width:' + p + '%;background:var(--cyan)"></div></div><div class="small" style="margin-top:6px"><b>' + d.sent + '</b> sent of ' + d.total + (d.failed ? ' · <span class="overdue">' + d.failed + ' failed</span>' : '') + '</div>' : '<div class="muted small">Not started yet.</div>'; };
  paintProg(c);
  const acts = $('#acts'); const btn = (id, label, cls) => '<button class="btn ' + (cls || '') + '" id="' + id + '">' + label + '</button>';
  acts.innerHTML = btn('ts', 'Send a test', 'ghost') + (['draft', 'paused'].includes(c.status) ? btn('go', c.status === 'paused' ? 'Resume' : 'Send now') + (c.status === 'draft' ? btn('sc', 'Schedule', 'ghost') : '') : '') + (['sending', 'scheduled'].includes(c.status) ? btn('ps', 'Pause', 'ghost') : '') + (c.status !== 'sending' ? btn('dl', 'Delete', 'ghost" style="color:var(--bad)') : '');
  const pv = async w => { try { const r = await capi('preview', { method: 'POST', data: { design: c.design, preheader: c.preheader } }); const f = $('#pvf'); f.style.maxWidth = (w || 600) + 'px'; f.srcdoc = r.html; } catch {} };
  const loadStats = async () => { if (c.status === 'draft') return; try { const d = await capi('campaigns/' + id + '/stats'); const pc = n => d.sent ? Math.round(n * 100 / d.sent) + '%' : '—'; const box = $('#stc'); if (!box) return; box.style.display = '';
    box.innerHTML = '<h2 style="margin-bottom:10px">Results</h2><div class="cards"><div class="card stat"><span class="muted small">Sent</span><b>' + d.sent + '</b></div><div class="card stat"><span class="muted small">Opened</span><b>' + pc(d.opens) + '</b><span class="muted small">' + d.opens + ' people</span></div><div class="card stat"><span class="muted small">Clicked</span><b>' + pc(d.clicks) + '</b><span class="muted small">' + d.clicks + ' people</span></div><div class="card stat"><span class="muted small">Unsubscribed</span><b>' + d.unsubs + '</b></div><div class="card stat ' + (d.failed ? 'bad' : '') + '"><span class="muted small">Failed</span><b>' + d.failed + '</b></div></div>' +
      '<div class="two" style="margin-top:14px"><div><h3 style="margin-bottom:6px">Top links</h3>' + (d.links.length ? d.links.map(l => '<div class="small" style="padding:4px 0;word-break:break-all"><b>' + l.people + '</b> ' + (l.people === 1 ? 'person' : 'people') + ' · ' + esc(l.url) + '</div>').join('') : '<div class="muted small">No clicks yet.</div>') + '</div><div><h3 style="margin-bottom:6px">Recent activity</h3>' + (d.recent.length ? d.recent.map(r => '<div class="small" style="padding:4px 0">' + esc(r.email) + ' <span class="muted">' + ({ open: 'opened', click: 'clicked', unsub: 'unsubscribed' })[r.kind] + ' · ' + esc(dtLocal(r.created_at)) + '</span></div>').join('') : '<div class="muted small">Nothing yet.</div>') + '</div></div><div class="muted small" style="margin-top:10px">Opens are counted when the recipient’s email app loads images. Some apps (Apple Mail privacy protection, Outlook) load them automatically, so open rates can look higher than real reads. Clicks are more reliable.</div>'; } catch {} };
  loadStats();
  pv(600); v.querySelectorAll('[data-pv]').forEach(b => b.onclick = () => { v.querySelectorAll('[data-pv]').forEach(x => x.classList.toggle('on', x === b)); pv(+b.dataset.pv); });
  const collect = () => { const f = Object.fromEntries(new FormData($('#cf'))); f.list_id = +f.list_id || null; f.include_risky = $('#cf').include_risky.checked; return f; };
  if (edit) {
    $('#cf').onsubmit = async e => { e.preventDefault(); try { await capi('campaigns/' + id, { method: 'PATCH', data: collect() }); toast('Saved'); render(); } catch (er) { toast(er.message); } };
    $('#dz').onclick = () => designEditor({ title: 'Design: ' + c.name, design: c.design, onSave: async d => { await capi('campaigns/' + id, { method: 'PATCH', data: { ...collect(), design: d } }); toast('Design saved'); render(); } });
  }
  $('#ts').onclick = () => { const to = prompt('Send a test email to:', S.user.email); if (!to) return; capi('campaigns/' + id + '/test', { method: 'POST', data: { to } }).then(() => toast('Test sent to ' + to)).catch(e => toast(e.message)); };
  const start = async (extra) => { try { if (edit) await capi('campaigns/' + id, { method: 'PATCH', data: collect() }); await capi('campaigns/' + id + '/start', { method: 'POST', data: extra || {} }); toast('Started'); render(); }
    catch (er) { if (er.data && er.data.needs_confirm) { if (confirm(er.data.unchecked + ' contact(s) on this list have not been verified. Send anyway? (Cancel, then use “Verify emails” on the list first.)')) start({ ...extra, skip_verify_warning: 1 }); } else toast(er.message); } };
  const go = $('#go'); if (go) go.onclick = () => { if (confirm('Start sending “' + c.name + '” now?')) start(); };
  const sc = $('#sc'); if (sc) sc.onclick = () => { const t = prompt('Send on (your local time), format YYYY-MM-DD HH:MM', new Date(Date.now() + 36e5).toISOString().slice(0, 10) + ' 09:00'); if (!t || !/^\d{4}-\d\d-\d\d \d\d:\d\d$/.test(t)) { if (t) toast('Use the format 2026-11-05 09:00'); return; } const utc = new Date(t.replace(' ', 'T')).toISOString().slice(0, 19).replace('T', ' '); start({ schedule_at: utc }); };
  const ps = $('#ps'); if (ps) ps.onclick = async () => { await capi('campaigns/' + id + '/pause', { method: 'POST' }); render(); };
  const dl = $('#dl'); if (dl) dl.onclick = async () => { if (!confirm('Delete this campaign? This cannot be undone.')) return; try { await capi('campaigns/' + id, { method: 'DELETE' }); location.hash = '#/campaigns'; } catch (er) { toast(er.message); } };
  if (c.status === 'sending') CP.timer = setInterval(async () => { if (!$('#prog')) { clearInterval(CP.timer); CP.timer = 0; return; } try { const r = await capi('tick', { method: 'POST' }); const me = (r.campaigns || []).find(x => x.id === id); loadStats(); if (me) { paintProg({ ...c, ...me }); } else { clearInterval(CP.timer); CP.timer = 0; render(); } } catch {} }, 6000);
}

/* ---- lists tab ---- */
async function campTabLists() {
  const rows = await capi('lists');
  $('#cpb').innerHTML = '<div class="row" style="margin-bottom:12px"><button class="btn" id="nl">+ New list</button></div>' + (rows.length ? '<div class="cards">' + rows.map(l => '<a class="card" href="#/campaigns/l/' + l.id + '" style="color:inherit;display:block"><h3>' + esc(l.name) + '</h3><div class="muted small">' + esc(l.description || '') + '</div><div style="margin-top:10px"><b style="font-size:24px">' + (+l.subscribed || 0) + '</b> <span class="muted small">subscribed of ' + l.total + '</span></div><div class="small muted" style="margin-top:6px">✓ ' + (+l.v_valid || 0) + ' valid · ⚠ ' + (+l.v_risky || 0) + ' risky · ✕ ' + (+l.v_invalid || 0) + ' invalid · ' + (+l.v_unchecked || 0) + ' unchecked</div></a>').join('') + '</div>' : '<div class="empty">No lists yet. A list is a group of people you email together.</div>');
  $('#nl').onclick = () => { const m = modal('<h2>New list</h2><form id="lf" style="margin-top:12px"><div class="field"><label>List name</label><input name="name" required maxlength="120" placeholder="e.g. Clients, Newsletter, Event attendees"></div><div class="field"><label>Description (optional)</label><input name="description" maxlength="300"></div><div class="row"><button class="btn">Create list</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close(); $('#lf', m).onsubmit = async e => { e.preventDefault(); const r = await capi('lists', { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); location.hash = '#/campaigns/l/' + r.id; }; };
}
let CL = { q: '', status: '', verify: '', page: 1 };
async function campList(id) {
  const v = $('#view'); const lists = await capi('lists'); const l = lists.find(x => x.id === id); if (!l) { location.hash = '#/campaigns'; return; }
  v.innerHTML = '<div class="row" style="margin-bottom:6px"><a href="#/campaigns" class="small" id="back">‹ Lists</a></div><div class="top"><div><h1>' + esc(l.name) + '</h1><div class="muted">' + l.subscribed + ' subscribed · ' + l.total + ' total · ✓ ' + (+l.v_valid || 0) + ' valid · ⚠ ' + (+l.v_risky || 0) + ' risky · ✕ ' + (+l.v_invalid || 0) + ' invalid · ' + (+l.v_unchecked || 0) + ' not checked</div></div></div>' +
    '<div class="row" style="margin-bottom:12px"><button class="btn sm" id="ac">+ Add contact</button><button class="btn ghost sm" id="im">Import CSV</button><button class="btn ghost sm" id="pp">Add from Pipeline</button><button class="btn ghost sm" id="vf">Verify emails</button><button class="btn ghost sm" id="rm">Remove invalid &amp; bounced</button><button class="btn ghost sm right" id="dl" style="color:var(--bad)">Delete list</button></div>' +
    '<div class="row" style="margin-bottom:12px"><input id="lq" placeholder="Search…" value="' + esc(CL.q) + '" style="max-width:240px"><select id="ls" style="width:auto">' + opts([['subscribed', 'Subscribed'], ['unsubscribed', 'Unsubscribed'], ['bounced', 'Bounced']], CL.status, 'All statuses') + '</select><select id="lv" style="width:auto">' + opts([['valid', 'Valid'], ['risky', 'Risky'], ['invalid', 'Invalid'], ['unchecked', 'Not checked']], CL.verify, 'All verification') + '</select></div><div class="card"><div class="tablewrap" id="lt"><div class="empty">Loading…</div></div><div class="row" id="lp" style="justify-content:center;padding:8px"></div></div>';
  const load = async () => {
    const qs = 'page=' + CL.page + (CL.q ? '&q=' + encodeURIComponent(CL.q) : '') + (CL.status ? '&status=' + CL.status : '') + (CL.verify ? '&verify=' + CL.verify : '');
    const d = await capi('lists/' + id + '/contacts?' + qs);
    $('#lt').innerHTML = d.contacts.length ? '<table><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Status</th><th>Verification</th><th></th></tr></thead><tbody>' + d.contacts.map(c => '<tr><td>' + esc(c.email) + '</td><td>' + esc([c.first_name, c.last_name].filter(Boolean).join(' ')) + '</td><td>' + esc(c.company || '') + '</td><td><span class="tag ' + (c.status === 'subscribed' ? 'done' : '') + '">' + c.status + '</span></td><td><span class="tag ' + VBADGE[c.verify][1] + '" title="' + esc(c.verify_note || '') + '">' + VBADGE[c.verify][0] + '</span>' + (c.verify_note && c.verify !== 'valid' ? '<div class="muted small">' + esc(c.verify_note) + '</div>' : '') + '</td><td><a href="#" data-rm="' + c.id + '" style="color:var(--bad)">Remove</a></td></tr>').join('') + '</tbody></table>' : '<div class="empty">No contacts here yet.</div>';
    const pages = Math.max(1, Math.ceil(d.total / d.per)); $('#lp').innerHTML = pages > 1 ? '<button class="btn ghost sm" id="pg1" ' + (CL.page <= 1 ? 'disabled' : '') + '>‹</button><span class="muted small">' + CL.page + ' / ' + pages + ' · ' + d.total + '</span><button class="btn ghost sm" id="pg2" ' + (CL.page >= pages ? 'disabled' : '') + '>›</button>' : '<span class="muted small">' + d.total + ' contact' + (d.total === 1 ? '' : 's') + '</span>';
    const a = $('#pg1'), b = $('#pg2'); if (a) a.onclick = () => { CL.page--; load(); }; if (b) b.onclick = () => { CL.page++; load(); };
    v.querySelectorAll('[data-rm]').forEach(x => x.onclick = async e => { e.preventDefault(); await capi('contact/' + x.dataset.rm, { method: 'DELETE' }); load(); });
  };
  $('#back').onclick = () => { CP.tab = 'lists'; ls.set('cptab', 'lists'); };
  let tm; $('#lq').oninput = e => { CL.q = e.target.value.trim(); CL.page = 1; clearTimeout(tm); tm = setTimeout(load, 300); }; $('#ls').onchange = e => { CL.status = e.target.value; CL.page = 1; load(); }; $('#lv').onchange = e => { CL.verify = e.target.value; CL.page = 1; load(); };
  $('#ac').onclick = () => { const m = modal('<h2>Add a contact</h2><form id="af" style="margin-top:12px"><div class="field"><label>Email</label><input type="email" name="email" required></div><div class="grid2"><div class="field"><label>First name</label><input name="first_name"></div><div class="field"><label>Last name</label><input name="last_name"></div></div><div class="field"><label>Company</label><input name="company"></div><div class="err" id="er"></div><div class="row"><button class="btn">Add</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close(); $('#af', m).onsubmit = async e => { e.preventDefault(); try { const r = await capi('lists/' + id + '/add', { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); toast(r.result === 'exists' ? 'Already on the list' : 'Added'); m.close(); render(); } catch (er) { $('#er', m).textContent = er.message; } }; };
  $('#im').onclick = () => campImport(id);
  $('#pp').onclick = () => { const m = modal('<h2>Add from Pipeline</h2><p class="muted small" style="margin:8px 0 12px">Copies Pipeline contacts that have an email address into this list. Existing people are skipped.</p><div class="field"><label>Only companies at stage</label><select id="st">' + opts(Object.entries(SL), '', 'All stages') + '</select></div><div class="row"><button class="btn" id="go">Add contacts</button><button class="btn ghost" id="cx">Cancel</button></div>');
    $('#cx', m).onclick = () => m.close(); $('#go', m).onclick = async () => { const r = await capi('lists/' + id + '/from_pipeline', { method: 'POST', data: { stage: $('#st', m).value } }); toast(r.added + ' added, ' + r.exists + ' already there'); m.close(); render(); }; };
  $('#vf').onclick = async () => { const b = $('#vf'); b.disabled = true; try { let left = 1, total = 0; while (left > 0) { const r = await capi('lists/' + id + '/verify', { method: 'POST', data: {} }); total += r.checked; left = r.remaining; b.textContent = 'Verifying… ' + total; if (!r.checked) break; } toast('Checked ' + total + ' address' + (total === 1 ? '' : 'es')); } catch (er) { toast(er.message); } render(); };
  $('#rm').onclick = async () => { if (!confirm('Remove every contact marked invalid or bounced from this list?')) return; const r = await capi('lists/' + id + '/remove_invalid', { method: 'POST', data: {} }); toast(r.removed + ' removed'); render(); };
  $('#dl').onclick = async () => { if (!confirm('Delete this list and all its contacts?')) return; try { await capi('lists/' + id, { method: 'DELETE' }); location.hash = '#/campaigns'; } catch (er) { toast(er.message); } };
  load();
}
function campImport(id) {
  const m = modal('<h2>Import contacts</h2><p class="muted small" style="margin:8px 0 12px">Upload a CSV (Excel → Save As → CSV) or paste it. The first row must be headings: <b>email</b> is required; <b>first name</b>, <b>last name</b> (or <b>name</b>) and <b>company</b> are optional.</p><input type="file" id="fl" accept=".csv,text/csv,text/plain"><textarea id="tx" placeholder="email,first name,last name,company" style="min-height:120px;margin-top:10px"></textarea><div class="muted small" id="pv" style="margin:8px 0"></div><div class="row"><button class="btn" id="go">Import</button><button class="btn ghost" id="cx">Cancel</button></div>');
  const parse = () => { const rows = csvParse($('#tx', m).value); if (rows.length < 2) return []; const h = rows[0].map(x => x.trim().toLowerCase().replace(/[\s_-]+/g, ''));
    const ix = names => h.findIndex(x => names.includes(x)), e = ix(['email', 'emailaddress', 'mail']), fn = ix(['firstname', 'first', 'givenname']), ln = ix(['lastname', 'last', 'surname', 'familyname']), nm = ix(['name', 'fullname', 'contact']), co = ix(['company', 'organisation', 'organization', 'companyname']);
    if (e < 0) return []; return rows.slice(1).map(r => { let f = fn >= 0 ? r[fn] : '', l = ln >= 0 ? r[ln] : ''; if (!f && !l && nm >= 0) { const p = (r[nm] || '').trim().split(/\s+/); f = p.shift() || ''; l = p.join(' '); } return { email: (r[e] || '').trim(), first_name: (f || '').trim(), last_name: (l || '').trim(), company: co >= 0 ? (r[co] || '').trim() : '' }; }).filter(r => r.email); };
  const upd = () => { const r = parse(); $('#pv', m).textContent = r.length ? r.length + ' contact' + (r.length === 1 ? '' : 's') + ' found' : ($('#tx', m).value.trim() ? 'No email column found. Check the first row.' : ''); };
  $('#fl', m).onchange = e => { const f = e.target.files[0]; if (!f) return; const r = new FileReader(); r.onload = () => { $('#tx', m).value = String(r.result).replace(/^\uFEFF/, ''); upd(); }; r.readAsText(f); };
  $('#tx', m).oninput = upd; $('#cx', m).onclick = () => m.close();
  $('#go', m).onclick = async () => { const rows = parse(); if (!rows.length) { toast('Nothing to import'); return; } try { const r = await capi('lists/' + id + '/import', { method: 'POST', data: { rows } }); toast(r.added + ' added · ' + r.exists + ' already there · ' + r.bad + ' invalid'); m.close(); render(); } catch (er) { toast(er.message); } };
}

/* ---- templates tab ---- */
async function campTabTemplates() {
  const rows = await capi('templates');
  $('#cpb').innerHTML = '<div class="row" style="margin-bottom:12px"><button class="btn" id="nt">+ New template</button></div>' + (rows.length ? '<div class="cards">' + rows.map(t => '<div class="card"><h3>' + esc(t.name) + '</h3><div class="muted small">' + esc(t.subject || 'No default subject') + '</div><div class="row" style="margin-top:12px"><button class="btn sm" data-e="' + t.id + '">Edit</button><button class="btn ghost sm" data-d="' + t.id + '" style="color:var(--bad)">Delete</button></div></div>').join('') + '</div>' : '<div class="empty">No templates yet. Design one once, reuse it for every campaign.</div>');
  const editT = async id => { const t = id ? await capi('templates/' + id) : { name: '', subject: '', design: null };
    designEditor({ title: id ? 'Template: ' + t.name : 'New template', design: t.design, withName: true, name: t.name, subject: t.subject, onSave: async (d, name, subject) => { if (!name) throw new Error('Give the template a name.'); await capi(id ? 'templates/' + id : 'templates', { method: id ? 'PATCH' : 'POST', data: { name, subject, design: d } }); toast('Template saved'); render(); } }); };
  $('#nt').onclick = () => editT(0);
  $('#cpb').querySelectorAll('[data-e]').forEach(b => b.onclick = () => editT(+b.dataset.e));
  $('#cpb').querySelectorAll('[data-d]').forEach(b => b.onclick = async () => { if (confirm('Delete this template? Campaigns already made from it are not affected.')) { await capi('templates/' + b.dataset.d, { method: 'DELETE' }); render(); } });
}

/* ---- the email designer ---- */
const BLOCKS = { header: 'Header', heading: 'Heading', text: 'Text', image: 'Image', button: 'Button', divider: 'Divider', spacer: 'Spacer', columns: 'Two columns' };
function newBlock(t) { return ({ header: { type: 'header', title: 'AEROSUB', logo: '' }, heading: { type: 'heading', text: 'A heading', align: 'left' }, text: { type: 'text', text: 'Write your text here.', align: 'left' }, image: { type: 'image', src: '', alt: '', link: '', size: 100, align: 'center' }, button: { type: 'button', text: 'Click here', url: 'https://', align: 'left', color: '' }, divider: { type: 'divider' }, spacer: { type: 'spacer', h: 24 }, columns: { type: 'columns', left: 'Left column text', right: 'Right column text' } })[t]; }
function designEditor(o) {
  const D = JSON.parse(JSON.stringify(o.design || { bg: '#EEF2F8', width: 600, accent: '#00A3DE', navy: '#0C1F61', blocks: [{ type: 'header', title: 'AEROSUB', logo: '' }, { type: 'heading', text: 'Hello {{first_name|there}},', align: 'left' }, { type: 'text', text: 'Write your message here. Blank lines make new paragraphs. Greet people by name with a merge field such as first name (see the tip under the blocks).', align: 'left' }, { type: 'button', text: 'Learn more', url: 'https://aerosub.co', align: 'left', color: '' }, { type: 'divider' }, { type: 'text', text: 'Best regards,\nThe Aerosub team', align: 'left' }] }));
  const m = modal('<div class="row"><h2 class="grow">' + esc(o.title || 'Design email') + '</h2><button class="btn ghost sm" id="dx">✕</button></div>' + (o.withName ? '<div class="grid2" style="margin:10px 0"><div class="field"><label>Template name</label><input id="tn" value="' + esc(o.name || '') + '" maxlength="120"></div><div class="field"><label>Default subject (optional)</label><input id="ts2" value="' + esc(o.subject || '') + '" maxlength="200"></div></div>' : '') +
    '<div class="dz"><div class="dz-l"><div class="row" style="margin-bottom:8px"><label class="small muted">Accent <input type="color" id="ca" value="' + D.accent + '" style="width:40px;padding:0;vertical-align:middle"></label><label class="small muted">Background <input type="color" id="cb" value="' + D.bg + '" style="width:40px;padding:0;vertical-align:middle"></label></div><div id="bl"></div><div class="row" style="margin:10px 0;gap:6px">' + Object.entries(BLOCKS).filter(([k]) => k !== 'header').map(([k, l]) => '<button type="button" class="btn ghost sm" data-add="' + k + '">+ ' + l + '</button>').join('') + '</div><div class="muted small">Merge fields: {{first_name}}, {{last_name}}, {{company}}, {{email}}. Add a fallback with {{first_name|there}}.</div></div>' +
    '<div class="dz-r"><div class="row" style="margin-bottom:8px"><span class="chip on" data-w="600">Desktop</span><span class="chip" data-w="375">Phone</span><span class="muted small right" id="pvs"></span></div><iframe id="dzf" sandbox="" style="width:100%;height:68vh;border:1px solid var(--line);border-radius:12px;background:#fff;display:block;margin:0 auto"></iframe></div></div><div class="err" id="er"></div><div class="row" style="margin-top:12px"><button class="btn" id="sv">Save</button><button class="btn ghost" id="cl">Close</button></div>');
  m.querySelector('.modal').style.cssText += ';max-width:96vw;width:1180px';
  let tm; const preview = () => { clearTimeout(tm); tm = setTimeout(async () => { try { const r = await capi('preview', { method: 'POST', data: { design: D } }); $('#dzf', m).srcdoc = r.html; $('#pvs', m).textContent = ''; } catch (e) { $('#pvs', m).textContent = e.message; } }, 350); };
  const fld = (b, k, label, ta, ph) => '<div class="field"><label class="small muted">' + label + '</label>' + (ta ? '<textarea data-k="' + k + '" style="min-height:' + (ta === 2 ? 60 : 90) + 'px">' + esc(b[k] || '') + '</textarea>' : '<input data-k="' + k + '" value="' + esc(b[k] || '') + '" placeholder="' + esc(ph || '') + '">') + '</div>';
  const alignSel = b => '<div class="field"><label class="small muted">Align</label><select data-k="align">' + opts([['left', 'Left'], ['center', 'Centre'], ['right', 'Right']], b.align || 'left') + '</select></div>';
  const paint = () => {
    $('#bl', m).innerHTML = D.blocks.map((b, i) => '<div class="card" style="padding:10px;margin-bottom:8px" data-i="' + i + '"><div class="row" style="margin-bottom:6px"><b class="grow small">' + BLOCKS[b.type] + '</b><a href="#" data-mv="-1">▲</a><a href="#" data-mv="1">▼</a><a href="#" data-del="1" style="color:var(--bad)">✕</a></div>' + ({
      header: fld(b, 'title', 'Brand text') + '<div class="field"><label class="small muted">Logo image (optional)</label><div class="row"><input data-k="logo" value="' + esc(b.logo || '') + '" placeholder="https://…" style="flex:1"><label class="btn ghost sm" style="cursor:pointer;margin:0">Upload<input type="file" accept="image/*" data-up="logo" hidden></label></div></div>',
      heading: fld(b, 'text', 'Heading') + alignSel(b), text: fld(b, 'text', 'Text', 1) + alignSel(b),
      image: '<div class="field"><label class="small muted">Image</label><div class="row"><input data-k="src" value="' + esc(b.src || '') + '" placeholder="https://… or upload" style="flex:1"><label class="btn ghost sm" style="cursor:pointer;margin:0">Upload<input type="file" accept="image/*" data-up="src" hidden></label></div></div>' + fld(b, 'alt', 'Description (for screen readers)') + fld(b, 'link', 'Link when clicked (optional)', 0, 'https://') + '<div class="field"><label class="small muted">Width ' + (b.size || 100) + '%</label><input type="range" min="20" max="100" step="5" data-k="size" value="' + (b.size || 100) + '"></div>' + alignSel(b),
      button: fld(b, 'text', 'Button text') + fld(b, 'url', 'Link', 0, 'https://') + alignSel(b) + '<div class="field"><label class="small muted">Colour (blank = accent)</label><input type="color" data-k="color" value="' + (b.color || D.accent) + '" style="width:48px;padding:0"></div>',
      divider: '<div class="muted small">A thin line.</div>', spacer: '<div class="field"><label class="small muted">Height ' + (b.h || 24) + 'px</label><input type="range" min="8" max="80" step="4" data-k="h" value="' + (b.h || 24) + '"></div>',
      columns: fld(b, 'left', 'Left column', 1) + fld(b, 'right', 'Right column', 1) }[b.type] || '') + '</div>').join('');
    $('#bl', m).querySelectorAll('[data-i]').forEach(card => {
      const i = +card.dataset.i, b = D.blocks[i];
      card.querySelectorAll('[data-k]').forEach(inp => { inp.oninput = inp.onchange = () => { b[inp.dataset.k] = (inp.type === 'range') ? +inp.value : inp.value; if (inp.type === 'range') { const lab = inp.previousElementSibling; lab.textContent = lab.textContent.replace(/\d+(%|px)/, inp.value + (b.type === 'spacer' ? 'px' : '%')); } preview(); }; });
      card.querySelectorAll('[data-up]').forEach(f => f.onchange = async () => { const file = f.files[0]; if (!file) return; try { const data = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(file); }); if (file.size > 1.4e6) throw new Error('Picture too large (max 1.5 MB)'); const r = await capi('images', { method: 'POST', data: { data, name: file.name } }); b[f.dataset.up] = r.url; paint(); preview(); } catch (er) { toast(er.message); } });
      card.querySelectorAll('[data-mv]').forEach(a => a.onclick = e => { e.preventDefault(); const j = i + +a.dataset.mv; if (j < 0 || j >= D.blocks.length) return; [D.blocks[i], D.blocks[j]] = [D.blocks[j], D.blocks[i]]; paint(); preview(); });
      card.querySelector('[data-del]').onclick = e => { e.preventDefault(); D.blocks.splice(i, 1); paint(); preview(); };
    });
  };
  m.querySelectorAll('[data-add]').forEach(b => b.onclick = () => { D.blocks.push(newBlock(b.dataset.add)); paint(); preview(); setTimeout(() => { const l = $('#bl', m); l.scrollTop = l.scrollHeight; }, 50); });
  $('#ca', m).oninput = e => { D.accent = e.target.value; preview(); }; $('#cb', m).oninput = e => { D.bg = e.target.value; preview(); };
  m.querySelectorAll('[data-w]').forEach(c => c.onclick = () => { m.querySelectorAll('[data-w]').forEach(x => x.classList.toggle('on', x === c)); $('#dzf', m).style.width = c.dataset.w === '375' ? '375px' : '100%'; });
  $('#dx', m).onclick = $('#cl', m).onclick = () => m.close();
  $('#sv', m).onclick = async () => { const b = $('#sv', m); b.disabled = true; try { await o.onSave(D, o.withName ? $('#tn', m).value.trim() : undefined, o.withName ? $('#ts2', m).value.trim() : undefined); m.close(); } catch (er) { $('#er', m).textContent = er.message; b.disabled = false; } };
  paint(); preview();
}

/* ---- auto-responders ---- */
async function campTabAutos() {
  const [rows, lists] = await Promise.all([capi('autos'), capi('lists')]);
  $('#cpb').innerHTML = '<div class="row" style="margin-bottom:12px"><button class="btn" id="na">+ New auto-responder</button></div><p class="muted small" style="margin-bottom:12px">An auto-responder is a series of emails sent automatically to people who join a list (for example a welcome email, then a follow-up 3 days later).</p><div class="card"><div class="tablewrap">' + (rows.length ? '<table><thead><tr><th>Name</th><th>Status</th><th>List</th><th>Emails</th><th>People</th><th>Sent</th></tr></thead><tbody>' + rows.map(a => '<tr data-a="' + a.id + '" style="cursor:pointer"><td><b>' + esc(a.name) + '</b></td><td><span class="tag ' + (a.status === 'active' ? 'done' : '') + '">' + ({ draft: 'Draft', active: 'Running', paused: 'Paused' })[a.status] + '</span></td><td>' + esc(a.list_name || '—') + '</td><td>' + a.steps + '</td><td>' + a.active + ' active · ' + a.finished + ' finished</td><td>' + a.sent + '</td></tr>').join('') + '</tbody></table>' : '<div class="empty">No auto-responders yet.</div>') + '</div></div>';
  $('#cpb').querySelectorAll('[data-a]').forEach(r => r.onclick = () => location.hash = '#/campaigns/a/' + r.dataset.a);
  $('#na').onclick = () => { const m = modal('<h2>New auto-responder</h2><form id="af" style="margin-top:12px"><div class="field"><label>Name</label><input name="name" required maxlength="140" placeholder="e.g. Welcome series"></div><div class="field"><label>Send to people who join this list</label><select name="list_id">' + opts(lists.map(l => [l.id, l.name]), '', '— choose —') + '</select></div><div class="row"><button class="btn">Create</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close(); $('#af', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.list_id = +f.list_id || null; const r = await capi('autos', { method: 'POST', data: f }); m.close(); location.hash = '#/campaigns/a/' + r.id; }; };
}
async function campAuto(id) {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  const [a, lists] = await Promise.all([capi('autos/' + id), capi('lists')]);
  const act = a.status === 'active', cn = a.counts || {};
  const dly = h => h % 24 === 0 && h > 0 ? [h / 24, 'days'] : [h, 'hours'];
  v.innerHTML = '<div class="row" style="margin-bottom:6px"><a href="#/campaigns" class="small" id="bk">‹ Auto-responders</a></div><div class="top"><div><h1>' + esc(a.name) + '</h1><span class="tag ' + (act ? 'done' : '') + '">' + ({ draft: 'Draft', active: 'Running', paused: 'Paused' })[a.status] + '</span></div></div>' +
    '<div class="two"><div class="card"><h2 style="margin-bottom:10px">Settings</h2><form id="sf"><div class="field"><label>Name</label><input name="name" value="' + esc(a.name) + '" maxlength="140"></div><div class="field"><label>People who join this list get the emails</label><select name="list_id">' + opts(lists.map(l => [l.id, l.name + ' (' + l.subscribed + ')']), a.list_id, '— choose a list —') + '</select></div><div class="grid2"><div class="field"><label>From name</label><input name="from_name" value="' + esc(a.from_name || '') + '" placeholder="default"></div><div class="field"><label>From email</label><input name="from_email" value="' + esc(a.from_email || '') + '" placeholder="default"></div></div><button class="btn">Save settings</button></form></div>' +
    '<div class="card"><h2 style="margin-bottom:10px">Status</h2><div class="cards" style="margin-bottom:12px"><div class="card stat"><span class="muted small">In the series</span><b>' + (cn.active || 0) + '</b></div><div class="card stat"><span class="muted small">Finished</span><b>' + (cn.finished || 0) + '</b></div><div class="card stat"><span class="muted small">Stopped</span><b>' + (cn.stopped || 0) + '</b></div></div><div class="row">' + (act ? '<button class="btn ghost" id="ps">Pause</button>' : '<button class="btn" id="go">' + (a.status === 'paused' ? 'Resume' : 'Start auto-responder') + '</button>') + '<button class="btn ghost" id="dl" style="color:var(--bad)">Delete</button></div><div class="muted small" style="margin-top:12px">New people who join the list start at email 1. People who unsubscribe, bounce or have an invalid address are stopped automatically. Emails are sent by the same scheduled task as campaigns, so timing is accurate to within a few minutes.</div></div></div>' +
    '<h2 style="margin:20px 0 10px">Emails in the series</h2><div id="steps"></div>' + (act ? '<div class="muted small">Pause the auto-responder to add, remove or reorder emails. You can still edit the content of existing emails.</div>' : '<button class="btn ghost" id="as">+ Add an email</button>');
  $('#bk').onclick = () => { CP.tab = 'autos'; ls.set('cptab', 'autos'); };
  $('#steps').innerHTML = a.steps.map((st, i) => { const [n, u] = dly(st.delay_hours);
    return '<div class="card" style="margin-bottom:12px" data-s="' + st.id + '"><div class="row"><h3 class="grow">Email ' + st.position + '</h3>' + (act ? '' : '<a href="#" data-mv="-1">▲</a><a href="#" data-mv="1">▼</a><a href="#" data-rmv="1" style="color:var(--bad)">Remove</a>') + '</div>' +
      '<div class="row" style="margin:8px 0"><span class="small muted">Send</span><input type="number" min="0" data-f="dn" value="' + n + '" style="width:90px"><select data-f="du" style="width:auto">' + opts([['hours', 'hours'], ['days', 'days']], u) + '</select><span class="small muted">' + (i === 0 ? 'after the person joins the list' : 'after the previous email') + '</span></div>' +
      '<div class="grid2"><div class="field"><label>Subject</label><input data-f="subject" value="' + esc(st.subject) + '" maxlength="200"></div><div class="field"><label>Preview text</label><input data-f="preheader" value="' + esc(st.preheader || '') + '" maxlength="200"></div></div>' +
      '<div class="row"><button class="btn sm" data-sv="1">Save</button><button class="btn ghost sm" data-dz="1">Design the email</button><button class="btn ghost sm" data-ts="1">Send a test</button><span class="small muted right">Sent ' + st.sent + ' · opened ' + st.opens + ' · clicked ' + st.clicks + '</span></div></div>'; }).join('');
  const body = card => { const g = k => card.querySelector('[data-f=' + k + ']').value; const n = Math.max(0, +g('dn') || 0), hours = g('du') === 'days' ? n * 24 : n; return { delay_hours: hours, subject: g('subject'), preheader: g('preheader') }; };
  v.querySelectorAll('[data-s]').forEach(card => { const sid = card.dataset.s, st = a.steps.find(x => x.id == sid);
    card.querySelector('[data-sv]').onclick = async () => { try { await capi('autos/' + id + '/steps/' + sid, { method: 'PATCH', data: body(card) }); toast('Saved'); } catch (er) { toast(er.message); } };
    card.querySelector('[data-dz]').onclick = () => designEditor({ title: 'Design: email ' + st.position, design: st.design, onSave: async d => { await capi('autos/' + id + '/steps/' + sid, { method: 'PATCH', data: { ...body(card), design: d } }); toast('Design saved'); render(); } });
    card.querySelector('[data-ts]').onclick = () => { const to = prompt('Send a test email to:', S.user.email); if (to) capi('autos/' + id + '/steps/' + sid + '/test', { method: 'POST', data: { to } }).then(() => toast('Test sent')).catch(e => toast(e.message)); };
    card.querySelectorAll('[data-mv]').forEach(x => x.onclick = async e => { e.preventDefault(); await capi('autos/' + id + '/steps/' + sid, { method: 'PATCH', data: { ...body(card), move: +x.dataset.mv } }); render(); });
    const rv = card.querySelector('[data-rmv]'); if (rv) rv.onclick = async e => { e.preventDefault(); if (confirm('Remove this email from the series?')) { try { await capi('autos/' + id + '/steps/' + sid, { method: 'DELETE' }); render(); } catch (er) { toast(er.message); } } };
  });
  $('#sf').onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.list_id = +f.list_id || null; try { await capi('autos/' + id, { method: 'PATCH', data: f }); toast('Saved'); render(); } catch (er) { toast(er.message); } };
  const as = $('#as'); if (as) as.onclick = async () => { await capi('autos/' + id + '/steps', { method: 'POST', data: {} }); render(); };
  const go = $('#go'); if (go) go.onclick = async () => { const inc = a.status === 'draft' ? confirm('Also send this series to the people who are ALREADY on the list?\n\nOK = yes, include them\nCancel = only people who join from now on') : false; if (!confirm('Start “' + a.name + '” now?')) return; try { await capi('autos/' + id + '/activate', { method: 'POST', data: { include_existing: inc } }); toast('Auto-responder is running'); render(); } catch (er) { toast(er.message); } };
  const ps = $('#ps'); if (ps) ps.onclick = async () => { await capi('autos/' + id + '/pause', { method: 'POST' }); render(); };
  $('#dl').onclick = async () => { if (!confirm('Delete this auto-responder?')) return; await capi('autos/' + id, { method: 'DELETE' }); location.hash = '#/campaigns'; };
}

/* ---- sending settings ---- */
async function campTabSettings() {
  const st = await capi('settings');
  $('#cpb').innerHTML = '<div class="two"><div class="card"><h2 style="margin-bottom:6px">Sending account</h2><p class="muted small" style="margin-bottom:12px">Bulk email should go through an email-sending service, not your normal mailboxes. Create a free account with one (for example Brevo, Amazon SES or Mailgun), then paste its SMTP details here. The password is stored encrypted and never shown again.</p><form id="sf">' +
    '<div class="grid2"><div class="field"><label>SMTP server</label><input name="smtp_host" value="' + esc(st.smtp_host || '') + '" placeholder="smtp-relay.brevo.com" required></div><div class="field"><label>Port</label><select name="smtp_port">' + opts([[587, '587 (recommended)'], [465, '465 (SSL)'], [2525, '2525']], st.smtp_port) + '</select></div></div>' +
    '<div class="grid2"><div class="field"><label>SMTP username</label><input name="smtp_user" value="' + esc(st.smtp_user || '') + '" autocomplete="off"></div><div class="field"><label>SMTP password / key</label><input type="password" name="smtp_pass" placeholder="' + (st.has_secret ? '•••••••• (saved; leave blank to keep)' : '') + '" autocomplete="new-password"></div></div>' +
    '<div class="grid2"><div class="field"><label>From email</label><input type="email" name="from_email" value="' + esc(st.from_email || '') + '" placeholder="news@aerosub.co" required></div><div class="field"><label>From name</label><input name="from_name" value="' + esc(st.from_name || '') + '" placeholder="Aerosub"></div></div>' +
    '<div class="field"><label>Reply-to email (optional)</label><input type="email" name="reply_to" value="' + esc(st.reply_to || '') + '" placeholder="marketing@aerosub.co"></div><div class="field"><label>Postal address shown in the footer (recommended by anti-spam laws)</label><input name="address" value="' + esc(st.address || '') + '" maxlength="300" placeholder="Aerosub, street, city, country"></div>' +
    '<div class="grid2"><div class="field"><label>Emails per batch</label><input type="number" name="batch_size" min="10" max="500" value="' + st.batch_size + '"></div><div class="field"><label>Maximum per hour</label><input type="number" name="hour_cap" min="20" max="5000" value="' + st.hour_cap + '"></div></div>' +
    '<div class="field"><label>This site’s address (for unsubscribe links)</label><input name="app_url" value="' + esc(st.app_url || '') + '" placeholder="' + esc(st.base) + '"></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="tt">Send a test email to me</button></div></form></div>' +
    '<div class="card"><h2 style="margin-bottom:8px">Before your first send</h2><div class="small" style="line-height:1.7">1. In your sending service, <b>verify the domain</b> aerosub.co and add the DNS records it gives you: <b>SPF</b>, <b>DKIM</b> and a <b>DMARC</b> record. Without these, bulk mail lands in spam.<br>2. Use a From address on that domain (for example news@aerosub.co).<br>3. Only email people who agreed to hear from you or have a clear business relationship. Unsubscribes are applied automatically and permanently.<br>4. Run <b>Verify emails</b> on each list first to catch typos and dead addresses.<br>5. Start with a small list and send yourself a test.<br><br><b>Common SMTP servers:</b><br>Brevo: smtp-relay.brevo.com, port 587<br>Amazon SES: email-smtp.&lt;region&gt;.amazonaws.com, port 587<br>Mailgun: smtp.mailgun.org, port 587<br><br><b>Sending speed:</b> a batch is sent every time the scheduled task runs (every 30 minutes by default) and also while you watch a campaign page. Lower “maximum per hour” to match your provider’s limit.</div></div></div>';
  $('#sf').onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.smtp_port = +f.smtp_port; f.batch_size = +f.batch_size; f.hour_cap = +f.hour_cap; try { const r = await capi('settings', { method: 'POST', data: f }); toast(r.has_secret ? 'Saved. The password / key is stored (the box clears on purpose).' : 'Saved, but NO password / key is stored yet. The server received ' + (r.pass_received || 0) + ' characters in the password box (a real key is 60+). Paste your SMTP key and save again.'); if (r.pass_received) console.log('SMTP key received by the server:', r.pass_received, 'characters'); render(); } catch (er) { $('#er').textContent = er.message; } };
  $('#tt').onclick = async () => { $('#er').textContent = ''; try { await capi('settings/test', { method: 'POST', data: { to: S.user.email } }); toast('Test email sent to ' + S.user.email); } catch (er) { $('#er').textContent = er.message + ' (save your settings first)'; } };
}

/* ================= MAIL ================= */
const M = { accs: [], accId: 0, pendingTo: '', acc: null, folders: [], folder: 'INBOX', page: 1, q: '', unread: false, list: null, msg: null, loadImg: false, pane: 'list', busy: false };
const mapi = (path, opt) => { opt = opt || {}; if ((opt.method || 'GET') === 'GET') path += (path.includes('?') ? '&' : '?') + 'acc=' + M.accId; else opt = { ...opt, data: { ...(opt.data || {}), acc: M.accId } }; return api(path, opt); };
const mAddr = a => (a && (a.name || a.email)) || '';
const mFull = a => a.name ? a.name + ' <' + a.email + '>' : a.email;
function mDate(d) { if (!d) return ''; const x = new Date(d.replace(' ', 'T')), n = new Date(); if (x.toDateString() === n.toDateString()) return x.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); return x.toLocaleDateString(undefined, x.getFullYear() === n.getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: '2-digit' }); }
function linkify(t) { return esc(t).replace(/\b(https?:\/\/[^\s<>"']+)/g, u => '<a href="' + u + '" target="_blank" rel="noopener noreferrer nofollow">' + u + '</a>'); }
const mRole = r => (M.folders.find(f => f.role === r) || {}).name;
const mCurRole = () => (M.folders.find(f => f.name === M.folder) || {}).role || '';
const FICON = { inbox: '📥', drafts: '📝', sent: '📤', archive: '🗄', junk: '🚫', trash: '🗑' };

async function mailLoadAccs() {
  const r = await api('mail/accounts'); M.accs = r.accounts; M.defs = r.defaults; M.userName = r.user_name; S.mail = M.accs.length > 0;
  if (!M.accs.find(a => a.id === M.accId)) M.accId = M.accs.length ? M.accs[0].id : 0;
  M.acc = M.accs.find(a => a.id === M.accId) || null;
}
views.mail = async () => {
  const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
  await mailLoadAccs();
  if (!M.accs.length) return mailConnect(v);
  v.innerHTML = '<div class="mail" id="mail" data-pane="' + M.pane + '"><aside class="m-folders card" id="mf"></aside><section class="m-list card" id="ml"></section><section class="m-read card" id="mr"><div class="empty">Select a message to read it.</div></section></div>';
  await mailFolders(); await mailList();
  if (M.msg) mailRead();
  if (M.pendingTo) { const t = M.pendingTo; M.pendingTo = ''; compose({ to: t }); }
};
function emailTo(addr) { if (!S.mail) { toast('Connect your mailbox first'); location.hash = '#/mail'; return; } M.pendingTo = addr; if (location.hash.startsWith('#/mail')) { render(); } else location.hash = '#/mail'; }
document.addEventListener('click', e => { const a = e.target.closest('[data-mail]'); if (a) { e.preventDefault(); e.stopPropagation(); emailTo(a.dataset.mail); } });
function mailPane(p) { M.pane = p; const m = $('#mail'); if (m) m.dataset.pane = p; }

function connectForm(onDone, first) {
  const d = M.defs;
  return '<form id="mc"><div class="field"><label>Mailbox email</label><input type="email" name="email" placeholder="you@aerosub.co" required autocomplete="off"></div><div class="field"><label>Mailbox password</label><input type="password" name="password" required autocomplete="new-password"></div><div class="field"><label>Your name on emails you send</label><input name="from_name" value="' + esc(M.userName || '') + '" maxlength="120"><div class="muted small">People who receive your mail see this name. If a colleague also uses this mailbox, they can use their own name.</div></div>' +
    '<details style="margin-bottom:12px"><summary class="small muted" style="cursor:pointer">Server settings (usually leave as they are)</summary><div class="grid2" style="margin-top:10px"><div class="field"><label>Incoming (IMAP) server</label><input name="imap_host" value="' + esc(d.imap_host) + '"></div><div class="field"><label>Port</label><input name="imap_port" value="993" readonly></div><div class="field"><label>Outgoing (SMTP) server</label><input name="smtp_host" value="' + esc(d.smtp_host) + '"></div><div class="field"><label>Port</label><select name="smtp_port"><option>465</option><option>587</option></select></div></div></details><div class="err" id="er"></div><button class="btn">Connect mailbox</button></form>';
}
function wireConnect(root, onDone) {
  $('#mc', root).onsubmit = async e => { e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true; b.textContent = 'Checking…'; const f = Object.fromEntries(new FormData(e.target)); f.imap_port = +f.imap_port; f.smtp_port = +f.smtp_port;
    try { const r = await api('mail/account', { method: 'POST', data: f }); M.accId = r.id; M.folder = 'INBOX'; M.msg = null; toast('Mailbox connected'); onDone(); } catch (er) { $('#er', root).textContent = er.message; b.disabled = false; b.textContent = 'Connect mailbox'; } };
}
function mailConnect(v) {
  v.innerHTML = '<div class="top"><h1>Mail</h1></div><div class="card" style="max-width:520px"><h2>Connect your work mailbox</h2><p class="muted small" style="margin:8px 0 14px">Read and send email from your official address without leaving Aerosub Office. Your mailbox password is stored encrypted on your own server and used only to open your mailbox and send your messages. No one else in the app can see your mail. You can connect more mailboxes later, including shared ones like marketing@.</p>' + connectForm() + '</div>';
  wireConnect(v, () => render());
}

async function mailFolders() {
  if (!$('#mf')) return;
  try { M.folders = await mapi('mail/folders'); } catch (e) { if ($('#mf')) $('#mf').innerHTML = '<div class="empty small">' + esc(e.message) + '</div>'; return; }
  if (!$('#mf')) return;
  const inbox = M.folders.find(f => f.role === 'inbox'); S.mailUnread = inbox ? (inbox.unseen || 0) : 0; if (M.accs.length > 1) api('mail/unread').then(u => { S.mailUnread = u.unseen; mailBadge(); }).catch(() => {});
  $('#mf').innerHTML = (M.accs.length > 1 ? '<select id="macc" style="width:100%;margin-bottom:10px">' + M.accs.map(a => '<option value="' + a.id + '"' + (a.id === M.accId ? ' selected' : '') + '>' + esc(a.email) + '</option>').join('') + '</select>' : '') + '<button class="btn" id="mcomp" style="width:100%;margin-bottom:12px">✎ Compose</button>' + M.folders.map(f => '<a href="#" class="m-f ' + (f.name === M.folder ? 'on' : '') + '" data-f="' + esc(f.name) + '"><span>' + (FICON[f.role] || '📁') + '</span><span class="grow">' + esc(f.role === 'inbox' ? 'Inbox' : f.name) + '</span>' + (f.unseen ? '<span class="badge" style="margin-left:auto">' + f.unseen + '</span>' : '') + '</a>').join('') +
    '<div class="muted small" style="margin:14px 4px 4px;word-break:break-all">' + esc(M.acc.email) + '<br>Sending as <b>' + esc(M.acc.from_name) + '</b></div><a href="#" id="msig" class="small" style="margin-left:4px;display:block">Signatures</a><a href="#" id="mset" class="small" style="margin-left:4px;display:block;margin-top:4px">Mailboxes &amp; settings</a>';
  $('#mcomp').onclick = () => compose();
  $('#mf').querySelectorAll('[data-f]').forEach(a => a.onclick = e => { e.preventDefault(); M.folder = a.dataset.f; M.page = 1; M.msg = null; M.q = ''; mailPane('list'); mailFolders(); mailList(); $('#mr').innerHTML = '<div class="empty">Select a message to read it.</div>'; });
  $('#mset').onclick = e => { e.preventDefault(); mailSettings('boxes'); };
  $('#msig').onclick = e => { e.preventDefault(); mailSettings('sig'); };
  const sw = $('#macc'); if (sw) sw.onchange = () => { M.accId = +sw.value; M.acc = M.accs.find(a => a.id === M.accId); M.folder = 'INBOX'; M.page = 1; M.msg = null; M.q = ''; mailPane('list'); mailFolders(); mailList(); $('#mr').innerHTML = '<div class="empty">Select a message to read it.</div>'; };
  mailBadge();
  function mailBadge() { document.querySelectorAll('a[href="#/mail"]').forEach(l => { let nb = l.querySelector('.badge'); if (S.mailUnread) { if (!nb) { nb = document.createElement('span'); nb.className = 'badge'; l.appendChild(nb); } nb.textContent = S.mailUnread; } else if (nb) nb.remove(); }); }
}

async function mailList() {
  const el = $('#ml'); if (!el) return;
  el.innerHTML = '<div class="m-tools row"><button class="btn ghost sm m-only" id="mbf">☰</button><input id="mq" placeholder="Search this folder…" value="' + esc(M.q) + '" style="flex:1;min-width:0"><span class="chip ' + (M.unread ? 'on' : '') + '" id="mun">Unread</span><button class="btn ghost sm" id="mrf" title="Refresh">↻</button></div><div id="mrows" class="list"><div class="empty">Loading…</div></div><div id="mpg" class="row" style="justify-content:center;padding:8px"></div>';
  $('#mbf').onclick = () => mailPane('folders');
  let tm; $('#mq').oninput = e => { M.q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(() => { M.page = 1; mailRows(); }, 450); };
  $('#mun').onclick = () => { M.unread = !M.unread; M.page = 1; $('#mun').classList.toggle('on', M.unread); mailRows(); };
  $('#mrf').onclick = () => { mailFolders(); mailRows(); };
  await mailRows();
}
async function mailRows() {
  const rows = $('#mrows'); if (!rows) return;
  const qs = 'folder=' + encodeURIComponent(M.folder) + '&page=' + M.page + (M.q ? '&q=' + encodeURIComponent(M.q) : '') + (M.unread ? '&unread=1' : '');
  let d; try { d = await mapi('mail/messages?' + qs); } catch (e) { rows.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; return; }
  M.list = d; const sentView = ['sent', 'drafts'].includes(mCurRole());
  rows.innerHTML = d.messages.length ? d.messages.map(m => '<div class="item m-row ' + (m.seen ? '' : 'unread') + (M.msg && M.msg.uid === m.uid ? ' sel' : '') + '" data-u="' + m.uid + '"><span class="dot ' + (m.seen ? '' : 'on') + '"></span><div class="grow" style="min-width:0"><div class="row" style="flex-wrap:nowrap"><span class="m-from">' + esc(sentView ? (m.to.map(mAddr).join(', ') || '(no recipient)') : (mAddr(m.from[0]) || '(unknown)')) + '</span><span class="muted small right">' + (m.has_att ? '📎 ' : '') + (m.flagged ? '★ ' : '') + esc(mDate(m.date)) + '</span></div><div class="m-subj">' + esc(m.subject || '(no subject)') + '</div></div></div>').join('') : '<div class="empty">' + (M.q ? 'No messages match.' : 'This folder is empty.') + '</div>';
  rows.querySelectorAll('.m-row').forEach(r => r.onclick = () => mailOpen(+r.dataset.u));
  const pages = Math.max(1, Math.ceil(d.total / d.per));
  $('#mpg').innerHTML = pages > 1 ? '<button class="btn ghost sm" id="mp" ' + (M.page <= 1 ? 'disabled' : '') + '>‹ Newer</button><span class="muted small">' + M.page + ' / ' + pages + ' · ' + d.total + ' messages</span><button class="btn ghost sm" id="mn" ' + (M.page >= pages ? 'disabled' : '') + '>Older ›</button>' : '<span class="muted small">' + d.total + ' message' + (d.total === 1 ? '' : 's') + '</span>';
  const p = $('#mp'), n = $('#mn'); if (p) p.onclick = () => { M.page--; mailRows(); }; if (n) n.onclick = () => { M.page++; mailRows(); };
}

async function mailOpen(uid) {
  mailPane('read'); const r = $('#mr'); r.innerHTML = '<div class="empty">Opening…</div>'; M.loadImg = false;
  try { M.msg = await mapi('mail/message?folder=' + encodeURIComponent(M.folder) + '&uid=' + uid); } catch (e) { r.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; return; }
  const row = $('#mrows .m-row[data-u="' + uid + '"]'); if (row) { row.classList.remove('unread'); row.classList.add('sel'); const dt = row.querySelector('.dot'); if (dt) dt.classList.remove('on'); }
  mailRead(); mailFolders();
}
function frameDoc(html, loadImg) {
  const csp = "default-src 'none'; img-src data:" + (loadImg ? ' https: http:' : '') + "; style-src 'unsafe-inline'; font-src data:";
  return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' + csp + '"><base target="_blank"><style>body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#111;background:#fff;margin:12px;word-wrap:break-word}img{max-width:100%;height:auto}table{max-width:100%}a{color:#0a6fb8}blockquote{border-left:3px solid #ccd;margin:8px 0;padding-left:10px;color:#444}</style></head><body>' + html + '</body></html>';
}
function mailRead() {
  const m = M.msg, r = $('#mr'); if (!m) return;
  const role = mCurRole(), moveOpts = M.folders.filter(f => f.name !== M.folder).map(f => [f.name, (f.role === 'inbox' ? 'Inbox' : f.name)]);
  r.innerHTML = '<div class="m-tools row"><button class="btn ghost sm m-only" id="mback">‹ Back</button>' + (role === 'drafts' ? '<button class="btn sm" id="medit">Continue editing</button>' : '<button class="btn sm" id="mrep">Reply</button><button class="btn ghost sm" id="mra">Reply all</button><button class="btn ghost sm" id="mfw">Forward</button><button class="btn ghost sm" id="mtask" title="Turn this email into a task (e.g. an RFQ)">➕ Add to task</button>') +
    '<span class="right row" style="gap:6px"><button class="btn ghost sm" id="mun2" title="Mark as unread">Unread</button><button class="btn ghost sm" id="mst" title="Star">' + '☆' + '</button><select id="mmv" style="width:auto;padding:5px 8px"><option value="">Move to…</option>' + opts(moveOpts) + '</select><button class="btn ghost sm" id="mdel" style="color:var(--bad)">' + (['trash', 'junk'].includes(role) ? 'Delete forever' : 'Delete') + '</button></span></div>' +
    '<div class="m-head"><h2 style="margin-bottom:6px">' + esc(m.subject || '(no subject)') + '</h2><div class="small"><b>' + esc(m.from.map(mFull).join(', ')) + '</b></div><div class="muted small">To: ' + esc(m.to.map(mFull).join(', ')) + (m.cc.length ? ' · Cc: ' + esc(m.cc.map(mFull).join(', ')) : '') + '</div><div class="muted small">' + esc(m.date ? new Date(m.date.replace(' ', 'T')).toLocaleString() : '') + '</div></div>' +
    (m.attachments.length ? '<div class="row" style="margin:10px 0">' + m.attachments.map(a => '<button class="chip" data-att="' + a.i + '">📎 ' + esc(a.name) + ' <span class="muted small">' + Math.max(1, Math.round(a.size / 1024)) + ' KB</span></button>').join('') + '</div>' : '') +
    (m.html && m.remote_images && !M.loadImg ? '<div class="small" style="background:var(--mist);border-radius:10px;padding:8px 12px;margin:10px 0">Images from the internet are blocked to protect your privacy. <a href="#" id="mimg">Load images</a></div>' : '') +
    '<div class="m-body" id="mbody"></div>';
  const body = $('#mbody', r);
  if (m.html) { const f = document.createElement('iframe'); f.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox'); f.setAttribute('referrerpolicy', 'no-referrer'); f.className = 'm-frame'; f.srcdoc = frameDoc(m.html, M.loadImg); body.appendChild(f); }
  else body.innerHTML = '<div style="white-space:pre-wrap;word-wrap:break-word">' + linkify(m.text) + '</div>';
  const q = id => $(id, r);
  q('#mback').onclick = () => { mailPane('list'); }; const mtk = q('#mtask'); if (mtk) mtk.onclick = mailToTask;
  const img = q('#mimg'); if (img) img.onclick = e => { e.preventDefault(); M.loadImg = true; mailRead(); };
  r.querySelectorAll('[data-att]').forEach(b => b.onclick = () => mailDownload(+b.dataset.att, m.attachments.find(a => a.i == b.dataset.att).name));
  if (q('#mrep')) { q('#mrep').onclick = () => compose({ mode: 'reply' }); q('#mra').onclick = () => compose({ mode: 'all' }); q('#mfw').onclick = () => compose({ mode: 'fwd' }); }
  if (q('#medit')) q('#medit').onclick = () => compose({ mode: 'draft' });
  q('#mun2').onclick = async () => { await mapi('mail/flag', { method: 'POST', data: { folder: M.folder, uid: m.uid, seen: false } }); toast('Marked as unread'); mailFolders(); mailRows(); };
  q('#mst').onclick = async () => { await mapi('mail/flag', { method: 'POST', data: { folder: M.folder, uid: m.uid, flagged: true } }); toast('Starred'); mailRows(); };
  q('#mmv').onchange = async e => { if (!e.target.value) return; try { await mapi('mail/move', { method: 'POST', data: { folder: M.folder, uid: m.uid, to: e.target.value } }); M.msg = null; $('#mr').innerHTML = '<div class="empty">Moved.</div>'; mailPane('list'); mailFolders(); mailRows(); } catch (er) { toast(er.message); } };
  q('#mdel').onclick = async () => { if (['trash', 'junk'].includes(role) && !confirm('Delete this message permanently? This cannot be undone.')) return; try { await mapi('mail/delete', { method: 'POST', data: { folder: M.folder, uid: m.uid } }); M.msg = null; $('#mr').innerHTML = '<div class="empty">Deleted.</div>'; mailPane('list'); mailFolders(); mailRows(); } catch (er) { toast(er.message); } };
}
async function mailDownload(i, name) {
  try {
    const r = await fetch('api/mail/attachment?acc=' + M.accId + '&folder=' + encodeURIComponent(M.folder) + '&uid=' + M.msg.uid + '&i=' + i, { credentials: 'same-origin' });
    if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || 'Download failed');
    const b = await r.blob(), u = URL.createObjectURL(b), a = document.createElement('a'); a.href = u; a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 4000);
  } catch (e) { toast(e.message); }
}
function sigEditorBar() { return '<div class="row" style="gap:4px;margin-bottom:6px"><button type="button" class="btn ghost sm" data-cmd="bold"><b>B</b></button><button type="button" class="btn ghost sm" data-cmd="italic"><i>I</i></button><button type="button" class="btn ghost sm" data-cmd="underline"><u>U</u></button><button type="button" class="btn ghost sm" data-cmd="insertUnorderedList">• List</button><button type="button" class="btn ghost sm" data-link="1">Link</button><button type="button" class="btn ghost sm" data-photo="1">Add my photo</button></div>'; }
function wireEditorBar(root) {
  root.querySelectorAll('[data-cmd]').forEach(b => b.onmousedown = e => { e.preventDefault(); document.execCommand(b.dataset.cmd, false, null); });
  root.querySelectorAll('[data-photo]').forEach(b => b.onmousedown = e => { e.preventDefault(); if (!S.avv) { toast('Upload your photo first in My profile'); return; } document.execCommand('insertHTML', false, '<img src="api/avatar/' + S.user.id + '" width="72" height="72" alt="">'); });
  root.querySelectorAll('[data-link]').forEach(b => b.onmousedown = e => { e.preventDefault(); const u = prompt('Link address (https://…)'); if (u && /^https?:\/\//i.test(u)) document.execCommand('createLink', false, u); });
}
function mailSettings(tab) {
  const m = modal('<div class="row"><h2 class="grow">Mail settings</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="row" style="margin:10px 0"><span class="chip" data-tab="boxes">Mailboxes</span><span class="chip" data-tab="sig">Signatures</span></div><div id="mtab"></div>');
  $('#cx', m).onclick = () => m.close();
  const done = async () => { await mailLoadAccs(); m.close(); render(); };
  const boxes = () => {
    $('#mtab', m).innerHTML = '<p class="muted small" style="margin-bottom:10px">Connect as many mailboxes as you need. Shared mailboxes (like marketing@) can be connected by several people at once. Each person sends with their own name, so everyone can tell who sent what.</p>' +
      M.accs.map(a => '<div class="item" style="flex-wrap:wrap;gap:8px" data-a="' + a.id + '"><div class="grow" style="min-width:200px"><b>' + esc(a.email) + '</b><div class="field" style="margin:6px 0 0"><label class="small muted">Your name when sending from this mailbox</label><input class="fn" value="' + esc(a.from_name) + '" maxlength="120"></div></div><div class="row"><button class="btn sm sv">Save name</button><button class="btn ghost sm dc" style="color:var(--bad)">Disconnect</button></div></div>').join('') +
      '<details style="margin-top:14px" ' + (M.accs.length ? '' : 'open') + '><summary class="btn ghost sm" style="display:inline-block;cursor:pointer">+ Add another mailbox</summary><div style="margin-top:12px">' + connectForm() + '</div></details>';
    wireConnect($('#mtab', m), done);
    m.querySelectorAll('[data-a]').forEach(el => {
      const id = +el.dataset.a, a = M.accs.find(x => x.id === id);
      el.querySelector('.sv').onclick = async () => { try { await api('mail/account', { method: 'POST', data: { id, email: a.email, from_name: el.querySelector('.fn').value } }); toast('Saved'); await mailLoadAccs(); } catch (er) { toast(er.message); } };
      el.querySelector('.dc').onclick = async () => { if (!confirm('Disconnect ' + a.email + ' from Aerosub Office? Emails stay on the mail server; only your saved password is removed.')) return; await api('mail/account?id=' + id, { method: 'DELETE' }); if (M.accId === id) { M.accId = 0; M.msg = null; } await done(); };
    });
  };
  const sig = () => {
    if (!M.accs.length) { $('#mtab', m).innerHTML = '<div class="empty">Connect a mailbox first.</div>'; return; }
    $('#mtab', m).innerHTML = '<p class="muted small" style="margin-bottom:10px">A signature belongs to one mailbox. It is added to the bottom of new messages and replies sent from that mailbox, and you can still edit it per message.</p><div class="field"><label>Mailbox</label><select id="sa">' + M.accs.map(a => '<option value="' + a.id + '"' + (a.id === M.accId ? ' selected' : '') + '>' + esc(a.email) + '</option>').join('') + '</select></div>' + sigEditorBar() + '<div id="se" class="m-editor" contenteditable="true" style="min-height:130px"></div><div class="row" style="margin-top:12px"><button class="btn" id="ss">Save signature</button></div>';
    const load = () => { const a = M.accs.find(x => x.id === +$('#sa', m).value); $('#se', m).innerHTML = a.signature_html || ''; };
    wireEditorBar($('#mtab', m)); $('#sa', m).onchange = load; load();
    $('#ss', m).onclick = async () => { const a = M.accs.find(x => x.id === +$('#sa', m).value); try { await api('mail/account', { method: 'POST', data: { id: a.id, email: a.email, signature_html: $('#se', m).innerHTML } }); await mailLoadAccs(); toast('Signature saved'); } catch (er) { toast(er.message); } };
  };
  const show = t => { m.querySelectorAll('[data-tab]').forEach(c => c.classList.toggle('on', c.dataset.tab === t)); (t === 'sig' ? sig : boxes)(); };
  m.querySelectorAll('[data-tab]').forEach(c => c.onclick = () => show(c.dataset.tab)); show(tab || 'boxes');
}

function pickContacts(cb) {
  const m = modal('<div class="row"><h2 class="grow">Add contacts</h2><button class="btn ghost sm" id="pcx">✕</button></div><input id="pq" placeholder="Search by name, company or email…" style="margin:10px 0"><div id="pl" class="list" style="max-height:48vh;overflow:auto"><div class="empty">Loading…</div></div><div class="row" style="margin-top:12px"><span class="muted small grow" id="pn">0 selected</span><button class="btn sm" data-to="to">Add to To</button><button class="btn ghost sm" data-to="cc">Cc</button><button class="btn ghost sm" data-to="bcc">Bcc</button></div>');
  const sel = new Map(); let rows = [];
  const paint = () => { $('#pl', m).innerHTML = rows.length ? rows.map((r, i) => '<label class="item" style="cursor:pointer;gap:10px"><input type="checkbox" data-i="' + i + '" ' + (sel.has(r.email.toLowerCase()) ? 'checked' : '') + ' style="width:auto"><div class="grow" style="min-width:0"><b>' + esc(r.name || r.email) + '</b> <span class="chip" style="font-size:11px">' + (r.kind === 'team' ? 'Team' : 'Contact') + '</span><div class="muted small">' + esc([r.company, r.email].filter(Boolean).join(' · ')) + '</div></div></label>').join('') : '<div class="empty">No contacts with an email address found.</div>';
    $('#pl', m).querySelectorAll('[data-i]').forEach(c => c.onchange = () => { const r = rows[+c.dataset.i], k = r.email.toLowerCase(); c.checked ? sel.set(k, r) : sel.delete(k); $('#pn', m).textContent = sel.size + ' selected'; }); };
  let tm; const load = async () => { try { rows = await api('mail/suggest?list=1&q=' + encodeURIComponent($('#pq', m).value.trim())); paint(); } catch (e) { $('#pl', m).innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; } };
  $('#pq', m).oninput = () => { clearTimeout(tm); tm = setTimeout(load, 250); }; load();
  $('#pcx', m).onclick = () => m.close();
  m.querySelectorAll('[data-to]').forEach(b => b.onclick = () => { if (!sel.size) { toast('Tick at least one contact'); return; } cb(b.dataset.to, [...sel.values()]); m.close(); });
}

function compose(o) {
  o = o || {}; const m = M.msg; let to = '', cc = '', subj = '', body = '';
  const quote = () => '<br><br><div>On ' + esc(m.date ? new Date(m.date.replace(' ', 'T')).toLocaleString() : '') + ', ' + esc(m.from.map(mFull).join(', ')) + ' wrote:</div><blockquote>' + esc(m.text).replace(/\n/g, '<br>') + '</blockquote>';
  if (o.mode === 'reply' || o.mode === 'all') {
    const rt = (m.reply_to && m.reply_to.length ? m.reply_to : m.from); to = rt.map(mFull).join(', '); subj = /^re:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject; body = quote();
    if (o.mode === 'all') cc = [...m.to, ...m.cc].filter(a => a.email.toLowerCase() !== M.acc.email.toLowerCase() && !rt.some(r => r.email === a.email)).map(mFull).join(', ');
  } else if (o.mode === 'fwd') { subj = /^fwd?:/i.test(m.subject) ? m.subject : 'Fwd: ' + m.subject; body = '<br><br><div>---------- Forwarded message ----------<br>From: ' + esc(m.from.map(mFull).join(', ')) + '<br>Subject: ' + esc(m.subject) + '</div><blockquote>' + esc(m.text).replace(/\n/g, '<br>') + '</blockquote>'; }
  else if (o.mode === 'draft') { to = m.to.map(mFull).join(', '); cc = m.cc.map(mFull).join(', '); subj = m.subject; body = esc(m.text).replace(/\n/g, '<br>'); }
  if (o.to) to = o.to; if (o.subject) subj = o.subject;
  let fromId = M.accId; const sigBlock = id => { const sg = (M.accs.find(a => a.id == id) || {}).signature_html || ''; return sg ? '<div data-sig="1">-- <br>' + sg + '</div>' : ''; };
  if (o.html) body = o.html + '<br>' + sigBlock(fromId); else if (o.mode !== 'draft') body = (o.mode ? '<br>' : '<br><br>') + sigBlock(fromId) + body;
  const files = (o.files || []).slice();
  const mod = modal('<div class="row"><h2 class="grow">New message</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="cf3" style="margin-top:10px">' + (M.accs.length > 1 ? '<div class="field"><label>From</label><select id="cfrom">' + M.accs.map(a => '<option value="' + a.id + '"' + (a.id === fromId ? ' selected' : '') + '>' + esc(a.from_name + ' <' + a.email + '>') + '</option>').join('') + '</select></div>' : '<div class="muted small" style="margin-bottom:8px">From: <b>' + esc(M.acc.from_name + ' <' + M.acc.email + '>') + '</b></div>') + '<div class="field"><label>To · <a href="#" id="cpk">Add from contacts</a></label><input name="to" list="mdl" value="' + esc(to) + '" autocomplete="off" required placeholder="name@company.com, another@company.com"><datalist id="mdl"></datalist></div><div class="grid2"><div class="field"><label>Cc</label><input name="cc" value="' + esc(cc) + '"></div><div class="field"><label>Bcc</label><input name="bcc"></div></div><div class="field"><label>Subject</label><input name="subject" value="' + esc(subj) + '" maxlength="300"></div>' +
    '<div class="row" style="gap:4px;margin-bottom:6px"><button type="button" class="btn ghost sm" data-cmd="bold"><b>B</b></button><button type="button" class="btn ghost sm" data-cmd="italic"><i>I</i></button><button type="button" class="btn ghost sm" data-cmd="underline"><u>U</u></button><button type="button" class="btn ghost sm" data-cmd="insertUnorderedList">• List</button><button type="button" class="btn ghost sm" id="clink">Link</button></div><div id="ed" class="m-editor" contenteditable="true">' + body + '</div>' +
    '<div class="row" style="margin:10px 0"><label class="btn ghost sm" style="cursor:pointer;margin:0">📎 Attach files<input type="file" id="fl" multiple hidden></label><span id="fls" class="row small muted"></span></div><div class="err" id="er"></div><div class="row"><button class="btn" id="snd">Send</button><button class="btn ghost" type="button" id="sdr">Save draft</button><span class="muted small right">Sent as ' + esc(M.acc.from_name) + '</span></div></form>');
  const ed = $('#ed', mod); ed.focus();
  const fs = $('#cfrom', mod); if (fs) fs.onchange = () => { const old = ed.querySelector('[data-sig]'), nb = sigBlock(+fs.value); fromId = +fs.value; if (old) { if (nb) { const t = document.createElement('div'); t.innerHTML = nb; old.replaceWith(t.firstChild); } else old.remove(); } else if (nb) ed.insertAdjacentHTML('beforeend', '<br>' + nb); };
  $('#cpk', mod).onclick = e => { e.preventDefault(); pickContacts((field, list) => { const inp = $('[name=' + field + ']', mod); const have = inp.value.toLowerCase(); const add = list.filter(r => !have.includes(r.email.toLowerCase())).map(r => r.name && r.name !== r.email ? r.name.replace(/[<>",]/g, '') + ' <' + r.email + '>' : r.email); inp.value = [inp.value.trim().replace(/[,;]\s*$/, ''), ...add].filter(Boolean).join(', '); }); };
  const sel = window.getSelection(); if (o.mode !== 'draft') { const r = document.createRange(); r.setStart(ed, 0); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }
  mod.querySelectorAll('[data-cmd]').forEach(b => b.onmousedown = e => { e.preventDefault(); document.execCommand(b.dataset.cmd, false, null); });
  $('#clink', mod).onmousedown = e => { e.preventDefault(); const u = prompt('Link address (https://…)'); if (u && /^https?:\/\//i.test(u)) document.execCommand('createLink', false, u); };
  $('#cx', mod).onclick = () => { if (!ed.innerText.trim() || confirm('Discard this message?')) mod.close(); };
  let st; $('[name=to]', mod).oninput = e => { const last = e.target.value.split(/[,;]/).pop().trim(); clearTimeout(st); if (last.length < 2) return; st = setTimeout(async () => { try { const s = await api('mail/suggest?q=' + encodeURIComponent(last)); const pre = e.target.value.slice(0, e.target.value.length - e.target.value.split(/[,;]/).pop().length); $('#mdl', mod).innerHTML = s.map(x => '<option value="' + esc(pre + (pre && !/\s$/.test(pre) ? ' ' : '') + (x.name ? x.name + ' <' + x.email + '>' : x.email)) + '"></option>').join(''); } catch {} }, 250); };
  const paintFiles = () => { $('#fls', mod).innerHTML = files.map((f, i) => '<span class="chip">' + esc(f.name) + ' <a href="#" data-rm="' + i + '" style="color:var(--bad)">✕</a></span>').join(''); $('#fls', mod).querySelectorAll('[data-rm]').forEach(a => a.onclick = e => { e.preventDefault(); files.splice(+a.dataset.rm, 1); paintFiles(); }); };
  paintFiles();
  $('#fl', mod).onchange = async e => {
    for (const f of e.target.files) {
      const tot = files.reduce((n, x) => n + x.size, 0) + f.size; if (tot > 8e6) { toast('Attachments are limited to about 8 MB in total'); break; }
      const data = await new Promise(res => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1] || ''); r.readAsDataURL(f); });
      files.push({ name: f.name, type: f.type || 'application/octet-stream', size: f.size, data });
    } e.target.value = ''; paintFiles();
  };
  const send = async draft => {
    const f = Object.fromEntries(new FormData($('#cf3', mod))); const b = $(draft ? '#sdr' : '#snd', mod); b.disabled = true; $('#er', mod).textContent = '';
    try {
      const r = await api('mail/send', { method: 'POST', data: { ...f, acc: fromId, html: ed.innerHTML, draft, attachments: files.map(x => ({ name: x.name, type: x.type, data: x.data })), in_reply_folder: (o.mode === 'reply' || o.mode === 'all') && fromId === M.accId ? M.folder : '', in_reply_uid: (o.mode === 'reply' || o.mode === 'all') && fromId === M.accId ? m.uid : 0 } });
      if (r.queued) { b.disabled = false; return; }
      if (o.mode === 'draft' && !draft) { try { await mapi('mail/delete', { method: 'POST', data: { folder: M.folder, uid: m.uid } }); } catch {} }
      toast(draft ? 'Draft saved' : (r.warning || 'Message sent')); mod.close(); mailFolders(); if ($('#mrows')) mailRows();
    } catch (er) { $('#er', mod).textContent = er.message; b.disabled = false; }
  };
  $('#cf3', mod).onsubmit = e => { e.preventDefault(); send(false); }; $('#sdr', mod).onclick = () => send(true);
}

/* ---------- login & first-run password ---------- */
function inviteForm() {
  const m = modal('<h2>Invite by link</h2><p class="muted small" style="margin:6px 0 12px">Enter their email. You get a personal link that lets them create their own account and choose their own password. The link works once and expires in 7 days.</p><form id="inf"><div class="field"><label>Their email</label><input type="email" name="email" required autofocus></div><div class="grid2"><div class="field"><label>Role</label><select name="role">' + opts(Object.entries(ROLE), 'employee') + '</select></div><div class="field"><label>Job title (optional)</label><input name="job_title" maxlength="120"></div></div>' +
    '<div class="field"><label>Departments</label><div class="chips" id="dpk">' + S.depts.map(d => '<label class="chip" style="cursor:pointer"><input type="checkbox" value="' + d.id + '" style="width:auto;margin-right:6px">' + esc(d.name) + '</label>').join('') + '</div></div><div class="err" id="er"></div><div class="row"><button class="btn">Create link</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
  $('#cx', m).onclick = () => m.close();
  $('#inf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.department_ids = [...m.querySelectorAll('#dpk input:checked')].map(i => +i.value);
    try { const r = await api('invites', { method: 'POST', data: f }); const subj = 'Your Aerosub Office invitation', body = 'Hi,\n\nYou have been invited to join Aerosub Office. Use this personal link to create your account (it works once and expires in ' + r.expires_days + ' days):\n\n' + r.link + '\n\nSee you inside.';
      m.querySelector('.modal').innerHTML = '<h2>Link ready for ' + esc(r.email) + '</h2><p class="muted small" style="margin:6px 0 10px">Send it to them by email or message. Only this person can use it, and only once.</p><input id="lk" readonly value="' + esc(r.link) + '" data-select="1"><div class="row" style="margin-top:12px;flex-wrap:wrap"><button class="btn" id="cp">Copy link</button><a class="btn ghost" href="mailto:' + encodeURIComponent(r.email) + '?subject=' + encodeURIComponent(subj) + '&body=' + encodeURIComponent(body) + '">Open in my email app</a><button class="btn ghost" id="dn">Done</button></div>';
      $('#cp', m).onclick = async () => { try { await navigator.clipboard.writeText(r.link); toast('Link copied'); } catch { $('#lk', m).select(); document.execCommand('copy'); toast('Link copied'); } };
      $('#dn', m).onclick = () => { m.close(); render(); }; } catch (er) { $('#er', m).textContent = er.message; } };
}
/* Show / hide eye on every password box inside `root`. */
const EYE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
function eyes(root) {
  (root || document).querySelectorAll('input[type=password]').forEach(inp => {
    if (inp.parentElement.classList.contains('pw')) return;
    const w = document.createElement('span'); w.className = 'pw'; inp.parentNode.insertBefore(w, inp); w.appendChild(inp);
    const b = document.createElement('button'); b.type = 'button'; b.className = 'pw-eye'; b.setAttribute('aria-label', 'Show password'); b.setAttribute('aria-pressed', 'false'); b.innerHTML = EYE;
    b.onclick = () => { const show = inp.type === 'password'; inp.type = show ? 'text' : 'password'; b.setAttribute('aria-pressed', String(show)); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); b.innerHTML = show ? EYE_OFF : EYE; inp.focus(); };
    w.appendChild(b);
  });
}
async function joinView(tok) {
  let inv; try { inv = await api('invite/' + tok); } catch (e) { $('#root').innerHTML = '<div class="gate"><div class="gatecard"><div class="brand">AEROSUB<small>Office</small></div><p style="margin:20px 0">' + esc(e.message) + '</p><a class="btn" href="#/" style="display:block;text-align:center">Go to sign in</a></div></div>'; return; }
  $('#root').innerHTML = '<div class="gate"><form class="gatecard" id="jf"><div class="brand">AEROSUB<small>Office · create your account</small></div><p class="muted small" style="margin:16px 0">You have been invited as <b>' + esc(inv.role) + '</b>. Your sign-in email is <b>' + esc(inv.email) + '</b>.</p><div class="field"><label>Your full name</label><input name="name" required maxlength="120" autofocus></div><div class="field"><label>Choose a password (10+ characters)</label><input type="password" name="password" minlength="10" autocomplete="new-password" required></div><div class="err" id="er"></div><button class="btn" style="width:100%">Create my account</button></form></div>';
  eyes($('#jf'));
  $('#jf').onsubmit = async e => { e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true; try { const r = await api('invite/' + tok, { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); S.user = r.user; S.csrf = r.csrf; history.replaceState(null, '', '#/home'); await boot(true); } catch (er) { $('#er').textContent = er.message; b.disabled = false; } };
}
function loginView() {
  $('#root').innerHTML = '<div class="gate"><form class="gatecard" id="lf"><div class="brand">AEROSUB<small>Office · team sign in</small></div><div class="field" style="margin-top:22px"><label>Email</label><input type="email" name="email" autocomplete="username" required></div>' +
    '<div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><div class="err" id="er"></div><button class="btn" style="width:100%">Sign in</button><div style="text-align:center;margin-top:14px"><a href="#" id="fg" class="small">Forgot your password?</a></div></form></div>';
  eyes($('#lf')); $('#fg').onclick = e => { e.preventDefault(); forgotView($('[name=email]', $('#lf')).value); };
  $('#lf').onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); const b = e.target.querySelector('button'); b.disabled = true;
    try { const r = await api('login', { method: 'POST', data: f }); S.user = r.user; S.csrf = r.csrf; await boot(true); } catch (er) { $('#er').textContent = er.message; b.disabled = false; } };
}
function forgotView(email) {
  $('#root').innerHTML = '<div class="gate"><form class="gatecard" id="ff"><div class="brand">AEROSUB<small>Office · forgotten password</small></div><p class="muted small" style="margin:16px 0">Enter the email you sign in with. We will email you a link to choose a new password, and let your administrator know in case they need to reset it for you.</p><div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" required value="' + esc(email || '') + '"></div><div class="err" id="er"></div><div id="ok" class="small" style="color:var(--ok);margin-bottom:10px" role="status"></div><button class="btn" style="width:100%">Send recovery link</button><div style="text-align:center;margin-top:14px"><a href="#" id="bk" class="small">‹ Back to sign in</a></div></form></div>';
  $('#bk').onclick = e => { e.preventDefault(); loginView(); };
  $('#ff').onsubmit = async e => { e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true; $('#er').textContent = '';
    try { const r = await api('forgot', { method: 'POST', data: { email: new FormData(e.target).get('email') } }); $('#ok').textContent = r.message || 'Check your email.'; b.textContent = 'Sent'; }
    catch (er) { $('#er').textContent = er.message; b.disabled = false; } };
}
async function resetView(tok) {
  const card = inner => $('#root').innerHTML = '<div class="gate"><div class="gatecard"><div class="brand">AEROSUB<small>Office · choose a new password</small></div>' + inner + '</div></div>';
  let info; try { info = await api('reset/' + tok); } catch (e) { card('<p style="margin:20px 0">' + esc(e.message) + '</p><a class="btn" href="#/" id="gl" style="display:block;text-align:center">Back to sign in</a>'); $('#gl').onclick = () => { location.hash = ''; }; return; }
  card('<form id="rf"><p class="muted small" style="margin:16px 0">Choose a new password for <b>' + esc(info.email) + '</b> (10 or more characters).</p><div class="field"><label>New password</label><input type="password" name="password" autocomplete="new-password" minlength="10" required></div><div class="field"><label>Repeat it</label><input type="password" name="again" autocomplete="new-password" minlength="10" required></div><div class="err" id="er"></div><button class="btn" style="width:100%">Save password</button></form>');
  eyes($('#rf'));
  $('#rf').onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); if (f.password !== f.again) { $('#er').textContent = 'The two passwords do not match.'; return; }
    try { await api('reset/' + tok, { method: 'POST', data: { password: f.password } }); card('<p style="margin:20px 0">Your password has been changed. You can sign in now.</p><a class="btn" href="#/" id="gl" style="display:block;text-align:center">Sign in</a>'); $('#gl').onclick = e2 => { e2.preventDefault(); location.hash = ''; render(); }; }
    catch (er) { $('#er').textContent = er.message; } };
}
function forcePassword() {
  $('#root').innerHTML = '<div class="gate"><form class="gatecard" id="pf"><div class="brand">AEROSUB<small>Set your own password</small></div><p class="muted small" style="margin:16px 0">You’re signed in with a temporary password. Choose a new one (10+ characters) to continue.</p><div class="field"><label>Temporary password</label><input type="password" name="current" required></div><div class="field"><label>New password</label><input type="password" name="new" minlength="10" autocomplete="new-password" required></div><div class="err" id="er"></div><button class="btn" style="width:100%">Save and continue</button></form></div>';
  eyes($('#pf'));
  $('#pf').onsubmit = async e => { e.preventDefault(); try { await api('password', { method: 'POST', data: Object.fromEntries(new FormData(e.target)) }); S.user.must_change_password = 0; render(); } catch (er) { $('#er').textContent = er.message; } };
}

/* ---------- router ---------- */
let busy = false;
async function render() {
  if (!S.user && /^#\/join\/[a-f0-9]{40}$/.test(location.hash)) return joinView(location.hash.slice(7));
  if (!S.user && /^#\/reset\/[a-f0-9]{40}$/.test(location.hash)) return resetView(location.hash.slice(8));
  if (!S.user) return loginView();
  if (+S.user.must_change_password) return forcePassword();
  shell(); paintBanners();
  const name = (location.hash.split('/')[1] || 'home'); const fn = views[name] || views.home;
  try { await fn(); } catch (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message || 'Something went wrong') + '</div>'; }
  try { if (window.OfficeExt && window.OfficeExt.afterView) window.OfficeExt.afterView(name); } catch {}
}
window.addEventListener('hashchange', () => { if (!location.hash.startsWith('#/tasks/') || !$('#tl')) render(); else { const id = +location.hash.split('/')[2]; if (id) taskDetail(id); } });

/** Change the little number on a menu item (side menu and phone tab bar) without redrawing anything else. */
function paintBadge(key, n) {
  document.querySelectorAll('a[href="#/' + key + '"]').forEach(l => { let b = l.querySelector('.badge'); if (n) { if (!b) { b = document.createElement('span'); b.className = 'badge'; l.appendChild(b); } b.textContent = n; } else if (b) b.remove(); });
}
async function poll() {
  if (!S.user || document.hidden) return;
  try {
    const m = await api('me'); if (!m.user) { S.user = null; return render(); }
    S.csrf = m.csrf; S.mail = !!m.mail;
    if (S.mail && ++S.mailTick % 2 === 0 && !location.hash.startsWith('#/mail')) { try { const u = await api('mail/unread'); if (u.unseen > S.mailUnread) { toast('New email (' + u.unseen + ' unread)'); } if (u.unseen !== S.mailUnread) { S.mailUnread = u.unseen; paintBadge('mail', S.mailUnread); } } catch {} }
    if (m.unread > S.unread && 'Notification' in window && Notification.permission === 'granted') {
      const n = (await api('notifications'))[0];
      if (n && !n.read_at) { const reg = await navigator.serviceWorker?.getRegistration(); const o = { body: n.body || '', icon: 'assets/icon-192.png', tag: 'n' + n.id, data: { link: n.link } }; reg ? reg.showNotification(n.title, o) : new Notification(n.title, o); }
    }
    if (m.unread !== S.unread) { S.unread = m.unread; paintBadge('alerts', S.unread); }
    flushQueue();
  } catch {}
}

async function boot(afterLogin) {
  const th = ls.get('theme'); if (th) document.documentElement.dataset.theme = th;
  if (ls.get('navmin')) document.documentElement.classList.add('nav-min');
  if (!afterLogin) {
    try { const m = await api('me'); S.user = m.user; S.csrf = m.csrf; S.unread = m.unread || 0; S.mail = !!m.mail; S.avv = (m.user && m.user.av) || 0; } catch { S.user = ls.get('lastuser', null); }
  } else S.unread = 0;
  if (S.user) { ls.set('lastuser', { id: S.user.id, name: S.user.name, email: S.user.email, role: S.user.role, department_id: S.user.department_id, department: S.user.department, must_change_password: 0 }); await loadRefs(); flushQueue(); }
  render();
  if (S.user && window.OfficeExt.pwaStart) window.OfficeExt.pwaStart();
}
document.body.insertAdjacentHTML('afterbegin', '<div id="root"></div>');
boot(false);
setInterval(poll, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
})();
