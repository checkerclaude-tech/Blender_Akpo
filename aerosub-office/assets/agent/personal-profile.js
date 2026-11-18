/* Agents area: Personal agent > Profile (mockup A7d): short intake answers, goals and the CV. Everything is sent encrypted to the laptop and
 * kept there. A copy of what the laptop last said is kept on this phone so the screen still works when the laptop is off.
 * A suggestion read from the CV is only a suggestion: nothing changes until the owner taps Accept. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const U = () => AA.personal.util;
  const FIELDS = [
    ['current_role', 'Current role', 'For example: Lead NDT engineer'], ['experience_years', 'Years of experience', 'For example: 12'],
    ['specialisms', 'Specialisms', 'For example: crawler UT, rope access, ROV inspection'], ['target_next', 'What you want next', 'For example: a consultancy or board role'],
    ['location', 'Where you are based or can work', 'For example: Port Harcourt, open to travel'], ['constraints', 'Things to avoid', 'For example: no long-term relocation'],
  ];
  const LABEL = Object.fromEntries(FIELDS.map(f => [f[0], f[1]]));
  const empty = () => ({ t: 'profile', facts: {}, goals: [], proposals: [], cv: null });
  const kb = n => n < 1024 ? n + ' bytes' : Math.round(n / 1024) + ' KB';

  AA.personal.tabs.profile = async box => {
    let snap = (await AA.pstore.get('profile')) || empty(), status = null, busy = '', note = '';

    const draw = () => {
      if (!$('#agprofile')) return;
      const cv = snap.cv;
      $('#agprofile').innerHTML = U().offline(status) + (note ? AA.banner('ok', '✓', esc(note)) : '') +
        (snap.proposals.length ? '<h2 class="ag-h">From your CV: is this right?</h2>' + snap.proposals.map(p =>
          '<div class="card ag-prop"><div class="muted small">' + esc(LABEL[p.field] || AA.humanise(p.field)) + '</div><b>' + esc(p.value) + '</b>' +
          '<div class="ag-actions"><button class="btn sm" data-acc="' + p.id + '">Yes, use this</button><button class="btn ghost sm" data-rej="' + p.id + '">No</button></div></div>').join('') : '') +
        '<h2 class="ag-h">About you</h2><div class="card ag-list">' + FIELDS.map(([k, label]) => {
          const f = snap.facts[k];
          return '<button class="ag-row-btn" data-f="' + k + '"><span class="grow"><span class="muted small">' + esc(label) + '</span><br>' +
            (f ? esc(f.value) : '<span class="muted">Add</span>') + '</span><span class="muted" aria-hidden="true">›</span></button>';
        }).join('') + '</div>' +
        '<h2 class="ag-h">Your goals</h2><div class="card ag-list">' + (snap.goals.length ? snap.goals.map(g =>
          '<div class="ag-row-btn"><span class="grow">' + esc(g.text) + (g.due ? '<br><span class="muted small">By ' + esc(U().day(g.due)) + '</span>' : '') + '</span>' +
          '<button class="btn ghost sm" data-rg="' + esc(g.id) + '" aria-label="Remove goal ' + esc(g.text) + '">Remove</button></div>').join('') : '<p class="muted">No goals yet. Your weekly brief is built from them.</p>') +
        (snap.goals.length < 5 ? '<div class="ag-actions"><button class="btn sm" id="agaddgoal">Add a goal</button></div>' : '') + '</div>' +
        '<h2 class="ag-h">Your CV</h2><div class="card">' + (cv
          ? '<b>' + esc(cv.name) + '</b><div class="muted small">' + esc(kb(cv.bytes)) + ' · added ' + esc(AA.rel(new Date(cv.uploaded_at).toISOString().replace('T', ' ').slice(0, 19) + 'Z')) + '. Only its text is kept, on your laptop.</div>'
          : '<p class="muted">Add your CV and I will suggest answers for the questions above. You decide what to keep.</p>') +
        (busy ? '<div class="muted small" aria-live="polite">' + esc(busy) + '</div>' : '') +
        '<div class="ag-actions"><label class="btn sm" for="agcvfile">' + (cv ? 'Replace CV' : 'Add your CV') + '</label><input id="agcvfile" type="file" accept=".pdf,.docx,.txt,application/pdf" hidden>' +
        (cv ? '<button class="btn ghost sm danger-text" id="agdelcv">Delete CV</button>' : '') + '</div><div id="agperr" class="ag-err" role="alert"></div></div>' + U().saved(snap);
      wire();
    };

    const fail = e => { const el = $('#agperr'); if (el) el.textContent = e.offline ? e.message : (e.message || "Couldn't do that. Try again."); };
    const refresh = async () => {
      try { await AA.psync.request({ t: 'profile_get' }, 'profile', 12000); status = await AA.psync.status().catch(() => null); }
      catch (e) { if (e.step === 'code') return AA.go(); }
      snap = (await AA.pstore.get('profile')) || snap; draw();
    };
    const send = async msg => { try { await AA.psync.post(msg); } catch (e) { fail(e); return false; } return true; };
    const latestError = async () => { const e = await AA.pstore.get('lastError'); return e && Date.now() - e.at < 60000 ? e : null; };

    const askField = k => {
      const [, label, hint] = FIELDS.find(f => f[0] === k), cur = (snap.facts[k] || {}).value || '';
      AA.sheet('<h2>' + esc(label) + '</h2><form id="agfld"><input id="agfv" maxlength="500" value="' + esc(cur) + '" placeholder="' + esc(hint) + '" aria-label="' + esc(label) + '">' +
        '<div class="ag-err" id="agfe" role="alert"></div><div class="ag-actions"><button class="btn" type="submit">Save</button><button class="btn ghost" type="button" id="agfx">Cancel</button></div></form>', s => {
        $('#agfx', s).onclick = s.close;
        $('#agfld', s).onsubmit = async e => {
          e.preventDefault(); const v = $('#agfv', s).value.trim();
          if (await send({ t: 'profile_set', field: k, value: v })) {
            if (v) snap.facts[k] = { value: v, source: 'intake' }; else delete snap.facts[k];
            s.close(); note = 'Saved'; draw(); setTimeout(() => { note = ''; draw(); }, 2500);
          } else $('#agfe', s).textContent = ($('#agperr') || {}).textContent || "Couldn't save. Try again.";
        };
      });
    };
    const askGoal = () => AA.sheet('<h2>Add a goal</h2><form id="aggoal"><label class="small muted" for="aggt">What do you want to achieve?</label><input id="aggt" maxlength="200" placeholder="For example: Move into a consultancy role">' +
      '<label class="small muted" for="aggd">By when (optional)</label><input id="aggd" type="date"><div class="ag-err" id="agge" role="alert"></div>' +
      '<div class="ag-actions"><button class="btn" type="submit">Add goal</button><button class="btn ghost" type="button" id="aggx">Cancel</button></div></form>', s => {
      $('#aggx', s).onclick = s.close;
      $('#aggoal', s).onsubmit = async e => {
        e.preventDefault(); const text = $('#aggt', s).value.trim(), due = $('#aggd', s).value || null;
        if (!text) { $('#agge', s).textContent = 'Write your goal first.'; return; }
        const g = { id: AA.psync.newId(), text, due };
        if (await send({ t: 'goal_add', ...g })) { snap.goals = snap.goals.concat(g); s.close(); draw(); } else $('#agge', s).textContent = "Couldn't save. Try again.";
      };
    });

    const wire = () => {
      box.querySelectorAll('[data-f]').forEach(b => b.onclick = () => askField(b.dataset.f));
      box.querySelectorAll('[data-rg]').forEach(b => b.onclick = async () => { if (await send({ t: 'goal_remove', id: b.dataset.rg })) { snap.goals = snap.goals.filter(g => g.id !== b.dataset.rg); draw(); } });
      box.querySelectorAll('[data-acc]').forEach(b => b.onclick = async () => { if (await send({ t: 'proposal', id: +b.dataset.acc, accept: true })) { const p = snap.proposals.find(x => x.id === +b.dataset.acc); if (p) snap.facts[p.field] = { value: p.value, source: 'cv' }; snap.proposals = snap.proposals.filter(x => x.id !== +b.dataset.acc); draw(); } });
      box.querySelectorAll('[data-rej]').forEach(b => b.onclick = async () => { if (await send({ t: 'proposal', id: +b.dataset.rej, accept: false })) { snap.proposals = snap.proposals.filter(x => x.id !== +b.dataset.rej); draw(); } });
      const add = $('#agaddgoal'); if (add) add.onclick = askGoal;
      const del = $('#agdelcv'); if (del) del.onclick = () => AA.sheet('<h2>Delete your CV?</h2><p>Its text is removed from your laptop, along with any suggestions still waiting. Answers you already accepted stay.</p><div class="ag-actions"><button class="btn danger" id="agyes">Delete</button><button class="btn ghost" id="agno">Keep</button></div>', s => {
        $('#agno', s).onclick = s.close;
        $('#agyes', s).onclick = async () => { s.close(); if (await send({ t: 'cv_delete' })) { snap.cv = null; snap.proposals = []; draw(); } };
      });
      const file = $('#agcvfile'); if (file) file.onchange = async () => {
        const f = file.files[0]; if (!f) return; $('#agperr').textContent = '';
        try {
          busy = 'Sending your CV…'; draw();
          await AA.psync.uploadCv(f, (d, n) => { busy = 'Sending your CV… ' + d + ' of ' + n; const el = box.querySelector('[aria-live=polite]'); if (el) el.textContent = busy; });
          busy = 'Your laptop is reading it…'; draw();
          const answered = await AA.psync.request({ t: 'profile_get' }, 'cv', 60000);
          if (answered) await AA.psync.request({ t: 'profile_get' }, 'profile', 15000);        // the profile with its suggestions can arrive a moment after the CV confirmation
          const st = await AA.pstore.get('cvStatus'), err = await latestError();
          busy = ''; snap = (await AA.pstore.get('profile')) || snap;
          if (!answered) note = 'Sent. Your laptop will read it when it is on.';
          else if (st && st.ok === false) { draw(); $('#agperr').textContent = st.message; return; }
          else note = 'CV added.' + (st && st.suggestions ? ' Check the suggestions below.' : '');
          if (err && !answered) note = '';
          draw();
        } catch (e) { busy = ''; draw(); fail(e); }
      };
    };

    box.innerHTML = '<div id="agprofile">' + AA.skeleton(3) + '</div>';
    draw(); refresh();
    AA.psync.watch(async types => { if (types.has('profile') && $('#agprofile')) { snap = (await AA.pstore.get('profile')) || snap; draw(); } });
  };
})();
