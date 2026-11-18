/* Agents area: Research agent > Files (mockup M5). Upload research documents and read the checked result.
 * Uses the same upload endpoint and the same result view as the office Documents screen. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  let timer = 0;

  const readBase64 = file => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1] || ''); r.onerror = () => rej(new Error('read')); r.readAsDataURL(file); });
  const statusChip = d => AA.chip(AA.DOC_TONE[d.status] || '', d.status === 'ready' ? '✓' : d.status === 'failed' ? '!' : '…', AA.DOC_STATUS[d.status] || AA.humanise(d.status));

  const row = d => {
    const counts = d.status === 'ready'
      ? '<span class="ag-row">' + AA.tag('ok', d.claim_supported + ' backed') + (d.claim_cannot_check ? AA.tag('warn', d.claim_cannot_check + " couldn't check") : '') + AA.tag('', AA.plural(d.claim_total, 'claim')) + '</span>'
      : d.status === 'queued' ? '<span class="muted small ag-block">Agent last online ' + esc(AA.rel(AA.research.health && AA.research.health.last_seen_at)) + '</span>' : '';
    return '<a class="card ag-doc" href="#/agents/research/files/' + (+d.id) + '"><span class="ag-line"><b class="grow">' + esc(d.original_name) + '</b>' + statusChip(d) + '</span>' +
      '<span class="muted small ag-block">Uploaded ' + esc(AA.date(d.created_at)) + (d.uploaded_by_name ? ' · ' + esc(d.uploaded_by_name) : '') + (d.company_name ? ' · ' + esc(d.company_name) : '') + '</span>' + counts + '</a>';
  };

  async function upload(file, max, err, prog, done) {
    err.textContent = '';
    if (!file) { err.textContent = 'Choose a PDF or Word file.'; return; }
    if (!/\.(pdf|docx)$/i.test(file.name)) { err.textContent = 'Only PDF and Word files can be checked.'; return; }
    if (file.size > max * 1048576) { err.textContent = 'This file is larger than ' + max + ' MB. Upload a smaller file.'; return; }
    prog.textContent = 'Uploading ' + file.name + '…';
    try {
      const r = await AA.call('documents', { method: 'POST', data: { name: file.name, data: await readBase64(file), company_id: null } });
      prog.textContent = '';
      if (r.duplicate) { err.innerHTML = 'This file was already checked on ' + esc(AA.date(r.checked_at) || 'an earlier date') + '. <a href="#/agents/research/files/' + (+r.id) + '">View the result.</a>'; return; }
      AA.X.toast('Document uploaded'); done();
    } catch (e) { if (e.step === 'code') return AA.go(); prog.textContent = ''; err.textContent = e.offline ? e.message : (e.message && e.status ? e.message : 'Upload failed. Check your connection and try again.'); }
  }

  AA.research.tabs.files = async (box, id) => {
    clearTimeout(timer);
    if (id !== null) return detail(box, id);
    let max = 8; try { max = (await AA.call('documents/limits')).max_mb || 8; } catch { /* keep the default */ }
    const draw = async () => {
      box.innerHTML = '<div class="card ag-drop">' + AA.icon.upload + '<b>Upload a research document</b><span class="muted small">PDF or Word, up to ' + max + ' MB. The agent checks each claim\'s sources.</span>' +
        '<input id="agfile" type="file" class="ag-sr" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" aria-label="Choose a file">' +
        '<button class="btn sm" id="agpick" type="button">Choose file</button><div id="agerr" class="ag-err" role="alert"></div><div id="agprog" class="muted small" aria-live="polite"></div></div><div id="aglist">' + AA.skeleton(3) + '</div>';
      $('#agpick').onclick = () => $('#agfile').click();
      $('#agfile').onchange = e => upload(e.target.files[0], max, $('#agerr'), $('#agprog'), draw);
      const list = $('#aglist');
      try {
        const rows = await AA.call('agent-owner/documents');
        list.innerHTML = rows.length ? '<div class="ag-stack">' + rows.map(row).join('') + '</div>' : AA.empty('No documents yet. Upload a PDF or Word file to have its sources checked.');
        if (rows.some(d => d.status === 'queued' || d.status === 'checking')) timer = setTimeout(() => { if ($('#aglist') && location.hash === '#/agents/research/files') draw(); }, 15000);
      } catch (e) { if (e.step === 'code') return AA.go(); list.innerHTML = AA.errorBox('files', 'agretry'); $('#agretry').onclick = draw; }
    };
    await draw();
  };

  async function detail(box, id) {
    let d;
    try { d = await AA.call('agent-owner/documents/' + id); }
    catch (e) { if (e.step === 'code') return AA.go(); box.innerHTML = e.status === 404 ? AA.empty("This document isn't available any more.") : AA.errorBox('this document', 'agretry'); const r = $('#agretry'); if (r) r.onclick = () => detail(box, id); return; }
    const head = '<div class="card ag-doc"><span class="ag-line"><b class="grow">' + esc(d.original_name) + '</b>' + statusChip(d) + '</span><span class="muted small ag-block">' +
      esc([d.company_name, d.uploaded_by_name ? 'Uploaded by ' + d.uploaded_by_name : '', d.checked_at ? 'Checked ' + AA.date(d.checked_at) : ''].filter(Boolean).join(' · ')) + '</span>' +
      '<a class="btn ghost sm" href="api/documents/' + (+id) + '/file">Download original</a></div>';
    if (!d.review) {
      box.innerHTML = head + AA.empty(d.status === 'failed' ? "Couldn't check this file. " + (d.status_note || '') : 'Waiting for agent. Last online ' + AA.rel(d.agent_last_seen_at) + '.') +
        (d.status === 'failed' ? '<button class="btn" id="agretry2">Retry</button>' : '');
      const rt = $('#agretry2'); if (rt) rt.onclick = async () => { try { await AA.call('documents/' + id + '/retry', { method: 'POST' }); detail(box, id); } catch (e) { AA.X.toast(e.message || "Couldn't retry. Try again."); } };
      if (d.status !== 'failed') timer = setTimeout(() => { if ($('#agbody') && location.hash === '#/agents/research/files/' + id) detail(box, id); }, 15000);
      return;
    }
    box.innerHTML = head + '<div id="agres"></div>';
    AA.X.renderResult($('#agres'), d, '');
  }
})();
