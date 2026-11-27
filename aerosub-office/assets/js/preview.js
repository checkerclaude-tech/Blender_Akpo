/* Aerosub Office: file preview. Wherever a document was shared (messages, shared My Documents, task files, chat, RFQ files) it can be looked at here
 * instead of downloaded: pictures, PDFs, text and CSV, audio and video, and Word / Excel / PowerPoint as readable text and tables.
 * A "Preview" button is added beside file links by itself. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._pvLoaded) return; X._pvLoaded = 1;
  const { api, modal, toast, esc } = X; const $ = (s, r = document) => r.querySelector(s);
  const MIME = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml', pdf: 'application/pdf', txt: 'text/plain', csv: 'text/csv', json: 'application/json', md: 'text/plain', log: 'text/plain', mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', mp4: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime' };
  const OFFICE = /\.(docx|xlsx|pptx)$/i;
  const b64 = blob => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = () => no(new Error('Could not read the file')); r.readAsDataURL(blob); });
  const csvTable = txt => { const rows = txt.split(/\r?\n/).slice(0, 300).map(l => l.split(/,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map(c => c.replace(/^"|"$/g, '').replace(/""/g, '"'))); return '<table>' + rows.map((r, i) => '<tr>' + r.slice(0, 30).map(c => (i ? '<td>' : '<th>') + esc(c) + (i ? '</td>' : '</th>')).join('') + '</tr>').join('') + '</table>'; };

  /** Open a file in a viewer window. url must be one of this site's own file addresses (it is fetched with the signed-in session). */
  X.preview = async function (url, name) {
    name = name || (decodeURIComponent((url.split('?')[0].split('/').pop()) || 'file'));
    const m = modal('<div class="row" style="gap:8px"><h2 class="grow" style="overflow-wrap:anywhere">' + esc(name) + '</h2><a class="btn ghost sm" id="pv-dl" href="' + esc(url) + '" download>Download</a><button class="btn ghost sm" id="pv-x">Close</button></div><div id="pv-b" class="pv-body"><div class="empty">Loading…</div></div>');
    let objUrl = ''; const done = () => { if (objUrl) URL.revokeObjectURL(objUrl); m.close(); }; $('#pv-x', m).onclick = done;
    const body = $('#pv-b', m), set = h => { body.innerHTML = h; }, none = why => set('<div class="empty">' + esc(why || 'There is no preview for this kind of file.') + ' Use Download to open it.</div>');
    try {
      const res = await fetch(url, { credentials: 'same-origin' }); if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The file could not be opened'); const blob = await res.blob();
      const ext = (name.split('.').pop() || '').toLowerCase(), type = (blob.type && blob.type !== 'application/octet-stream' ? blob.type : (MIME[ext] || '')).split(';')[0];
      if (blob.size > 25 * 1048576) return none('This file is large (' + Math.round(blob.size / 1048576) + ' MB).');
      if (/^image\//.test(type)) { objUrl = URL.createObjectURL(blob); set('<img src="' + objUrl + '" alt="' + esc(name) + '" class="pv-img">'); }
      else if (type === 'application/pdf') { objUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' })); set('<iframe class="pv-frame" src="' + objUrl + '" title="' + esc(name) + '"></iframe>'); }
      else if (/^video\//.test(type)) { objUrl = URL.createObjectURL(blob); set('<video controls class="pv-img" src="' + objUrl + '"></video>'); }
      else if (/^audio\//.test(type)) { objUrl = URL.createObjectURL(blob); set('<audio controls src="' + objUrl + '" style="width:100%"></audio>'); }
      else if (OFFICE.test(name)) { const r = await api('preview', { method: 'POST', data: { name, data: await b64(blob) } }); set('<div class="pv-doc">' + r.html + '</div>' + (r.truncated ? '<div class="muted small" style="margin-top:8px">Only the first part is shown. Download the file for all of it.</div>' : '') + '<div class="muted small" style="margin-top:8px">A simple view of the text and tables. Pictures and formatting are left out.</div>'); }
      else if (ext === 'csv' || type === 'text/csv') { const t = await blob.text(); set('<div class="pv-doc">' + csvTable(t.slice(0, 400000)) + '</div>'); }
      else if (/^text\//.test(type) || /(^|\/)(json|xml)$|\+xml$/.test(type) || ['txt', 'md', 'log', 'json'].includes(ext)) { const t = await blob.text(); set('<pre class="pv-text">' + esc(t.slice(0, 200000)) + (t.length > 200000 ? '\n… (shortened)' : '') + '</pre>'); }
      else none();
    } catch (e) { set('<div class="empty">' + esc(e.message || 'The file could not be previewed') + '</div>'); }
  };

  /* a Preview button beside file links, wherever they appear */
  const RX = /^(?:\.\/)?api\/(share\/file\/\d+|tasks\/\d+\/files\/\d+|chat\/files\/\d+|myfiles\/shared\/\d+\/file|finance\/rfqs\/\d+\/file\/\d+)(?:\?.*)?$/;
  let tm = 0;
  const scan = () => { document.querySelectorAll('a[href]').forEach(a => { if (a.dataset.pv || !RX.test(a.getAttribute('href') || '')) return; a.dataset.pv = '1'; const href = a.getAttribute('href').replace(/\?.*$/, ''), name = (a.textContent || '').replace(/^📎\s*/, '').replace(/\s+\d[\d.,]*\s*(KB|MB|B)\b.*$/i, '').trim() || 'file';
    const b = document.createElement('button'); b.type = 'button'; b.className = 'pv-btn'; b.textContent = '👁 Preview'; b.title = 'Look at it here instead of downloading'; b.onclick = e => { e.preventDefault(); e.stopPropagation(); X.preview(href, name); }; a.insertAdjacentElement('afterend', b); }); };
  new MutationObserver(() => { clearTimeout(tm); tm = setTimeout(scan, 120); }).observe(document.body, { childList: true, subtree: true }); scan();
})();
