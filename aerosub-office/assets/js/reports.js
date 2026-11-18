/* Aerosub Office: daily task reports dashboard, department heads and department members.
 * Loaded after app.js. Uses only what app.js exposes on window.OfficeExt. No inline event attributes (the CSP blocks them). */
(() => {
  'use strict';
  const X = window.OfficeExt;
  if (!X) return;
  const { api, modal, toast, esc, S, views, readOnly } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  /* ---------- wording ---------- */
  const SL = { todo: 'Not yet started', in_progress: 'Open / ongoing', blocked: 'Blocked', done: 'Completed', deferred: 'Deferred', canceled: 'Canceled', transferred: 'Transferred' };
  const CARD_ORDER = ['in_progress', 'todo', 'deferred', 'canceled', 'done', 'transferred'];
  const PICK = ['in_progress', 'todo', 'blocked', 'deferred', 'done', 'canceled']; // transferred is set only by "Transfer"
  const CLOSED = ['done', 'canceled', 'transferred'];
  const tag = st => '<span class="tag ' + esc(st) + '">' + esc(SL[st] || st) + '</span>';
  const initials = n => (n || '?').split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  const avatar = (id, name) => '<span class="avatar"><span>' + esc(initials(name)) + '</span><img src="api/avatar/' + id + '?v=' + (S.avv || 0) + '" alt="" loading="lazy" data-rm="1"></span>';
  const dayOf = v => new Date(String(v).slice(0, 10) + 'T00:00:00');
  const fmtDay = v => v ? dayOf(v).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '';
  const fmtShort = v => v ? dayOf(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '';
  const wk = v => dayOf(v).toLocaleDateString(undefined, { weekday: 'short' });
  const ago = v => { if (!v) return ''; const s = (Date.now() - new Date(String(v).replace(' ', 'T')).getTime()) / 1000; if (s < 90) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; };
  const shiftDay = (v, n) => { const d = dayOf(v); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const bar = p => '<span class="rp-bar" role="img" aria-label="' + (+p || 0) + ' percent"><i style="width:' + Math.max(0, Math.min(100, +p || 0)) + '%"></i></span>';
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>';
  const isAdmin = () => S.user && S.user.role === 'super_admin';

  // The modal's own close handler only knows about task links, so keep the address bar tidy when a report was opened by link.
  function open(html, onMount) {
    const m = modal(html, onMount); const base = m.close;
    m.close = () => { base(); if (location.hash.startsWith('#/reports/')) history.replaceState(null, '', '#/reports'); };
    return m;
  }

  /* ---------- menu ---------- */
  const prevNav = X.nav;
  X.nav = (items, icons) => {
    if (prevNav) prevNav(items, icons);
    const at = items.findIndex(x => x[0] === 'tasks'); items.splice(at < 0 ? 1 : at + 1, 0, ['reports', 'Reports', ICON]);
    if (isAdmin()) { const a = items.findIndex(x => x[0] === 'admin'); items.splice(a < 0 ? items.length : a, 0, ['departments', 'Departments', (icons && icons.team) || ICON]); }
  };
  const prevMore = X.more;
  X.more = items => {
    if (prevMore) prevMore(items);
    if (!(S.user.tabs && S.user.tabs.reports === false)) items.unshift(['reports', 'Reports', null]);
    if (isAdmin()) items.push(['departments', 'Departments & heads', null]);
  };

  /* ---------- reports dashboard ---------- */
  const RP = { date: '', dept: '', user: '', status: '' };

  views.reports = async () => {
    const sub1 = location.hash.split('/')[2];
    if (sub1 === 'weekly') return weeklyView();
    if (sub1 === 'performance') return window.RPPerf ? window.RPPerf.view() : X.render();
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
    const q = new URLSearchParams(); if (RP.date) q.set('date', RP.date); if (RP.dept) q.set('department', RP.dept); if (RP.user) q.set('user', RP.user);
    const D = await api('reports/dashboard?' + q.toString());
    RP.date = D.date === D.today ? '' : D.date; RP.dept = D.filter.department || ''; RP.user = D.filter.user || '';
    const isToday = D.date === D.today, ro = readOnly();
    const scopeTxt = D.scope === 'all' ? 'Every department' : D.scope === 'department' ? (D.departments.map(d => d.name).join(', ') || 'Your department') : 'Your own tasks and reports shared with you';
    const people = D.people || [];
    v.innerHTML =
      '<div class="top"><div><h1>Reports</h1><div class="muted">' + esc(scopeTxt) + '</div></div><span class="right row">' +
      (ro ? '' : '<button class="btn" id="rpnew">+ Daily report</button>') + '<a class="btn ghost" href="#/reports/weekly">Weekly report</a>' + (D.perf ? '<a class="btn ghost" href="#/reports/performance">Team performance</a>' : '') + '<button class="btn ghost" id="rpprint">Print</button></span></div>' +
      '<div class="rp-filters"><div class="row"><button class="btn ghost sm" id="rpprev" aria-label="Previous day">‹</button><input type="date" id="rpdate" value="' + esc(D.date) + '" max="' + esc(D.today) + '" aria-label="Report date">' +
      '<button class="btn ghost sm" id="rpnext" aria-label="Next day"' + (isToday ? ' disabled' : '') + '>›</button>' + (isToday ? '' : '<button class="btn ghost sm" id="rptoday">Today</button>') + '</div>' +
      (D.review && D.departments.length > 1 ? '<select id="rpdept" aria-label="Department">' + opt('', 'All departments', RP.dept) + D.departments.map(d => opt(d.id, d.name, RP.dept)).join('') + '</select>' : '') +
      (D.review && people.length > 1 ? '<select id="rpuser" aria-label="Person">' + opt('', 'Everyone', RP.user) + people.map(p => opt(p.id, p.name, RP.user)).join('') + '</select>' : '') + '</div>' +
      (D.my_missing && isToday && !ro ? '<div class="banner" style="background:#fff4dd;color:#8a5a00"><span>You have <b>' + D.my_missing + '</b> task' + (D.my_missing > 1 ? 's' : '') + ' in progress with no report today.</span><button class="btn sm right" id="rpnew2">Report now</button></div>' : '') +
      '<div class="rp-cards" id="rpcards">' + CARD_ORDER.map(k => '<button class="rp-card ' + k + (RP.status === k ? ' on' : '') + '" data-st="' + k + '"><span class="small">' + SL[k] + '</span><b>' + (D.counts[k] || 0) + '</b></button>').join('') +
      (D.counts.blocked ? '<button class="rp-card blocked' + (RP.status === 'blocked' ? ' on' : '') + '" data-st="blocked"><span class="small">Blocked</span><b>' + D.counts.blocked + '</b></button>' : '') + '</div>' +
      '<div class="muted small rp-note">Counts show tasks as they stand now. Completed, canceled and transferred cover the 30 days before ' + esc(fmtShort(D.date)) + '. Tap a card to list those tasks.</div>' +
      '<div id="rptasks"></div>' +
      '<div class="two rp-main"><div class="col">' +
      '<div class="card"><div class="row"><h2>' + (isToday ? "Today's reports" : 'Reports for ' + esc(fmtDay(D.date))) + '</h2><span class="muted small right">' + D.totals.reports + ' report' + (D.totals.reports === 1 ? '' : 's') + '</span></div><div class="rp-feed" id="rpfeed"></div></div>' +
      '<div class="card"><h2>Not reported yet</h2><div class="muted small">Tasks marked Open / ongoing or Blocked that have no report for this day.</div><div class="list" id="rpmiss"></div></div>' +
      '</div><div class="col">' +
      '<div class="card"><h2>Last 7 days</h2><div class="rp-trend" id="rptrend"></div><div class="rp-legend"><span><i class="a"></i>Reports</span><span><i class="b"></i>Tasks completed</span></div></div>' +
      (D.review ? '<div class="card"><h2>People</h2><div class="tablewrap"><table class="rp-people"><thead><tr><th>Name</th><th title="Open / ongoing">Open</th><th title="Not yet started">New</th><th title="Completed in the last 30 days">Done</th><th title="Reports received / reports expected">Reported</th></tr></thead><tbody id="rppeople"></tbody></table></div></div>' : '') +
      (D.shared.length ? '<div class="card"><h2>Shared with me</h2><div class="list" id="rpshared"></div></div>' : '') +
      '</div></div>';

    // feed
    $('#rpfeed').innerHTML = D.reports.length ? D.reports.map(r =>
      '<div class="rp-item" data-rep="' + r.id + '" tabindex="0" role="button">' +
      '<div class="row">' + avatar(r.user_id, r.user_name) + '<div class="grow"><b>' + esc(r.task_title) + '</b><div class="muted small">' + esc(r.user_name) + (r.department ? ' · ' + esc(r.department) : '') + ' · ' + esc(ago(r.updated_at)) + '</div></div>' + tag(r.status) + '</div>' +
      '<div class="row rp-prog">' + bar(r.progress) + '<span class="small muted">' + r.progress + '%' + (r.hours != null ? ' · ' + (+r.hours) + ' h' : '') + '</span></div>' +
      '<div class="rp-sum">' + esc(r.summary) + '</div>' + (r.blockers ? '<div class="rp-block">⚠ ' + esc(r.blockers) + '</div>' : '') +
      '<div class="muted small">' + (+r.comment_count ? '💬 ' + r.comment_count + ' · ' : '') + (+r.share_count ? '👥 Shared with ' + r.share_count + ' · ' : '') + '<span class="rp-open">Open</span></div></div>').join('')
      : '<div class="empty">No reports for this day yet.</div>';
    $$('.rp-item', v).forEach(el => { const go = () => openReport(+el.dataset.rep); el.onclick = go; el.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }; });

    // missing
    $('#rpmiss').innerHTML = D.missing.length ? D.missing.map(m =>
      '<div class="item" data-go="#/tasks/' + m.task_id + '"><div class="grow"><b>' + esc(m.title) + '</b><div class="muted small">' + esc(m.user_name || '') + (m.department ? ' · ' + esc(m.department) : '') + (m.due_date ? ' · due ' + esc(fmtShort(m.due_date)) : '') + '</div></div>' + tag(m.status) + '</div>').join('') +
      (D.missing_total > D.missing.length ? '<div class="muted small" style="padding:8px">+ ' + (D.missing_total - D.missing.length) + ' more</div>' : '')
      : '<div class="empty">' + (D.totals.expected ? 'Everyone has reported. 🎉' : 'No tasks are in progress.') + '</div>';

    // trend
    const mx = Math.max(1, ...D.trend.map(t => Math.max(t.reports, t.completed)));
    $('#rptrend').innerHTML = D.trend.map(t => '<button class="rp-day' + (t.date === D.date ? ' on' : '') + '" data-day="' + t.date + '" title="' + esc(fmtDay(t.date)) + '"><span class="rp-cols"><i class="a" style="height:' + Math.round(t.reports / mx * 100) + '%"></i><i class="b" style="height:' + Math.round(t.completed / mx * 100) + '%"></i></span><span class="small">' + t.reports + '/' + t.completed + '</span><span class="small muted">' + esc(wk(t.date)) + '</span></button>').join('');

    // people
    if (D.review) $('#rppeople').innerHTML = D.people_rows.length ? D.people_rows.map(p =>
      '<tr class="rp-prow" data-user="' + p.id + '"><td><b>' + esc(p.name) + '</b></td><td>' + p.counts.in_progress + (p.counts.blocked ? ' <span class="tag blocked">' + p.counts.blocked + ' blocked</span>' : '') + '</td><td>' + p.counts.todo + '</td><td>' + p.counts.done + '</td><td>' +
      (p.expected ? '<span class="tag ' + (p.reported >= p.expected ? 'done' : p.reported ? 'deferred' : 'blocked') + '">' + p.reported + ' / ' + p.expected + '</span>' : '<span class="muted">—</span>') + '</td></tr>').join('')
      : '<tr><td colspan="5" class="muted">No tasks yet.</td></tr>';

    // shared
    if (D.shared.length) $('#rpshared').innerHTML = D.shared.map(s => '<div class="item" data-rep="' + s.id + '"><div class="grow"><b>' + esc(s.task_title) + '</b><div class="muted small">' + esc(s.user_name) + ' · ' + esc(fmtShort(s.report_date)) + ' · shared by ' + esc(s.shared_by || '—') + (s.note ? ' — ' + esc(s.note) : '') + '</div></div>' + tag(s.status) + '</div>').join('');
    $$('#rpshared .item', v).forEach(el => el.onclick = () => openReport(+el.dataset.rep));

    // events
    const go = () => X.render();
    $('#rpdate').onchange = e => { RP.date = e.target.value || ''; go(); };
    $('#rpprev').onclick = () => { RP.date = shiftDay(D.date, -1); go(); };
    const nx = $('#rpnext'); if (nx) nx.onclick = () => { const n = shiftDay(D.date, 1); RP.date = n >= D.today ? '' : n; go(); };
    const td = $('#rptoday'); if (td) td.onclick = () => { RP.date = ''; go(); };
    const sd = $('#rpdept'); if (sd) sd.onchange = () => { RP.dept = sd.value; RP.user = ''; go(); };
    const su = $('#rpuser'); if (su) su.onchange = () => { RP.user = su.value; go(); };
    $$('.rp-day', v).forEach(b => b.onclick = () => { RP.date = b.dataset.day >= D.today ? '' : b.dataset.day; go(); });
    $$('.rp-prow', v).forEach(r => r.onclick = () => { RP.user = r.dataset.user; go(); });
    $$('.rp-card', v).forEach(c => c.onclick = () => { RP.status = RP.status === c.dataset.st ? '' : c.dataset.st; $$('.rp-card', v).forEach(x => x.classList.toggle('on', x.dataset.st === RP.status)); taskPanel(D); });
    const nw = $('#rpnew'), nw2 = $('#rpnew2'); if (nw) nw.onclick = () => reportForm({ date: D.date }); if (nw2) nw2.onclick = () => reportForm({});
    $('#rpprint').onclick = () => window.print();
    taskPanel(D);

    const sub = location.hash.split('/')[2];
    if (sub && /^\d+$/.test(sub)) { history.replaceState(null, '', '#/reports'); openReport(+sub); }
  };

  // The tasks behind one status card
  async function taskPanel(D) {
    const box = $('#rptasks'); if (!box) return;
    if (!RP.status) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="card"><div class="empty">Loading…</div></div>';
    const p = new URLSearchParams({ status: RP.status }); if (RP.dept) p.set('department', RP.dept); if (RP.user) p.set('assignee', RP.user);
    let rows = []; try { rows = await api('tasks?' + p.toString()); } catch (e) { box.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    if (!RP.user && !RP.dept && D.scope === 'own') rows = rows.filter(t => +t.assignee_id === +S.user.id);
    if (CLOSED.includes(RP.status)) { const since = shiftDay(D.date, -30); rows = rows.filter(t => String(t.updated_at).slice(0, 10) >= since); }
    box.innerHTML = '<div class="card rp-tasks"><div class="row"><h2>' + esc(SL[RP.status]) + '</h2><span class="muted small">' + rows.length + ' task' + (rows.length === 1 ? '' : 's') + '</span><button class="btn ghost sm right" id="rpclose">Close</button></div><div class="list">' +
      (rows.length ? rows.map(t => '<div class="item" data-go="#/tasks/' + t.id + '"><div class="grow"><b>' + esc(t.title) + '</b><div class="muted small">' + esc(t.assignee_name || 'Unassigned') + (t.department ? ' · ' + esc(t.department) : '') + (t.due_date ? ' · due ' + esc(fmtShort(t.due_date)) : '') + (t.status_reason ? ' · ' + esc(t.status_reason) : '') + '</div></div><span class="tag ' + esc(t.priority) + '">' + esc(t.priority) + '</span></div>').join('') : '<div class="empty">Nothing here.</div>') + '</div></div>';
    $('#rpclose').onclick = () => { RP.status = ''; $$('.rp-card').forEach(x => x.classList.remove('on')); box.innerHTML = ''; };
  }

  /* ---------- submit a daily report ---------- */
  async function reportForm(pre) {
    pre = pre || {};
    let tasks = []; try { tasks = (await api('tasks?assignee=me')).filter(t => !CLOSED.includes(t.status) || +t.id === +pre.task_id); } catch (e) { return toast(e.message); }
    if (!tasks.length) return toast('You have no open tasks to report on.');
    const today = String((await api('health')).time).slice(0, 10); // the server's date, which decides what counts as today
    const first = pre.task_id ? tasks.find(t => +t.id === +pre.task_id) : tasks.find(t => t.status === 'in_progress') || tasks[0];
    const m = open('<h2>' + (pre.id ? 'Edit report' : 'Daily report') + '</h2><form id="rpf" style="margin-top:14px">' +
      '<div class="field"><label>Task</label><select name="task_id" id="rpt">' + tasks.map(t => opt(t.id, t.title + ' — ' + (SL[t.status] || t.status), first && first.id)).join('') + '</select></div>' +
      '<div class="grid2"><div class="field"><label>Day</label><input type="date" name="report_date" id="rpd" value="' + esc(pre.date || today) + '" max="' + esc(today) + '" min="' + esc(shiftDay(today, -3)) + '"></div>' +
      '<div class="field"><label>Hours worked (optional)</label><input type="number" name="hours" id="rph" min="0" max="24" step="0.5" inputmode="decimal"></div></div>' +
      '<div class="field"><label>Status of the task</label><select name="status" id="rps">' + PICK.map(k => opt(k, SL[k], first && first.status === 'todo' ? 'in_progress' : first && first.status)).join('') + '</select></div>' +
      '<div class="field" id="rpduef" style="display:none"><label>Deferred until</label><input type="date" name="due_date" id="rpdue" min="' + esc(today) + '"></div>' +
      '<div class="field"><label>Progress: <b id="rppv">0</b>%</label><input type="range" name="progress" id="rpp" min="0" max="100" step="5" value="0"></div>' +
      '<div class="field"><label>What did you do?</label><textarea name="summary" id="rpsum" required maxlength="3000" rows="4" placeholder="Short summary of today\'s work"></textarea></div>' +
      '<div class="field"><label id="rpbl">Blockers or help needed (optional)</label><textarea name="blockers" id="rpbk" maxlength="1500" rows="2"></textarea></div>' +
      '<div class="err" id="er"></div><div class="row"><button class="btn" id="rpsave">Submit report</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    const st = $('#rps', m), pr = $('#rpp', m);
    const sync = () => { $('#rpduef', m).style.display = st.value === 'deferred' ? '' : 'none'; $('#rpdue', m).required = st.value === 'deferred'; $('#rpbk', m).required = st.value === 'blocked'; $('#rpbl', m).textContent = st.value === 'blocked' ? 'What is blocking you?' : 'Blockers or help needed (optional)'; if (st.value === 'done') { pr.value = 100; } $('#rppv', m).textContent = pr.value; };
    st.onchange = sync; pr.oninput = sync;
    const prefill = async () => { // an existing report for this task and day is edited, not duplicated
      const tid = $('#rpt', m).value, day = $('#rpd', m).value; if (!tid || !day) return;
      try { const h = await api('reports?task=' + tid + '&user=' + S.user.id + '&from=' + day + '&to=' + day); const r = h[0];
        $('#rpsave', m).textContent = r ? 'Update report' : 'Submit report';
        if (r) { $('#rpsum', m).value = r.summary; $('#rpbk', m).value = r.blockers || ''; pr.value = r.progress; $('#rph', m).value = r.hours == null ? '' : r.hours; st.value = PICK.includes(r.status) ? r.status : st.value; }
        sync(); } catch {} };
    $('#rpt', m).onchange = () => { const t = tasks.find(x => +x.id === +$('#rpt', m).value); if (t) st.value = t.status === 'todo' ? 'in_progress' : (PICK.includes(t.status) ? t.status : 'in_progress'); sync(); prefill(); };
    $('#rpd', m).onchange = prefill; sync(); prefill();
    $('#rpf', m).onsubmit = async e => {
      e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.task_id = +f.task_id; f.progress = +f.progress; const b = $('#rpsave', m); b.disabled = true;
      try { const r = await api('reports', { method: 'POST', data: f }); if (r && r.queued) { toast('Saved offline — it will send when you are back online'); m.close(); return; } m.close(); toast(r.updated ? 'Report updated' : 'Report submitted'); X.render(); }
      catch (er) { $('#er', m).textContent = er.message; b.disabled = false; }
    };
  }

  /* ---------- one report: details, comments, sharing ---------- */
  async function openReport(id) {
    let r; try { r = await api('reports/' + id); } catch (e) { return toast(e.message); }
    const m = open('<div class="row"><h2 class="grow">' + esc(r.task_title) + '</h2><button class="btn ghost sm" id="cl">Close</button></div>' +
      '<div class="row" style="margin:10px 0">' + avatar(r.user_id, r.user_name) + '<div class="grow"><b>' + esc(r.user_name) + '</b><div class="muted small">' + esc(fmtDay(r.report_date)) + (r.department ? ' · ' + esc(r.department) : '') + '</div></div>' + tag(r.status) + '</div>' +
      '<div class="row rp-prog">' + bar(r.progress) + '<span class="small muted">' + r.progress + '%' + (r.hours != null ? ' · ' + (+r.hours) + ' h' : '') + '</span></div>' +
      '<p class="rp-full">' + esc(r.summary) + '</p>' + (r.blockers ? '<div class="rp-block">⚠ ' + esc(r.blockers) + '</div>' : '') +
      '<div class="row" style="margin:12px 0"><a class="btn ghost sm" href="#/tasks/' + r.task_id + '" data-close-scrim="1">Open task</a>' + (r.can_share ? '<button class="btn ghost sm" id="rsh">Share</button>' : '') + (r.can_edit ? '<button class="btn ghost sm" id="red">Edit</button>' : '') + (r.can_delete ? '<button class="btn ghost sm" id="rdel" style="color:var(--bad)">Delete</button>' : '') + '</div>' +
      '<h3>Shared with</h3><div class="chips" id="rshl" style="margin:8px 0 14px">' + (r.shares.length ? r.shares.map(s => '<span class="chip" title="' + esc((s.shared_by ? 'Shared by ' + s.shared_by : '') + (s.note ? ' — ' + s.note : '')) + '">' + esc(s.name) + (r.can_share || +s.id === +S.user.id ? ' <a href="#" data-unshare="' + s.id + '" aria-label="Stop sharing with ' + esc(s.name) + '" style="margin-left:4px">✕</a>' : '') + '</span>').join('') : '<span class="muted small">Not shared with anyone else.</span>') + '</div>' +
      '<h3>Comments</h3><div class="list" id="rcm">' + (r.comments.length ? r.comments.map(c => '<div style="padding:9px 0"><b>' + esc(c.name) + '</b> <span class="muted small">' + esc(ago(c.created_at)) + '</span>' + (+c.user_id === +S.user.id || isAdmin() ? ' <a href="#" class="small" data-delc="' + c.id + '">delete</a>' : '') + '<div style="white-space:pre-wrap">' + esc(c.body) + '</div></div>').join('') : '<div class="muted small" style="padding:8px 0">No comments yet.</div>') + '</div>' +
      (r.can_comment ? '<form id="rcf" class="row" style="margin-top:10px;flex-wrap:nowrap"><input name="body" placeholder="Write a comment…" required maxlength="3000"><button class="btn sm">Send</button></form>' : '') +
      (r.history.length ? '<h3 style="margin-top:18px">Earlier reports on this task</h3><div class="list">' + r.history.map(h => '<div class="item" data-rep="' + h.id + '"><div class="grow"><b>' + esc(fmtShort(h.report_date)) + '</b> <span class="muted small">' + esc(h.summary.slice(0, 90)) + '</span></div>' + tag(h.status) + '</div>').join('') + '</div>' : ''));
    $('#cl', m).onclick = () => m.close();
    const again = () => { m.remove(); openReport(id); };
    $$('.item[data-rep]', m).forEach(el => el.onclick = () => { m.remove(); openReport(+el.dataset.rep); });
    const cf = $('#rcf', m); if (cf) cf.onsubmit = async e => { e.preventDefault(); try { await api('reports/' + id + '/comments', { method: 'POST', data: { body: new FormData(e.target).get('body') } }); again(); } catch (er) { toast(er.message); } };
    $$('[data-delc]', m).forEach(a => a.onclick = async e => { e.preventDefault(); if (!confirm('Delete this comment?')) return; try { await api('reports/' + id + '/comments/' + a.dataset.delc, { method: 'DELETE' }); again(); } catch (er) { toast(er.message); } });
    $$('[data-unshare]', m).forEach(a => a.onclick = async e => { e.preventDefault(); e.stopPropagation(); try { await api('reports/' + id + '/share/' + a.dataset.unshare, { method: 'DELETE' }); if (+a.dataset.unshare === +S.user.id) { m.close(); X.render(); } else again(); } catch (er) { toast(er.message); } });
    const sh = $('#rsh', m); if (sh) sh.onclick = () => shareDialog(r, again);
    const ed = $('#red', m); if (ed) ed.onclick = () => { m.close(); reportForm({ task_id: r.task_id, date: String(r.report_date).slice(0, 10), id: r.id }); };
    const dl = $('#rdel', m); if (dl) dl.onclick = async () => { if (!confirm('Delete this report?')) return; try { await api('reports/' + id, { method: 'DELETE' }); m.close(); X.render(); } catch (er) { toast(er.message); } };
  }

  function shareDialog(r, done) {
    const already = new Set(r.shares.map(s => +s.id));
    const us = S.users.filter(u => +u.active === 1 && +u.id !== +S.user.id && +u.id !== +r.user_id && !already.has(+u.id));
    const m = open('<h2>Share this report</h2><div class="muted small" style="margin:4px 0 10px">' + esc(r.task_title) + ' · ' + esc(r.user_name) + '. People you share it with can read it and add comments.</div>' +
      (S.depts.length ? '<div class="chips" id="shd" style="margin-bottom:10px">' + S.depts.map(d => '<span class="chip" data-dept="' + d.id + '">+ ' + esc(d.name) + '</span>').join('') + '</div>' : '') +
      '<input id="shq" placeholder="Search people" aria-label="Search people" style="margin-bottom:8px"><div class="rp-pick" id="shl">' +
      us.map(u => '<label class="rp-pickrow" data-name="' + esc(u.name.toLowerCase()) + '"><input type="checkbox" value="' + u.id + '" data-depts="' + esc((u.department_ids || []).join(',')) + '"><span>' + esc(u.name) + '</span><span class="muted small">' + esc(u.department || u.job_title || '') + '</span></label>').join('') + '</div>' +
      '<div class="field" style="margin-top:12px"><label>Note (optional)</label><input id="shn" maxlength="500" placeholder="Why are you sharing this?"></div><div class="err" id="er"></div><div class="row"><button class="btn" id="shgo">Share</button><button class="btn ghost" id="cx" type="button">Cancel</button></div>');
    $('#cx', m).onclick = () => m.close();
    $('#shq', m).oninput = e => { const q = e.target.value.toLowerCase(); $$('.rp-pickrow', m).forEach(l => l.style.display = l.dataset.name.includes(q) ? '' : 'none'); };
    $$('[data-dept]', m).forEach(c => c.onclick = () => { c.classList.toggle('on'); $$('#shl input', m).forEach(i => { if (i.dataset.depts.split(',').includes(c.dataset.dept)) i.checked = c.classList.contains('on'); }); });
    $('#shgo', m).onclick = async () => {
      const ids = $$('#shl input:checked', m).map(i => +i.value); if (!ids.length) { $('#er', m).textContent = 'Choose at least one person'; return; }
      try { const res = await api('reports/' + r.id + '/share', { method: 'POST', data: { user_ids: ids, note: $('#shn', m).value } }); m.close(); toast('Shared with ' + res.shared.join(', ')); done(); } catch (er) { $('#er', m).textContent = er.message; }
    };
  }

  /* ---------- buttons inside a task: daily report, transfer ---------- */
  const prevOnTask = X.onTask;
  X.onTask = (m, t) => {
    if (prevOnTask) prevOnTask(m, t);
    const ro = readOnly(); const row = $('#ns', m) && $('#ns', m).parentElement;
    if (t.status === 'transferred') {
      const note = document.createElement('div'); note.className = 'rp-transferred';
      note.innerHTML = 'This task was <b>transferred</b>' + (t.status_reason ? ' — ' + esc(t.status_reason) : '') + '.' + (t.transferred_to_id ? ' <a href="#/tasks/' + t.transferred_to_id + '" data-close-scrim="1">Open the new task</a>' : '');
      const h = $('h3', m); if (h) h.parentNode.insertBefore(note, h); return;
    }
    if (ro || !row) return;
    const mine = +t.assignee_id === +S.user.id;
    if (mine && !CLOSED.includes(t.status)) { const b = document.createElement('button'); b.className = 'btn sm'; b.textContent = 'Daily report'; b.onclick = () => { m.close(); reportForm({ task_id: t.id }); }; row.appendChild(b); }
    if (t.can_edit && !CLOSED.includes(t.status)) { const b = document.createElement('button'); b.className = 'btn ghost sm'; b.textContent = 'Transfer'; b.onclick = () => transferDialog(m, t); row.appendChild(b); }
    if (t.status_reason && ['deferred', 'canceled', 'blocked'].includes(t.status)) { const n = document.createElement('div'); n.className = 'muted small'; n.style.width = '100%'; n.textContent = SL[t.status] + ': ' + t.status_reason; row.appendChild(n); }
  };

  function transferDialog(parent, t) {
    const me = S.user; let us = S.users.filter(u => +u.active === 1 && u.role !== 'founder' && +u.id !== +t.assignee_id);
    if (me.role !== 'super_admin') { const md = [...(me.head_of || []), ...(me.role === 'manager' ? (me.department_ids || []) : [])]; us = us.filter(u => +u.id === +me.id || (u.department_ids || []).some(d => md.includes(d))); }
    const m = open('<h2>Transfer task</h2><div class="muted small" style="margin:4px 0 12px">' + esc(t.title) + '. This task is closed as <b>Transferred</b> and a new copy is opened for the person you choose. Past reports stay on the original.</div>' +
      '<div class="field"><label>Transfer to</label><select id="trto">' + opt('', '— choose —', '') + us.map(u => opt(u.id, u.name + (u.department ? ' — ' + u.department : ''), '')).join('') + '</select></div>' +
      '<div class="field"><label>Reason (optional)</label><input id="trn" maxlength="300"></div><div class="err" id="er"></div><div class="row"><button class="btn" id="trgo">Transfer</button><button class="btn ghost" id="cx" type="button">Cancel</button></div>');
    $('#cx', m).onclick = () => m.close();
    $('#trgo', m).onclick = async () => {
      const to = +$('#trto', m).value; if (!to) { $('#er', m).textContent = 'Choose who the task goes to'; return; }
      try { const r = await api('tasks/' + t.id + '/transfer', { method: 'POST', data: { to_user_id: to, note: $('#trn', m).value } }); m.close(); parent.close(); toast('Task transferred'); X.render(); if (r && r.id) location.hash = '#/tasks/' + r.id; } catch (er) { $('#er', m).textContent = er.message; }
    };
  }

  /* ---------- weekly report: the "Team weekly report" sheet (ASL-HR-WR-001), editable, then Excel or print ---------- */
  const WK = { week: '', user: '' };
  const shiftWeek = (v, n) => shiftDay(v, 7 * n);
  async function weeklyView() {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
    const sidRaw = location.hash.split('/')[3], saved = /^\d+$/.test(sidRaw || ''), sid = saved ? +sidRaw : 0;
    let W;
    if (saved) W = await api('reports/weekly/' + sid);
    else { const q = new URLSearchParams(); if (WK.week) q.set('week', WK.week); if (WK.user) q.set('user', WK.user); W = await api('reports/weekly?' + q.toString()); WK.week = W.week_start; WK.user = String(W.people.find(p => p.name === W.employee)?.id || WK.user || ''); }
    const ed = !saved, legend = W.legend, col = k => '#' + (legend.find(l => l.key === k) || legend[1]).color;
    const dateTxt = W.date_text || dayOf(W.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const cell = (k, val) => '<td class="wr-edit"' + (ed ? ' contenteditable="true" spellcheck="true"' : '') + ' data-k="' + k + '">' + esc(val || '') + '</td>';
    const rowHtml = (r, n) => '<td class="wr-sn">' + (n + 1) + '</td>' + cell('client', r.client) + cell('project', r.project) + cell('task', r.task) +
      '<td class="wr-st" style="background:' + col(r.status) + '"><select data-k="status" aria-label="Status"' + (ed ? '' : ' disabled') + '>' + legend.map(l => opt(l.key, l.short, r.status)).join('') + '</select></td>' + cell('outstanding', r.outstanding) + cell('comment', r.comment) + cell('review', r.review) +
      (ed ? '<td class="wr-x noprint"><button type="button" class="btn ghost sm" data-del aria-label="Remove row">✕</button></td>' : '');
    const info = saved ? '<div class="banner noprint" style="background:var(--mist);color:var(--ink);border:1px solid var(--line)"><span>' + (W.saved.shared_by ? 'Shared with you by <b>' + esc(W.saved.shared_by) + '</b>' : 'Saved weekly report') + ' · updated ' + esc(ago(W.saved.updated_at)) + (W.saved.note ? ' — “' + esc(W.saved.note) + '”' : '') + (W.saved.recipients.length ? '<br><span class="small muted">Shared with: ' + W.saved.recipients.map(r => esc(r.name)).join(', ') + '</span>' : '') + '</span></div>' : '';
    v.innerHTML = '<div class="top noprint"><div><h1>Weekly report</h1><div class="muted">' + (saved ? 'Week of ' + esc(fmtShort(W.week_start)) + ' · ' + esc(W.employee) : 'Week of ' + esc(fmtShort(W.week_start)) + ' to ' + esc(fmtShort(W.week_end)) + '. Filled in from tasks and daily reports. Change anything, then share, download or print.') + '</div></div><span class="right row"><a class="btn ghost" href="#/reports/weekly">' + (saved ? '‹ My weekly report' : '‹ Reports') + '</a>' + (ed && !readOnly() ? '<button class="btn" id="wsh">Share…</button>' : '') + '<button class="btn' + (ed && !readOnly() ? ' ghost' : '') + '" id="wxl">Download Excel</button><button class="btn ghost" id="wpr">Print / PDF</button></span></div>' + info +
      (ed ? '<div class="rp-filters noprint"><div class="row"><button class="btn ghost sm" id="wprev" aria-label="Previous week">‹</button><input type="date" id="wdate" value="' + esc(W.week_start) + '" aria-label="Any day in the week"><button class="btn ghost sm" id="wnext" aria-label="Next week">›</button></div>' + (W.people.length > 1 ? '<select id="wuser" aria-label="Person">' + W.people.map(p => opt(p.id, p.name, WK.user)).join('') + '</select>' : '') + '</div>' : '') +
      '<div class="wr-sheet print-ok"><div class="wr-band"><img src="assets/img/aerosub-logo.png" alt="Aerosub" data-rm="1"><span class="wr-code"><b>' + esc(W.code) + '</b><span>' + esc(W.rev) + '</span></span></div>' +
      '<div class="wr-head"><div class="wr-title">TEAM WEEKLY REPORT</div><div class="wr-meta"><div><b>Employee name</b><span class="wr-edit"' + (ed ? ' contenteditable="true"' : '') + ' id="wname">' + esc(W.employee) + '</span></div><div><b>Date</b><span class="wr-edit"' + (ed ? ' contenteditable="true"' : '') + ' id="wdt">' + esc(dateTxt) + '</span></div></div></div>' +
      '<table class="wr-t"><thead><tr><th>SN</th><th>Client/Category</th><th>Project</th><th>Task</th><th>Status</th><th>Outstanding</th><th>Suggestion/Comment</th><th>Management review</th>' + (ed ? '<th class="noprint"></th>' : '') + '</tr></thead><tbody id="wbody">' + W.rows.map((r, n) => '<tr>' + rowHtml(r, n) + '</tr>').join('') + '</tbody></table>' +
      (ed ? '<div class="row noprint" style="margin:8px 0"><button class="btn ghost sm" id="wadd">+ Add row</button></div>' : '') +
      '<div class="wr-foot"><table class="wr-leg"><tr><th colspan="2">**LEGEND**</th></tr>' + legend.map(l => '<tr><td style="background:#' + l.color + '"></td><td>' + esc(l.label) + '</td></tr>').join('') + '</table>' +
      '<table class="wr-leg"><tr><td>Reported by:</td><td class="wr-edit"' + (ed ? ' contenteditable="true"' : '') + ' id="wby">' + esc(W.employee) + '</td></tr><tr><td>Designation:</td><td class="wr-edit"' + (ed ? ' contenteditable="true"' : '') + ' id="wdes">' + esc(W.designation) + '</td></tr></table></div></div>' +
      '<div id="wlists" class="noprint"></div>';
    const body = $('#wbody'); const renum = () => $$('#wbody tr').forEach((tr, i) => { $('.wr-sn', tr).textContent = i + 1; });
    const wire = tr => { const del = $('[data-del]', tr); if (del) del.onclick = () => { tr.remove(); renum(); }; const sel = $('select', tr); sel.onchange = () => { $('.wr-st', tr).style.background = col(sel.value); };
      if (ed) $$('.wr-edit', tr).forEach(c => c.onpaste = e => { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); }); };
    $$('#wbody tr').forEach(wire);
    const add = $('#wadd'); if (add) add.onclick = () => { const tr = document.createElement('tr'); tr.innerHTML = rowHtml({ status: 'view' }, body.children.length); body.appendChild(tr); wire(tr); tr.querySelector('.wr-edit').focus(); };
    const T = c => c.innerText.replace(/ /g, ' ').trim();
    const collect = () => ({ code: W.code, rev: W.rev, employee: T($('#wname')), designation: T($('#wdes')), date: W.date, date_text: T($('#wdt')), week_start: W.week_start,
      rows: $$('#wbody tr').map(tr => { const o = {}; $$('[data-k]', tr).forEach(c => { o[c.dataset.k] = c.tagName === 'SELECT' ? c.value : T(c); }); return o; }).filter(r => r.client || r.project || r.task || r.outstanding || r.comment || r.review) });
    const by = $('#wby'); if (by && ed) by.oninput = () => { $('#wname').textContent = T(by); };
    $('#wxl').onclick = async e => { const b = e.target; b.disabled = true;
      try { const res = await fetch('api/reports/weekly-xlsx', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf }, body: JSON.stringify(collect()) });
        if (!res.ok) { let m = 'Could not create the Excel file'; try { m = (await res.json()).error || m; } catch {} throw new Error(m); }
        const blob = await res.blob(), a = document.createElement('a'), fn = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || ''); a.href = URL.createObjectURL(blob); a.download = fn ? fn[1] : 'Weekly_Report.xlsx'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); toast('Excel file downloaded'); }
      catch (er) { toast(er.message); } b.disabled = false; };
    $('#wpr').onclick = () => window.print();
    const sh = $('#wsh'); if (sh) sh.onclick = () => weeklyShare(W, collect);
    if (ed) { const go = () => X.render();
      $('#wdate').onchange = e => { WK.week = e.target.value; go(); }; $('#wprev').onclick = () => { WK.week = shiftWeek(W.week_start, -1); go(); }; $('#wnext').onclick = () => { WK.week = shiftWeek(W.week_start, 1); go(); };
      const wu = $('#wuser'); if (wu) wu.onchange = () => { WK.user = wu.value; go(); };
      // shared reports (kept off the sheet itself so they never print)
      try { const L = await api('reports/weekly-shared'); const row = (x, who) => '<a class="item" href="#/reports/weekly/' + x.id + '" style="color:inherit"><div class="grow"><b>' + esc(who) + '</b><div class="muted small">Week of ' + esc(fmtShort(x.week_start)) + (x.shared_by ? ' · shared by ' + esc(x.shared_by) : '') + (x.note ? ' — ' + esc(x.note) : '') + (x.shares != null ? ' · shared with ' + x.shares : '') + '</div></div><span class="muted">›</span></a>';
        const blocks = [['Shared with me', L.with_me.map(x => row(x, x.employee))], ['Reports I shared', L.mine.filter(x => +x.shares).map(x => row(x, 'My report'))], ['Team reports', L.team.map(x => row(x, x.employee))]].filter(b => b[1].length);
        if (blocks.length) $('#wlists').innerHTML = '<div class="two" style="margin-top:18px">' + blocks.map(b => '<div class="card"><h2 style="margin-bottom:6px">' + b[0] + '</h2><div class="list">' + b[1].join('') + '</div></div>').join('') + '</div>'; } catch {}
    }
  }

  // Share the weekly report with team members and top members: a notification, plus an e-mail with the Excel file attached.
  async function weeklyShare(W, collect) {
    await X.loadRefs(); const me = S.user, myDepts = (me.department_ids || []).map(Number);
    const users = S.users.filter(u => +u.active === 1 && +u.id !== +me.id && +u.id !== +WK.user);
    const heads = new Set(S.depts.filter(d => d.head_id && myDepts.includes(+d.id)).map(d => +d.head_id));
    const top = new Set(users.filter(u => ['super_admin', 'founder', 'manager'].includes(u.role)).map(u => +u.id)); heads.forEach(h => top.add(h));
    const team = new Set(users.filter(u => (u.department_ids || []).some(d => myDepts.includes(+d))).map(u => +u.id));
    const m = open('<h2>Share this weekly report</h2><div class="muted small" style="margin:4px 0 10px">People you share it with get a notification and can open it here. You can also email it to them with the Excel file attached.</div>' +
      '<div class="chips" style="margin-bottom:10px"><span class="chip" data-q="heads">+ My department head' + (heads.size > 1 ? 's' : '') + '</span><span class="chip" data-q="top">+ Top members (heads, managers, admins)</span><span class="chip" data-q="team">+ My team</span></div>' +
      '<input id="shq" placeholder="Search people" aria-label="Search people" style="margin-bottom:8px"><div class="rp-pick" id="shl">' +
      users.map(u => '<label class="rp-pickrow" data-name="' + esc(u.name.toLowerCase()) + '"><input type="checkbox" value="' + u.id + '"><span>' + esc(u.name) + (top.has(+u.id) ? ' <span class="tag in_progress">' + (heads.has(+u.id) ? 'Head' : esc(({ super_admin: 'Admin', founder: 'Founder', manager: 'Manager' })[u.role] || 'Lead')) + '</span>' : '') + '</span><span class="muted small">' + esc(u.department || u.job_title || '') + '</span></label>').join('') + '</div>' +
      '<div class="field" style="margin-top:12px"><label>Note (optional)</label><input id="shn" maxlength="500" placeholder="Anything they should look at first?"></div><div class="cr-checks"><label><input type="checkbox" id="she" checked> Also send it by email, with the Excel file attached</label></div><div class="err" id="er"></div><div class="row"><button class="btn" id="shgo">Share</button><button class="btn ghost" id="cx" type="button">Cancel</button></div>');
    $('#cx', m).onclick = () => m.close();
    $('#shq', m).oninput = e => { const q = e.target.value.toLowerCase(); $$('.rp-pickrow', m).forEach(l => l.style.display = l.dataset.name.includes(q) ? '' : 'none'); };
    $$('[data-q]', m).forEach(c => c.onclick = () => { const set = c.dataset.q === 'heads' ? heads : c.dataset.q === 'top' ? top : team; c.classList.toggle('on'); $$('#shl input', m).forEach(i => { if (set.has(+i.value)) i.checked = c.classList.contains('on'); }); });
    $('#shgo', m).onclick = async e => {
      const ids = $$('#shl input:checked', m).map(i => +i.value); if (!ids.length) { $('#er', m).textContent = 'Choose at least one person'; return; } const b = e.target; b.disabled = true; $('#er', m).textContent = '';
      try { const r = await api('reports/weekly-share', { method: 'POST', data: { ...collect(), user_id: +WK.user || undefined, user_ids: ids, note: $('#shn', m).value, email: $('#she', m).checked } }); m.close();
        toast('Shared with ' + r.shared.length + (r.shared.length === 1 ? ' person' : ' people') + ($('#she') === null && r.emailed ? ' · ' + r.emailed + ' emailed' : '') + (r.email_failed ? ' · ' + r.email_failed + ' email(s) could not be sent (check Campaigns → Settings)' : '')); X.render(); }
      catch (er) { $('#er', m).textContent = er.message; b.disabled = false; } };
  }

  /* ---------- Departments: set the head, add and remove people (Super Admin) ---------- */
  views.departments = async () => {
    if (!isAdmin()) { location.hash = '#/home'; return; }
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
    const [deps, users] = await Promise.all([api('departments'), api('users')]); S.depts = deps.map(d => ({ id: d.id, name: d.name })); S.users = users;
    const active = users.filter(u => +u.active === 1 && u.role !== 'founder');
    v.innerHTML = '<div class="top"><div><h1>Departments</h1><div class="muted">Choose each department\'s head and who belongs to it. Heads see their department\'s daily reports, can comment, share them and give tasks to their people.</div></div></div>' +
      '<div class="card" style="margin-bottom:18px"><form id="dnf" class="row" style="flex-wrap:nowrap"><input name="name" placeholder="New department name" required maxlength="80"><button class="btn sm">Add department</button></form></div>' +
      '<div class="rp-depts">' + deps.map(d => {
        const mem = users.filter(u => (u.department_ids || []).includes(+d.id));
        return '<div class="card rp-dept" data-d="' + d.id + '"><div class="row"><h2 class="grow">' + esc(d.name) + '</h2><a class="small" href="#/reports" data-rpdept="' + d.id + '">View reports</a></div>' +
          '<div class="field" style="margin-top:12px"><label>Head of department</label><select data-head="' + d.id + '">' + opt('', '— no head —', d.head_id || '') + active.map(u => opt(u.id, u.name + (u.job_title ? ' — ' + u.job_title : ''), d.head_id || '')).join('') + '</select></div>' +
          '<label class="small muted">People (' + mem.length + ')</label><div class="chips" style="margin:6px 0 12px">' + (mem.length ? mem.map(u => '<span class="chip">' + esc(u.name) + (+d.head_id === +u.id ? ' ★' : '') + ' <a href="#" data-rm-user="' + u.id + '" data-dept="' + d.id + '" aria-label="Remove ' + esc(u.name) + '" style="margin-left:4px">✕</a></span>').join('') : '<span class="muted small">Nobody yet.</span>') + '</div>' +
          '<div class="row" style="flex-wrap:nowrap"><select data-addsel="' + d.id + '"><option value="">Add a person…</option>' + active.filter(u => !(u.department_ids || []).includes(+d.id)).map(u => opt(u.id, u.name, '')).join('') + '</select><button class="btn ghost sm" data-add="' + d.id + '">Add</button></div></div>';
      }).join('') + '</div>';
    const refresh = () => X.render();
    $('#dnf').onsubmit = async e => { e.preventDefault(); try { await api('departments', { method: 'POST', data: { name: new FormData(e.target).get('name') } }); refresh(); } catch (er) { toast(er.message); } };
    $$('[data-head]', v).forEach(s => s.onchange = async () => { try { await api('departments/' + s.dataset.head, { method: 'PATCH', data: { head_id: s.value ? +s.value : null } }); toast(s.value ? 'Head of department saved' : 'Head removed'); refresh(); } catch (er) { toast(er.message); refresh(); } });
    $$('[data-add]', v).forEach(b => b.onclick = async () => { const sel = $('[data-addsel="' + b.dataset.add + '"]', v); if (!sel.value) return; try { await api('departments/' + b.dataset.add + '/members', { method: 'POST', data: { user_ids: [+sel.value] } }); refresh(); } catch (er) { toast(er.message); } });
    $$('[data-rm-user]', v).forEach(a => a.onclick = async e => { e.preventDefault(); if (!confirm('Remove this person from the department?')) return; try { await api('departments/' + a.dataset.dept + '/members/' + a.dataset.rmUser, { method: 'DELETE' }); refresh(); } catch (er) { toast(er.message); } });
    $$('[data-rpdept]', v).forEach(a => a.onclick = () => { RP.dept = a.dataset.rpdept; RP.user = ''; RP.date = ''; });
  };
})();
