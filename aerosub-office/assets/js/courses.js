/* Aerosub Office: Learn → Courses. Course cards, videos / links / PowerPoint slides, graded objective tests, question import,
 * retakes, grades. Loaded after app.js; uses only what app.js shares on window.OfficeExt. No inline event attributes (CSP).
 * Addresses: #/learn/c · #/learn/c/grades · #/learn/c/ID[/questions|settings|grades|test|result/ATTEMPT] */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || !X.views || !X.views.learn) return;
  const { api, modal, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';
  const isAdmin = () => S.user && S.user.role === 'super_admin';
  const ro = () => S.user && S.user.role === 'founder';
  const fmtD = v => { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '—' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); };
  const pct = n => n == null ? '—' : (Math.round(n * 10) / 10) + '%';
  const nl = v => esc(v || '').replace(/(https:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>');
  const b64 = f => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1] || ''); r.onerror = rej; r.readAsDataURL(f); });
  const LET = ['A', 'B', 'C', 'D', 'E'];
  let timer = 0; const stopTimer = () => { if (timer) { clearInterval(timer); timer = 0; } if (X.cr && X.cr.leave) { const f = X.cr.leave; X.cr.leave = null; f(); } };
  const view = () => $('#view');

  /* the two tabs on the Learn page */
  const baseLearn = views.learn;
  views.learn = async () => {
    stopTimer(); const parts = location.hash.split('/');
    if (parts[2] === 'c') return route(parts);
    await baseLearn();
    if (!parts[2] && view()) view().insertAdjacentHTML('afterbegin', tabs('proc'));
  };
  const tabs = on => '<div class="chips lt-tabs" style="margin-bottom:14px"><a class="chip' + (on === 'proc' ? ' on' : '') + '" href="#/learn">Procedures</a><a class="chip' + (on === 'courses' ? ' on' : '') + '" href="#/learn/c">Courses</a></div>';
  const back = (href, label) => '<div class="row" style="margin-bottom:8px"><a href="' + href + '" class="small">‹ ' + label + '</a></div>';
  const fail = e => { view().innerHTML = '<div class="card empty">' + esc(e.message || 'Something went wrong') + '</div>'; };

  async function route(parts) {
    try {
      if (!parts[3]) return await list();
      if (parts[3] === 'grades') return await gradesOverview();
      const id = +parts[3]; if (!id) return await list();
      if (parts[4] === 's' && +parts[5] && X.courseSection) return await X.courseSection(id, +parts[5]);
      if (parts[4] === 'test') return await testView(id);
      if (parts[4] === 'result' && +parts[5]) return await resultView(id, +parts[5]);
      return await coursePage(id, ['questions', 'settings', 'grades'].includes(parts[4]) ? parts[4] : 'overview');
    } catch (e) { fail(e); }
  }

  /* ---------- course cards ---------- */
  async function list() {
    const v = view(); v.innerHTML = '<div class="empty">Loading…</div>'; const D = await api('courses');
    const card = c => {
      const m = c.mine, st = m.passed ? '<span class="tag done">Passed ' + pct(m.best) + '</span>' : m.open_attempt ? '<span class="tag in_progress">In progress</span>' : m.count ? '<span class="tag deferred">Best ' + pct(m.best) + '</span>' : (c.question_count ? '<span class="tag">Not taken</span>' : '');
      return '<a class="cr-card" href="#/learn/c/' + c.id + '"><div class="cr-img">' + (c.image_url ? '<img src="' + esc(c.image_url) + '" alt="" loading="lazy" data-rm="1">' : '') + '<span class="cr-ph" aria-hidden="true">' + esc((c.title || '?').slice(0, 1).toUpperCase()) + '</span>' + (c.status === 'draft' ? '<span class="cr-draft">Draft</span>' : '') + '</div>' +
        '<div class="cr-body"><div class="cr-title">' + esc(c.title) + '</div><div class="muted small cr-desc">' + esc(c.description || '') + '</div>' + (c.progress && c.progress.total ? '<div class="cr-pbar" style="margin-top:8px" role="img" aria-label="Progress"><i style="width:' + Math.round(c.progress.done * 100 / c.progress.total) + '%"></i></div><div class="muted small">' + c.progress.done + ' of ' + c.progress.total + ' sections done</div>' : '') + '<div class="cr-tags">' + (c.departments.length ? c.departments.map(d => '<span class="tag">' + esc(d) + '</span>').join('') : '<span class="tag">Everyone</span>') + '</div>' +
        '<div class="row cr-foot"><span class="muted small">' + c.question_count + ' question' + (c.question_count === 1 ? '' : 's') + (c.item_count ? ' · ' + c.item_count + ' section' + (c.item_count === 1 ? '' : 's') : '') + '</span><span class="right">' + st + '</span></div></div></a>';
    };
    v.innerHTML = '<div class="top"><div><h1>Learn</h1><div class="muted">Courses with tests, set by your administrator</div></div><span class="right row">' + (D.can_grades ? '<a class="btn ghost" href="#/learn/c/grades">Grades</a>' : '') + (D.can_manage ? '<button class="btn" id="cnew">+ New course</button>' : '') + '</span></div>' + tabs('courses') +
      (D.courses.length ? '<div class="cr-grid">' + D.courses.map(card).join('') + '</div>' : '<div class="card empty">' + (D.can_manage ? 'No courses yet. Click “New course” to create the first one.' : 'No courses have been assigned to you yet.') + '</div>');
    const n = $('#cnew'); if (n) n.onclick = () => { const m = modal('<h2>New course</h2><form id="ncf" style="margin-top:12px"><div class="field"><label>Course title</label><input name="title" required maxlength="200" autofocus></div><div class="err" id="er"></div><div class="row"><button class="btn">Create</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
      $('#cx', m).onclick = () => m.close(); $('#ncf', m).onsubmit = async e => { e.preventDefault(); try { const r = await api('courses', { method: 'POST', data: { title: new FormData(e.target).get('title') } }); m.close(); location.hash = '#/learn/c/' + r.id + '/settings'; } catch (er) { $('#er', m).textContent = er.message; } }; };
  }

  /* ---------- one course ---------- */
  async function coursePage(id, tab) {
    const v = view(); v.innerHTML = '<div class="empty">Loading…</div>'; const c = await api('courses/' + id); const m = c.mine, adm = c.can_manage;
    const left = m.remaining, canTest = c.can_take && c.question_count > 0 && (left === null || left > 0 || m.open_attempt);
    const action = ro() ? '' : !c.question_count ? '<span class="muted small">The test is not ready yet.</span>' : m.open_attempt ? '<a class="btn" href="#/learn/c/' + id + '/test">Resume test</a>' : canTest ? '<a class="btn" href="#/learn/c/' + id + '/test">' + (m.count ? 'Retake test' : 'Take the test') + '</a>' : '<span class="tag blocked">No attempts left</span>';
    const facts = [c.question_count + ' questions' + (c.questions_per_test && c.questions_per_test < c.question_count ? ' (' + c.questions_per_test + ' per test)' : ''), 'Pass mark ' + c.pass_mark + '%', c.time_limit_min ? c.time_limit_min + ' min limit' : 'No time limit', c.max_attempts ? c.max_attempts + ' attempt' + (c.max_attempts > 1 ? 's' : '') : 'Unlimited attempts'];
    v.innerHTML = back('#/learn/c', 'Courses') + '<div class="card cr-hero"><div class="cr-himg">' + (c.image_url ? '<img src="' + esc(c.image_url) + '" alt="" data-rm="1">' : '') + '</div><div class="grow"><div class="row"><h1 class="grow">' + esc(c.title) + '</h1>' + (c.status === 'draft' ? '<span class="tag deferred">Draft: only you can see it</span>' : '') + '</div>' +
      '<p style="white-space:pre-wrap;margin:6px 0">' + esc(c.description || '') + '</p><div class="cr-tags">' + (c.departments.length ? c.departments.map(d => '<span class="tag">' + esc(d) + '</span>').join('') : '<span class="tag">Everyone</span>') + facts.map(f => '<span class="tag in_progress">' + esc(f) + '</span>').join('') + '</div>' +
      '<div class="row" style="margin-top:12px">' + action + (m.count ? '<span class="small muted">Best ' + pct(m.best) + (m.passed ? ' · Passed ✓' : '') + (left !== null ? ' · ' + left + ' attempt' + (left === 1 ? '' : 's') + ' left' : '') + '</span>' : '') + '</div></div></div>' +
      (adm || c.can_grades ? '<div class="chips lt-tabs" style="margin:14px 0"><a class="chip' + (tab === 'overview' ? ' on' : '') + '" href="#/learn/c/' + id + '">Course</a>' + (adm ? '<a class="chip' + (tab === 'questions' ? ' on' : '') + '" href="#/learn/c/' + id + '/questions">Questions (' + c.question_count + ')</a><a class="chip' + (tab === 'settings' ? ' on' : '') + '" href="#/learn/c/' + id + '/settings">Settings</a>' : '') + (c.can_grades ? '<a class="chip' + (tab === 'grades' ? ' on' : '') + '" href="#/learn/c/' + id + '/grades">Grades</a>' : '') + '</div>' : '<div style="height:14px"></div>') +
      '<div id="cbody"></div>';
    const box = $('#cbody');
    if (tab === 'overview') { if (X.courseOverview) X.courseOverview(box, c); else overview(box, c); } else if (tab === 'questions') { if (X.courseQuestions) await X.courseQuestions(box, c); else await questionsTab(box, c); } else if (tab === 'settings') await settingsTab(box, c); else await gradesTab(box, c);
  }

  const video = d => d.provider === 'youtube' ? '<div class="cr-video"><iframe src="https://www.youtube-nocookie.com/embed/' + esc(d.id) + '" title="Video" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"></iframe></div><div class="small" style="margin-top:4px"><a href="https://www.youtube.com/watch?v=' + esc(d.id) + '" target="_blank" rel="noopener noreferrer">Open on YouTube</a></div>'
    : '<div class="cr-video"><iframe src="https://player.vimeo.com/video/' + esc(d.id) + (d.h ? '?h=' + esc(d.h) : '') + '" title="Video" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; picture-in-picture"></iframe></div><div class="small" style="margin-top:4px"><a href="https://vimeo.com/' + esc(d.id) + '" target="_blank" rel="noopener noreferrer">Open on Vimeo</a></div>';
  function slides(it) {
    const S_ = (it.data && it.data.slides) || []; let i = 0;
    const box = document.createElement('div'); box.className = 'cr-slides'; box.tabIndex = 0;
    const draw = () => { const s = S_[i]; box.innerHTML = '<div class="cr-slide">' + (s.title ? '<h3>' + esc(s.title) + '</h3>' : '') + s.blocks.map(b => b.k === 'img' ? '<img src="' + esc(b.v) + '" alt="" data-rm="1">' : '<p>' + esc(b.v) + '</p>').join('') + (!s.title && !s.blocks.length ? '<p class="muted">(This slide has no text.)</p>' : '') + '</div>' +
      '<div class="row cr-snav"><button class="btn ghost sm" data-p' + (i === 0 ? ' disabled' : '') + '>‹ Previous</button><span class="small muted grow" style="text-align:center">Slide ' + (i + 1) + ' of ' + S_.length + '</span><button class="btn ghost sm" data-n' + (i === S_.length - 1 ? ' disabled' : '') + '>Next ›</button></div>'; $('[data-p]', box).onclick = () => { i--; draw(); }; $('[data-n]', box).onclick = () => { i++; draw(); }; };
    box.onkeydown = e => { if (e.key === 'ArrowRight' && i < S_.length - 1) { i++; draw(); box.focus(); } else if (e.key === 'ArrowLeft' && i > 0) { i--; draw(); box.focus(); } };
    if (S_.length) draw(); else box.innerHTML = '<div class="muted">No slides.</div>'; return box;
  }
  function overview(box, c) {
    const adm = c.can_manage;
    box.innerHTML = (c.items.length ? '' : '<div class="card empty">' + (adm ? 'No lessons yet. Add text, a video, a link or a PowerPoint below.' : 'There are no lessons in this course yet.') + '</div>') + '<div id="cits"></div>' +
      (adm ? '<div class="card" style="margin-top:14px"><h3 style="margin-bottom:8px">Add to this course</h3><div class="row"><button class="btn ghost sm" data-add="text">+ Text</button><button class="btn ghost sm" data-add="video">+ YouTube / Vimeo video</button><button class="btn ghost sm" data-add="link">+ Link</button><label class="btn ghost sm" style="cursor:pointer;margin:0">+ PowerPoint (.pptx)<input type="file" id="cppt" accept=".pptx" hidden></label></div><div class="muted small" style="margin-top:6px">PowerPoint files are read here: their slides appear in the course. Old .ppt files must be saved as .pptx first.</div></div>' : '');
    const host = $('#cits', box);
    c.items.forEach((it, n) => {
      const card = document.createElement('div'); card.className = 'card cr-item';
      const body = it.kind === 'text' ? '<div class="cr-text">' + nl(it.body) + '</div>' : it.kind === 'video' ? video(it.data || {}) : it.kind === 'link' ? '<div class="small muted" style="margin-bottom:6px">' + esc(it.body || '') + '</div><a class="btn ghost sm" href="' + esc(it.url) + '" target="_blank" rel="noopener noreferrer nofollow">Open link ↗</a>' : '<div id="sl' + it.id + '"></div>';
      card.innerHTML = '<div class="row"><h3 class="grow">' + esc(it.title) + '</h3><span class="tag">' + ({ text: 'Notes', video: 'Video', link: 'Link', slides: 'Slides' })[it.kind] + '</span>' + (adm ? '<span class="cr-ctl"><button class="btn ghost sm" data-up="' + it.id + '"' + (n === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button><button class="btn ghost sm" data-dn="' + it.id + '"' + (n === c.items.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button><button class="btn ghost sm" data-ed="' + it.id + '">Edit</button><button class="btn ghost sm" data-rm-it="' + it.id + '" style="color:var(--bad)">Remove</button></span>' : '') + '</div><div style="margin-top:8px">' + body + '</div>';
      host.appendChild(card); if (it.kind === 'slides') $('#sl' + it.id, card).replaceWith(slides(it));
    });
    if (!adm) return;
    const move = async (id, dir) => { await api('courses/' + c.id + '/items/' + id + '/move', { method: 'POST', data: { dir } }); X.render(); };
    $$('[data-up]', box).forEach(b => b.onclick = () => move(b.dataset.up, -1)); $$('[data-dn]', box).forEach(b => b.onclick = () => move(b.dataset.dn, 1));
    $$('[data-rm-it]', box).forEach(b => b.onclick = async () => { if (!confirm('Remove this from the course?')) return; await api('courses/' + c.id + '/items/' + b.dataset.rmIt, { method: 'DELETE' }); X.render(); });
    $$('[data-ed]', box).forEach(b => b.onclick = () => itemForm(c, c.items.find(i => i.id == b.dataset.ed)));
    $$('[data-add]', box).forEach(b => b.onclick = () => itemForm(c, null, b.dataset.add));
    $('#cppt', box).onchange = async e => { const f = e.target.files[0]; if (!f) return; if (f.size > 15e6) return toast('That file is larger than 15 MB'); const t = toast; t('Reading the presentation…');
      try { const r = await api('courses/' + c.id + '/slides', { method: 'POST', data: { name: f.name, data: await b64(f) } }); toast(r.slides + ' slides added'); X.render(); } catch (er) { toast(er.message); } };
  }
  function itemForm(c, it, kind) {
    kind = it ? it.kind : kind; const names = { text: 'Text', video: 'Video', link: 'Link', slides: 'Slides' };
    const m = modal('<h2>' + (it ? 'Edit' : 'Add') + ' ' + names[kind].toLowerCase() + '</h2><form id="itf" style="margin-top:12px"><div class="field"><label>Heading</label><input name="title" maxlength="200" value="' + esc(it ? it.title : '') + '" placeholder="' + (kind === 'video' ? 'e.g. Pre-flight walk-through' : '') + '"></div>' +
      (kind === 'text' ? '<div class="field"><label>Text</label><textarea name="body" rows="9" required maxlength="20000">' + esc(it ? it.body : '') + '</textarea></div>' : '') +
      (kind === 'video' ? '<div class="field"><label>YouTube or Vimeo link</label><input name="url" type="url" required value="' + esc(it ? it.url : '') + '" placeholder="https://www.youtube.com/watch?v=…"></div>' : '') +
      (kind === 'link' ? '<div class="field"><label>Link (https://…)</label><input name="url" type="url" required value="' + esc(it ? it.url : '') + '"></div><div class="field"><label>Short description (optional)</label><input name="body" maxlength="500" value="' + esc(it && it.body || '') + '"></div>' : '') +
      '<div class="err" id="er"></div><div class="row"><button class="btn">' + (it ? 'Save' : 'Add') + '</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    $('#itf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); if (!it) f.kind = kind; try { await api('courses/' + c.id + '/items' + (it ? '/' + it.id : ''), { method: it ? 'PATCH' : 'POST', data: f }); m.close(); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
  }

  /* ---------- questions (admin) ---------- */
  async function questionsTab(box, c) {
    box.innerHTML = '<div class="empty">Loading…</div>'; const { questions: Q } = await api('courses/' + c.id + '/questions');
    box.innerHTML = '<div class="card"><div class="row"><h2 class="grow">Question bank <span class="muted small">(' + Q.length + ')</span></h2><button class="btn ghost sm" id="qimp">Import spreadsheet</button><a class="btn ghost sm" href="api/courses/template" download>Download template</a><button class="btn sm" id="qadd">+ Add question</button></div>' +
      '<div class="muted small" style="margin:6px 0 12px">Each test picks ' + (c.questions_per_test && c.questions_per_test < Q.length ? c.questions_per_test + ' at random from the bank' : 'all of the questions') + '. Questions are objective, with 2 to 5 answers (A to E).</div>' +
      (Q.length ? '<div class="tablewrap"><table class="cr-q"><thead><tr><th>#</th><th>Question</th><th>Answers</th><th>Correct</th><th></th></tr></thead><tbody>' + Q.map((q, i) => '<tr><td>' + (i + 1) + '</td><td>' + esc(q.question.slice(0, 160)) + (q.image_url ? ' 🖼' : '') + (q.hint ? ' <span class="muted small" title="' + esc(q.hint) + '">💡</span>' : '') + '</td><td class="small muted">' + LET.filter(l => q['opt_' + l.toLowerCase()]).length + '</td><td><b>' + esc(q.correct) + '</b> <span class="small muted">' + esc((q['opt_' + q.correct.toLowerCase()] || '').slice(0, 40)) + '</span></td><td style="white-space:nowrap"><button class="btn ghost sm" data-eq="' + q.id + '">Edit</button> <button class="btn ghost sm" data-dq="' + q.id + '" style="color:var(--bad)">✕</button></td></tr>').join('') + '</tbody></table></div><div style="margin-top:10px"><button class="btn ghost sm" id="qall" style="color:var(--bad)">Delete all questions</button></div>' : '<div class="empty">No questions yet. Add them one by one, or import a spreadsheet.</div>') + '</div>';
    $('#qadd', box).onclick = () => qForm(c, null); $('#qimp', box).onclick = () => importForm(c);
    $$('[data-eq]', box).forEach(b => b.onclick = () => qForm(c, Q.find(q => q.id == b.dataset.eq)));
    $$('[data-dq]', box).forEach(b => b.onclick = async () => { if (!confirm('Delete this question?')) return; await api('courses/' + c.id + '/questions/' + b.dataset.dq, { method: 'DELETE' }); X.render(); });
    const all = $('#qall', box); if (all) all.onclick = async () => { if (!confirm('Delete ALL ' + Q.length + ' questions of this course? This cannot be undone.')) return; await api('courses/' + c.id + '/questions', { method: 'DELETE' }); X.render(); };
  }
  function qForm(c, q) {
    const m = modal('<h2>' + (q ? 'Edit question' : 'New question') + '</h2><form id="qf" style="margin-top:12px"><div class="field"><label>Question</label><textarea name="question" rows="3" required maxlength="2000">' + esc(q ? q.question : '') + '</textarea></div><div class="field"><label>Image link (optional, https://…)</label><input name="image_url" value="' + esc(q && q.image_url || '') + '"></div>' +
      '<div class="muted small" style="margin-bottom:6px">Answers: fill at least two, and tick the correct one.</div>' + LET.map(l => '<div class="cr-opt"><label class="cr-rad"><input type="radio" name="correct" value="' + l + '"' + (q ? (q.correct === l ? ' checked' : '') : (l === 'A' ? ' checked' : '')) + ' aria-label="Answer ' + l + ' is correct"><b>' + l + '</b></label><input name="opt_' + l.toLowerCase() + '" maxlength="500" value="' + esc(q && q['opt_' + l.toLowerCase()] || '') + '" placeholder="Answer ' + l + (l > 'B' ? ' (optional)' : '') + '"></div>').join('') +
      '<div class="field" style="margin-top:8px"><label>Hint (optional, learners can open it)</label><input name="hint" maxlength="500" value="' + esc(q && q.hint || '') + '"></div><div class="err" id="er"></div><div class="row"><button class="btn">' + (q ? 'Save' : 'Add question') + '</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    $('#qf', m).onsubmit = async e => { e.preventDefault(); try { await api('courses/' + c.id + '/questions' + (q ? '/' + q.id : ''), { method: q ? 'PATCH' : 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
  }
  function importForm(c) {
    const m = modal('<h2>Import questions from a spreadsheet</h2><p class="muted small" style="margin:6px 0 12px">Use an Excel (.xlsx) or CSV file with these columns: <b>Questions, Correct Answer, Image Link, Answer A, Answer B, Answer C, Answer D, Answer E, Hint</b>. “Correct Answer” is a letter A–E (or the full text of the right answer). <a href="api/courses/template" download>Download the template</a>.</p>' +
      '<form id="imf"><div class="field"><input type="file" name="file" accept=".xlsx,.csv,.txt" required></div><div class="field"><label class="small"><input type="radio" name="mode" value="append" checked style="width:auto"> Add to the existing questions</label><br><label class="small"><input type="radio" name="mode" value="replace" style="width:auto"> Replace all existing questions</label></div><div class="err" id="er"></div><div id="res"></div><div class="row"><button class="btn" id="go">Import</button><button class="btn ghost" type="button" id="cx">Close</button></div></form>');
    $('#cx', m).onclick = () => { m.close(); X.render(); };
    $('#imf', m).onsubmit = async e => { e.preventDefault(); const f = e.target.file.files[0]; if (!f) return; if (e.target.mode.value === 'replace' && !confirm('Replace ALL existing questions with the ones in this file?')) return; const go = $('#go', m); go.disabled = true; $('#er', m).textContent = '';
      try { const r = await api('courses/' + c.id + '/questions/import', { method: 'POST', data: { name: f.name, data: await b64(f), mode: e.target.mode.value } });
        $('#res', m).innerHTML = '<div class="card" style="margin:10px 0;box-shadow:none;background:var(--mist)"><b style="color:var(--ok)">' + r.added + ' question' + (r.added === 1 ? '' : 's') + ' added</b>' + (r.skipped ? ' · <b style="color:var(--bad)">' + r.skipped + ' skipped</b>' : '') + (r.fixed ? '<div class="small muted" style="margin-top:6px">' + r.fixed + ' question' + (r.fixed === 1 ? ' had' : 's had') + ' a correct answer that was not listed under Answer A–E, so it was added as one of the choices (the last wrong choice was dropped when all five were full). Please skim those questions.</div>' : '') + ' · the course now has ' + r.total + '.' +
          (r.errors.length ? '<div class="small" style="margin-top:8px;max-height:180px;overflow:auto">' + r.errors.map(x => 'Row ' + x.row + ': ' + esc(x.error)).join('<br>') + '</div>' : '') + '</div>'; go.textContent = 'Import another'; go.disabled = false; e.target.reset(); }
      catch (er) { $('#er', m).textContent = er.message; go.disabled = false; } };
  }

  /* ---------- settings (admin) ---------- */
  async function settingsTab(box, c) {
    await X.loadRefs(); const users = S.users.filter(u => +u.active === 1 && u.role !== 'founder'); const vset = new Set(c.viewer_ids || []);
    const att = [[1, '1 (no retakes)'], [2, '2'], [3, '3'], [5, '5'], [10, '10'], [0, 'Unlimited']]; if (!att.some(a => a[0] === c.max_attempts)) att.splice(att.length - 1, 0, [c.max_attempts, String(c.max_attempts)]);
    box.innerHTML = '<form class="card" id="cs"><h2 style="margin-bottom:10px">Course settings</h2><div class="field"><label>Title</label><input name="title" required maxlength="200" value="' + esc(c.title) + '"></div><div class="field"><label>Description</label><textarea name="description" rows="3" maxlength="5000">' + esc(c.description || '') + '</textarea></div>' +
      '<div class="field"><label>Course picture</label><div class="row"><input name="image_url" id="cimg" value="' + esc(c.image_url || '') + '" placeholder="https://… link, or upload a picture" style="flex:1;min-width:200px"><label class="btn ghost sm" style="cursor:pointer;margin:0">Upload<input type="file" id="cup" accept="image/png,image/jpeg,image/webp,image/gif" hidden></label></div></div>' +
      '<div class="grid2"><div class="field"><label>Status</label><select name="status">' + opt('draft', 'Draft (only you can see it)', c.status) + opt('published', 'Published', c.status) + '</select></div><div class="field"><label>Category (optional)</label><input name="category" maxlength="80" value="' + esc(c.category || '') + '"></div></div>' +
      '<h3 style="margin:14px 0 8px">The test</h3><div class="grid2"><div class="field"><label>Pass mark (%)</label><input type="number" name="pass_mark" min="1" max="100" value="' + c.pass_mark + '"></div><div class="field"><label>Questions per test <span class="muted small">(0 = all of the bank)</span></label><input type="number" name="questions_per_test" min="0" max="1000" value="' + c.questions_per_test + '"></div></div>' +
      '<div class="grid2"><div class="field"><label>Attempts allowed per person</label><select name="max_attempts">' + att.map(a => opt(a[0], a[1], c.max_attempts)).join('') + '</select><div class="muted small">More attempts for one person can be given from the Grades tab.</div></div><div class="field"><label>Time limit (minutes, 0 = none)</label><input type="number" name="time_limit_min" min="0" max="600" value="' + c.time_limit_min + '"></div></div>' +
      '<div class="cr-checks"><label><input type="checkbox" name="shuffle_questions"' + (+c.shuffle_questions ? ' checked' : '') + '> Mix up the order of questions</label><label><input type="checkbox" name="shuffle_answers"' + (+c.shuffle_answers ? ' checked' : '') + '> Mix up the order of answers</label><label><input type="checkbox" name="show_answers"' + (+c.show_answers ? ' checked' : '') + '> Show the right answers after the test</label></div>' +
      '<h3 style="margin:14px 0 6px">Who takes it</h3><div class="muted small" style="margin-bottom:6px">Tick the departments this course is for. Leave all unticked for everyone.</div><div class="chips" id="cdp">' + S.depts.map(d => '<label class="chip" style="cursor:pointer"><input type="checkbox" value="' + d.id + '" style="width:auto"' + (c.department_ids.includes(+d.id) ? ' checked' : '') + '> ' + esc(d.name) + '</label>').join('') + '</div>' +
      '<h3 style="margin:14px 0 6px">Who sees everyone’s grades</h3><div class="muted small" style="margin-bottom:6px">You and the Founder always can. Everyone else sees only their own result, unless you pick them here.</div><div class="cr-checks"><label><input type="checkbox" name="heads_see_grades"' + (+c.heads_see_grades ? ' checked' : '') + '> Department heads and managers see the grades of their own department</label></div>' +
      '<input id="cvq" placeholder="Search people" aria-label="Search people" style="margin:8px 0"><div class="rp-pick" id="cvl" style="max-height:200px">' + users.map(u => '<label class="rp-pickrow" data-name="' + esc(u.name.toLowerCase()) + '"><input type="checkbox" value="' + u.id + '"' + (vset.has(+u.id) ? ' checked' : '') + '><span>' + esc(u.name) + '</span><span class="muted small">' + esc(u.department || u.job_title || '') + '</span></label>').join('') + '</div>' +
      '<div class="cr-checks" style="margin-top:12px"><label><input type="checkbox" name="notify"> Tell the people it is for (when published)</label></div><div class="err" id="er"></div><div class="row" style="margin-top:12px"><button class="btn">Save settings</button><button class="btn ghost right" type="button" id="cdel" style="color:var(--bad)">Delete course</button></div></form>';
    const f = $('#cs', box); $('#cvq', box).oninput = e => { const q = e.target.value.toLowerCase(); $$('#cvl .rp-pickrow', box).forEach(l => l.style.display = l.dataset.name.includes(q) ? '' : 'none'); };
    $('#cup', box).onchange = async e => { const file = e.target.files[0]; if (!file) return; try { const data = await shrink(file); const r = await api('learn/img', { method: 'POST', data: { data } }); $('#cimg', box).value = r.url; toast('Picture uploaded: remember to save'); } catch (er) { toast(er.message || 'Could not use that picture'); } };
    f.onsubmit = async e => { e.preventDefault(); const d = Object.fromEntries(new FormData(f)); for (const k of ['shuffle_questions', 'shuffle_answers', 'show_answers', 'heads_see_grades', 'notify']) d[k] = f.elements[k].checked;
      d.department_ids = $$('#cdp input:checked', box).map(i => +i.value); d.viewer_ids = $$('#cvl input:checked', box).map(i => +i.value);
      try { await api('courses/' + c.id, { method: 'PATCH', data: d }); toast('Saved'); X.render(); } catch (er) { $('#er', box).textContent = er.message; } };
    $('#cdel', box).onclick = async () => { if (!confirm('Delete “' + c.title + '” with its lessons, questions and everybody’s results? This cannot be undone.')) return; await api('courses/' + c.id, { method: 'DELETE' }); location.hash = '#/learn/c'; };
  }
  function shrink(file) {
    return new Promise((res, rej) => { if (!/^image\//.test(file.type)) return rej(new Error('Choose a picture'));
      const img = new Image(), url = URL.createObjectURL(file); img.onload = () => { const k = Math.min(1, 1200 / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); res(c.toDataURL('image/jpeg', 0.85)); };
      img.onerror = () => rej(new Error('That picture could not be read')); img.src = url; });
  }

  /* ---------- grades ---------- */
  async function gradesTab(box, c) {
    box.innerHTML = '<div class="empty">Loading…</div>'; const G = await api('courses/' + c.id + '/grades'); const s = G.summary;
    box.innerHTML = '<div class="cards" style="margin-bottom:14px"><div class="card stat"><span class="muted small">People</span><b>' + s.assigned + '</b></div><div class="card stat"><span class="muted small">Have taken it</span><b>' + s.taken + '</b></div><div class="card stat ok"><span class="muted small">Passed</span><b>' + s.passed + '</b></div><div class="card stat"><span class="muted small">Average best</span><b>' + pct(s.average_best) + '</b></div></div>' +
      '<div class="card"><div class="row"><h2 class="grow">Grades</h2><span class="muted small">' + (G.scope === 'all' ? 'Everyone' : 'Your department') + ' · pass mark ' + G.course.pass_mark + '%</span><a class="btn ghost sm" href="api/courses/' + c.id + '/grades?format=csv" download>Export CSV</a></div><div class="tablewrap"><table><thead><tr><th>Name</th><th>Department</th><th>Attempts</th><th>Best</th><th>Latest</th><th>Result</th><th>Last attempt</th><th></th></tr></thead><tbody>' +
      (G.rows.length ? G.rows.map(r => '<tr><td><b>' + esc(r.name) + '</b></td><td class="small">' + esc(r.department || '—') + '</td><td>' + r.attempts + (+r.extra ? ' <span class="tag" title="Extra attempts given">+' + r.extra + '</span>' : '') + '</td><td>' + pct(r.best) + '</td><td>' + pct(r.latest) + '</td><td>' + (r.attempts ? (r.passed ? '<span class="tag done">Passed</span>' : '<span class="tag blocked">Not passed</span>') : '<span class="tag">Not taken</span>') + '</td><td class="small">' + fmtD(r.last_at) + '</td><td style="white-space:nowrap">' + (r.attempts ? '<button class="btn ghost sm" data-h="' + r.user_id + '">History</button> ' : '') + (G.can_grant && r.attempts ? '<button class="btn ghost sm" data-g="' + r.user_id + '" title="Allow one more attempt">Allow retake</button>' : '') + '</td></tr>').join('') : '<tr><td colspan="8" class="muted">Nobody is assigned to this course yet.</td></tr>') + '</tbody></table></div></div>';
    $$('[data-h]', box).forEach(b => b.onclick = async () => { const A = (await api('courses/' + c.id + '/grades?user=' + b.dataset.h)).attempts; const p = G.rows.find(r => r.user_id == b.dataset.h);
      const m = modal('<div class="row"><h2 class="grow">' + esc(p.name) + '</h2><button class="btn ghost sm" id="cx">Close</button></div><div class="list" style="margin-top:8px">' + (A.length ? A.map(a => '<div class="item" data-a="' + a.id + '"><div class="grow"><b>Attempt ' + a.attempt_no + '</b> <span class="muted small">' + fmtD(a.submitted_at) + '</span></div><span>' + a.correct_count + '/' + a.total + ' · <b>' + pct(+a.score) + '</b></span>' + (+a.passed ? '<span class="tag done">Passed</span>' : '<span class="tag blocked">Not passed</span>') + '</div>').join('') : '<div class="empty">No attempts.</div>') + '</div>'); $('#cx', m).onclick = () => m.close();
      $$('[data-a]', m).forEach(el => el.onclick = () => { m.close(); location.hash = '#/learn/c/' + c.id + '/result/' + el.dataset.a; }); });
    $$('[data-g]', box).forEach(b => b.onclick = async () => { if (!confirm('Allow this person one more attempt?')) return; await api('courses/' + c.id + '/grant', { method: 'POST', data: { user_id: +b.dataset.g, extra: 1 } }); toast('Retake allowed'); X.render(); });
  }
  async function gradesOverview() {
    const v = view(); v.innerHTML = '<div class="empty">Loading…</div>'; const D = await api('courses/grades');
    v.innerHTML = back('#/learn/c', 'Courses') + '<div class="top"><div><h1>Grades</h1><div class="muted">Results for the courses you may see grades for</div></div></div><div class="card"><div class="tablewrap"><table><thead><tr><th>Course</th><th>People</th><th>Taken</th><th>Passed</th><th>Average best</th><th></th></tr></thead><tbody>' +
      (D.courses.length ? D.courses.map(c => '<tr><td><b>' + esc(c.title) + '</b>' + (c.status === 'draft' ? ' <span class="tag deferred">Draft</span>' : '') + '</td><td>' + c.assigned + '</td><td>' + c.attempted + '</td><td>' + c.passed + '</td><td>' + pct(c.average_best) + '</td><td><a class="btn ghost sm" href="#/learn/c/' + c.id + '/grades">Open</a></td></tr>').join('') : '<tr><td colspan="6" class="muted">Nothing to show.</td></tr>') + '</tbody></table></div></div>';
  }

  /* ---------- taking a test ---------- */
  async function testView(id) {
    if (X.courseTest) return X.courseTest(id);
    const v = view(); v.innerHTML = '<div class="empty">Loading the test…</div>'; const c = await api('courses/' + id); let T;
    try { T = await api('courses/' + id + '/start', { method: 'POST', data: {} }); } catch (e) { v.innerHTML = back('#/learn/c/' + id, c.title) + '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const ans = { ...T.answers }; let done = false, saveT = 0; const total = T.questions.length;
    v.innerHTML = '<div class="cr-test"><div class="cr-sticky"><div class="row"><b class="grow">' + esc(c.title) + ' · Attempt ' + T.attempt_no + '</b><span id="tt" class="tag in_progress" style="display:none"></span><span class="small muted" id="tcount"></span></div><div class="rp-bar" style="margin-top:6px"><i id="tbar" style="width:0"></i></div></div>' +
      T.questions.map((q, n) => '<div class="card cr-q-card" id="q' + q.id + '"><div class="cr-qn">Question ' + (n + 1) + ' of ' + total + '</div><div class="cr-qt">' + esc(q.question).replace(/\n/g, '<br>') + '</div>' + (q.image_url ? '<img class="cr-qimg" src="' + esc(q.image_url) + '" alt="" loading="lazy" data-rm="1">' : '') +
        '<div class="cr-opts" role="radiogroup" aria-label="Answers to question ' + (n + 1) + '">' + q.options.map((o, i) => '<label class="cr-o"><input type="radio" name="q' + q.id + '" value="' + o.k + '"' + (ans[q.id] === o.k ? ' checked' : '') + '><span class="cr-l">' + LET[i] + '</span><span>' + esc(o.text) + '</span></label>').join('') + '</div>' +
        (q.hint ? '<div class="cr-hint"><button type="button" class="btn ghost sm" data-hint="' + q.id + '">💡 Hint</button><span class="small muted" id="h' + q.id + '" style="display:none"> ' + esc(q.hint) + '</span></div>' : '') + '</div>').join('') +
      '<div class="cr-bar"><span id="tleft" class="small"></span><button class="btn" id="tsub">Submit answers</button></div></div>';
    const count = () => { const n = T.questions.filter(q => ans[q.id]).length; $('#tcount').textContent = n + ' of ' + total + ' answered'; $('#tbar').style.width = Math.round(n * 100 / total) + '%'; $('#tleft').textContent = n === total ? 'All answered' : (total - n) + ' not answered yet'; }; count();
    const persist = () => { clearTimeout(saveT); saveT = setTimeout(() => api('courses/' + id + '/attempts/' + T.attempt_id + '/save', { method: 'POST', data: { answers: ans } }).catch(() => {}), 500); };
    $$('.cr-test input[type=radio]', v).forEach(r => r.onchange = () => { ans[r.name.slice(1)] = r.value; count(); persist(); });
    $$('[data-hint]', v).forEach(b => b.onclick = () => { const s = $('#h' + b.dataset.hint); s.style.display = s.style.display === 'none' ? '' : 'none'; });
    const submit = async auto => {
      if (done) return; const missing = T.questions.filter(q => !ans[q.id]).length; if (!auto && missing && !confirm(missing + ' question' + (missing > 1 ? 's are' : ' is') + ' not answered. Submit anyway?')) return; done = true; stopTimer(); $('#tsub').disabled = true;
      try { const r = await api('courses/' + id + '/attempts/' + T.attempt_id + '/submit', { method: 'POST', data: { answers: ans } }); if (r.queued) { done = false; $('#tsub').disabled = false; return toast('You are offline. Submit again when you are back online.'); } history.replaceState(null, '', '#/learn/c/' + id); resultHtml(v, c, r); window.scrollTo(0, 0); }
      catch (er) { done = false; $('#tsub').disabled = false; toast(er.message); } };
    $('#tsub').onclick = () => submit(false);
    if (T.seconds_left != null) { let left = T.seconds_left; const tt = $('#tt'); tt.style.display = ''; const tick = () => { const mm = Math.floor(left / 60), ss = left % 60; tt.textContent = '⏱ ' + mm + ':' + String(ss).padStart(2, '0'); tt.className = 'tag ' + (left <= 60 ? 'blocked' : 'in_progress'); if (left <= 0) { stopTimer(); toast('Time is up: submitting your answers'); submit(true); } left--; }; tick(); timer = setInterval(tick, 1000); }
  }
  function resultHtml(v, c, r) {
    const rev1 = (r.review && X.reviewHtml) ? X.reviewHtml(r.review) : null;
    const rev = rev1 !== null ? rev1 : r.review ? '<div class="cr-review">' + r.review.map((q, n) => '<div class="card cr-q-card ' + (q.is_correct ? 'ok' : 'bad') + '"><div class="cr-qn">Question ' + (n + 1) + ' · ' + (q.is_correct ? '<span style="color:var(--ok)">Correct ✓</span>' : q.selected ? '<span style="color:var(--bad)">Wrong ✗</span>' : '<span style="color:var(--bad)">Not answered</span>') + '</div><div class="cr-qt">' + esc(q.question).replace(/\n/g, '<br>') + '</div>' + (q.image_url ? '<img class="cr-qimg" src="' + esc(q.image_url) + '" alt="" loading="lazy" data-rm="1">' : '') +
      '<div class="cr-opts">' + q.options.map((o, i) => '<div class="cr-o ' + (o.k === q.correct ? 'is-right' : '') + (o.k === q.selected && o.k !== q.correct ? ' wrongpick' : '') + '"><span class="cr-l">' + LET[i] + '</span><span>' + esc(o.text) + '</span>' + (o.k === q.correct ? '<b class="small" style="margin-left:auto">✓ correct</b>' : o.k === q.selected ? '<b class="small" style="margin-left:auto">your answer</b>' : '') + '</div>').join('') + '</div>' + (q.hint ? '<div class="small muted" style="margin-top:6px">💡 ' + esc(q.hint) + '</div>' : '') + '</div>').join('') + '</div>' : '<div class="card muted" style="margin-top:14px">Your administrator has chosen not to show the right answers for this course.</div>';
    const again = r.remaining == null || r.remaining > 0;
    v.innerHTML = back('#/learn/c/' + c.id, c.title) + '<div class="card cr-score ' + (r.passed ? 'pass' : 'fail') + '"><div class="cr-big">' + pct(r.score) + '</div><div><h2>' + (r.passed ? 'You passed 🎉' : 'Not passed this time') + '</h2><div class="muted">' + r.correct + ' of ' + r.total + ' correct · pass mark ' + (r.pass_mark || c.pass_mark) + '%' + (r.remaining != null ? ' · ' + r.remaining + ' attempt' + (r.remaining === 1 ? '' : 's') + ' left' : '') + '</div><div class="row" style="margin-top:10px"><a class="btn" href="#/learn/c/' + c.id + '">Back to the course</a>' + (again ? '<a class="btn ghost" href="#/learn/c/' + c.id + '/test">Take it again</a>' : '') + '</div></div></div>' + rev;
  }
  async function resultView(id, aid) {
    const v = view(); v.innerHTML = '<div class="empty">Loading…</div>'; const c = await api('courses/' + id), r = await api('courses/' + id + '/attempts/' + aid);
    resultHtml(v, c, { ...r, pass_mark: c.pass_mark, remaining: 0 }); const h = $('h2', v); if (h && r.user_name) h.insertAdjacentHTML('afterend', '<div class="small muted">' + esc(r.user_name) + ' · attempt ' + r.attempt_no + ' · ' + fmtD(r.submitted_at) + '</div>');
    const ag = $$('a.btn', v).find(a => /Take it again/.test(a.textContent)); if (ag) ag.remove();
  }
  X.cr = { video, slides, nl, back, view, fail, shrink, b64, pct, fmtD, opt, LET, importForm };
})();
