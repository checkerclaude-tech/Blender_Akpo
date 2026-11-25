/* Aerosub Office: dependent tasks (flows). A plan of steps where each step waits for the ones before it, has its own time frame (one window for everyone
 * on it, or one per person), can hold sub-tasks, and is drawn as a timeline for the person who set it up. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._flLoaded) return; X._flLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const day = s => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d) / 86400000; };
  const ymd = n => new Date(n * 86400000).toISOString().slice(0, 10);
  const fd = s => s ? new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '';
  const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const COL = { todo: '#8aa0bd', in_progress: '#0a5eb0', blocked: '#c9d1de', done: '#1f9d57', deferred: '#b08900', canceled: '#b0b0b0' };
  const SL = { todo: 'To do', in_progress: 'In progress', blocked: 'Waiting', done: 'Done', deferred: 'Deferred', canceled: 'Cancelled' };
  const people = async () => { if (!S.users || !S.users.length) { try { S.users = await api('users'); } catch { S.users = []; } } return (S.users || []).filter(u => u.active !== 0); };

  /* ---------------- one step's fields: used for every step in the builder and for add / edit ---------------- */
  /** opts: { users, earlier: [{key,title}], step (existing), mkey } -> { el, get() } */
  function stepCard(opts, idx) {
    const st = opts.step || {}, el = document.createElement('div'); el.className = 'fl-step card'; const uid = 'fs' + Math.random().toString(36).slice(2, 7);
    const un = id => (opts.users.find(u => +u.id === +id) || {}).name || ('#' + id);
    let extra = (st.people || []).map(p => +p.id).filter(i => i !== +st.assignee_id); let scope = st.scope || 'group'; let win = {}; (st.windows || []).forEach(w => { win[w.user_id] = { start_date: w.start_date, due_date: w.due_date }; });
    const ou = id => '<option value="' + id + '">' + esc(un(id)) + '</option>';
    el.innerHTML = '<div class="row"><b class="grow fl-n">' + (idx != null ? 'Step ' + (idx + 1) : 'Step') + '</b>' + (opts.removable ? '<button type="button" class="btn ghost sm" data-rm title="Remove this step">Remove</button>' : '') + '</div>' +
      '<div class="grid2"><div class="field"><label>What has to be done</label><input data-f="title" maxlength="200" placeholder="e.g. Create the plan for the UI" value="' + esc(st.title || '') + '"></div><div class="field"><label>Main person</label><select data-f="as">' + opts.users.map(u => '<option value="' + u.id + '"' + (+u.id === +(st.assignee_id || S.user.id) ? ' selected' : '') + '>' + esc(u.name) + '</option>').join('') + '</select></div></div>' +
      '<div class="field"><label>Also on this step</label><div class="row" style="flex-wrap:wrap;gap:6px" data-ex></div><select data-f="addp"><option value="">+ Add another person…</option>' + opts.users.map(u => '<option value="' + u.id + '">' + esc(u.name) + '</option>').join('') + '</select></div>' +
      '<div class="grid2"><div class="field"><label>Starts <span class="muted small">(empty = when the steps it waits for are done)</span></label><input type="date" data-f="start" value="' + esc(st.start_date || '') + '"></div><div class="field"><label>' + (opts.step ? 'Ends' : 'Days needed') + '</label>' + (opts.step ? '<input type="date" data-f="due" value="' + esc(st.due_date || '') + '">' : '<input type="number" data-f="days" min="1" max="365" value="3">') + '</div></div>' +
      '<div class="field"><label>Time frame</label><label class="fl-rad"><input type="radio" name="sc' + uid + '" value="group"' + (scope === 'group' ? ' checked' : '') + '> One time frame for everyone on this step</label><label class="fl-rad"><input type="radio" name="sc' + uid + '" value="user"' + (scope === 'user' ? ' checked' : '') + '> A separate time frame for each person</label><div data-win class="fl-win"></div></div>' +
      '<div class="field"><label>This step waits for</label><div data-after class="fl-after">' + (opts.earlier.length ? opts.earlier.map(e => '<label class="fl-rad"><input type="checkbox" value="' + esc(String(e.key)) + '"' + ((st.after || []).map(String).includes(String(e.key)) || (!opts.step && opts.defaultAfter === e.key) ? ' checked' : '') + '> ' + esc(e.title) + '</label>').join('') : '<span class="muted small">Nothing: it can start straight away.</span>') + '</div></div>';
    const people$ = () => [+($('[data-f=as]', el).value), ...extra];
    const paintEx = () => { $('[data-ex]', el).innerHTML = extra.length ? extra.map(id => '<span class="chip">' + esc(un(id)) + ' <button type="button" class="sh-x" data-x="' + id + '" aria-label="Remove ' + esc(un(id)) + '">×</button></span>').join('') : '<span class="muted small">Nobody else</span>'; $$('[data-x]', el).forEach(b => b.onclick = () => { extra = extra.filter(i => i !== +b.dataset.x); paintEx(); paintWin(); }); };
    const paintWin = () => { const box = $('[data-win]', el); if (scope !== 'user') { box.innerHTML = ''; return; } const s0 = $('[data-f=start]', el).value, e0 = $('[data-f=due]', el) ? $('[data-f=due]', el).value : '';
      box.innerHTML = people$().map(id => { const w = win[id] || {}; return '<div class="fl-wrow"><span class="grow">' + esc(un(id)) + '</span><input type="date" data-wu="' + id + '" data-w="start" value="' + esc(w.start_date || s0) + '" aria-label="Start for ' + esc(un(id)) + '"><span class="muted small">to</span><input type="date" data-wu="' + id + '" data-w="due" value="' + esc(w.due_date || e0) + '" aria-label="End for ' + esc(un(id)) + '"></div>'; }).join('');
      $$('[data-wu]', box).forEach(i => i.onchange = () => { const k = +i.dataset.wu; win[k] = win[k] || {}; win[k][i.dataset.w === 'start' ? 'start_date' : 'due_date'] = i.value; }); };
    $('[data-f=addp]', el).onchange = e => { const v = +e.target.value; e.target.value = ''; if (v && !extra.includes(v) && v !== +$('[data-f=as]', el).value) { extra.push(v); paintEx(); paintWin(); } };
    $('[data-f=as]', el).onchange = () => { extra = extra.filter(i => i !== +$('[data-f=as]', el).value); paintEx(); paintWin(); };
    $$('input[type=radio]', el).forEach(r => r.onchange = () => { scope = r.value; paintWin(); }); const dueIn = $('[data-f=due]', el); if (dueIn) dueIn.onchange = paintWin; $('[data-f=start]', el).onchange = paintWin;
    const rm = $('[data-rm]', el); if (rm) rm.onclick = () => opts.onRemove && opts.onRemove(el);
    paintEx(); paintWin();
    return { el, get() { const w = {}; if (scope === 'user') $$('[data-wu]', el).forEach(i => { const k = i.dataset.wu; (w[k] = w[k] || {})[i.dataset.w === 'start' ? 'start_date' : 'due_date'] = i.value || null; });
      const o = { title: $('[data-f=title]', el).value.trim(), assignee_id: +$('[data-f=as]', el).value, user_ids: extra, scope, windows: w, start_date: $('[data-f=start]', el).value || null, after: $$('[data-after] input:checked', el).map(c => opts.step ? +c.value : +c.value) };
      if ($('[data-f=days]', el)) o.days = +$('[data-f=days]', el).value || 3; if (dueIn) o.due_date = dueIn.value || null; return o; }, setIndex(i) { const n = $('.fl-n', el); if (n) n.textContent = 'Step ' + (i + 1); } };
  }

  /* ---------------- creating a dependent task ---------------- */
  async function flowForm(pre) {
    const users = await people(); const cards = [];
    const m = modal('<div class="row"><h2 class="grow">New dependent task</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">A plan made of steps. Each step goes to a person (or a few), has its own time frame, and starts only when the steps it waits for are done. Everyone on it is told when it is their turn, and you see the whole plan as a timeline.</div>' +
      '<div class="field"><label>Name of the plan</label><input id="fl-t" maxlength="200" placeholder="e.g. Build the Aerosub website" value="' + esc((pre && pre.title) || '') + '"></div><div class="grid2"><div class="field"><label>Details</label><textarea id="fl-d" rows="2" placeholder="What is this for?"></textarea></div><div class="field"><label>Plan starts</label><input type="date" id="fl-s" value="' + today() + '"></div></div>' +
      '<div id="fl-steps"></div><div class="row" style="margin:10px 0"><button type="button" class="btn ghost" id="fl-add">+ Add a step</button></div><div class="err" id="er" role="alert"></div><div class="row"><button class="btn" id="fl-go">Create the plan</button></div>');
    m.firstElementChild.classList.add('fl-wide'); const host = $('#fl-steps', m);
    const add = () => { const i = cards.length; const c = stepCard({ users, earlier: cards.map((x, k) => ({ key: k, title: (x.get().title || 'Step ' + (k + 1)) })), defaultAfter: i - 1, removable: true, onRemove: el => { if (cards.length < 2) return; const k = cards.findIndex(c => c.el === el); cards.splice(k, 1); el.remove(); cards.forEach((c, j) => c.setIndex(j)); } }, i); cards.push(c); host.appendChild(c.el); c.el.scrollIntoView({ block: 'nearest' }); };
    add(); $('#fl-add', m).onclick = add; $('#cx', m).onclick = () => m.close();
    $('#fl-go', m).onclick = async () => { $('#er', m).textContent = ''; const title = $('#fl-t', m).value.trim(); if (!title) { $('#er', m).textContent = 'Give the plan a name'; return; }
      const steps = cards.map(c => c.get()); const bad = steps.findIndex(s => !s.title); if (bad >= 0) { $('#er', m).textContent = 'Step ' + (bad + 1) + ' needs a name'; return; }
      $('#fl-go', m).disabled = true; try { const r = await api('tasks', { method: 'POST', data: { title, description: $('#fl-d', m).value.trim(), flow: { start_date: $('#fl-s', m).value || null, steps } } }); m.close(); toast('Plan created with ' + steps.length + ' step' + (steps.length > 1 ? 's' : '')); showFlow(r.id); X.render(); }
      catch (e) { $('#er', m).textContent = e.message; $('#fl-go', m).disabled = false; } };
  }
  X.flowForm = flowForm;
  document.addEventListener('click', e => { const c = e.target.closest('[data-md]'); if (!c) return; const sc = c.closest('.scrim'); if (c.dataset.md === 'f') { const t = sc && $('input[name=title]', sc); const title = t ? t.value : ''; if (sc) sc.remove(); flowForm({ title }); } });

  /* ---------------- the timeline ---------------- */
  async function showFlow(id) {
    let D; try { D = await api('tasks/' + id + '/flow'); } catch (e) { toast(e.message); return; }
    const m = modal('<div class="row"><h2 class="grow">' + esc(D.flow.title) + '</h2>' + (D.can_edit ? '<button class="btn sm" id="fl-newstep">+ Add a step</button>' : '') + '<button class="btn ghost sm" id="cx">Close</button></div><div id="fl-body"></div>'); m.firstElementChild.classList.add('fl-wide'); $('#cx', m).onclick = () => m.close();
    const draw = () => {
      const steps = D.steps, [a, b] = D.range; const pct = D.progress[1] ? Math.round(D.progress[0] * 100 / D.progress[1]) : 0;
      if (!steps.length || !a) { $('#fl-body', m).innerHTML = '<div class="empty">No steps yet.</div>'; return; }
      const min = Math.min(day(a), day(D.today)) - 1, max = Math.max(day(b), day(D.today)) + 2, n = max - min, W = Math.max(26, Math.min(46, Math.floor(760 / n))), LW = 230, RH = s => (s.scope === 'user' && s.windows.length ? 18 + s.windows.length * 17 : 44);
      const ys = []; let y = 36; steps.forEach(s => { ys.push(y); y += RH(s) + 6; }); const H = y + 8, WW = n * W;
      let ax = ''; for (let i = 0; i < n; i++) { const d = new Date((min + i) * 86400000); const wk = [0, 6].includes(d.getUTCDay()); ax += '<div class="fl-day' + (wk ? ' we' : '') + '" style="left:' + i * W + 'px;width:' + W + 'px">' + (d.getUTCDate() === 1 || i === 0 || W >= 34 || d.getUTCDay() === 1 ? d.getUTCDate() : '') + (d.getUTCDate() === 1 || i === 0 ? '<small>' + d.toLocaleDateString(undefined, { month: 'short', timeZone: 'UTC' }) + '</small>' : '') + '</div>'; }
      const pos = s => { const st = day(s.span[0]) - min, en = day(s.span[1]) - min + 1; return [st * W, Math.max(W * .8, (en - st) * W)]; }; let bars = '', labels = '', svg = '';
      steps.forEach((s, i) => { const [x, w] = pos(s), top = ys[i], h = RH(s), col = COL[s.status] || '#8aa0bd'; const idx = {}; steps.forEach((q, k) => idx[q.id] = k);
        labels += '<div class="fl-lab" style="top:' + top + 'px;height:' + h + 'px" data-open="' + s.id + '" tabindex="0" role="link"><b>' + esc(s.title) + '</b><div class="muted small">' + esc(s.assignee || '') + (s.people.length ? ' +' + s.people.length : '') + ' · <span style="color:' + col + ';font-weight:600">' + (s.waiting ? 'Waiting' : SL[s.status]) + '</span>' + (s.subs[1] ? ' · ☑ ' + s.subs[0] + '/' + s.subs[1] : '') + '</div></div>';
        if (s.scope === 'user' && s.windows.length) { bars += '<div class="fl-grp" style="left:' + x + 'px;width:' + w + 'px;top:' + top + 'px;height:' + h + 'px;border-color:' + col + '"></div>'; s.windows.forEach((wd, k) => { const wx = (day(wd.start_date) - min) * W, ww = Math.max(W * .8, (day(wd.due_date) - day(wd.start_date) + 1) * W); bars += '<div class="fl-bar" data-open="' + s.id + '" style="left:' + wx + 'px;width:' + ww + 'px;top:' + (top + 14 + k * 17) + 'px;height:14px;background:' + col + ';color:' + (s.waiting ? '#33415c' : '#fff') + '" title="' + esc(wd.name + ': ' + fd(wd.start_date) + ' to ' + fd(wd.due_date)) + '"><span>' + esc(wd.name.split(' ')[0]) + '</span></div>'; }); }
        else bars += '<div class="fl-bar' + (s.waiting ? ' wait' : '') + '" data-open="' + s.id + '" style="left:' + x + 'px;width:' + w + 'px;top:' + (top + 8) + 'px;height:26px;background:' + col + ';color:' + (s.waiting ? '#33415c' : '#fff') + '" title="' + esc(fd(s.span[0]) + ' to ' + fd(s.span[1])) + '"><span>' + esc(fd(s.span[0])) + ' – ' + esc(fd(s.span[1])) + '</span></div>';
        s.after.forEach(p => { const k = idx[p]; if (k == null) return; const ps = steps[k], [px, pw] = pos(ps), y1 = ys[k] + RH(ps) / 2, y2 = top + h / 2, x1 = px + pw, x2 = x, xm = Math.max(x1, x2 - 10) + 0; svg += '<path d="M' + x1 + ',' + y1 + ' H' + (x1 + 8) + ' V' + y2 + ' H' + Math.max(x2 - 2, x1 + 8) + '" fill="none" stroke="#7b8aa3" stroke-width="1.4" marker-end="url(#fa)"/>'; }); });
      const tx = (day(D.today) - min) * W + W / 2;
      $('#fl-body', m).innerHTML = '<div class="row" style="margin:6px 0 10px;gap:12px"><div class="rp-bar grow" role="img" aria-label="' + pct + ' percent done"><i style="width:' + pct + '%"></i></div><b>' + D.progress[0] + ' of ' + D.progress[1] + ' steps done</b></div>' +
        '<div class="fl-legend small muted">' + ['todo', 'in_progress', 'done'].map(k => '<span><i style="background:' + COL[k] + '"></i>' + SL[k] + '</span>').join('') + '<span><i style="background:' + COL.blocked + '"></i>Waiting for an earlier step</span><span>→ starts after</span></div>' +
        '<div class="fl-wrap"><div class="fl-left" style="width:' + LW + 'px;height:' + H + 'px"><div class="fl-lh">Step</div>' + labels + '</div><div class="fl-scroll"><div class="fl-chart" style="width:' + WW + 'px;height:' + H + 'px"><div class="fl-axis">' + ax + '</div>' + '<div class="fl-today" style="left:' + tx + 'px" title="Today"></div><svg width="' + WW + '" height="' + H + '" class="fl-svg"><defs><marker id="fa" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0,0 L7,3.5 L0,7 z" fill="#7b8aa3"/></marker></defs>' + svg + '</svg>' + bars + '</div></div></div>' +
        '<div class="muted small" style="margin-top:8px">Click a step to open it.' + (D.can_edit ? ' Use Edit on a step in its details to change its people, dates or what it waits for.' : '') + '</div>';
      $$('[data-open]', m).forEach(e => { const go = () => { m.close(); location.hash = '#/tasks/' + e.dataset.open; }; e.onclick = go; e.onkeydown = ev => { if (ev.key === 'Enter') go(); }; });
    };
    draw(); const ns = $('#fl-newstep', m);
    if (ns) ns.onclick = async () => { const users = await people(); const f = modal('<div class="row"><h2 class="grow">Add a step</h2><button class="btn ghost sm" id="cx">✕</button></div><div id="fs"></div><div class="err" id="er" role="alert"></div><div class="row" style="margin-top:10px"><button class="btn" id="go">Add the step</button></div>'); f.firstElementChild.classList.add('fl-wide');
      const c = stepCard({ users, earlier: D.steps.map(s => ({ key: s.id, title: s.title })), defaultAfter: D.steps.length ? D.steps[D.steps.length - 1].id : null }); $('#fs', f).appendChild(c.el); $('#cx', f).onclick = () => f.close();
      $('#go', f).onclick = async () => { const d = c.get(); if (!d.title) { $('#er', f).textContent = 'Name the step'; return; } $('#go', f).disabled = true; try { await api('tasks/' + D.flow.id + '/steps', { method: 'POST', data: d }); f.close(); m.close(); toast('Step added'); showFlow(D.flow.id); X.render(); } catch (e) { $('#er', f).textContent = e.message; $('#go', f).disabled = false; } }; };
  }
  X.showFlow = showFlow;
  async function editStep(t, flowSteps) {
    const users = await people(); const info = t.flow_info || {}; const st = { title: t.title, assignee_id: t.assignee_id, people: t.shares || [], start_date: t.start_date, due_date: t.due_date, scope: info.scope, windows: info.windows || [], after: (info.after || []).map(a => a.id) };
    let steps = flowSteps; if (!steps) { try { steps = (await api('tasks/' + t.id + '/flow')).steps; } catch { steps = []; } }
    const f = modal('<div class="row"><h2 class="grow">Edit step</h2><button class="btn ghost sm" id="cx">✕</button></div><div id="fs"></div><div class="err" id="er" role="alert"></div><div class="row" style="margin-top:10px"><button class="btn" id="go">Save</button></div>'); f.firstElementChild.classList.add('fl-wide');
    const c = stepCard({ users, earlier: steps.filter(s => s.id !== t.id).map(s => ({ key: s.id, title: s.title })), step: st }); $('#fs', f).appendChild(c.el); $('#cx', f).onclick = () => f.close();
    $('#go', f).onclick = async () => { const d = c.get(); if (!d.title) { $('#er', f).textContent = 'Name the step'; return; } $('#go', f).disabled = true; try { await api('tasks/' + t.id + '/step', { method: 'PUT', data: d }); f.close(); toast('Saved'); X.render(); location.hash = '#/tasks'; setTimeout(() => { location.hash = '#/tasks/' + t.id; }, 50); } catch (e) { $('#er', f).textContent = e.message; $('#go', f).disabled = false; } };
  }

  /* ---------------- inside a task: the plan it belongs to, and sub-tasks ---------------- */
  const prev = X.onTask;
  X.onTask = (m, t) => {
    if (prev) prev(m, t); const h3 = $$('h3', m).find(x => /Comments/.test(x.textContent)); if (!h3 || $('#fl-panel', m)) return; const fi = t.flow_info, mine = t.can_edit;
    const p = document.createElement('div'); p.id = 'fl-panel'; p.style.margin = '12px 0';
    let html = '';
    if (fi && fi.is_flow) html += '<div class="fl-info"><b>📅 Dependent task</b> <span class="muted small">A plan of steps that wait for each other.</span><div class="row" style="margin-top:6px"><button class="btn sm" id="fl-tl">See the timeline</button></div></div>';
    else if (fi && fi.flow_id) html += '<div class="fl-info"><b>📅 Step of “' + esc(fi.flow_title || '') + '”</b>' + (fi.waiting_on && fi.waiting_on.length ? '<div class="small" style="margin-top:4px">⏳ Starts after: <b>' + fi.waiting_on.map(w => esc(w.title)).join(', ') + '</b></div>' : (fi.after && fi.after.length ? '<div class="small" style="margin-top:4px">✅ Everything before it is done</div>' : '')) +
      (fi.unlocks && fi.unlocks.length ? '<div class="small">Opens next: ' + fi.unlocks.map(w => esc(w.title)).join(', ') + '</div>' : '') + (fi.windows && fi.windows.length ? '<div class="small muted">Time frames: ' + fi.windows.map(w => esc(w.name) + ' ' + fd(w.start_date) + '–' + fd(w.due_date)).join(' · ') + '</div>' : '') + '<div class="row" style="margin-top:6px"><button class="btn sm" id="fl-tl">See the timeline</button>' + (mine ? '<button class="btn ghost sm" id="fl-ed">Edit step</button>' : '') + '</div></div>';
    const subs = t.subs || [], dn = subs.filter(s => +s.done).length;
    html += '<div class="fl-subs"><div class="row"><b>Sub-tasks</b> <span class="muted small" id="fl-sc">' + (subs.length ? dn + ' of ' + subs.length + ' done' : '') + '</span></div><div id="fl-sl">' + subs.map(sub).join('') + '</div><form id="fl-sf" class="row" style="flex-wrap:nowrap;margin-top:6px"><input id="fl-si" placeholder="Add a sub-task…" maxlength="200" autocomplete="off"><button class="btn ghost sm">Add</button></form></div>';
    p.innerHTML = html; h3.before(p);
    const bind = () => { $$('[data-sd]', p).forEach(c => c.onchange = async () => { try { await api('tasks/' + t.id + '/subs/' + c.dataset.sd, { method: 'PATCH', data: { done: c.checked } }); c.closest('.fl-sub').classList.toggle('done', c.checked); const all = $$('[data-sd]', p); $('#fl-sc', p).textContent = all.filter(x => x.checked).length + ' of ' + all.length + ' done'; } catch (e) { c.checked = !c.checked; toast(e.message); } });
      $$('[data-sx]', p).forEach(b => b.onclick = async () => { try { await api('tasks/' + t.id + '/subs/' + b.dataset.sx, { method: 'DELETE' }); b.closest('.fl-sub').remove(); } catch (e) { toast(e.message); } }); };
    function sub(s) { return '<label class="fl-sub' + (+s.done ? ' done' : '') + '"><input type="checkbox" data-sd="' + s.id + '"' + (+s.done ? ' checked' : '') + '><span class="grow">' + esc(s.title) + (s.user_name ? ' <span class="muted small">· ' + esc(s.user_name) + '</span>' : '') + '</span><button type="button" class="sh-x" data-sx="' + s.id + '" title="Remove" aria-label="Remove">×</button></label>'; }
    bind(); $('#fl-sf', p).onsubmit = async e => { e.preventDefault(); const i = $('#fl-si', p), v = i.value.trim(); if (!v) return; try { const r = await api('tasks/' + t.id + '/subs', { method: 'POST', data: { title: v } }); i.value = ''; $('#fl-sl', p).insertAdjacentHTML('beforeend', sub({ id: r.id, title: v, done: 0 })); bind(); const all = $$('[data-sd]', p); $('#fl-sc', p).textContent = all.filter(x => x.checked).length + ' of ' + all.length + ' done'; } catch (er) { toast(er.message); } };
    const tl = $('#fl-tl', p); if (tl) tl.onclick = () => showFlow(t.id); const ed = $('#fl-ed', p); if (ed) ed.onclick = () => { m.close(); editStep(t); };
  };
})();
