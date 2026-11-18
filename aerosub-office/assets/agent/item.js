/* Agents area: Research agent > one inbox item (mockup M4). Decisions are in decide.js. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const safeUrl = u => (/^https?:\/\/[^\s]+$/i.test(String(u || '')) ? u : '');
  const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u; } };
  const HISTORY = { approve: 'Approved', edit_approve: 'Edited and approved', reject: 'Rejected', clear_flag: 'Risk flag cleared' };
  const card = (title, html) => '<section class="card ag-sec">' + (title ? '<h2>' + esc(title) + '</h2>' : '') + html + '</section>';
  const kv = (k, v) => v ? '<div class="ag-kv"><span class="muted">' + esc(k) + '</span><span>' + esc(v) + '</span></div>' : '';

  const factors = list => !list || !list.length ? '<p class="muted small">No score breakdown was given for this item.</p>'
    : '<ul class="ag-plain" aria-label="Score breakdown">' + list.map(f => {
      const name = AA.humanise(f.factor);
      return '<li><div class="ag-kv"><span>' + esc(name) + '</span><b aria-label="' + esc(name + ' ' + f.points + ' of ' + f.max) + '">' + (+f.points) + ' of ' + (+f.max) + '</b></div>' +
        '<div class="ag-bar-track" aria-hidden="true"><i style="width:' + (f.max ? Math.min(100, f.points / f.max * 100) : 0) + '%"></i></div></li>';
    }).join('') + '</ul>';

  const sources = list => !list || !list.length ? '<p class="muted">No sources attached.</p>'
    : list.map(s => '<div class="ag-src">' + AA.verifyChip(s.label) + (s.tier ? ' <span class="muted small">' + esc(AA.TIER[s.tier] || s.tier) + '</span>' : '') +
      (s.title ? '<p>' + esc(s.title) + '</p>' : '') + (s.quote ? '<blockquote>“' + esc(s.quote) + '”</blockquote>' : '') +
      (safeUrl(s.url) ? '<p class="small"><a href="' + esc(safeUrl(s.url)) + '" target="_blank" rel="noopener noreferrer">Read it on ' + esc(host(s.url)) + '</a></p>' : '') + '</div>').join('');

  const contacts = list => !list || !list.length ? '<p class="muted">No direct email or phone found. We don\'t guess contact details.</p>'
    : list.map(c => '<div class="ag-src"><b>' + esc(c.name) + '</b>' + (c.position ? ' <span class="muted">· ' + esc(c.position) + '</span>' : '') +
      '<div class="ag-row">' + AA.tag(c.check === 'verified' ? 'ok' : c.check === 'likely' ? 'warn' : '', AA.CONTACT_CHECK[c.check || 'unverified']) +
      (c.email ? '<span>' + esc(c.email) + '</span>' : '') + (c.phone ? '<span>' + esc(c.phone) + '</span>' : '') + '</div>' +
      '<p class="muted small">' + esc(c.evidence || 'No evidence recorded.') + '</p></div>').join('');

  const tasks = list => !list || !list.length ? '<p class="muted">No tasks suggested.</p>'
    : '<ol class="ag-tasks">' + list.map(t => '<li><b>' + esc(t.title) + '</b>' + (t.why ? ' <span class="muted">— ' + esc(t.why) + '</span>' : '') + '</li>').join('') + '</ol>';

  const plainFacts = it => {
    const rows = Object.entries(it.payload).filter(([k, v]) => !AA.HIDDEN.has(k) && v !== null && v !== '' && typeof v !== 'object').map(([k, v]) => kv(AA.FIELD[k] || AA.humanise(k), String(v))).join('');
    const note = it.payload.source_note ? kv('Where this came from', String(it.payload.source_note)) : '';
    return (rows || note ? '<div class="ag-facts">' + rows + note + '</div>' : '<p class="muted">No further details.</p>') + (it.kind === 'contact' && it.payload.name ? contacts([{ name: it.payload.name, position: it.payload.position, email: it.payload.email, phone: it.payload.phone, check: it.payload.email_evidence ? 'likely' : 'unverified', evidence: it.payload.email_evidence || it.payload.source_note }]) : '');
  };

  const body = it => {
    const p = it.payload, company = p.company_name || p.buyer || 'this company';
    if (it.kind !== 'signal') return card(AA.KIND[it.kind] || 'Details', plainFacts(it) + (it.score !== null ? '<div class="ag-row">' + AA.score(it.score) + '</div>' : ''));
    return card('', '<div class="ag-top">' + AA.score(it.score) + '<div class="grow"><h2>' + esc(company) + (p.signal_type ? ' · ' + esc(AA.SIGNAL[p.signal_type] || AA.humanise(p.signal_type)) : '') + '</h2>' +
      (p.summary ? '<p>' + esc(p.summary) + '</p>' : '') + '</div></div>' + '<div class="ag-facts">' + kv('Aerosub service', p.service) + kv('Buyer', p.buyer) + kv('Timing', p.timing) + kv('Existing relationship', p.existing_relationship) + '</div>') +
      card('How the score adds up', factors(p.score_breakdown) + ((p.notes || []).map(n => '<p class="muted small">' + esc(n) + '</p>').join(''))) + card('Evidence', sources(p.sources)) + card('Who to contact', contacts(p.contacts)) +
      (p.competitors && p.competitors.length ? card('Competitors involved', '<p>' + esc(p.competitors.join(', ')) + '</p>') : '') + card('Suggested tasks', tasks(p.tasks));
  };

  const history = it => !it.history || !it.history.length ? '' : card('History', '<ul class="ag-plain">' + it.history.map(h =>
    '<li class="ag-kv"><span>' + esc(HISTORY[h.action] || AA.humanise(h.action)) + (h.reason_code ? ': ' + esc(AA.REJECT_LABEL(h.reason_code)) : '') + (h.actor ? ' · ' + esc(h.actor) : '') + '</span><span class="muted">' + esc(AA.date(h.created_at)) + '</span></li>').join('') + '</ul>');

  AA.item = async (box, id) => {
    let it;
    try { it = await AA.call('agent-owner/items/' + id); }
    catch (e) { if (e.step === 'code') return AA.go(); box.innerHTML = e.status === 404 ? AA.empty("This opportunity isn't available any more.") : AA.errorBox('this opportunity', 'agretry'); const r = $('#agretry'); if (r) r.onclick = () => AA.item(box, id); return; }
    const company = it.payload.company_name || it.payload.buyer || it.payload.name || '';
    const banners = it.flag_open ? (it.payload.risk_flags || []).map(f => AA.banner('bad', '⚑', esc(AA.riskText(f, company)))).join('') : '';
    let cleared = false;
    const actions = () => it.status !== 'pending' ? '' :
      '<div class="ag-actions">' + (it.flag_open && !cleared ? '<button class="btn" id="agclear">Clear risk flag</button>' : '<button class="btn" id="agok">Approve</button>') +
      '<button class="btn ghost" id="agedit">Edit</button><button class="btn ghost danger-text" id="agno">Reject</button></div>';
    const syncing = it.status === 'approved' && it.sync_state === 'syncing'
      ? AA.banner('warn', '↻', "Approval saved, but the pipeline update didn't go through. We'll retry automatically. <button class=\"btn ghost sm\" id=\"agretrysync\">Retry now</button>") : '';
    const draw = () => { box.innerHTML = banners + syncing + body(it) + history(it) + actions(); wire(); };
    const wire = () => {
      const on = (sel, fn) => { const el = $(sel, box); if (el) el.onclick = fn; };
      on('#agclear', () => AA.decide.clearFlag(company, () => { cleared = true; draw(); }));
      on('#agok', () => AA.decide.approve(it, {}, it.flag_open && cleared));
      on('#agedit', () => AA.decide.edit(it, edits => AA.decide.approve(it, edits, it.flag_open && cleared)));
      on('#agno', () => AA.decide.reject(it));
      on('#agretrysync', async () => { try { const r = await AA.call('agent-owner/items/' + id + '/sync', { method: 'POST' }); AA.X.toast(r.ok ? 'Pipeline updated.' : 'Still could not update the pipeline.'); AA.item(box, id); } catch (e) { AA.X.toast(e.message); } });
    };
    draw();
  };
})();
