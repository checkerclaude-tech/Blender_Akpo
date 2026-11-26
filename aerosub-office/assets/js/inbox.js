/* Aerosub Office: Campaigns > Inbox automation.
 * Scan the info@ mailbox; each business email becomes a contact, a funnel entry, action tasks and a draft reply that Marketing approves.
 * Replies can carry documents from Documents / My Documents, and a proforma / invoice can be started for the sender in one click. */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const STAT = { new: 'New', awaiting: 'To review', sent: 'Replied', closed: 'Closed out', skipped: 'Skipped', discarded: 'Discarded' };
  const STAGE = { researching: 'Researching', contact_identified: 'Contact identified', outreach_sent: 'Outreach sent', in_discussion: 'In discussion', proposal_sent: 'Proposal sent', negotiating: 'Negotiating', won: 'Won', on_hold: 'On hold' };
  const fsize = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  const when = t => { const d = new Date(String(t || '').replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const b64 = f => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = () => no(new Error('Could not read ' + f.name)); r.readAsDataURL(f); });
  let D = null, filter = 'awaiting';

  X.campInbox = async () => {
    const box = $('#cpb'); box.innerHTML = '<div class="empty">Loading…</div>';
    D = await api('inbox-auto/overview' + (filter && filter !== 'all' ? '?status=' + filter : ''));
    const c = D.counts, n = k => +(c[k] || 0), set = D.settings;
    box.innerHTML = '<div class="card"><div class="row" style="flex-wrap:wrap;gap:8px"><div class="grow"><h2 style="margin:0">Inbox automation</h2><div class="muted small">Everyone in Marketing scans the same primary mailbox (info@); what you scan is yours alone. Nothing becomes a task, a report row or a Pipeline entry until you review it: add it as your task, or discard it. You can delete any of your results.' + (set.last_scan_at ? ' Last scan ' + esc(when(set.last_scan_at)) + '.' : ' Not scanned yet.') + '</div></div>' +
      '<select id="iad" aria-label="Look back" style="width:auto"><option value="3">Last 3 days</option><option value="7">Last 7 days</option><option value="14" selected>Last 14 days</option><option value="30">Last 30 days</option></select><button class="btn" id="ias">⟳ Scan inbox now</button><button class="btn ghost" id="iaset">Settings &amp; rules</button></div>' +
      (D.accounts.length ? '' : '<div class="banner" style="margin-top:10px;background:rgba(224,160,45,.14);color:var(--ink);border:1px solid rgba(224,160,45,.5)"><span>Connect the info@ mailbox first (Mail tab), then scan.</span></div>') + '</div>' +
      '<div class="chips" style="margin:12px 0" id="iaf">' + [['awaiting', 'To review'], ['sent', 'Replied'], ['closed', 'Closed out'], ['discarded', 'Discarded'], ['skipped', 'Skipped'], ['all', 'All']].map(([k, l]) => '<button type="button" class="chip' + (filter === k ? ' on' : '') + '" data-f="' + k + '">' + l + (k !== 'all' && n(k) ? ' (' + n(k) + ')' : '') + '</button>').join('') + '</div>' +
      '<div id="ial">' + (D.items.length ? D.items.map(card).join('') : '<div class="card empty">Nothing here yet. Press “Scan inbox now”.</div>') + '</div>';
    $('#iaf', box).querySelectorAll('[data-f]').forEach(b => b.onclick = () => { filter = b.dataset.f; X.campInbox(); });
    $('#ias', box).onclick = async e => { const bt = e.target; bt.disabled = true; bt.textContent = 'Scanning…'; try { const r = await api('inbox-auto/scan', { method: 'POST', data: { days: +$('#iad', box).value, limit: 80 } }); toast(r.new + ' new email' + (r.new === 1 ? '' : 's') + ' to review' + (r.skipped ? ', ' + r.skipped + ' automatic skipped' : '')); filter = 'awaiting'; X.campInbox(); } catch (er) { toast(er.message); bt.disabled = false; bt.textContent = '⟳ Scan inbox now'; } };
    $('#iaset', box).onclick = settingsDialog;
    $$('[data-del-ia]', box).forEach(b => b.onclick = async () => { if (!confirm('Delete this from your results? Tasks already made from it stay.')) return; try { await api('inbox-auto/items/' + b.dataset.delIa + '/delete', { method: 'POST', data: {} }); X.campInbox(); } catch (er) { toast(er.message); } });
    $$('[data-addtask]', box).forEach(b => b.onclick = async () => { b.disabled = true; try { const r = await api('inbox-auto/items/' + b.dataset.addtask + '/task', { method: 'POST', data: {} }); toast('Added to your tasks (' + r.tasks + ')'); X.campInbox(); } catch (er) { toast(er.message); b.disabled = false; } });
    $$('[data-discard]', box).forEach(b => b.onclick = async () => { if (!confirm('Discard this email? It leaves the review list and no task is made.')) return; try { await api('inbox-auto/items/' + b.dataset.discard + '/discard', { method: 'POST', data: {} }); X.campInbox(); } catch (er) { toast(er.message); } });
    $$('[data-rv]', box).forEach(b => b.onclick = () => reviewDialog(D.items.find(i => i.id == b.dataset.rv)));
    $$('[data-cl]', box).forEach(b => b.onclick = async () => { if (!confirm('Close this out without replying?')) return; try { await api('inbox-auto/items/' + b.dataset.cl + '/close', { method: 'POST', data: {} }); X.campInbox(); } catch (er) { toast(er.message); } });
    $$('[data-cat]', box).forEach(s => s.onchange = async () => { try { await api('inbox-auto/items/' + s.dataset.cat + '/category', { method: 'POST', data: { category: s.value } }); toast('Sorted again: tasks and draft updated'); X.campInbox(); } catch (er) { toast(er.message); X.campInbox(); } });
    $$('[data-doc]', box).forEach(b => b.onclick = () => { const i = D.items.find(x => x.id == b.dataset.id); if (!window.OfficeFin) return toast('Finance is not available'); window.OfficeFin.form(null, { doc_type: b.dataset.doc, client_name: i.company && !/\(individual\)$/.test(i.company) ? i.company : (i.from_name || i.from_email), client_email: i.from_email, attention: i.from_name, task_id: i.task_id || '' }); });
  };
  const card = i => {
    const rules = D.settings.rules, cat = rules.find(r => r.key === i.category), open = ['new', 'awaiting'].includes(i.status);
    return '<div class="card ia-item" data-ia="' + i.id + '" style="margin-bottom:10px"><div class="row" style="flex-wrap:wrap;gap:8px"><div class="grow"><b>' + esc(i.from_name || i.from_email) + '</b> <span class="muted small">&lt;' + esc(i.from_email) + '&gt; · ' + esc(when(i.received_at)) + '</span><div><b>' + esc(i.subject) + '</b></div></div>' +
      '<span class="tag ' + (i.status === 'sent' ? 'done' : i.status === 'skipped' ? '' : 'in_progress') + '">' + esc(STAT[i.status] || i.status) + '</span>' + (i.acked ? '<span class="tag done" title="An automatic acknowledgement was sent">Acknowledged</span>' : '') + '</div>' +
      '<div class="muted small" style="margin:6px 0;overflow-wrap:anywhere">' + esc((i.snippet || '').slice(0, 260)) + '</div>' +
      (i.status === 'skipped' ? '<div class="small muted">Skipped: ' + esc(i.skip_reason || '') + '. Nothing was added to the CRM.</div>' :
        '<div class="row" style="flex-wrap:wrap;gap:8px;font-size:13px"><label class="small muted">Sorted as <select data-cat="' + i.id + '" style="width:auto"' + (open ? '' : ' disabled') + '>' + rules.map(r => '<option value="' + esc(r.key) + '"' + (r.key === i.category ? ' selected' : '') + '>' + esc(r.label) + '</option>').join('') + '</select></label>' +
        (!i.contact_id && !i.company_id ? '<span class="muted small" title="The sender enters the Pipeline when you add this as a task or reply">Not in the Pipeline yet</span>' : '') + (i.company ? '<span class="chip">🏢 ' + esc(i.company) + (i.stage ? ' · ' + esc(STAGE[i.stage] || i.stage) : '') + '</span>' : '') + (i.contact_id ? '<a class="chip" href="#/pipeline">👤 In contacts</a>' : '') + '</div>' +
        (i.tasks.length || i.task_id ? '<div class="small" style="margin-top:8px"><b>Added to your tasks</b><br>' + i.tasks.map(t => '<a href="#/tasks/' + t.id + '">' + (t.status === 'done' ? '✓ ' : '☐ ') + esc(t.title) + '</a>').join('<br>') + '</div>' : '') +
        '<div class="row" style="margin-top:10px;flex-wrap:wrap;gap:6px">' + '<button class="btn ghost sm" data-del-ia="' + i.id + '" title="Remove this from my results" style="margin-left:auto;order:9;color:var(--bad)">🗑 Delete</button>' + (open ? (i.task_id ? '' : '<button class="btn sm" data-addtask="' + i.id + '" title="Makes the tasks for this email, assigned to you">➕ Add as task</button><button class="btn ghost sm" data-discard="' + i.id + '" title="Junk or not needed: no task is made">🗑 Discard</button>') + '<button class="btn ' + (i.task_id ? '' : 'ghost ') + 'sm" data-rv="' + i.id + '">Review &amp; reply…</button><button class="btn ghost sm" data-cl="' + i.id + '">Close out</button>' : '') +
        (i.status !== 'skipped' && cat && cat.business ? ['proforma', 'invoice', 'commercial'].map(t => '<button class="btn ghost sm" data-doc="' + t + '" data-id="' + i.id + '">+ ' + ({ proforma: 'Proforma', invoice: 'Invoice', commercial: 'Commercial' })[t] + '</button>').join('') : '') + '</div>') + '</div>';
  };

  /* ---------- review the draft, choose documents, send ---------- */
  async function reviewDialog(i) {
    let lib = []; try { lib = (await api('inbox-auto/library')).files; } catch {}
    const rule = D.settings.rules.find(r => r.key === i.category) || { attach: [] }, hints = (rule.attach || []).map(h => h.toLowerCase()), extra = [];
    const pre = f => hints.some(h => h && f.name.toLowerCase().includes(h));
    const m = modal('<div class="row"><h2 class="grow">Reply to ' + esc(i.from_name || i.from_email) + '</h2><button class="btn ghost sm" id="rx">✕</button></div><div class="muted small" style="margin:4px 0 8px">Re: ' + esc(i.subject) + '</div>' +
      '<div class="field"><label>To</label><input id="rt" type="email" value="' + esc(i.from_email) + '"></div><div class="field"><label>Subject</label><input id="rs" maxlength="300" value="' + esc(i.draft_subject || 'Re: ' + i.subject) + '"></div>' +
      '<div class="field"><label>Message <span class="muted small">(your mailbox signature is added)</span></label><textarea id="rb" rows="10" maxlength="8000">' + esc(i.draft_body || '') + '</textarea></div>' +
      '<div class="field"><label>Attach documents</label><div class="muted small" style="margin-bottom:4px">From Documents and My Documents. Suggested ones are ticked.</div><div id="rl" style="max-height:170px;overflow:auto;display:grid;gap:2px">' +
      (lib.length ? lib.map(f => '<label class="item" style="cursor:pointer"><input type="checkbox" value="' + esc(f.ref) + '" style="width:auto"' + (pre(f) ? ' checked' : '') + '><span class="grow">' + esc(f.name) + ' <span class="muted small">' + fsize(f.size) + '</span></span><span class="tag">' + esc(f.source) + '</span></label>').join('') : '<div class="muted small" style="padding:6px">No files found. Upload your company profile and pitch deck in Documents or My Documents, or add a file below.</div>') + '</div>' +
      '<div class="row" style="margin-top:6px"><label class="btn ghost sm" style="cursor:pointer;margin:0">📎 Add a file from this device<input type="file" id="rf" multiple hidden></label><span class="small muted" id="rfl"></span></div></div>' +
      '<div class="err" id="er"></div><div class="row"><button class="btn" id="rg">Send reply</button><button class="btn ghost" id="rc" type="button">Cancel</button></div>');
    $('#rx', m).onclick = $('#rc', m).onclick = () => m.close();
    $('#rf', m).onchange = async e => { for (const f of e.target.files) { if (f.size > 8e6) { $('#er', m).textContent = f.name + ' is larger than 8 MB'; continue; } if (extra.length < 4) extra.push({ name: f.name, type: f.type || 'application/octet-stream', data: await b64(f) }); } e.target.value = ''; $('#rfl', m).textContent = extra.map(f => f.name).join(', '); };
    $('#rg', m).onclick = async e => { const bt = e.target; bt.disabled = true; bt.textContent = 'Sending…'; $('#er', m).textContent = '';
      try { const r = await api('inbox-auto/items/' + i.id + '/send', { method: 'POST', data: { to: $('#rt', m).value, subject: $('#rs', m).value, body: $('#rb', m).value, attach: $$('#rl input:checked', m).map(x => x.value), files: extra } }); m.close(); toast('Reply sent' + (r.attached ? ' with ' + r.attached + ' file' + (r.attached === 1 ? '' : 's') : '')); X.campInbox(); }
      catch (er) { $('#er', m).textContent = er.message; bt.disabled = false; bt.textContent = 'Send reply'; } };
  }

  /* ---------- settings and rules ---------- */
  function settingsDialog() {
    const s = D.settings, rules = JSON.parse(JSON.stringify(s.rules));
    const rbox = (r, n) => '<details class="card" style="box-shadow:none;margin:6px 0;padding:8px 10px"' + (n === 0 ? ' open' : '') + '><summary><b>' + esc(r.label) + '</b> <span class="muted small">(' + (r.business ? 'business: enters the funnel' : 'not a sales lead') + ')</span></summary><div data-r="' + n + '" style="margin-top:8px">' +
      '<div class="grid2"><div class="field"><label>Name</label><input data-k="label" maxlength="80" value="' + esc(r.label) + '"></div><div class="field"><label>Priority of its tasks</label><select data-k="priority">' + ['low', 'medium', 'high', 'urgent'].map(p => '<option' + (r.priority === p ? ' selected' : '') + '>' + p + '</option>').join('') + '</select></div></div>' +
      '<label class="small" style="display:block;margin:4px 0"><input type="checkbox" data-k="business" style="width:auto"' + (r.business ? ' checked' : '') + '> Business enquiry: add the sender to the funnel</label>' +
      '<div class="field"><label>Move the company to this funnel stage (never backwards)</label><select data-k="stage"><option value="">Do not change</option>' + D.stages.filter(x => x !== 'on_hold' && x !== 'won').map(x => '<option value="' + x + '"' + (r.stage === x ? ' selected' : '') + '>' + esc(STAGE[x]) + '</option>').join('') + '</select></div>' +
      '<div class="field"><label>Words that identify it (comma or new line)</label><textarea data-k="keywords" rows="2">' + esc((r.keywords || []).join(', ')) + '</textarea></div>' +
      '<div class="field"><label>Tasks to create (one per line)</label><textarea data-k="tasks" rows="3">' + esc((r.tasks || []).join('\n')) + '</textarea></div>' +
      '<div class="field"><label>Documents to suggest (words in file names, e.g. profile, pitch deck)</label><input data-k="attach" value="' + esc((r.attach || []).join(', ')) + '"></div>' +
      '<div class="field"><label>Draft reply <span class="muted small">{name} {sender} {company} {subject}</span></label><textarea data-k="reply" rows="5">' + esc(r.reply || '') + '</textarea></div></div></details>';
    const m = modal('<div class="row"><h2 class="grow">Inbox automation: settings</h2><button class="btn ghost sm" id="sx">✕</button></div>' +
      '<div class="cr-checks"><label><input type="checkbox" id="se"' + (s.enabled ? ' checked' : '') + '> Scan automatically (every time the scheduled task runs)</label></div>' +
      '<div class="grid2"><div class="field"><label>Primary mailbox' + (D.settings.mailbox_email ? ' <span class="muted small">(now: ' + esc(D.settings.mailbox_email) + ')</span>' : '') + ' <span class="muted small">Everyone scans this one; only its emails can become tasks</span></label><select id="sm"><option value="">Keep the current primary mailbox</option>' + D.accounts.map(a => '<option value="' + a.id + '"' + (+s.mailbox_id === a.id ? ' selected' : '') + '>' + esc(a.email) + '</option>').join('') + '</select></div>' +
      '<div class="field"><label>Who approves replies</label><select id="sa"><option value="">The Marketing head (or me)</option>' + D.marketing.map(u => '<option value="' + u.id + '"' + (+s.approver_id === u.id ? ' selected' : '') + '>' + esc(u.name) + '</option>').join('') + '</select></div></div>' +
      '<h3 style="margin:12px 0 4px">Instant acknowledgement</h3><div class="muted small" style="margin-bottom:6px">Optional. Sent at once to people who write about business, once a day per sender. Never sent to automatic mail. The real reply still waits for approval.</div>' +
      '<div class="cr-checks"><label><input type="checkbox" id="sk"' + (s.auto_ack ? ' checked' : '') + '> Send an automatic “we received your email”</label></div><div class="field"><label>Subject</label><input id="ss" value="' + esc(s.ack_subject) + '"></div><div class="field"><textarea id="sb" rows="4">' + esc(s.ack_body) + '</textarea></div>' +
      '<h3 style="margin:12px 0 4px">Categories and what happens</h3><div id="rr">' + rules.map(rbox).join('') + '</div>' +
      '<div class="field" style="margin-top:10px"><label>Never process these senders (one address or domain per line)</label><textarea id="si" rows="3">' + esc(s.skip_list) + '</textarea></div><div class="err" id="er"></div><div class="row"><button class="btn" id="sg">Save</button><button class="btn ghost" id="sc" type="button">Cancel</button></div>');
    m.querySelector('.modal').style.cssText += ';max-width:96vw;width:760px';
    $('#sx', m).onclick = $('#sc', m).onclick = () => m.close();
    $('#sg', m).onclick = async () => {
      const out = $$('[data-r]', m).map(el => { const g = k => $('[data-k=' + k + ']', el), r0 = rules[+el.dataset.r]; return { key: r0.key, weight: r0.weight || 1, label: g('label').value, priority: g('priority').value, business: g('business').checked ? 1 : 0, stage: g('stage').value, keywords: g('keywords').value, tasks: g('tasks').value, attach: g('attach').value, reply: g('reply').value }; });
      try { await api('inbox-auto/settings', { method: 'POST', data: { enabled: $('#se', m).checked ? 1 : 0, mailbox_id: $('#sm', m).value || null, approver_id: $('#sa', m).value || null, auto_ack: $('#sk', m).checked ? 1 : 0, ack_subject: $('#ss', m).value, ack_body: $('#sb', m).value, skip_list: $('#si', m).value, rules: out } }); m.close(); toast('Saved'); X.campInbox(); }
      catch (er) { $('#er', m).textContent = er.message; } };
  }
})();
