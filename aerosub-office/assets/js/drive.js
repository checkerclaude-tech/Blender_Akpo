/* Aerosub Office: Google Drive folders in My Documents. Paste the link of a folder shared as "Anyone with the link" and it shows up as a folder here.
 * Open it to browse its sub-folders and files; download a file, email it, or share it with colleagues. No Google account sign-in or API settings. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._gdLoaded) return; X._gdLoaded = 1;
  const { api, modal, toast, esc } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const ICON = { folder: '📁', doc: '📝', sheet: '📊', slide: '📽️', file: '📄' };
  const icon = i => i.kind !== 'file' ? ICON[i.kind] : /\.(png|jpe?g|gif|webp|svg)$/i.test(i.name) ? '🖼️' : /\.pdf$/i.test(i.name) ? '📕' : /\.(zip|rar|7z)$/i.test(i.name) ? '🗜️' : /\.(mp4|mov|avi)$/i.test(i.name) ? '🎞️' : /\.(docx?|rtf|odt)$/i.test(i.name) ? '📝' : /\.(xlsx?|csv)$/i.test(i.name) ? '📊' : /\.pptx?$/i.test(i.name) ? '📽️' : ICON.file;
  const kindLabel = i => ({ folder: 'Folder', doc: 'Google Doc (downloads as Word)', sheet: 'Google Sheet (downloads as Excel)', slide: 'Google Slides (downloads as PowerPoint)', file: 'File' })[i.kind];

  const prev = X.myShareInit;
  X.myShareInit = async (v, items) => {
    if (prev) prev(v, items); const bar = $('.my-bar', v); if (!bar) return;
    if (!$('#gd-add', v)) { const row = $('.row', bar), b = document.createElement('button'); b.className = 'btn ghost'; b.id = 'gd-add'; b.type = 'button'; b.innerHTML = '🟢 Google Drive folder'; b.title = 'Show a Google Drive folder here'; const last = $('#mylink', row); last ? last.after(b) : row.appendChild(b); b.onclick = () => connect(() => X.render()); }
    let drives = []; try { drives = (await api('myfiles/drive')).drives; } catch { return; } const old = $('#my-drives', v); if (old) old.remove(); if (!drives.length) return;
    const sec = document.createElement('div'); sec.id = 'my-drives'; sec.style.marginBottom = '12px';
    sec.innerHTML = '<div class="my-grid">' + drives.map(d => '<div class="card my-item" data-gd="' + d.id + '"><div class="my-thumb"><span class="my-ph" aria-hidden="true" style="font-size:38px">📁</span></div><div class="my-meta"><b class="my-t">' + esc(d.name) + '</b><div class="muted small">Google Drive folder</div></div><div class="row" style="margin-top:8px;gap:6px"><button class="btn sm" data-gdo="' + d.id + '">Open</button><button class="btn ghost sm" data-gdx="' + d.id + '" title="Remove this folder from My Documents (nothing is deleted in Drive)">Disconnect</button></div></div>').join('') + '</div>';
    const grid = $('.my-grid', v); const host = grid ? grid.parentNode : v; host.insertBefore(sec, grid || null); const by = Object.fromEntries(drives.map(d => [d.id, d]));
    $$('[data-gdo]', sec).forEach(b => b.onclick = () => browse(by[b.dataset.gdo])); $$('[data-gd] .my-thumb', sec).forEach(t => t.onclick = () => browse(by[t.closest('[data-gd]').dataset.gd]));
    $$('[data-gdx]', sec).forEach(b => b.onclick = async () => { if (!confirm('Disconnect “' + by[b.dataset.gdx].name + '”? Nothing is deleted in Google Drive.')) return; try { await api('myfiles/drive/' + b.dataset.gdx, { method: 'DELETE' }); X.render(); } catch (e) { toast(e.message); } });
  };

  function connect(done) {
    const m = modal('<div class="row"><h2 class="grow">Add a Google Drive folder</h2><button class="btn ghost sm" id="cx">✕</button></div>' +
      '<ol class="small" style="margin:8px 0 10px 18px;line-height:1.6"><li>In Google Drive, right-click the folder → <b>Share</b>.</li><li>Under <b>General access</b> choose <b>Anyone with the link</b> (Viewer).</li><li>Press <b>Copy link</b> and paste it below.</li></ol>' +
      '<div class="field"><label>Folder link</label><input id="gd-u" placeholder="https://drive.google.com/drive/folders/…" autocomplete="off"></div><div class="field"><label>Name in My Documents (optional)</label><input id="gd-n" maxlength="160" placeholder="e.g. Project files"></div>' +
      '<div class="muted small" style="margin-bottom:8px">You do not need to sign in to Google or set anything up. Anyone with the link can see that folder in Drive, so keep private material out of it. Aerosub Office only reads the folder and never changes it.</div><div class="err" id="er" role="alert"></div><div class="row"><button class="btn" id="go">Connect</button></div>');
    $('#cx', m).onclick = () => m.close(); $('#gd-u', m).focus();
    $('#go', m).onclick = async () => { $('#er', m).textContent = ''; const url = $('#gd-u', m).value.trim(); if (!url) { $('#er', m).textContent = 'Paste the folder link'; return; } $('#go', m).disabled = true; $('#go', m).textContent = 'Checking the folder…';
      try { const r = await api('myfiles/drive', { method: 'POST', data: { url, name: $('#gd-n', m).value.trim() } }); m.close(); toast('Connected · ' + r.items + ' item' + (r.items === 1 ? '' : 's') + ' in the folder'); done(); } catch (e) { $('#er', m).textContent = e.message; $('#go', m).disabled = false; $('#go', m).textContent = 'Connect'; } };
  }

  /** The folder browser. `trail` is the path of folders opened so far. */
  async function browse(drive) {
    const m = modal('<div class="row"><h2 class="grow">🟢 ' + esc(drive.name) + '</h2><button class="btn ghost sm" id="cx">Close</button></div><div id="gd-trail" class="small" style="margin:6px 0"></div><input id="gd-q" placeholder="Filter this folder" style="margin-bottom:8px;max-width:300px"><div id="gd-body"><div class="empty">Loading…</div></div>'); m.firstElementChild.classList.add('fl-wide'); $('#cx', m).onclick = () => m.close();
    const trail = [{ id: null, name: drive.name }]; let items = [];
    const load = async () => { const cur = trail[trail.length - 1]; $('#gd-body', m).innerHTML = '<div class="empty">Loading…</div>'; $('#gd-q', m).value = '';
      try { const r = await api('myfiles/drive/' + drive.id + '/list' + (cur.id ? '?folder=' + encodeURIComponent(cur.id) : '')); items = r.items; paint(); } catch (e) { $('#gd-body', m).innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; } paintTrail(); };
    const paintTrail = () => { $('#gd-trail', m).innerHTML = trail.map((t, i) => i === trail.length - 1 ? '<b>' + esc(t.name) + '</b>' : '<a href="#" data-t="' + i + '">' + esc(t.name) + '</a>').join(' › '); $$('[data-t]', m).forEach(a => a.onclick = e => { e.preventDefault(); trail.length = +a.dataset.t + 1; load(); }); };
    const paint = () => { const q = $('#gd-q', m).value.trim().toLowerCase(); const list = items.filter(i => !q || i.name.toLowerCase().includes(q));
      $('#gd-body', m).innerHTML = list.length ? '<div class="list">' + list.map(i => '<div class="item" data-i="' + esc(i.id) + '"><span style="font-size:22px" aria-hidden="true">' + icon(i) + '</span><div class="grow" style="min-width:0"><b style="word-break:break-word">' + esc(i.name) + '</b><div class="muted small">' + kindLabel(i) + (i.modified ? ' · ' + esc(i.modified) : '') + '</div></div>' +
        (i.kind === 'folder' ? '<button class="btn sm" data-o="' + esc(i.id) + '">Open</button>' : '<a class="btn sm" data-dl="' + esc(i.id) + '" href="#">Download</a><button class="btn ghost sm" data-ml="' + esc(i.id) + '">✉ Mail</button>') + '<button class="btn ghost sm" data-sh="' + esc(i.id) + '">Share</button></div>').join('') + '</div>' : '<div class="empty">' + (items.length ? 'Nothing matches.' : 'This folder is empty.') + '</div>';
      const by = Object.fromEntries(items.map(i => [i.id, i]));
      $$('[data-o]', m).forEach(b => b.onclick = () => { trail.push({ id: b.dataset.o, name: by[b.dataset.o].name }); load(); });
      $$('[data-dl]', m).forEach(a => a.onclick = e => { e.preventDefault(); const i = by[a.dataset.dl]; location.href = 'api/myfiles/drive/' + drive.id + '/file?file=' + encodeURIComponent(i.id) + '&kind=' + i.kind + '&name=' + encodeURIComponent(i.name); toast('Downloading “' + i.name + '”…'); });
      $$('[data-ml]', m).forEach(b => b.onclick = () => mail(drive, by[b.dataset.ml]));
      $$('[data-sh]', m).forEach(b => b.onclick = async () => { const i = by[b.dataset.sh]; try { const r = await api('myfiles/drive/' + drive.id + '/link', { method: 'POST', data: { file: i.id, kind: i.kind, name: i.name } }); X.shareVault({ id: r.id, title: i.name }); } catch (e) { toast(e.message); } });
    };
    $('#gd-q', m).oninput = paint; load();
  }
  async function mail(drive, i) {
    let mailInfo = { mine: null }; try { mailInfo = (await api('myfiles/people')).mail; } catch {} const mp = X.shMailPane(mailInfo, i.name);
    const m = modal('<div class="row"><h2 class="grow">Email “' + esc(i.name) + '”</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">The file is fetched from Google Drive and attached (up to 10 MB). ' + (i.kind === 'doc' ? 'It goes as a Word file.' : i.kind === 'sheet' ? 'It goes as an Excel file.' : i.kind === 'slide' ? 'It goes as a PowerPoint file.' : '') + '</div>' + mp.html + '<div class="err" id="er" role="alert"></div><div class="row" style="margin-top:12px"><button class="btn" id="go">Send</button><span class="muted small" id="shn"></span></div>');
    $('#cx', m).onclick = () => m.close();
    $('#go', m).onclick = async () => { const g = mp.get(m); if (!g.to) { $('#er', m).textContent = 'Type the email address to send it to'; return; } $('#go', m).disabled = true; $('#shn', m).textContent = 'Fetching and sending…';
      try { const r = await api('myfiles/drive/' + drive.id + '/email', { method: 'POST', data: Object.assign({ file: i.id, kind: i.kind, name: i.name }, g) }); m.close(); toast((r.done || ['Sent']).join(' · ')); } catch (e) { $('#er', m).textContent = e.message; $('#go', m).disabled = false; $('#shn', m).textContent = ''; } };
  }
})();
