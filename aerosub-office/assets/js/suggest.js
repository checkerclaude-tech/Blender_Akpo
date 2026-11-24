/* Aerosub Office: as-you-type suggestions for finance documents, and files attached to a document.
 * X.suggest(input, kind, onPick)  kind = client | contact | product | quote. The suggestion fills the fields, which stay editable.
 * Also adds the "Documents attached" card on a finance document. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, toast, esc } = X;
  const fsize = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  const b64 = f => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = () => no(new Error('Could not read ' + f.name)); r.readAsDataURL(f); });

  X.suggest = (input, kind, onPick) => {
    if (!input || input._sg) return; input._sg = 1; input.setAttribute('autocomplete', 'off'); let box = null, items = [], sel = 0, tm = 0, seq = 0;
    const close = () => { clearTimeout(tm); seq++; if (box) { box.remove(); box = null; } items = []; };
    const draw = () => {
      if (!box) { box = document.createElement('div'); box.className = 'mn-menu sg-menu'; box.setAttribute('role', 'listbox'); box.addEventListener('mousedown', e => e.preventDefault()); document.body.appendChild(box); }
      const r = input.getBoundingClientRect(), below = innerHeight - r.bottom > 220; box.style.left = Math.max(8, Math.min(r.left, innerWidth - 8 - Math.min(380, innerWidth - 16))) + 'px'; box.style.width = Math.min(Math.max(r.width, 280), 380, innerWidth - 16) + 'px';
      if (below) { box.style.top = (r.bottom + 4) + 'px'; box.style.bottom = ''; } else { box.style.bottom = (innerHeight - r.top + 4) + 'px'; box.style.top = ''; }
      box.innerHTML = items.map((o, i) => '<div class="mn-o' + (i === sel ? ' on' : '') + '" role="option" data-i="' + i + '"><span class="grow"><b>' + esc(o.label || o.name) + '</b><span class="muted small"> ' + esc([o.code && 'Code ' + o.code, o.unit, o.price != null ? (o.currency || '') + ' ' + Number(o.price).toLocaleString() : '', o.email].filter(Boolean).join(' · ')) + '</span></span><span class="tag">' + esc(o.source) + '</span></div>').join('');
      box.querySelectorAll('.mn-o').forEach(n => n.onclick = () => pick(+n.dataset.i));
    };
    const pick = i => { const o = items[i]; close(); if (o) onPick(o); };
    const ask = async q => { const my = ++seq; try { const r = await api('finance/suggest?kind=' + kind + '&q=' + encodeURIComponent(q)); if (my !== seq) return; items = r.items || []; sel = 0; items.length ? draw() : close(); } catch { close(); } };
    if (kind === 'client') input.addEventListener('focus', () => { if (!input.value.trim()) ask(''); });   // an empty box offers the companies used most recently
    input.addEventListener('input', () => { clearTimeout(tm); const q = input.value.trim(); if (!q) return close(); tm = setTimeout(async () => { const my = ++seq; try { const r = await api('finance/suggest?kind=' + kind + '&q=' + encodeURIComponent(q)); if (my !== seq) return; items = r.items || []; sel = 0; items.length ? draw() : close(); } catch { close(); } }, 150); });
    input.addEventListener('keydown', e => { if (!box) return; if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); return close(); } if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; draw(); } else if (e.key === 'Enter' || e.key === 'Tab') { if (e.key === 'Enter') e.preventDefault(); pick(sel); } });
    input.addEventListener('blur', () => setTimeout(close, 160));
  };

  /* documents attached to a finance document (shown on the page, never printed) */
  const prev = X.finDocHook;
  X.finDocHook = (d, v, id) => {
    if (prev) prev(d, v, id); if (d.doc_type === 'receipt') return; const files = d.files || [], can = d.can_correspond;
    if (!files.length && !can) return;
    const card = document.createElement('div'); card.className = 'card noprint'; card.style.margin = '12px 0'; card.id = 'fdfiles';
    card.innerHTML = '<div class="row" style="flex-wrap:wrap;gap:8px"><h3 class="grow" style="margin:0">Attached documents <span class="muted small">(' + files.length + ')</span></h3>' + (can ? '<label class="btn ghost sm" style="cursor:pointer;margin:0">+ Add documents<input type="file" id="ffa" multiple hidden></label>' : '') + '</div>' +
      (files.length ? '<div class="list" style="margin-top:8px">' + files.map(f => '<div class="item" style="flex-wrap:wrap">' + (/^image\//.test(f.mime) ? '<img src="api/finance/doc/' + id + '/files/' + f.id + '?view=1" alt="" class="fd-th" data-view="' + f.id + '">' : '<span style="margin-right:8px">📎</span>') + '<span class="grow">' + esc(f.name) + ' <span class="muted small">' + fsize(f.size) + '</span></span>' + (/^(image\/(png|jpeg|gif|webp)|application\/pdf)$/.test(f.mime) ? '<button class="btn ghost sm" data-view="' + f.id + '">👁 View</button>' : '') + '<a class="btn ghost sm" href="api/finance/doc/' + id + '/files/' + f.id + '" download>⬇ Download</a>' + (can ? '<button class="btn ghost sm" data-fdel="' + f.id + '" aria-label="Remove ' + esc(f.name) + '">✕</button>' : '') + '</div>').join('') + '</div>' : '<div class="muted small" style="margin-top:6px">Nothing attached yet: vendor quotes, scans, supporting documents.</div>');
    const anchor = v.querySelector('.fin-sheet'); if (anchor) v.insertBefore(card, anchor); else v.appendChild(card);
    const add = card.querySelector('#ffa'); if (add) add.onchange = async e => { for (const f of e.target.files) { try { if (f.size > 8e6) throw new Error(f.name + ' is larger than 8 MB'); await api('finance/doc/' + id + '/files', { method: 'POST', data: { name: f.name, type: f.type, data: await b64(f) } }); } catch (er) { toast(er.message); } } X.render(); };
    card.querySelectorAll('[data-view]').forEach(el => el.onclick = () => { const f = files.find(x => x.id == el.dataset.view), u = 'api/finance/doc/' + id + '/files/' + f.id + '?view=1', pdf = f.mime === 'application/pdf';
      const m = X.modal('<div class="row"><h3 class="grow" style="margin:0;overflow-wrap:anywhere">' + esc(f.name) + '</h3><a class="btn ghost sm" href="' + u + '" target="_blank" rel="noopener">Open in a new tab</a><button class="btn ghost sm" id="fvx">Close</button></div><div style="margin-top:10px;text-align:center">' + (pdf ? '<iframe src="' + u + '" title="' + esc(f.name) + '" style="width:100%;height:72vh;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>' : '<img src="' + u + '" alt="' + esc(f.name) + '" style="max-width:100%;max-height:74vh;border-radius:8px">') + '</div>');
      m.querySelector('.modal').style.cssText += ';max-width:96vw;width:min(960px,96vw)'; m.querySelector('#fvx').onclick = () => m.close(); });
    card.querySelectorAll('[data-fdel]').forEach(b => b.onclick = async () => { if (!confirm('Remove this document?')) return; try { await api('finance/doc/' + id + '/files/' + b.dataset.fdel, { method: 'DELETE' }); X.render(); } catch (er) { toast(er.message); } });
  };
  X.finUploadFiles = async (docId, files) => { let n = 0; for (const f of files) { try { await api('finance/doc/' + docId + '/files', { method: 'POST', data: { name: f.name, type: f.type, data: f.data } }); n++; } catch (er) { toast(er.message); } } return n; };
  X.fileB64 = b64;
  if (!document.querySelector('script[src*="fincomments.js"]')) { const sc = document.createElement('script'); sc.src = 'assets/js/fincomments.js?v=1'; sc.defer = true; document.head.appendChild(sc); }   // the comments box on documents
})();
