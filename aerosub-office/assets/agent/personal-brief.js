/* Agents area: Personal agent > Brief (mockup M7): this week's two or three actions, each with the goal it serves, a reason and a deadline.
 * It is made on the laptop on Monday (or the first time the laptop runs that week). The last brief is kept on this phone. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const U = () => AA.personal.util;

  AA.personal.tabs.brief = async box => {
    let snap = (await AA.pstore.get('brief')) || { t: 'brief', week: null, actions: [], has_goals: null }, status = null, busy = false;

    const draw = () => {
      const el = $('#agbrief'); if (!el) return;
      let body;
      if (snap.actions.length) {
        const left = snap.actions.filter(a => !a.done).length;
        body = '<h2 class="ag-h">Week of ' + esc(U().day(snap.week)) + '</h2><p class="muted small">' + (left ? left + (left === 1 ? ' action' : ' actions') + ' left' : 'All done. Well done.') + '</p>' +
          snap.actions.map(a => '<div class="card ag-action' + (a.done ? ' done' : '') + '"><button class="ag-tick" role="checkbox" aria-checked="' + !!a.done + '" data-d="' + esc(a.id) + '" aria-label="Mark done: ' + esc(a.title) + '">' + (a.done ? '✓' : '') + '</button>' +
            '<div class="grow"><b>' + esc(a.title) + '</b><div class="muted small">' + esc(a.reason) + '</div><div class="ag-row">' + AA.tag('priv', 'Goal: ' + a.goal) + AA.tag('', 'Due ' + U().day(a.due, true)) + '</div></div></div>').join('') +
          '<div class="ag-actions"><button class="btn ghost sm" id="agremake"' + (busy ? ' disabled' : '') + '>Make a new brief</button></div>';
      } else if (snap.has_goals === false) {
        body = AA.empty('Add a goal in Profile and your first brief will be built around it.') + '<div class="ag-actions"><a class="btn" href="#/agents/personal/profile">Add a goal</a></div>';
      } else {
        body = AA.empty('Your first brief arrives Monday. You can also make one now.') + '<div class="ag-actions"><button class="btn" id="agmake"' + (busy ? ' disabled' : '') + '>' + (busy ? 'Making it…' : 'Make my brief now') + '</button></div>';
      }
      el.innerHTML = U().offline(status) + body + '<div id="agperr" class="ag-err" role="alert"></div>' + U().saved(snap);
      box.querySelectorAll('[data-d]').forEach(b => b.onclick = () => tick(b.dataset.d));
      ['agmake', 'agremake'].forEach(id => { const b = $('#' + id); if (b) b.onclick = make; });
    };
    const refresh = async () => {
      try { await AA.psync.request({ t: 'brief_get' }, 'brief', 12000); status = await AA.psync.status().catch(() => null); }
      catch (e) { if (e.step === 'code') return AA.go(); }
      snap = (await AA.pstore.get('brief')) || snap; draw();
    };
    const tick = async id => {
      const a = snap.actions.find(x => x.id === id); if (!a) return;
      try { await AA.psync.post({ t: 'brief_done', id, done: !a.done }); a.done = !a.done; draw(); }
      catch (e) { const el = $('#agperr'); if (el) el.textContent = e.message || "Couldn't save that. Try again."; }
    };
    const make = async () => {
      busy = true; draw();
      try {
        const answered = await AA.psync.request({ t: 'brief_make' }, 'brief', 60000);
        const err = await AA.pstore.get('lastError');
        snap = (await AA.pstore.get('brief')) || snap; busy = false; draw();
        const el = $('#agperr');
        if (el) el.textContent = !answered ? 'Asked. Your laptop will make it when it is on.' : (err && err.for === 'brief' && Date.now() - err.at < 90000 && !snap.actions.length ? err.message : '');
      } catch (e) { busy = false; draw(); const el = $('#agperr'); if (el) el.textContent = e.message || "Couldn't ask for a brief. Try again."; }
    };

    box.innerHTML = '<div id="agbrief">' + AA.skeleton(2) + '</div>';
    draw(); refresh();
    AA.psync.watch(async types => { if (types.has('brief') && $('#agbrief')) { snap = (await AA.pstore.get('brief')) || snap; busy = false; draw(); } });
  };
})();
