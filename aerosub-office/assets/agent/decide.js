/* Agents area: the owner's decisions on an inbox item, as sheets: Approve (assign tasks), Edit, Reject, Clear risk flag.
 * Every decision is recorded on the server under the owner's name; nothing here changes the pipeline by itself. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const EDITABLE = {
    company: [['name', 'Company name'], ['sector', 'Sector'], ['location', 'Location'], ['website', 'Website'], ['notes', 'Notes']],
    contact: [['name', 'Name'], ['position', 'Position'], ['notes', 'Notes']],
    task: [['title', 'Task'], ['description', 'Details'], ['due_date', 'Due date (YYYY-MM-DD)']],
    research: [['title', 'Title'], ['body', 'Notes', 1]],
    signal: [['summary', 'Summary', 1]],
  };
  const back = () => { location.hash = '#/agents/research/inbox'; }; // the hash change redraws the list
  const busy = (btn, on, text) => { btn.disabled = on; if (text) btn.dataset.t = btn.dataset.t || btn.textContent; btn.textContent = on ? 'Working…' : (btn.dataset.t || btn.textContent); };

  const D = (AA.decide = {});

  D.clearFlag = (company, done) => AA.sheet(
    '<h2>Clear risk flag?</h2><p>You are saying you have looked at the risk on <b>' + esc(company || 'this company') + '</b> and are happy for outreach to go ahead. This is recorded under your name.</p>' +
    '<div class="ag-actions"><button class="btn" id="agyes">Clear risk flag</button><button class="btn ghost" id="agcancel">Cancel</button></div>',
    s => { $('#agcancel', s).onclick = s.close; $('#agyes', s).onclick = () => { s.close(); done(); }; });

  D.reject = it => AA.sheet(
    '<h2>Why are you rejecting this?</h2><fieldset class="ag-radios"><legend class="ag-sr">Reason for rejecting</legend>' +
    AA.REJECT.map(([c, l]) => '<label class="ag-radio"><input type="radio" name="why" value="' + c + '"> ' + esc(l) + '</label>').join('') + '</fieldset><div id="agerr" class="ag-err" role="alert"></div>' +
    '<div class="ag-actions"><button class="btn danger" id="agyes" disabled>Reject</button><button class="btn ghost" id="agcancel">Cancel</button></div>',
    s => {
      const yes = $('#agyes', s); let code = '';
      s.querySelectorAll('[name=why]').forEach(r => r.onchange = () => { code = r.value; yes.disabled = false; });
      $('#agcancel', s).onclick = s.close;
      yes.onclick = async () => {
        busy(yes, true, 1);
        try { await AA.call('agent-owner/items/' + it.id + '/reject', { method: 'POST', data: { reason_code: code } }); s.close(); AA.X.toast('Rejected.'); back(); }
        catch (e) { $('#agerr', s).textContent = e.message; busy(yes, false); }
      };
    });

  D.edit = (it, next) => {
    const fields = EDITABLE[it.kind] || [];
    AA.sheet('<h2>Edit before approving</h2>' + fields.map(([k, label, long]) => {
      const val = esc(String(it.payload[k] ?? ''));
      return '<div class="field"><label for="ed_' + k + '">' + esc(label) + '</label>' + (long ? '<textarea id="ed_' + k + '" data-k="' + k + '">' + val + '</textarea>' : '<input id="ed_' + k + '" data-k="' + k + '" value="' + val + '">') + '</div>';
    }).join('') + '<p class="muted small">Your changes are recorded next to the original. The sources cannot be edited.</p>' +
      '<div class="ag-actions"><button class="btn" id="agyes">Save changes and continue</button><button class="btn ghost" id="agcancel">Cancel</button></div>',
    s => {
      $('#agcancel', s).onclick = s.close;
      $('#agyes', s).onclick = () => {
        const edits = {}; s.querySelectorAll('[data-k]').forEach(el => { if (el.value !== String(it.payload[el.dataset.k] ?? '')) edits[el.dataset.k] = el.value; });
        if (!Object.keys(edits).length) { AA.X.toast('Nothing was changed.'); return; }
        s.close(); next(edits);
      };
    });
  };

  /** "Assign tasks": each suggested task gets a person and a date, then one button approves and creates them. */
  D.approve = async (it, edits, clearFlag) => {
    let people = [];
    try { people = await AA.call('agent-owner/people'); } catch (e) { if (e.step === 'code') return AA.go(); }
    const tasks = ((it.payload.tasks) || []).filter(t => t.title), n = tasks.length;
    const who = '<option value="">Me</option>' + people.map(p => '<option value="' + (+p.id) + '">' + esc(p.name) + (p.job_title ? ', ' + esc(p.job_title) : '') + '</option>').join('');
    AA.sheet('<h2>' + (n ? 'Assign tasks' : 'Approve') + '</h2>' + (n ? '<p class="muted small">Each task goes to the pipeline under your name.</p>' : '<p>This will be added to the pipeline. No tasks are attached.</p>') +
      tasks.map((t, i) => '<div class="ag-src"><b>' + esc(t.title) + '</b>' + (t.why ? '<p class="muted small">' + esc(t.why) + '</p>' : '') +
        '<div class="ag-two"><div><label for="w' + i + '">Who does this?</label><select id="w' + i + '">' + who + '</select></div><div><label for="d' + i + '">Due date</label><input id="d' + i + '" type="date" value="' + esc(t.due_date || '') + '"></div></div>' +
        (t.suggested_owner ? '<p class="muted small">Suggested: ' + esc(t.suggested_owner) + '</p>' : '') + '</div>').join('') +
      '<div id="agerr" class="ag-err" role="alert"></div><div class="ag-actions"><button class="btn" id="agyes">' + (n ? 'Approve and create ' + AA.plural(n, 'task') : 'Approve') + '</button><button class="btn ghost" id="agcancel">Cancel</button></div>',
    s => {
      $('#agcancel', s).onclick = s.close;
      const yes = $('#agyes', s);
      yes.onclick = async () => {
        busy(yes, true, 1);
        const assignments = tasks.map((t, i) => ({ index: i, assignee_id: $('#w' + i, s).value ? +$('#w' + i, s).value : null, due_date: $('#d' + i, s).value || null }));
        try {
          const r = await AA.call('agent-owner/items/' + it.id + '/approve', { method: 'POST', data: { edits, assignments, clear_flag: !!clearFlag } });
          s.close();
          const made = r.created && r.created.tasks ? r.created.tasks.length : n;
          AA.X.toast(r.sync === 'syncing' ? (r.message || "Approval saved, but the pipeline update didn't go through. We'll retry automatically.") : 'Approved — ' + AA.plural(made, 'task') + ' created.');
          back();
        } catch (e) { if (e.step === 'code') { s.close(); return AA.go(); } $('#agerr', s).textContent = e.message; busy(yes, false); }
      };
    });
  };
})();
