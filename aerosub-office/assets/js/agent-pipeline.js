/* Aerosub Office: agent platform screens for the Pipeline (Documents, "Ask the agent to research", Agent badge).
 * Loaded after app.js. It only uses what app.js exposes on window.OfficeExt (see office-app-changes/README.md).
 * Wording follows docs/design/aerosub-agent-platform-design-spec.md section 8. */
(() => {
  'use strict';
  const X = window.OfficeExt;
  if (!X) return; // the three small hooks in app.js are not installed: do nothing rather than break the app
  const { api, modal, toast, esc, S, views, readOnly } = X;
  const $ = (s, r = document) => r.querySelector(s);

  /* ---------- wording ---------- */
  const DOC_STATUS = { queued: 'Waiting for agent', checking: 'Checking', ready: 'Ready', failed: "Couldn't check this file" };
  const DOC_TONE = { queued: 'info', checking: 'info', ready: 'ok', failed: 'bad' };
  const LABELS = {
    supported: ['Supported', '✓', 'ok', 'We opened the source and found a passage that backs this claim.'],
    partly_supported: ['Partly supported', '◐', 'warn', 'The source backs most of it, but a number, date or name differs.'],
    not_supported: ['Not supported', '✕', 'bad', 'We opened the source and found nothing that backs this claim.'],
    cannot_check: ["Couldn't check", '⏸', 'info', "We couldn't open the source. This is not a pass."],
    cited_not_online: ['Cited, not online', '⌕', 'info', "The source is named, but we couldn't find it online."],
    source_suggested: ['Source suggested', '↗', 'info', 'The document gave no source. We found a possible one — please review it.'],
    no_source: ['No source', '–', 'muted', 'No source is given and none was found.'],
    author_flagged: ['Author unsure', '⚑', 'warn', 'The author marked this as unconfirmed or conflicting.'],
  };
  const REASON = { dead: 'link dead', blocked: 'site blocked us', paywalled: 'paywalled', login: 'login needed' };
  const REASON_LONG = { dead: 'the link is dead', blocked: 'the site blocked us', paywalled: 'it is behind a paywall', login: 'it needs a login' };
  const TIER = { primary: 'Primary source', news: 'News', weak: 'Weak source (forum, job board, aggregator)' };

  const fmtDate = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); };
  const rel = v => {
    if (!v) return 'never';
    const m = Math.round((Date.now() - new Date(String(v).replace(' ', 'T')).getTime()) / 60000);
    if (isNaN(m)) return 'never'; if (m < 1) return 'just now'; if (m < 60) return m + ' minute' + (m === 1 ? '' : 's') + ' ago';
    const h = Math.floor(m / 60); if (h < 24) return h + ' hour' + (h === 1 ? '' : 's') + ' ago';
    const d = Math.floor(h / 24); return d === 1 ? 'Yesterday' : d < 7 ? d + ' days ago' : fmtDate(v);
  };
  const chip = (tone, icon, text, title) => '<span class="ag-chip ' + tone + '"' + (title ? ' title="' + esc(title) + '"' : '') + '><span aria-hidden="true">' + icon + '</span>' + esc(text) + '</span>';
  const labelChip = (label, reason) => {
    const l = LABELS[label] || [label, '•', 'muted', '']; const text = label === 'cannot_check' && reason ? "Couldn't check — " + (REASON[reason] || reason) : l[0];
    const tip = label === 'cannot_check' && reason ? "We couldn't open the source: " + (REASON_LONG[reason] || reason) + '. This is not a pass.' : l[3];
    return chip(l[2], l[1], text, tip) + '<span class="ag-sr">' + esc(tip) + '</span>';
  };
  const docUrl = (id, tail) => location.origin + location.pathname.replace(/[^/]*$/, '') + 'api/documents/' + id + (tail || '');
  const asError = (e, fallback) => (e && e.message && !/^Failed to fetch|NetworkError/i.test(e.message)) ? e.message : fallback;

  /* ---------- Documents list ---------- */
  let refreshTimer = 0;
  views.documents = async () => {
    clearTimeout(refreshTimer);
    const id = location.hash.split('/')[2];
    if (id) return docDetail(+id);
    const v = $('#view');
    v.innerHTML = '<div class="top"><div><h1>Documents</h1><div class="muted">Reports checked by the agent: which claims have a source you can open</div></div>' +
      (readOnly() ? '' : '<button class="btn right" id="agup">Upload document</button>') + '</div><div id="aglist"><div class="empty">Loading…</div></div>';
    const up = $('#agup'); if (up) up.onclick = () => uploadDialog();
    await drawList();
  };

  async function drawList() {
    const box = $('#aglist'); if (!box) return;
    let rows;
    try { rows = await api('documents'); }
    catch { box.innerHTML = '<div class="card empty">Couldn\'t load documents. <button class="btn ghost sm" id="agretry">Retry</button></div>'; const r = $('#agretry'); if (r) r.onclick = drawList; return; }
    if (!rows.length) { box.innerHTML = '<div class="card empty">No documents yet. Upload a PDF or Word file to have its sources checked.</div>'; return; }
    box.innerHTML = '<div class="card"><div class="tablewrap"><table><thead><tr><th>Document</th><th>Attached to</th><th>Uploaded by</th><th>Uploaded</th><th>Status</th><th>Agent check</th></tr></thead><tbody>' +
      rows.map(d => '<tr data-doc="' + d.id + '" tabindex="0" style="cursor:pointer"><td><b>' + esc(d.original_name) + '</b></td><td>' + esc(d.company_name || d.task_title || '—') + '</td><td>' + esc(d.uploaded_by_name || '—') + '</td><td>' + esc(fmtDate(d.created_at)) + '</td><td>' +
        chip(DOC_TONE[d.status] || 'muted', d.status === 'ready' ? '✓' : d.status === 'failed' ? '!' : '…', DOC_STATUS[d.status] || d.status) + '</td><td>' +
        (d.status === 'ready' ? esc(d.claim_supported + ' of ' + d.claim_total + ' claims backed' + (d.claim_cannot_check ? ', ' + d.claim_cannot_check + " couldn't be checked" : '')) : '<span class="muted">—</span>') + '</td></tr>').join('') + '</tbody></table></div></div>';
    box.querySelectorAll('[data-doc]').forEach(tr => { const go = () => { location.hash = '#/documents/' + tr.dataset.doc; }; tr.onclick = go; tr.onkeydown = e => { if (e.key === 'Enter') go(); }; });
    if (rows.some(d => d.status === 'queued' || d.status === 'checking')) refreshTimer = setTimeout(() => { if ($('#aglist')) drawList(); }, 15000);
  }

  /* ---------- Upload ---------- */
  function readBase64(file) {
    return new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result).split(',')[1] || ''); r.onerror = () => reject(new Error('read')); r.readAsDataURL(file); });
  }
  async function uploadDialog(preset) {
    let max = 8; try { max = (await api('documents/limits')).max_mb || 8; } catch { /* keep the default */ }
    const companies = (S.companies || []).slice().sort((a, b) => a.name.localeCompare(b.name));
    const m = modal('<h2>Upload document</h2><form id="agf" style="margin-top:14px"><div class="field"><label for="agfile">File</label><input id="agfile" type="file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">' +
      '<div class="muted small">PDF or Word, up to ' + max + ' MB. The agent checks each claim\'s sources.</div></div>' +
      '<div class="field"><label for="agco">Attach to</label><select id="agco"><option value="">Not attached to a company</option>' + companies.map(c => '<option value="' + c.id + '"' + (preset && +preset === +c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select></div>' +
      '<div class="err" id="agerr" role="alert"></div><div id="agprog" class="muted small" aria-live="polite"></div><div class="row"><button class="btn" id="agsend">Upload</button><button class="btn ghost" type="button" id="agcx">Cancel</button></div></form>');
    $('#agcx', m).onclick = () => m.close();
    $('#agf', m).onsubmit = async e => {
      e.preventDefault(); const err = $('#agerr', m), prog = $('#agprog', m), f = $('#agfile', m).files[0]; err.textContent = '';
      if (!f) { err.textContent = 'Choose a PDF or Word file.'; return; }
      if (!/\.(pdf|docx)$/i.test(f.name)) { err.textContent = 'Only PDF and Word files can be checked.'; return; }
      if (f.size > max * 1048576) { err.textContent = 'This file is larger than ' + max + ' MB. Upload a smaller file.'; return; }
      const send = $('#agsend', m); send.disabled = true; prog.textContent = 'Uploading ' + f.name + '…';
      try {
        const r = await api('documents', { method: 'POST', data: { name: f.name, data: await readBase64(f), company_id: $('#agco', m).value || null } });
        if (r.duplicate) { prog.textContent = ''; err.innerHTML = 'This file was already checked on ' + esc(fmtDate(r.checked_at) || 'an earlier date') + '. <a href="#/documents/' + (+r.id) + '">View the result.</a>'; send.disabled = false; return; }
        m.close(); toast('Document uploaded'); if (location.hash.startsWith('#/documents')) { location.hash = '#/documents'; X.render(); } else location.hash = '#/documents';
      } catch (er) { prog.textContent = ''; err.textContent = asError(er, 'Upload failed. Check your connection and try again.'); send.disabled = false; }
    };
  }

  /* ---------- Document detail ---------- */
  async function docDetail(id) {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let d;
    try { d = await api('documents/' + id); } catch { v.innerHTML = '<div class="card empty">Couldn\'t load the check results. <button class="btn ghost sm" id="agretry">Retry</button></div>'; $('#agretry').onclick = () => docDetail(id); return; }
    const head = '<div class="top"><div><a href="#/documents" class="small">← All documents</a><h1>' + esc(d.original_name) + '</h1><div class="muted small">' +
      esc([d.company_name, d.uploaded_by_name ? 'Uploaded by ' + d.uploaded_by_name : '', d.checked_at ? 'Checked ' + fmtDate(d.checked_at) : ''].filter(Boolean).join(' · ')) + '</div></div>' +
      '<div class="right">' + chip(DOC_TONE[d.status] || 'muted', d.status === 'ready' ? '✓' : '…', DOC_STATUS[d.status] || d.status) + ' <a class="btn ghost sm" href="' + esc(docUrl(id, '/file')) + '">Download original</a></div></div>';
    if (!d.review) {
      v.innerHTML = head + '<div class="card empty">' + (d.status === 'failed'
        ? esc("Couldn't check this file. " + (d.status_note || '')) + (readOnly() ? '' : ' <button class="btn sm" id="agretry2">Retry</button>')
        : 'Waiting for agent. Last online ' + esc(rel(d.agent_last_seen_at)) + '.') + '</div>';
      const rt = $('#agretry2'); if (rt) rt.onclick = async () => { try { await api('documents/' + id + '/retry', { method: 'POST' }); docDetail(id); } catch (er) { toast(asError(er, "Couldn't retry. Try again.")); } };
      return;
    }
    renderResult(v, d, head);
  }

  /* Scorecard, summary, claims and conflicts of a checked document, drawn into `v` after `head`.
   * Shared: the Documents screen above and the Agents area (X.renderResult). */
  function renderResult(v, d, head) {
    const sc = d.review.scorecard, tabs = [['sc', 'Scorecard'], ['sm', 'Summary'], ['cl', 'Claims'], ['cf', 'Conflicts']];
    v.innerHTML = head + '<div class="chips" role="tablist" aria-label="Check results" style="margin:12px 0">' + tabs.map(([k, l], i) => '<button type="button" role="tab" class="chip ' + (i === 0 ? 'on' : '') + '" data-tab="' + k + '" aria-selected="' + (i === 0) + '">' + l + '</button>').join('') + '</div><div id="agtab"></div>';
    const pct = n => Math.round(n * 100) + '%';
    const panels = {
      sc: () => '<div class="cards"><div class="card stat"><span class="muted small">Statements found</span><b>' + sc.claims_total + '</b></div><div class="card stat"><span class="muted small">Cited by the author</span><b>' + pct(sc.citation_coverage) + '</b></div>' +
        '<div class="card stat ok"><span class="muted small">Backed by a source we opened</span><b>' + (sc.labels.supported || 0) + '</b></div><div class="card stat warn"><span class="muted small">Author unsure</span><b>' + sc.author_flagged + '</b></div></div>' +
        '<div class="card"><h3>Backing by kind of source</h3><ul>' + Object.entries(sc.support_by_tier).map(([t, x]) => '<li>' + esc(TIER[t] || t) + ': ' + x.supported + ' of ' + x.total + ' backed</li>').join('') + '</ul>' +
        (Object.values(sc.cannot_check_by_reason).some(n => n) ? '<h3>Why some could not be checked</h3><ul>' + Object.entries(sc.cannot_check_by_reason).filter(([, n]) => n).map(([r, n]) => '<li>' + n + ' — ' + esc(REASON[r] || r) + '</li>').join('') + '</ul>' : '') + '</div>',
      sm: () => '<div class="card"><p>' + esc(d.review.summary || 'No summary was written.') + '</p>' + (d.review.action_points.length ? '<h3>What to do next</h3><ol>' + d.review.action_points.map(a => '<li>' + esc(a.text) + ' <span class="muted small">(statement ' + esc((a.claims || []).join(', ')) + ')</span></li>').join('') + '</ol>' : '') + '</div>',
      cl: () => {
        const by = k => d.claims.filter(c => !k || c.label === k);
        return '<div class="row" style="margin-bottom:10px"><label for="agfilter" class="small">Show</label><select id="agfilter" style="width:auto"><option value="">All statements (' + d.claims.length + ')</option>' + Object.entries(LABELS).map(([k, l]) => '<option value="' + k + '">' + esc(l[0]) + ' (' + by(k).length + ')</option>').join('') + '</select></div><div id="agclaims"></div>';
      },
      cf: () => d.review.conflicts.length ? d.review.conflicts.map(c => '<div class="card"><div class="two"><div><h3>This document</h3><p>' + esc(c.claim_text) + '</p></div><div><h3>' + esc(c.other_document) + '</h3><p>' + esc(c.other_claim_text) + '</p></div></div></div>').join('') : '<div class="card empty">No conflicts with other documents.</div>',
    };
    const paintClaims = k => {
      const hand = new Set(sc.check_by_hand || []), list = d.claims.filter(c => !k || c.label === k);
      $('#agclaims').innerHTML = list.length ? list.map(c => '<div class="card"><div class="row">' + labelChip(c.label, c.reason) + '<span class="muted small right">Statement ' + c.position + (hand.has(c.position) ? ' · worth checking by hand' : '') + '</span></div><p>' + esc(c.claim_text) + '</p>' +
        (c.quote ? '<blockquote class="ag-quote">“' + esc(c.quote) + '”</blockquote>' : '') + '<div class="muted small">' + esc(c.source_tier ? (TIER[c.source_tier] || c.source_tier) + ' · ' : '') +
        (/^https?:\/\//i.test(c.source_url || '') ? '<a href="' + esc(c.source_url) + '" target="_blank" rel="noopener noreferrer">Open the source<span class="ag-sr"> (opens in a new tab)</span></a>' : 'No source opened') + '</div></div>').join('') : '<div class="card empty">No claims were found in this document.</div>';
    };
    const show = k => { $('#agtab').innerHTML = panels[k](); v.querySelectorAll('[data-tab]').forEach(b => { const on = b.dataset.tab === k; b.classList.toggle('on', on); b.setAttribute('aria-selected', on); }); if (k === 'cl') { paintClaims(''); $('#agfilter').onchange = e => paintClaims(e.target.value); } };
    v.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => show(b.dataset.tab));
    show('sc');
  }

  /* ---------- Company page: Ask the agent to research, badge, ratings, attached documents ---------- */
  X.onCompany = (m, c) => {
    const box = document.createElement('div'); box.id = 'agbox'; box.className = 'ag-box';
    const root = m.querySelector('.modal'); if (!root) return; root.insertBefore(box, root.children[1] || null);
    const ro = readOnly();
    box.innerHTML = '<div class="row" id="agbadge"></div>' + (ro ? '' : '<div class="row"><button class="btn ghost sm" id="agask">Ask the agent to research</button></div>') + '<div id="agdocs"></div><div class="muted small" id="agmsg" role="status" aria-live="polite"></div>';
    const ask = $('#agask', box); if (ask) ask.onclick = () => researchDialog(c, box);
    badgeInto($('#agbadge', box), 'company', c.id, !ro);
    api('documents?company_id=' + c.id).then(rows => {
      if (!rows.length) return;
      $('#agdocs', box).innerHTML = '<h3 style="margin-top:8px">Documents (' + rows.length + ')</h3><div class="list">' + rows.map(d => '<a class="item" href="#/documents/' + d.id + '" data-close-scrim="1"><div class="grow"><b>' + esc(d.original_name) + '</b><div class="muted small">' + esc(DOC_STATUS[d.status] || d.status) + (d.status === 'ready' ? ' · ' + d.claim_supported + ' of ' + d.claim_total + ' claims backed' : '') + '</div></div></a>').join('') + '</div>';
    }).catch(() => { /* the section simply stays empty */ });
  };

  /* ---------- Task page: RFQs can be researched too, and anything the agent created shows its badge ---------- */
  X.onTask = (m, t) => {
    const isRfq = t.task_type === 'rfq', byAgent = t.source === 'agent';
    if (!isRfq && !byAgent) return;
    const root = m.querySelector('.modal'); if (!root) return;
    const box = document.createElement('div'); box.id = 'agbox'; box.className = 'ag-box'; root.insertBefore(box, root.children[1] || null);
    const ro = readOnly();
    box.innerHTML = '<div class="row" id="agbadge"></div>' + (isRfq && !ro ? '<div class="row"><button class="btn ghost sm" id="agask">Ask the agent to research this RFQ</button></div>' : '') + '<div class="muted small" id="agmsg" role="status"></div>';
    const ask = $('#agask', box); if (ask) ask.onclick = () => researchDialog({ id: t.id, name: t.title }, box, 'task_id');
    if (byAgent) badgeInto($('#agbadge', box), 'task', t.id, !ro);
  };

  function researchDialog(c, box, key = 'company_id') {
    const m = modal('<h2>Ask the agent to research</h2><p class="muted small">' + esc(c.name) + '</p><form id="agrf" style="margin-top:10px"><div class="field"><label for="agnote">Note</label><textarea id="agnote" maxlength="500" placeholder="Anything the agent should focus on? (optional)"></textarea></div>' +
      '<div class="err" id="agrerr" role="alert"></div><div class="row"><button class="btn" id="agrsend">Send</button><button class="btn ghost" type="button" id="agrcx">Cancel</button></div></form>');
    $('#agrcx', m).onclick = () => m.close();
    $('#agrf', m).onsubmit = async e => {
      e.preventDefault(); const err = $('#agrerr', m), send = $('#agrsend', m); err.textContent = ''; send.disabled = true;
      try {
        await api('research-requests', { method: 'POST', data: { [key]: c.id, note: $('#agnote', m).value } });
        m.close(); const msg = $('#agmsg', box); if (msg) msg.textContent = "Sent to the owner's research inbox. Results appear here after he approves them."; toast('Request sent');
      } catch (er) {
        send.disabled = false;
        err.textContent = er.status === 409 ? 'A request for ' + c.name + ' is already open.' : 'Request not sent. Try again.';
      }
    };
  }

  /* ---------- Agent badge and Useful / Not useful ---------- */
  const badgeCache = { company: new Map(), contact: new Map(), task: new Map() };
  async function loadBadges(type, ids) {
    const need = ids.filter(i => !badgeCache[type].has(i)); if (!need.length) return;
    try { const rows = await api('agent-badges?subject_type=' + type + '&ids=' + need.join(',')); need.forEach(i => badgeCache[type].set(i, null)); rows.forEach(r => badgeCache[type].set(+r.subject_id, r)); }
    catch { /* no badges is a safe fallback */ }
  }
  const badgeHtml = b => '<span class="ag-badge" title="Suggested by the agent and approved by ' + esc(b.approved_by_name || 'the owner') + '.">Agent' + (b.score !== null && b.score !== undefined ? ' · ' + (+b.score) : '') + '</span>';
  function ratingHtml(b) {
    const on = b.rating;
    return '<span class="ag-rate" role="group" aria-label="Was this suggestion useful?"><button type="button" class="btn ghost sm" data-rate="useful" aria-pressed="' + (on === 'useful') + '">Useful</button><button type="button" class="btn ghost sm" data-rate="not_useful" aria-pressed="' + (on === 'not_useful') + '">Not useful</button></span>';
  }
  function wireRating(el, type, id, b) {
    el.querySelectorAll('[data-rate]').forEach(btn => btn.onclick = async e => {
      e.stopPropagation(); const rating = btn.dataset.rate;
      try { await api('agent-ratings', { method: 'POST', data: { subject_type: type, subject_id: id, rating } }); b.rating = rating; el.querySelectorAll('[data-rate]').forEach(x => x.setAttribute('aria-pressed', String(x.dataset.rate === rating))); toast('Thanks. Your answer was saved.'); }
      catch { toast("Couldn't save your answer. Try again."); }
    });
  }
  async function badgeInto(el, type, id, withRating) {
    await loadBadges(type, [id]); const b = badgeCache[type].get(id); if (!b || !el) return;
    el.innerHTML = badgeHtml(b) + (withRating ? ratingHtml(b) : ''); wireRating(el, type, id, b);
  }

  // rows in the Pipeline lists (companies table, board cards, contacts table) get a badge when the agent created them
  let scanTimer = 0;
  async function scan() {
    const view = $('#view'); if (!view) return;
    const pick = (sel, attr, type) => Array.from(view.querySelectorAll(sel)).filter(el => !el.dataset.ag).map(el => ({ el, id: +el.getAttribute(attr), type }));
    const targets = pick('[data-co]', 'data-co', 'company').concat(pick('tr[data-k]', 'data-k', 'contact'));
    if (!targets.length) return;
    targets.forEach(t => { t.el.dataset.ag = '1'; });
    for (const type of ['company', 'contact']) await loadBadges(type, [...new Set(targets.filter(t => t.type === type).map(t => t.id))]);
    targets.forEach(t => {
      const b = badgeCache[t.type].get(t.id); if (!b) return;
      const name = t.el.querySelector('b'); if (!name || name.parentNode.querySelector('.ag-badge')) return;
      name.insertAdjacentHTML('afterend', ' ' + badgeHtml(b) + (readOnly() ? '' : ' ' + ratingHtml(b))); wireRating(name.parentNode, t.type, t.id, b);
    });
  }
  new MutationObserver(() => { clearTimeout(scanTimer); scanTimer = setTimeout(scan, 200); }).observe(document.body, { childList: true, subtree: true });

  X.renderResult = renderResult;

  /* ---------- Documents in the menu ---------- */
  // Its own icon (a page with lines). It used to borrow the book from Learn and Research, so three menu items looked the same.
  const DOCUMENT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>';
  X.nav = (items, icons) => {
    const at = items.findIndex(x => x[0] === 'pipeline'); items.splice(at < 0 ? items.length : at + 1, 0, ['documents', 'Documents', DOCUMENT_ICON]);
  };
})();
