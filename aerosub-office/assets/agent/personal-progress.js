/* Agents area: Personal agent > Progress (mockup A7e): each goal with its steps, whether it is moving or stalled, and the monthly review.
 * Counting only: it says what you finished, never what it guesses. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const U = () => AA.personal.util;
  const STATE = { moving: ['ok', '▲', 'Moving'], stalled: ['warn', '⏸', 'Stalled'], new: ['info', '•', 'New'], finished: ['ok', '✓', 'Finished'] };

  AA.personal.tabs.progress = async box => {
    let snap = (await AA.pstore.get('progress')) || { t: 'progress', goals: [], review: null }, status = null;

    const goal = g => {
      const st = STATE[g.state] || ['', '•', AA.humanise(g.state)], done = g.steps.filter(s => s.done).length;
      return '<div class="card"><div class="ag-row"><b class="grow">' + esc(g.text) + '</b>' + AA.chip(st[0], st[1], st[2]) + '</div>' +
        '<div class="muted small">' + (g.due ? 'By ' + esc(U().day(g.due)) + ' · ' : '') + (g.steps.length ? done + ' of ' + g.steps.length + ' steps done' : 'No steps yet') +
        (g.actions_total ? ' · ' + g.actions_done + ' of ' + g.actions_total + ' weekly actions done' : '') + '</div>' +
        g.steps.map(s => '<div class="ag-step' + (s.done ? ' done' : '') + '"><button class="ag-tick sm" role="checkbox" aria-checked="' + !!s.done + '" data-d="' + esc(s.id) + '" aria-label="Mark done: ' + esc(s.text) + '">' + (s.done ? '✓' : '') + '</button>' +
          '<span class="grow">' + esc(s.text) + (s.due ? '<br><span class="muted small">By ' + esc(U().day(s.due)) + '</span>' : '') + '</span>' +
          '<button class="btn ghost sm" data-rm="' + esc(s.id) + '" aria-label="Remove step ' + esc(s.text) + '">Remove</button></div>').join('') +
        '<div class="ag-actions"><button class="btn ghost sm" data-add="' + esc(g.id) + '">Add a step</button></div></div>';
    };
    const review = r => !r ? AA.empty('Your first review is written at the start of next month, or you can make one now.')
      : '<div class="card"><b>' + esc(r.summary) + '</b><div class="muted small">Last 30 days</div>' +
        (r.moved.length ? '<h3 class="ag-h">What moved</h3><ul>' + r.moved.map(m => '<li><b>' + esc(m.goal) + '</b><br><span class="muted small">' + esc(m.detail) + '</span></li>').join('') + '</ul>' : '') +
        (r.stalled.length ? '<h3 class="ag-h">What stalled</h3><ul>' + r.stalled.map(m => '<li><b>' + esc(m.goal) + '</b><br><span class="muted small">' + esc(m.detail) + '</span></li>').join('') + '</ul>' : '') + '</div>';

    const draw = () => {
      const el = $('#agprog'); if (!el) return;
      el.innerHTML = U().offline(status) + (snap.goals.length ? '<h2 class="ag-h">Your goals</h2>' + snap.goals.map(goal).join('') : AA.empty('Add a goal in Profile and track your steps here.') + '<div class="ag-actions"><a class="btn" href="#/agents/personal/profile">Add a goal</a></div>') +
        '<h2 class="ag-h">Monthly review</h2>' + review(snap.review) + (snap.goals.length ? '<div class="ag-actions"><button class="btn ghost sm" id="agrev">Make a review now</button></div>' : '') +
        '<div id="agperr" class="ag-err" role="alert"></div>' + U().saved(snap);
      box.querySelectorAll('[data-d]').forEach(b => b.onclick = () => tick(b.dataset.d));
      box.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => send({ t: 'milestone_remove', id: b.dataset.rm }, () => { snap.goals.forEach(g => { g.steps = g.steps.filter(s => s.id !== b.dataset.rm); }); }));
      box.querySelectorAll('[data-add]').forEach(b => b.onclick = () => addStep(b.dataset.add));
      const rv = $('#agrev'); if (rv) rv.onclick = async () => { try { await AA.psync.request({ t: 'review_make' }, 'progress', 30000); snap = (await AA.pstore.get('progress')) || snap; draw(); } catch (e) { fail(e); } };
    };
    const fail = e => { const el = $('#agperr'); if (el) el.textContent = e.offline ? e.message : (e.message || "Couldn't do that. Try again."); };
    const send = async (msg, local) => { try { await AA.psync.post(msg); if (local) local(); draw(); } catch (e) { fail(e); } };
    const tick = id => {
      for (const g of snap.goals) { const s = g.steps.find(x => x.id === id); if (s) return send({ t: 'milestone_done', id, done: !s.done }, () => { s.done = !s.done; }); }
    };
    const addStep = gid => AA.sheet('<h2>Add a step</h2><form id="agstep"><label class="small muted" for="agst">What is the next step?</label><input id="agst" maxlength="200" placeholder="For example: Email two consultancies">' +
      '<label class="small muted" for="agsd">By when (optional)</label><input id="agsd" type="date"><div class="ag-err" id="agse" role="alert"></div>' +
      '<div class="ag-actions"><button class="btn" type="submit">Add step</button><button class="btn ghost" type="button" id="agsx">Cancel</button></div></form>', s => {
      $('#agsx', s).onclick = s.close;
      $('#agstep', s).onsubmit = async e => {
        e.preventDefault(); const text = $('#agst', s).value.trim(), due = $('#agsd', s).value || null;
        if (!text) { $('#agse', s).textContent = 'Write the step first.'; return; }
        const step = { id: AA.psync.newId(), text, due, done: false };
        try { await AA.psync.post({ t: 'milestone_add', id: step.id, goal: gid, text, due }); const g = snap.goals.find(x => x.id === gid); if (g) g.steps.push(step); s.close(); draw(); }
        catch (er) { $('#agse', s).textContent = er.message || "Couldn't save. Try again."; }
      };
    });
    const refresh = async () => {
      try { await AA.psync.request({ t: 'progress_get' }, 'progress', 12000); status = await AA.psync.status().catch(() => null); }
      catch (e) { if (e.step === 'code') return AA.go(); }
      snap = (await AA.pstore.get('progress')) || snap; draw();
    };

    box.innerHTML = '<div id="agprog">' + AA.skeleton(2) + '</div>';
    draw(); refresh();
    AA.psync.watch(async types => { if (types.has('progress') && $('#agprog')) { snap = (await AA.pstore.get('progress')) || snap; draw(); } });
  };
})();
