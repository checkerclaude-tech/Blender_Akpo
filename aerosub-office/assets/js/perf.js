/* Aerosub Office: team performance charts for department heads, managers and admins (Reports → Team performance).
 * Plain SVG, no libraries. Colours come from CSS variables so every theme and dark mode stays readable. */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, esc, S } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const P = { days: 30, dept: '' };
  const STAT = [['in_progress', 'Open / ongoing', 'var(--st-prog)'], ['todo', 'Not yet started', 'var(--st-todo)'], ['blocked', 'Blocked', 'var(--st-blk)'], ['deferred', 'Deferred', 'var(--st-def)'], ['done', 'Completed', 'var(--st-done)'], ['canceled', 'Canceled', 'var(--st-can)'], ['transferred', 'Transferred', 'var(--st-tr)']];
  const shortDay = v => new Date(v + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';
  const firstName = n => String(n || '').split(' ')[0];

  function lineChart(series) {
    const W = 680, H = 230, L = 34, R = 10, T = 12, B = 30, w = W - L - R, h = H - T - B, n = series.length;
    const max = Math.max(1, ...series.map(s => Math.max(s.reports, s.done))), nice = max <= 4 ? max : Math.ceil(max / 4) * 4;
    const x = i => L + (n === 1 ? w / 2 : i * w / (n - 1)), y = v => T + h - v / nice * h;
    const path = k => series.map((s, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(s[k]).toFixed(1)).join(' ');
    const area = k => path(k) + ' L' + x(n - 1).toFixed(1) + ' ' + (T + h) + ' L' + x(0).toFixed(1) + ' ' + (T + h) + ' Z';
    const ticks = [0, 1, 2, 3, 4].map(i => Math.round(nice * i / 4)).filter((v, i, a) => a.indexOf(v) === i);
    const step = Math.max(1, Math.ceil(n / 7));
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" class="pf-svg" role="img" aria-label="Daily reports submitted and tasks completed">' +
      ticks.map(v => '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(v) + '" y2="' + y(v) + '" class="pf-grid"/><text x="' + (L - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end" class="pf-t">' + v + '</text>').join('') +
      '<path d="' + area('reports') + '" fill="var(--st-prog)" opacity=".14"/><path d="' + path('reports') + '" fill="none" stroke="var(--st-prog)" stroke-width="2.4" stroke-linejoin="round"/>' +
      '<path d="' + path('done') + '" fill="none" stroke="var(--st-done)" stroke-width="2.4" stroke-linejoin="round" stroke-dasharray="6 4"/>' +
      series.map((s, i) => '<circle cx="' + x(i) + '" cy="' + y(s.reports) + '" r="' + (n > 40 ? 1.6 : 3) + '" fill="var(--st-prog)"><title>' + shortDay(s.date) + ': ' + s.reports + ' report(s), ' + s.done + ' completed</title></circle>').join('') +
      series.map((s, i) => i % step === 0 ? '<text x="' + x(i) + '" y="' + (H - 8) + '" text-anchor="middle" class="pf-t">' + shortDay(s.date) + '</text>' : '').join('') + '</svg>';
  }
  function donut(status) {
    const tot = STAT.reduce((a, s) => a + (status[s[0]] || 0), 0), R = 70, r = 44, cx = 90, cy = 90; let a0 = -Math.PI / 2;
    if (!tot) return '<div class="muted small" style="padding:20px">No tasks yet.</div>';
    const arcs = STAT.filter(s => status[s[0]]).map(s => { const v = status[s[0]], a1 = a0 + v / tot * Math.PI * 2, big = a1 - a0 > Math.PI ? 1 : 0, full = v === tot;
      const P = (rad, a) => (cx + rad * Math.cos(a)).toFixed(2) + ' ' + (cy + rad * Math.sin(a)).toFixed(2);
      const d = full ? 'M' + (cx - R) + ' ' + cy + ' a' + R + ' ' + R + ' 0 1 0 ' + 2 * R + ' 0 a' + R + ' ' + R + ' 0 1 0 ' + (-2 * R) + ' 0 M' + (cx - r) + ' ' + cy + ' a' + r + ' ' + r + ' 0 1 1 ' + 2 * r + ' 0 a' + r + ' ' + r + ' 0 1 1 ' + (-2 * r) + ' 0' : 'M' + P(R, a0) + ' A' + R + ' ' + R + ' 0 ' + big + ' 1 ' + P(R, a1) + ' L' + P(r, a1) + ' A' + r + ' ' + r + ' 0 ' + big + ' 0 ' + P(r, a0) + ' Z';
      a0 = a1; return '<path d="' + d + '" fill="' + s[2] + '" fill-rule="evenodd"><title>' + s[1] + ': ' + v + '</title></path>'; }).join('');
    return '<div class="pf-donut"><svg viewBox="0 0 180 180" role="img" aria-label="Tasks by status">' + arcs + '<text x="90" y="88" text-anchor="middle" class="pf-big">' + tot + '</text><text x="90" y="106" text-anchor="middle" class="pf-t">tasks</text></svg><ul class="pf-leg">' +
      STAT.filter(s => status[s[0]]).map(s => '<li><i style="background:' + s[2] + '"></i>' + s[1] + ' <b>' + status[s[0]] + '</b></li>').join('') + '</ul></div>';
  }
  function workload(rows) {
    const keys = [['done', 'Completed', 'var(--st-done)'], ['in_progress', 'Open / ongoing', 'var(--st-prog)'], ['todo', 'Not yet started', 'var(--st-todo)'], ['deferred', 'Deferred', 'var(--st-def)'], ['blocked', 'Blocked', 'var(--st-blk)']];
    const max = Math.max(1, ...rows.map(r => keys.reduce((a, k) => a + r[k[0]], 0)));
    return '<div class="pf-bars">' + rows.map(r => { const tot = keys.reduce((a, k) => a + r[k[0]], 0);
      return '<div class="pf-row"><span class="pf-name" title="' + esc(r.name) + '">' + esc(firstName(r.name)) + '</span><span class="pf-track" role="img" aria-label="' + esc(r.name) + ': ' + keys.map(k => r[k[0]] + ' ' + k[1].toLowerCase()).join(', ') + '">' +
        keys.map(k => r[k[0]] ? '<i style="width:' + (r[k[0]] / max * 100) + '%;background:' + k[2] + '" title="' + k[1] + ': ' + r[k[0]] + '"></i>' : '').join('') + '</span><b class="pf-val">' + tot + '</b></div>'; }).join('') + '</div>' +
      '<div class="pf-leg inline">' + keys.map(k => '<span><i style="background:' + k[2] + '"></i>' + k[1] + '</span>').join('') + '</div>';
  }
  function consistency(rows, work) {
    const tone = v => v >= 80 ? 'var(--st-done)' : v >= 50 ? 'var(--st-def)' : 'var(--st-blk)';
    return '<div class="pf-bars">' + rows.slice().sort((a, b) => b.consistency - a.consistency).map(r => '<div class="pf-row"><span class="pf-name" title="' + esc(r.name) + '">' + esc(firstName(r.name)) + '</span><span class="pf-track" role="img" aria-label="' + esc(r.name) + ' reported on ' + r.days_reported + ' of ' + work + ' working days"><i style="width:' + r.consistency + '%;background:' + tone(r.consistency) + '"></i></span><b class="pf-val">' + r.consistency + '%</b></div>').join('') + '</div>' +
      '<div class="muted small" style="margin-top:6px">Share of the ' + work + ' working days in this period on which the person sent at least one daily report.</div>';
  }

  async function view() {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
    const q = new URLSearchParams({ days: P.days }); if (P.dept) q.set('department', P.dept); let D;
    try { D = await api('reports/performance?' + q.toString()); } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    P.dept = D.department || '';
    const t = D.totals, sorted = { key: 'done', dir: -1 };
    v.innerHTML = '<div class="top"><div><h1>Team performance</h1><div class="muted">' + esc(shortDay(D.from)) + ' to ' + esc(shortDay(D.to)) + ' · ' + D.people.length + ' people</div></div><span class="right row"><a class="btn ghost" href="#/reports">‹ Reports</a><button class="btn ghost" id="pfp">Print</button></span></div>' +
      '<div class="rp-filters"><select id="pfd" aria-label="Period">' + [[7, 'Last 7 days'], [14, 'Last 14 days'], [30, 'Last 30 days'], [60, 'Last 60 days'], [90, 'Last 90 days']].map(a => opt(a[0], a[1], D.days)).join('') + '</select>' +
      (D.departments.length > 1 ? '<select id="pfs" aria-label="Department">' + opt('', 'All departments', P.dept) + D.departments.map(d => opt(d.id, d.name, P.dept)).join('') + '</select>' : '') + '</div>' +
      '<div class="cards"><div class="card stat"><span class="muted small">Daily reports</span><b>' + t.reports + '</b></div><div class="card stat ok"><span class="muted small">Completed (tasks and reports)</span><b>' + t.done + '</b></div><div class="card stat"><span class="muted small">Hours logged</span><b>' + (Math.round(t.hours * 10) / 10) + '</b></div><div class="card stat bad"><span class="muted small">Blocked now</span><b>' + t.blocked + '</b></div><div class="card stat warn"><span class="muted small">Overdue now</span><b>' + t.late + '</b></div></div>' +
      ((t.wk_done + t.wk_view + t.wk_lost) ? '<div class="muted small" style="margin:-4px 0 10px">Includes ' + (t.wk_done + t.wk_view + t.wk_lost) + ' item' + ((t.wk_done + t.wk_view + t.wk_lost) === 1 ? '' : 's') + ' entered in weekly reports that are not on the task list: ' + t.wk_done + ' delivered (counted as completed), ' + t.wk_view + ' in view (counted as in progress) and ' + t.wk_lost + ' nil/lost.</div>' : '') + '<div class="two pf-two"><div class="card"><h2>Activity</h2><div class="pf-leg inline" style="margin:4px 0 6px"><span><i style="background:var(--st-prog)"></i>Daily reports</span><span><i style="background:var(--st-done)"></i>Tasks completed</span></div>' + lineChart(D.series) + '</div><div class="card"><h2>Tasks by status</h2>' + donut(D.status) + '</div></div>' +
      '<div class="two pf-two"><div class="card"><h2>Workload per person</h2><div class="muted small" style="margin-bottom:8px">Tasks each person holds now, plus those completed in this period.</div>' + (D.people.length ? workload(D.people) : '<div class="empty">No people.</div>') + '</div><div class="card"><h2>Reporting consistency</h2><div style="margin-top:8px"></div>' + (D.people.length ? consistency(D.people, D.workdays) : '<div class="empty">No people.</div>') + '</div></div>' +
      '<div class="card"><h2 style="margin-bottom:8px">Leaderboard</h2><div class="tablewrap"><table class="pf-table"><thead><tr>' + [['name', 'Name'], ['done', 'Completed'], ['on_time', 'On time'], ['reports', 'Reports'], ['wk_total', 'From weekly reports'], ['consistency', 'Consistency'], ['avg_progress', 'Avg progress'], ['hours', 'Hours'], ['blocked', 'Blocked'], ['late', 'Overdue']].map(c => '<th><button class="pf-sort" data-s="' + c[0] + '">' + c[1] + '</button></th>').join('') + '</tr></thead><tbody id="pfb"></tbody></table></div></div>';
    const draw = () => { const k = sorted.key, rows = D.people.slice().sort((a, b) => k === 'name' ? a.name.localeCompare(b.name) * sorted.dir * -1 : ((b[k] ?? -1) - (a[k] ?? -1)) * (sorted.dir === -1 ? 1 : -1));
      $('#pfb').innerHTML = rows.length ? rows.map(r => '<tr><td><b>' + esc(r.name) + '</b></td><td>' + r.done + '</td><td>' + (r.on_time == null ? '—' : r.on_time + '%') + '</td><td>' + r.reports + '</td><td>' + (r.wk_total ? '<span title="Items entered in weekly reports">' + r.wk.done + ' done · ' + r.wk.view + ' open · ' + r.wk.lost + ' lost</span>' : '—') + '</td><td>' + r.consistency + '%</td><td>' + r.avg_progress + '%</td><td>' + r.hours + '</td><td>' + (r.blocked ? '<span class="tag blocked">' + r.blocked + '</span>' : '0') + '</td><td>' + (r.late ? '<span class="tag deferred">' + r.late + '</span>' : '0') + '</td></tr>').join('') : '<tr><td colspan="9" class="muted">No people.</td></tr>'; };
    draw(); v.querySelectorAll('.pf-sort').forEach(b => b.onclick = () => { sorted.dir = sorted.key === b.dataset.s ? -sorted.dir : -1; sorted.key = b.dataset.s; draw(); });
    $('#pfd').onchange = e => { P.days = +e.target.value; view(); }; const sd = $('#pfs'); if (sd) sd.onchange = () => { P.dept = sd.value; view(); }; $('#pfp').onclick = () => window.print();
  }
  window.RPPerf = { view };
})();
