/* Aerosub Office: the course exam. Counters for answered / not answered / flagged, a question map to jump to any question,
 * puzzle questions (put in order, match pairs), text + picture + video on every question, and the editor for all of it.
 * Loaded after courses.js. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || !X.views || !X.views.learn || !X.cr) return;
  const { api, modal, toast, esc, S } = X; const { LET } = X.cr;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const ro = () => S.user && S.user.role === 'founder';
  const TYPE = { mcq: 'Multiple choice', order: 'Puzzle: put in order', match: 'Puzzle: match pairs' };
  let timer = 0; const stopTimer = () => { if (timer) { clearInterval(timer); timer = 0; } };
  window.addEventListener('hashchange', () => { if (!/\/test$/.test(location.hash)) stopTimer(); });

  const media = q => (q.extra_text ? '<div class="cr-extra">' + X.cr.nl(q.extra_text).replace(/\n/g, '<br>') + '</div>' : '') +
    (q.image_url ? '<img class="cr-qimg" src="' + esc(q.image_url) + '" alt="" loading="lazy" data-rm="1">' : '') + (q.video ? '<div style="margin:8px 0">' + X.cr.video(q.video) + '</div>' : '');
  const lead = (q, n, total, extra) => '<div class="cr-qn">Question ' + (n + 1) + ' of ' + total + (q.qtype !== 'mcq' ? ' · <span class="tag">' + (q.qtype === 'order' ? 'Puzzle: order' : 'Puzzle: match') + '</span>' : '') + (extra || '') + '</div><div class="cr-qt">' + esc(q.question).replace(/\n/g, '<br>') + '</div>' + media(q);

  /* is a question answered? mcq: a letter; order: learner touched it; match: every pair has a pick */
  const answered = (q, a) => q.qtype === 'mcq' ? !!a : q.qtype === 'order' ? Array.isArray(a) && a.length === q.items.length : Array.isArray(a) && a.length === q.left.length && a.every(v => v >= 0);
  const partly = (q, a) => q.qtype === 'match' && Array.isArray(a) && a.some(v => v >= 0) && !answered(q, a);

  function qBody(q, ans, change) {   // the answer area of one question; calls change(value) when the learner answers
    if (q.qtype === 'mcq') return '<div class="cr-opts" role="radiogroup" aria-label="Answers">' + q.options.map((o, i) => '<label class="cr-o"><input type="radio" name="q' + q.id + '" value="' + o.k + '"' + (ans === o.k ? ' checked' : '') + '><span class="cr-l">' + LET[i] + '</span><span>' + esc(o.text) + '</span></label>').join('') + '</div>';
    if (q.qtype === 'order') {
      const cur = Array.isArray(ans) && ans.length === q.items.length ? ans.map(i => q.items.find(x => x.i === i)) : q.items;
      return '<div class="cr-ord" data-ord="' + q.id + '"><div class="muted small" style="margin-bottom:6px">Use the arrows to put these in the correct order.' + (Array.isArray(ans) ? '' : ' <b>Not set yet:</b> move something, or confirm the order as it is.') + '</div>' +
        cur.map((it, k) => '<div class="cr-ordi"><span class="cr-l">' + (k + 1) + '</span><span class="grow">' + esc(it.text) + '</span><button type="button" class="btn ghost sm" data-mv="' + k + '" data-d="-1"' + (k === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button><button type="button" class="btn ghost sm" data-mv="' + k + '" data-d="1"' + (k === cur.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button></div>').join('') +
        (Array.isArray(ans) ? '' : '<button type="button" class="btn ghost sm" data-keep style="margin-top:6px">This order is my answer</button>') + '</div>';
    }
    return '<div class="cr-mat"><div class="muted small" style="margin-bottom:6px">Pick the matching item for each one.</div>' + q.left.map((l, k) => '<div class="cr-mati"><span class="grow">' + esc(l.text) + '</span><select data-m="' + k + '" aria-label="Match for ' + esc(l.text) + '"><option value="-1">Choose…</option>' + q.right.map(r => '<option value="' + r.i + '"' + (Array.isArray(ans) && ans[k] === r.i ? ' selected' : '') + '>' + esc(r.text) + '</option>').join('') + '</select></div>').join('') + '</div>';
  }
  function wireQ(box, q, getA, change) {
    $$('input[type=radio]', box).forEach(r => r.onchange = () => change(r.value));
    $$('[data-mv]', box).forEach(b => b.onclick = () => { const a = Array.isArray(getA()) && getA().length === q.items.length ? getA().slice() : q.items.map(x => x.i), k = +b.dataset.mv, j = k + +b.dataset.d; [a[k], a[j]] = [a[j], a[k]]; change(a); });
    const keep = $('[data-keep]', box); if (keep) keep.onclick = () => change(q.items.map(x => x.i));
    $$('select[data-m]', box).forEach(s => s.onchange = () => { const a = Array.isArray(getA()) && getA().length === q.left.length ? getA().slice() : q.left.map(() => -1); a[+s.dataset.m] = +s.value; change(a); });
  }

  /* ---------- taking the test ---------- */
  X.courseTest = async id => {
    const v = X.cr.view(); stopTimer(); v.innerHTML = '<div class="empty">Loading the test…</div>'; const c = await api('courses/' + id); let T;
    try { T = await api('courses/' + id + '/start', { method: 'POST', data: {} }); } catch (e) { v.innerHTML = X.cr.back('#/learn/c/' + id, esc(c.title)) + '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const Q = T.questions, total = Q.length, ans = { ...(T.answers || {}) }, flag = new Set(); let done = false, saveT = 0, filter = 'all', cur = 0;
    const key = 'aero_flag_' + T.attempt_id; try { JSON.parse(sessionStorage.getItem(key) || '[]').forEach(x => flag.add(+x)); } catch {}
    const st = q => answered(q, ans[q.id]) ? 'a' : partly(q, ans[q.id]) ? 'p' : 'n';
    v.innerHTML = '<div class="cr-test"><div class="cr-sticky"><div class="row" style="flex-wrap:wrap;gap:8px"><b class="grow">' + esc(c.title) + ' · Attempt ' + T.attempt_no + '</b><span id="tt" class="tag in_progress" style="display:none"></span></div>' +
      '<div class="cr-counts" aria-live="polite"><span class="cc a"><b id="ca">0</b> answered</span><span class="cc n"><b id="cn">0</b> not answered</span><span class="cc f"><b id="cf">0</b> flagged</span></div><div class="rp-bar" style="margin:6px 0"><i id="tbar" style="width:0"></i></div>' +
      '<details class="cr-mapbox" open><summary class="small muted" style="cursor:pointer">Question map: tap a number to jump to it</summary><div class="cr-map" id="cmap" role="list"></div><div class="small muted cr-key"><span class="k a"></span> answered <span class="k n"></span> not answered <span class="k p"></span> partly <span class="k f"></span> flagged</div>' +
      '<div class="chips" style="margin-top:6px"><button type="button" class="chip on" data-f="all">All</button><button type="button" class="chip" data-f="n">Not answered</button><button type="button" class="chip" data-f="flag">Flagged</button><button type="button" class="chip" data-f="a">Answered</button></div></details></div><div id="cqs"></div>' +
      '<div class="cr-bar"><span id="tleft" class="small"></span><button class="btn" id="tsub">Submit answers</button></div></div>';
    const one = (q, n) => '<div class="card cr-q-card" id="q' + q.id + '" data-n="' + n + '"><div class="row">' + '<div class="grow">' + lead(q, n, total, ' <span class="cr-st" data-st></span>') + '</div><button type="button" class="btn ghost sm" data-flag="' + q.id + '" aria-pressed="false" title="Flag to come back to it">⚑ Flag</button></div><div data-body>' + qBody(q, ans[q.id]) + '</div>' +
      (q.hint ? '<div class="cr-hint"><button type="button" class="btn ghost sm" data-hint="' + q.id + '">💡 Hint</button><span class="small muted" id="h' + q.id + '" style="display:none"> ' + esc(q.hint) + '</span></div>' : '') + '</div>';
    $('#cqs', v).innerHTML = Q.map(one).join('');
    const persist = () => { clearTimeout(saveT); saveT = setTimeout(() => api('courses/' + id + '/attempts/' + T.attempt_id + '/save', { method: 'POST', data: { answers: ans } }).catch(() => {}), 500); };
    const paint = () => {
      const a = Q.filter(q => st(q) === 'a').length, f = Q.filter(q => flag.has(q.id)).length; $('#ca', v).textContent = a; $('#cn', v).textContent = total - a; $('#cf', v).textContent = f; $('#tbar', v).style.width = Math.round(a * 100 / total) + '%';
      $('#tleft', v).textContent = a === total ? 'All questions answered' : (total - a) + ' not answered yet';
      $('#cmap', v).innerHTML = Q.map((q, n) => '<button type="button" role="listitem" class="cm ' + st(q) + (flag.has(q.id) ? ' f' : '') + (n === cur ? ' cur' : '') + '" data-go="' + q.id + '" aria-label="Question ' + (n + 1) + ': ' + ({ a: 'answered', n: 'not answered', p: 'partly answered' }[st(q)]) + (flag.has(q.id) ? ', flagged' : '') + '">' + (n + 1) + '</button>').join('');
      Q.forEach((q, n) => { const el = $('#q' + q.id, v); const s = st(q); el.classList.toggle('is-done', s === 'a'); el.classList.toggle('is-flag', flag.has(q.id)); const t = $('[data-st]', el); t.textContent = s === 'a' ? '· answered ✓' : s === 'p' ? '· partly answered' : '· not answered'; t.className = 'cr-st ' + s; const fb = $('[data-flag]', el); fb.setAttribute('aria-pressed', flag.has(q.id)); fb.textContent = flag.has(q.id) ? '⚑ Flagged' : '⚑ Flag';
        el.style.display = filter === 'all' || (filter === 'n' && s !== 'a') || (filter === 'a' && s === 'a') || (filter === 'flag' && flag.has(q.id)) ? '' : 'none'; });
      $$('[data-go]', $('#cmap', v)).forEach(b => b.onclick = () => { const el = $('#q' + b.dataset.go, v); filter = 'all'; paint2(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); cur = +el.dataset.n; el.classList.add('pulse'); setTimeout(() => el.classList.remove('pulse'), 900); });
      try { sessionStorage.setItem(key, JSON.stringify([...flag])); } catch {}
    };
    const paint2 = () => { $$('[data-f]', v).forEach(b => b.classList.toggle('on', b.dataset.f === filter)); paint(); };
    const remount = q => { const el = $('#q' + q.id, v), body = $('[data-body]', el); const change = val => { ans[q.id] = val; body.innerHTML = qBody(q, val); wireQ(body, q, () => ans[q.id], change); paint(); persist(); }; wireQ(body, q, () => ans[q.id], change); };
    Q.forEach(remount);
    $$('[data-flag]', v).forEach(b => b.onclick = () => { const i = +b.dataset.flag; flag.has(i) ? flag.delete(i) : flag.add(i); paint(); });
    $$('[data-hint]', v).forEach(b => b.onclick = () => { const s = $('#h' + b.dataset.hint); s.style.display = s.style.display === 'none' ? '' : 'none'; });
    $$('[data-f]', v).forEach(b => b.onclick = () => { filter = b.dataset.f; paint2(); });
    paint2();
    const submit = async auto => {
      if (done) return; const miss = Q.map((q, n) => st(q) !== 'a' ? n + 1 : 0).filter(Boolean);
      if (!auto && miss.length && !confirm(miss.length + ' question' + (miss.length > 1 ? 's are' : ' is') + ' not fully answered (' + miss.slice(0, 12).join(', ') + (miss.length > 12 ? '…' : '') + '). Submit anyway?')) return;
      done = true; stopTimer(); $('#tsub', v).disabled = true;
      try { const r = await api('courses/' + id + '/attempts/' + T.attempt_id + '/submit', { method: 'POST', data: { answers: ans } }); if (r.queued) { done = false; $('#tsub', v).disabled = false; return toast('You are offline. Submit again when you are back online.'); } try { sessionStorage.removeItem(key); } catch {} location.hash = '#/learn/c/' + id + '/result/' + T.attempt_id; }
      catch (er) { done = false; $('#tsub', v).disabled = false; toast(er.message); } };
    $('#tsub', v).onclick = () => submit(false);
    if (T.seconds_left != null) { let left = T.seconds_left; const tt = $('#tt', v); tt.style.display = ''; const tick = () => { const mm = Math.floor(left / 60), ss = left % 60; tt.textContent = '⏱ ' + mm + ':' + String(ss).padStart(2, '0'); tt.className = 'tag ' + (left <= 60 ? 'blocked' : 'in_progress'); if (left <= 0) { stopTimer(); toast('Time is up: submitting your answers'); submit(true); } left--; }; tick(); timer = setInterval(tick, 1000); }
  };

  /* ---------- the review after a test (all question types) ---------- */
  X.reviewHtml = review => '<div class="cr-review">' + review.map((q, n) => {
    const ok = q.is_correct, sel = q.qtype === 'mcq' ? q.selected : q.answered;
    const head = '<div class="cr-qn">Question ' + (n + 1) + ' · ' + (ok ? '<span style="color:var(--ok)">Correct ✓</span>' : sel ? '<span style="color:var(--bad)">Wrong ✗</span>' : '<span style="color:var(--bad)">Not answered</span>') + (q.qtype !== 'mcq' ? ' · <span class="tag">' + (q.qtype === 'order' ? 'Puzzle: order' : 'Puzzle: match') + '</span>' : '') + '</div><div class="cr-qt">' + esc(q.question).replace(/\n/g, '<br>') + '</div>' + media(q);
    let body = '';
    if (q.qtype === 'mcq') body = '<div class="cr-opts">' + q.options.map((o, i) => '<div class="cr-o ' + (o.k === q.correct ? 'is-right' : '') + (o.k === q.selected && o.k !== q.correct ? ' wrongpick' : '') + '"><span class="cr-l">' + LET[i] + '</span><span>' + esc(o.text) + '</span>' + (o.k === q.correct ? '<b class="small" style="margin-left:auto">✓ correct</b>' : o.k === q.selected ? '<b class="small" style="margin-left:auto">your answer</b>' : '') + '</div>').join('') + '</div>';
    else if (q.qtype === 'order') body = '<div class="grid2"><div><div class="small muted">Your order</div>' + (q.given_items ? '<ol class="cr-ol">' + q.given_items.map(t => '<li>' + esc(t) + '</li>').join('') + '</ol>' : '<div class="small" style="color:var(--bad)">Not answered</div>') + '</div><div><div class="small muted">Correct order</div><ol class="cr-ol">' + q.correct_items.map(t => '<li>' + esc(t) + '</li>').join('') + '</ol></div></div>';
    else body = '<div class="cr-mat">' + q.pairs.map(p => '<div class="cr-mati ' + (p.ok ? 'is-right' : 'wrongpick') + '"><span class="grow">' + esc(p.l) + '</span><span>' + (p.ok ? '✓ ' + esc(p.r) : (p.given ? '✗ ' + esc(p.given) : '✗ no answer') + ' <b class="small">→ ' + esc(p.r) + '</b>') + '</span></div>').join('') + '</div>';
    return '<div class="card cr-q-card ' + (ok ? 'ok' : 'bad') + '">' + head + body + (q.hint ? '<div class="small muted" style="margin-top:6px">💡 ' + esc(q.hint) + '</div>' : '') + '</div>'; }).join('') + '</div>';

  /* ---------- the question bank and editor ---------- */
  X.courseQuestions = async (box, c) => {
    box.innerHTML = '<div class="empty">Loading…</div>'; const { questions: Q } = await api('courses/' + c.id + '/questions');
    const sum = q => q.qtype === 'order' ? ((q.puzzle && q.puzzle.items) || []).length + ' steps' : q.qtype === 'match' ? ((q.puzzle && q.puzzle.pairs) || []).length + ' pairs' : LET.filter(l => q['opt_' + l.toLowerCase()]).length + ' answers · correct <b>' + esc(q.correct) + '</b>';
    const has = q => (q.extra_text ? ' 📝' : '') + (q.image_url ? ' 🖼' : '') + (q.video ? ' ▶' : '') + (q.hint ? ' 💡' : '');
    box.innerHTML = '<div class="card"><div class="row" style="flex-wrap:wrap;gap:8px"><h2 class="grow">Question bank <span class="muted small">(' + Q.length + ')</span></h2><button class="btn ghost sm" id="qimp">Import spreadsheet</button><a class="btn ghost sm" href="api/courses/template" download>Download template</a><button class="btn sm" id="qadd">+ Add question</button></div>' +
      '<div class="muted small" style="margin:6px 0 12px">Each test picks ' + (c.questions_per_test && c.questions_per_test < Q.length ? c.questions_per_test + ' at random from the bank' : 'all of the questions') + '. Every question can have extra text, a picture and a video. Types: multiple choice, put-in-order puzzle, match-the-pairs puzzle.</div>' +
      (Q.length ? '<div class="tablewrap"><table class="cr-q"><thead><tr><th>#</th><th>Question</th><th>Type</th><th>Details</th><th></th></tr></thead><tbody>' + Q.map((q, i) => '<tr><td>' + (i + 1) + '</td><td>' + esc(q.question.slice(0, 150)) + '<span class="muted small">' + has(q) + '</span></td><td class="small">' + esc(TYPE[q.qtype || 'mcq']) + '</td><td class="small muted">' + sum(q) + '</td><td style="white-space:nowrap"><button class="btn ghost sm" data-mq="' + q.id + '" data-d="-1" aria-label="Move up">↑</button><button class="btn ghost sm" data-mq="' + q.id + '" data-d="1" aria-label="Move down">↓</button> <button class="btn ghost sm" data-eq="' + q.id + '">Edit</button> <button class="btn ghost sm" data-cq="' + q.id + '" title="Duplicate">⧉</button> <button class="btn ghost sm" data-dq="' + q.id + '" style="color:var(--bad)">✕</button></td></tr>').join('') + '</tbody></table></div><div style="margin-top:10px"><button class="btn ghost sm" id="qall" style="color:var(--bad)">Delete all questions</button></div>' : '<div class="empty">No questions yet. Add them one by one, or import a spreadsheet.</div>') + '</div>';
    $('#qadd', box).onclick = () => qEditor(c, null); $('#qimp', box).onclick = () => X.cr.importForm(c);
    $$('[data-eq]', box).forEach(b => b.onclick = () => qEditor(c, Q.find(q => q.id == b.dataset.eq)));
    $$('[data-dq]', box).forEach(b => b.onclick = async () => { if (!confirm('Delete this question?')) return; await api('courses/' + c.id + '/questions/' + b.dataset.dq, { method: 'DELETE' }); X.render(); });
    $$('[data-cq]', box).forEach(b => b.onclick = async () => { try { await api('courses/' + c.id + '/questions/' + b.dataset.cq + '/duplicate', { method: 'POST', data: {} }); toast('Question duplicated'); X.render(); } catch (e) { toast(e.message); } });
    $$('[data-mq]', box).forEach(b => b.onclick = async () => { try { await api('courses/' + c.id + '/questions/' + b.dataset.mq + '/move', { method: 'POST', data: { dir: +b.dataset.d } }); X.render(); } catch (e) { toast(e.message); } });
    const all = $('#qall', box); if (all) all.onclick = async () => { if (!confirm('Delete ALL ' + Q.length + ' questions of this course? This cannot be undone.')) return; await api('courses/' + c.id + '/questions', { method: 'DELETE' }); X.render(); };
  };
  function qEditor(c, q) {
    const type0 = q ? (q.qtype || 'mcq') : 'mcq', pz = (q && q.puzzle) || {}, vurl = q && q.video ? q.video.url || '' : '';
    const m = modal('<h2>' + (q ? 'Edit question' : 'New question') + '</h2><form id="qf" style="margin-top:12px">' +
      '<div class="field"><label>Type</label><select name="qtype" id="qt"' + (q ? ' disabled' : '') + '>' + Object.keys(TYPE).map(k => '<option value="' + k + '"' + (k === type0 ? ' selected' : '') + '>' + TYPE[k] + '</option>').join('') + '</select>' + (q ? '<div class="muted small">The type cannot be changed. Add a new question instead.</div>' : '') + '</div>' +
      '<div class="field"><label>Question</label><textarea name="question" rows="3" required maxlength="2000">' + esc(q ? q.question : '') + '</textarea></div>' +
      '<div class="field"><label>More text (optional): a scenario, a passage, notes</label><textarea name="extra_text" rows="3" maxlength="5000">' + esc(q && q.extra_text || '') + '</textarea></div>' +
      '<div class="field"><label>Picture (optional)</label><div class="row"><input name="image_url" value="' + esc(q && q.image_url || '') + '" placeholder="https://… link, or upload a picture" style="flex:1;min-width:180px"><label class="btn ghost sm" style="cursor:pointer;margin:0">Upload<input type="file" id="qup" accept="image/png,image/jpeg,image/webp,image/gif" hidden></label></div></div>' +
      '<div class="field"><label>Video (optional): YouTube or Vimeo link</label><input name="video_url" type="url" value="' + esc(vurl) + '" placeholder="https://www.youtube.com/watch?v=…"></div><div id="qx"></div>' +
      '<div class="field" style="margin-top:8px"><label>Hint (optional, learners can open it)</label><input name="hint" maxlength="500" value="' + esc(q && q.hint || '') + '"></div><div class="err" id="er"></div><div class="row"><button class="btn">' + (q ? 'Save' : 'Add question') + '</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    $('#qup', m).onchange = async e => { const f = e.target.files[0]; if (!f) return; try { const data = await X.cr.shrink(f), r = await api('learn/img', { method: 'POST', data: { data } }); $('[name=image_url]', m).value = r.url; toast('Picture uploaded'); } catch (er) { toast(er.message || 'Could not use that picture'); } };
    const rowsOf = (n, f) => Array.from({ length: n }, (_, i) => f(i)).join('');
    const draw = () => { const t = $('#qt', m).value, x = $('#qx', m);
      if (t === 'mcq') x.innerHTML = '<div class="muted small" style="margin-bottom:6px">Answers: fill at least two, and tick the correct one.</div>' + LET.map(l => '<div class="cr-opt"><label class="cr-rad"><input type="radio" name="correct" value="' + l + '"' + (q ? (q.correct === l ? ' checked' : '') : (l === 'A' ? ' checked' : '')) + ' aria-label="Answer ' + l + ' is correct"><b>' + l + '</b></label><input name="opt_' + l.toLowerCase() + '" maxlength="500" value="' + esc(q && q['opt_' + l.toLowerCase()] || '') + '" placeholder="Answer ' + l + '"></div>').join('');
      else if (t === 'order') { const it = pz.items || ['', '', '']; x.innerHTML = '<div class="muted small" style="margin-bottom:6px">Write the steps in the <b>correct order</b> (2 to 8). Learners see them shuffled and put them right.</div><div id="ol">' + rowsOf(Math.max(it.length, 3), i => '<div class="cr-opt"><b class="cr-l">' + (i + 1) + '</b><input data-it maxlength="200" value="' + esc(it[i] || '') + '" placeholder="Step ' + (i + 1) + '"></div>') + '</div><button type="button" class="btn ghost sm" id="oladd">+ Add step</button>'; $('#oladd', m).onclick = () => { const n = $$('[data-it]', m).length; if (n >= 8) return toast('Up to 8 steps'); $('#ol', m).insertAdjacentHTML('beforeend', '<div class="cr-opt"><b class="cr-l">' + (n + 1) + '</b><input data-it maxlength="200" placeholder="Step ' + (n + 1) + '"></div>'); }; }
      else { const pr = pz.pairs || [{}, {}, {}]; x.innerHTML = '<div class="muted small" style="margin-bottom:6px">Each row is a correct pair (2 to 6). Learners see the right-hand items shuffled and match them.</div><div id="pl">' + rowsOf(Math.max(pr.length, 3), i => '<div class="cr-opt"><input data-l maxlength="200" value="' + esc(pr[i] && pr[i].l || '') + '" placeholder="Left ' + (i + 1) + '"><span>↔</span><input data-r maxlength="200" value="' + esc(pr[i] && pr[i].r || '') + '" placeholder="Matches ' + (i + 1) + '"></div>') + '</div><button type="button" class="btn ghost sm" id="pladd">+ Add pair</button>'; $('#pladd', m).onclick = () => { const n = $$('[data-l]', m).length; if (n >= 6) return toast('Up to 6 pairs'); $('#pl', m).insertAdjacentHTML('beforeend', '<div class="cr-opt"><input data-l maxlength="200" placeholder="Left ' + (n + 1) + '"><span>↔</span><input data-r maxlength="200" placeholder="Matches ' + (n + 1) + '"></div>'); }; } };
    $('#qt', m).onchange = draw; draw();
    $('#qf', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.qtype = $('#qt', m).value;
      if (f.qtype === 'order') f.items = $$('[data-it]', m).map(i => i.value); if (f.qtype === 'match') f.pairs = $$('[data-l]', m).map((l, i) => ({ l: l.value, r: $$('[data-r]', m)[i].value }));
      try { await api('courses/' + c.id + '/questions' + (q ? '/' + q.id : ''), { method: q ? 'PATCH' : 'POST', data: f }); m.close(); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
  }
})();
