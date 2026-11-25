/* Aerosub Office: Letters (Word documents on letterheads).
 * "+ New document" opens a menu of every document type, Word letters included. A letter is written here, kept in Finance > Letters, downloaded as an editable .docx,
 * printed as PDF with the letterhead and its top / bottom padding on every page, and shared by email or onto a task. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._ltLoaded) return; X._ltLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const MM = 96 / 25.4;
  const when = t => t ? new Date(String(t).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  const canUse = () => !!(S.user && S.user.finance);

  /* ---------------- the New document menu ---------------- */
  const TYPES = [['commercial', '▤', 'Commercial', 'Quotation with categories and subtotals'], ['proforma', '▥', 'Proforma invoice', 'Before the work or delivery'], ['invoice', '▦', 'Invoice', 'Request for payment'], ['requisition', '▧', 'Requisition', 'Internal purchase request'], ['letter', 'W', 'Letter (Word)', 'Editable Word document on a letterhead']];
  function menu(btn) {
    $$('.nd-menu').forEach(e => e.remove());
    const m = document.createElement('div'); m.className = 'nd-menu'; m.setAttribute('role', 'menu');
    m.innerHTML = TYPES.map(([k, ic, l, s]) => '<button type="button" role="menuitem" data-nd="' + k + '"><span class="ic">' + ic + '</span><span><b>' + l + '</b><span class="sub">' + s + '</span></span></button>').join('') + '<hr><button type="button" role="menuitem" data-nd="letters"><span class="ic">☰</span><span><b>All letters</b><span class="sub">Open, download or share a letter</span></span></button>';
    const r = btn.getBoundingClientRect(); document.body.appendChild(m); m.style.top = (window.scrollY + r.bottom + 6) + 'px'; m.style.left = Math.max(8, window.scrollX + r.right - m.offsetWidth) + 'px';
    const close = () => { m.remove(); document.removeEventListener('mousedown', away, true); document.removeEventListener('keydown', key, true); };
    const away = e => { if (!m.contains(e.target) && e.target !== btn) close(); }, key = e => { if (e.key === 'Escape') { close(); btn.focus(); } };
    document.addEventListener('mousedown', away, true); document.addEventListener('keydown', key, true);
    $$('[data-nd]', m).forEach(b => b.onclick = () => { const k = b.dataset.nd; close();
      if (k === 'letter') location.hash = '#/finance/letters/new'; else if (k === 'letters') location.hash = '#/finance/letters'; else if (window.OfficeFin) window.OfficeFin.form(null, { doc_type: k }); });
    const f = $('button', m); if (f) f.focus();
  }
  let busy = false;
  new MutationObserver(() => { if (busy) return; busy = true; setTimeout(() => { try {
    const b = $('#fnew'); if (b && !b.dataset.ltm && canUse()) { b.dataset.ltm = '1'; b.setAttribute('aria-haspopup', 'menu'); b.textContent = '+ New document ▾'; b.onclick = e => { e.preventDefault(); menu(b); }; }
    const st = $('#fset'); if (st && !$('#flet') && canUse()) { const l = document.createElement('button'); l.className = 'btn ghost'; l.id = 'flet'; l.textContent = 'Letters'; l.onclick = () => { location.hash = '#/finance/letters'; }; st.before(l); }
  } finally { busy = false; } }, 60); }).observe(document.body, { childList: true, subtree: true });

  /* ---------------- routing: #/finance/letters and #/finance/letters/{id|new} ---------------- */
  const origFinance = X.views.finance;
  X.views.finance = async (...a) => { const p = location.hash.split('/'); if (p[2] === 'letters' && canUse()) return p[3] ? editor(p[3]) : listPage(); return origFinance(...a); };

  /* ---------------- the list ---------------- */
  async function listPage() {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let q = '';
    const draw = async () => {
      let D; try { D = await api('finance/letters' + (q ? '?q=' + encodeURIComponent(q) : '')); } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
      $('#lt-rows').innerHTML = D.letters.length ? D.letters.map(l => '<div class="lt-row" data-l="' + l.id + '" tabindex="0" role="link"><div class="grow"><b>' + esc(l.title) + '</b><div class="muted small">' + esc(l.preview || 'Empty letter') + '</div></div><div class="small muted" style="text-align:right">' + esc(l.letterhead || '') + '<br>' + esc(when(l.updated_at)) + (l.created_by ? ' · ' + esc(l.created_by) : '') + '</div>' +
        '<a class="btn ghost sm" href="api/finance/letters/' + l.id + '/docx" data-stop="1" title="Download the editable Word file">Word ⬇</a><button class="btn ghost sm" data-share="' + l.id + '">Share</button></div>').join('') : '<div class="empty">No letters yet. Press “New letter” to write the first one.</div>';
      const by = Object.fromEntries(D.letters.map(l => [l.id, l]));
      $$('[data-l]', v).forEach(r => { const go = () => { location.hash = '#/finance/letters/' + r.dataset.l; }; r.onclick = e => { if (e.target.closest('[data-stop],[data-share]')) return; go(); }; r.onkeydown = e => { if (e.key === 'Enter') go(); }; });
      $$('[data-share]', v).forEach(b => b.onclick = () => X.shareLetter(by[b.dataset.share]));
    };
    v.innerHTML = '<div class="top"><div><h1>Letters</h1><div class="muted">Word documents on the Aerosub letterheads. Editable in Word, printable as PDF, shareable by email or on a task.</div></div><span class="right row"><button class="btn ghost" id="lt-back">← Finance</button><button class="btn ghost" id="lt-heads">Letterheads</button><button class="btn" id="lt-new">+ New letter</button></span></div>' +
      '<div class="row" style="margin-bottom:10px"><input id="lt-q" placeholder="Search letters" style="max-width:320px" aria-label="Search letters"></div><div class="card lt-list" id="lt-rows" style="padding:0"></div>';
    $('#lt-back').onclick = () => { location.hash = '#/finance'; }; $('#lt-new').onclick = () => { location.hash = '#/finance/letters/new'; }; $('#lt-heads').onclick = () => manager();
    let tm; $('#lt-q').oninput = e => { q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(draw, 300); }; draw();
  }

  /* ---------------- the editor ---------------- */
  const STARTER = () => '<p>' + new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) + '</p><p><br></p><p>The Managing Director<br>Company name<br>Address</p><p><br></p><p><b>Subject: Add the subject here</b></p><p>Dear Sir/Madam,</p><p>Start writing your letter here.</p><p><br></p><p>Yours faithfully,</p><p><br><br></p><p><b>Your name</b><br>Position<br>Aerosub</p>';
  async function editor(idOrNew) {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let heads, L = null;
    try { heads = (await api('finance/letterheads')).letterheads; if (idOrNew !== 'new') L = (await api('finance/letters/' + idOrNew)).letter; } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + ' <a href="#/finance/letters">Back to letters</a></div>'; return; }
    let id = L ? L.id : 0, lhId = L ? (L.letterhead_id || (heads[0] || {}).id) : ((heads.find(h => h.is_default) || heads[0] || {}).id), dirty = false, saving = false, timer = null;
    const head = () => heads.find(h => h.id === +lhId) || heads[0] || { pad_top: 25, pad_bottom: 25, pad_left: 22, pad_right: 22, art: null };
    v.innerHTML = '<div class="lt-top"><button class="btn ghost sm" id="lt-back">← Letters</button><input class="lt-title" id="lt-title" maxlength="200" placeholder="Letter title" aria-label="Letter title" value="' + esc(L ? L.title : 'Untitled letter') + '"><label class="small muted" for="lt-head">Letterhead</label><select id="lt-head">' + heads.map(h => '<option value="' + h.id + '">' + esc(h.name) + '</option>').join('') + '</select>' +
      '<button class="btn" id="lt-save">Save</button><a class="btn ghost" id="lt-word" href="#" title="Download the editable Word file">Word ⬇</a><button class="btn ghost" id="lt-print">Print / PDF</button><button class="btn ghost" id="lt-share">Share</button>' + (L ? '<button class="btn ghost" id="lt-del">Delete</button>' : '') + '<span class="small muted" id="lt-state"></span></div>' +
      '<div class="lt-tb" id="lt-tb" role="toolbar" aria-label="Formatting">' +
      '<select id="lt-font" aria-label="Font"><option value="">Font</option><option>Calibri</option><option>Arial</option><option>Times New Roman</option><option>Georgia</option><option>Verdana</option><option>Courier New</option></select>' +
      '<select id="lt-size" aria-label="Size"><option value="">Size</option>' + [9, 10, 11, 12, 14, 16, 18, 20, 24, 28].map(n => '<option>' + n + '</option>').join('') + '</select><span class="sep"></span>' +
      [['bold', '<b>B</b>', 'Bold'], ['italic', '<i>I</i>', 'Italic'], ['underline', '<u>U</u>', 'Underline'], ['strikeThrough', '<s>S</s>', 'Strikethrough']].map(([c, h, t]) => '<button type="button" data-c="' + c + '" title="' + t + '" aria-label="' + t + '">' + h + '</button>').join('') +
      '<input type="color" id="lt-color" value="#000000" title="Text colour" aria-label="Text colour"><span class="sep"></span>' +
      [['justifyLeft', '⇤', 'Align left'], ['justifyCenter', '↔', 'Centre'], ['justifyRight', '⇥', 'Align right'], ['justifyFull', '☰', 'Justify'], ['insertUnorderedList', '• List', 'Bullets'], ['insertOrderedList', '1. List', 'Numbers'], ['outdent', '⇠', 'Less indent'], ['indent', '⇢', 'More indent']].map(([c, h, t]) => '<button type="button" data-c="' + c + '" title="' + t + '" aria-label="' + t + '">' + h + '</button>').join('') + '<span class="sep"></span>' +
      '<button type="button" id="lt-h2" title="Heading">Heading</button><button type="button" id="lt-link" title="Add a link">Link</button><button type="button" id="lt-table" title="Insert a table">Table</button><button type="button" id="lt-hr" title="Line">―</button><button type="button" data-c="removeFormat" title="Clear formatting">Clear</button><span class="sep"></span><button type="button" data-c="undo" title="Undo">↶</button><button type="button" data-c="redo" title="Redo">↷</button></div>' +
      '<div class="lt-stage"><div class="lt-sheet" id="lt-sheet"><div class="lt-body" id="lt-ed" contenteditable="true" spellcheck="true" role="textbox" aria-multiline="true" aria-label="Letter text"></div></div></div>' +
      '<div class="lt-note">The letterhead and its padding are shown as they print. Where the text reaches the bottom padding it moves to the next page. Word and PDF paginate exactly.</div>';
    const ed = $('#lt-ed'), sheet = $('#lt-sheet'), state = $('#lt-state'); $('#lt-head').value = lhId;
    ed.innerHTML = L ? L.body || '<p><br></p>' : STARTER();
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, true); } catch {}
    const html = () => { const c = ed.cloneNode(true); $$('[data-pg]', c).forEach(e => { e.removeAttribute('data-pg'); e.style.marginTop = ''; if (!e.getAttribute('style')) e.removeAttribute('style'); }); return c.innerHTML; };
    const frame = () => { const h = head(); sheet.style.backgroundImage = h.art ? 'url("' + h.art + '")' : 'none'; sheet.style.padding = h.pad_top + 'mm ' + h.pad_right + 'mm ' + h.pad_bottom + 'mm ' + h.pad_left + 'mm'; ed.style.minHeight = (297 - h.pad_top - h.pad_bottom) + 'mm'; paginate(); };
    function paginate() {   // keeps each block of writing inside the page's text area, so it never lands on the artwork
      const h = head(), H = (297 - h.pad_top - h.pad_bottom) * MM, G = (h.pad_top + h.pad_bottom) * MM, P = H + G; if (!(H > 40)) return;
      $$('[data-pg]', ed).forEach(e => { e.removeAttribute('data-pg'); e.style.marginTop = ''; });
      for (const k of [...ed.children]) { const t = k.offsetTop, b = t + k.offsetHeight, ps = Math.floor(t / P) * P; if (b > ps + H + 1 && k.offsetHeight <= H && t > ps + 1) { k.style.marginTop = Math.ceil(ps + P - t) + 'px'; k.setAttribute('data-pg', '1'); } else if (t >= ps + H && t < ps + P) { k.style.marginTop = Math.ceil(ps + P - t) + 'px'; k.setAttribute('data-pg', '1'); } }
      const pages = Math.max(1, Math.ceil((ed.scrollHeight + 1) / P)); sheet.style.minHeight = (pages * 297) + 'mm'; $$('.lt-pgmark', sheet).forEach(e => e.remove());
    }
    frame();
    const mark = () => { dirty = true; state.textContent = 'Unsaved'; clearTimeout(timer); if (id) timer = setTimeout(() => save(true), 4000); paginate(); };
    ed.addEventListener('input', mark); $('#lt-title').addEventListener('input', () => { dirty = true; state.textContent = 'Unsaved'; });
    $('#lt-head').onchange = e => { lhId = +e.target.value; frame(); dirty = true; state.textContent = 'Unsaved'; if (id) { clearTimeout(timer); timer = setTimeout(() => save(true), 1500); } };
    async function save(quiet) {
      if (saving) return true; saving = true; state.textContent = 'Saving…';
      try { const data = { title: $('#lt-title').value.trim() || 'Untitled letter', letterhead_id: +lhId, body: html() };
        if (id) await api('finance/letters/' + id, { method: 'PATCH', data }); else { const r = await api('finance/letters', { method: 'POST', data }); id = r.id; history.replaceState(null, '', '#/finance/letters/' + id); }
        dirty = false; state.textContent = 'Saved ✓'; if (!quiet) toast('Letter saved'); return true; }
      catch (e) { state.textContent = 'Not saved'; toast(e.message); return false; } finally { saving = false; }
    }
    $('#lt-save').onclick = () => save(false);
    $('#lt-back').onclick = async () => { if (dirty && !(await save(true))) return; location.hash = '#/finance/letters'; };
    const word = $('#lt-word'); word.onclick = async e => { e.preventDefault(); if (dirty || !id) { if (!(await save(true))) return; } location.href = 'api/finance/letters/' + id + '/docx'; };
    $('#lt-share').onclick = async () => { if (dirty || !id) { if (!(await save(true))) return; } X.shareLetter({ id, title: $('#lt-title').value.trim() || 'Untitled letter' }); };
    $('#lt-print').onclick = () => print(head(), html(), $('#lt-title').value.trim() || 'Letter');
    if ($('#lt-del')) $('#lt-del').onclick = async () => { if (!confirm('Delete this letter? This cannot be undone.')) return; try { await api('finance/letters/' + id, { method: 'DELETE' }); dirty = false; location.hash = '#/finance/letters'; } catch (e) { toast(e.message); } };
    // formatting
    const tb = $('#lt-tb'); tb.addEventListener('mousedown', e => { if (!e.target.closest('select,input')) e.preventDefault(); });
    const cmd = (c, val) => { ed.focus(); try { document.execCommand(c, false, val); } catch {} mark(); };
    $$('[data-c]', tb).forEach(b => b.onclick = () => cmd(b.dataset.c));
    $('#lt-font').onchange = e => { if (e.target.value) { cmd('fontName', e.target.value); } e.target.value = ''; };
    $('#lt-size').onchange = e => { const pt = e.target.value; e.target.value = ''; if (!pt) return; ed.focus(); try { document.execCommand('styleWithCSS', false, false); document.execCommand('fontSize', false, '7'); document.execCommand('styleWithCSS', false, true); } catch {}
      $$('font[size="7"]', ed).forEach(f => { const s = document.createElement('span'); s.style.fontSize = pt + 'pt'; while (f.firstChild) s.appendChild(f.firstChild); f.replaceWith(s); }); mark(); };
    $('#lt-color').oninput = e => cmd('foreColor', e.target.value);
    $('#lt-h2').onclick = () => cmd('formatBlock', 'h2');
    $('#lt-hr').onclick = () => cmd('insertHorizontalRule');
    $('#lt-link').onclick = () => { const u = prompt('Link address (https://…)'); if (u && /^(https?:\/\/|mailto:)\S+$/i.test(u.trim())) cmd('createLink', u.trim()); else if (u) toast('The link must start with https://'); };
    $('#lt-table').onclick = () => { const r = Math.min(20, Math.max(1, parseInt(prompt('How many rows?', '3'), 10) || 0)), c = Math.min(8, Math.max(1, parseInt(prompt('How many columns?', '3'), 10) || 0)); if (!r || !c) return; cmd('insertHTML', '<table>' + ('<tr>' + '<td><br></td>'.repeat(c) + '</tr>').repeat(r) + '</table><p><br></p>'); };
    ed.addEventListener('paste', e => { e.preventDefault(); const t = (e.clipboardData || window.clipboardData).getData('text/html'); const p = (e.clipboardData || window.clipboardData).getData('text/plain');
      if (t) { const d = new DOMParser().parseFromString(t, 'text/html'); $$('script,style,img,iframe,object,meta,link', d).forEach(x => x.remove()); $$('*', d.body).forEach(x => { [...x.attributes].forEach(a => { if (!['style', 'href', 'colspan'].includes(a.name)) x.removeAttribute(a.name); }); }); document.execCommand('insertHTML', false, d.body.innerHTML); } else document.execCommand('insertText', false, p); });
    const leave = e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } }; window.addEventListener('beforeunload', leave);
    const unhook = () => { window.removeEventListener('beforeunload', leave); window.removeEventListener('hashchange', unhook); clearTimeout(timer); if (dirty && id) save(true); }; window.addEventListener('hashchange', unhook);
    document.fonts && document.fonts.ready.then(paginate);
  }

  /* ---------------- print / save as PDF ---------------- */
  function print(h, bodyHtml, title) {
    const old = $('#printroot'); if (old) old.remove();
    const root = document.createElement('div'); root.id = 'printroot'; root.className = 'lt-printroot';
    root.innerHTML = (h.art ? '<img class="lt-bgimg" alt="" src="' + esc(h.art) + '">' : '') + '<table class="lt-pt"><thead><tr><td><div style="height:' + h.pad_top + 'mm"></div></td></tr></thead><tfoot><tr><td><div style="height:' + h.pad_bottom + 'mm"></div></td></tr></tfoot><tbody><tr><td><div class="lt-body" style="padding:0 ' + h.pad_right + 'mm 0 ' + h.pad_left + 'mm">' + bodyHtml + '</div></td></tr></tbody></table>';
    document.body.appendChild(root); const prevTitle = document.title; document.title = title;
    const done = () => { root.remove(); document.documentElement.classList.remove('printing', 'ltprint'); document.title = prevTitle; window.removeEventListener('afterprint', done); };
    window.addEventListener('afterprint', done); document.documentElement.classList.add('printing', 'ltprint');
    const img = $('img', root), go = () => setTimeout(() => window.print(), 120); if (img && !img.complete) { img.onload = go; img.onerror = go; } else go();
  }

  /* ---------------- letterheads ---------------- */
  async function manager() {
    const m = modal('<div class="row"><h2 class="grow">Letterheads</h2><button class="btn ghost sm" id="mx">✕</button></div><div class="muted small" style="margin:4px 0 10px">A letterhead is the full-page picture (logo and address at the top, contacts at the bottom) plus the padding that keeps the writing clear of it. Letters can use any of them.</div><div id="lh-list"><div class="empty">Loading…</div></div><div class="row" style="margin-top:10px"><button class="btn" id="lh-add">+ Add a letterhead</button></div>');
    $('#mx', m).onclick = () => m.close();
    const load = async () => { let D; try { D = await api('finance/letterheads'); } catch (e) { $('#lh-list', m).innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; return; }
      $('#lh-list', m).innerHTML = D.letterheads.map(h => '<div class="lt-lh">' + (h.art ? '<img src="' + esc(h.art) + '" alt="">' : '<span class="nopic"></span>') + '<div class="grow"><b>' + esc(h.name) + '</b>' + (h.is_default ? ' <span class="tag done">Default</span>' : '') + '<div class="muted small">Padding: top ' + h.pad_top + ' mm · bottom ' + h.pad_bottom + ' mm · sides ' + h.pad_left + '/' + h.pad_right + ' mm</div></div><button class="btn ghost sm" data-e="' + h.id + '">Edit</button><button class="btn ghost sm" data-d="' + h.id + '">Delete</button></div>').join('');
      const by = Object.fromEntries(D.letterheads.map(h => [h.id, h])); $$('[data-e]', m).forEach(b => b.onclick = () => form(by[b.dataset.e])); $$('[data-d]', m).forEach(b => b.onclick = async () => { if (!confirm('Delete the letterhead “' + by[b.dataset.d].name + '”?')) return; try { await api('finance/letterheads/' + b.dataset.d, { method: 'DELETE' }); load(); } catch (e) { toast(e.message); } }); };
    $('#lh-add', m).onclick = () => form(null); load();
    function form(h) {
      let pic = null; const x = h || { name: '', pad_top: 52, pad_bottom: 44, pad_left: 22, pad_right: 22, is_default: false, art: null };
      const f = modal('<div class="row"><h2 class="grow">' + (h ? 'Edit letterhead' : 'New letterhead') + '</h2><button class="btn ghost sm" id="fx">✕</button></div><div class="row" style="align-items:flex-start;gap:16px;flex-wrap:wrap;margin-top:10px"><div class="grow" style="min-width:240px">' +
        '<div class="field"><label>Name</label><input id="lh-name" maxlength="120" value="' + esc(x.name) + '" placeholder="e.g. Aerosub Nigeria, Aerosub Projects"></div>' +
        '<div class="field"><label>Letterhead picture (PNG or JPG, the whole A4 page)</label><input type="file" id="lh-file" accept="image/png,image/jpeg"></div>' +
        '<div class="grid2"><div class="field"><label>Top padding (mm)</label><input id="lh-t" type="number" min="5" max="120" step="0.5" value="' + x.pad_top + '"></div><div class="field"><label>Bottom padding (mm)</label><input id="lh-b" type="number" min="5" max="120" step="0.5" value="' + x.pad_bottom + '"></div></div>' +
        '<div class="grid2"><div class="field"><label>Left padding (mm)</label><input id="lh-l" type="number" min="5" max="120" step="0.5" value="' + x.pad_left + '"></div><div class="field"><label>Right padding (mm)</label><input id="lh-r" type="number" min="5" max="120" step="0.5" value="' + x.pad_right + '"></div></div>' +
        '<label class="sh-radio"><input type="checkbox" id="lh-def"' + (x.is_default ? ' checked' : '') + '> Use for new letters</label></div>' +
        '<div><div class="lt-prev" id="lh-prev"><div class="lt-safe" id="lh-safe"></div></div><div class="muted small" style="max-width:210px;margin-top:4px">The dashed box is where the writing goes. Move the padding until it clears the artwork.</div></div></div><div class="err" id="er" role="alert"></div><div class="row" style="margin-top:12px"><button class="btn" id="lh-save">Save</button></div>');
      const num = id => Math.max(5, Math.min(120, parseFloat($(id, f).value) || 5));
      const prev = () => { const t = num('#lh-t'), b = num('#lh-b'), l = num('#lh-l'), r = num('#lh-r'), s = $('#lh-safe', f).style; s.left = l / 210 * 100 + '%'; s.right = r / 210 * 100 + '%'; s.top = t / 297 * 100 + '%'; s.bottom = b / 297 * 100 + '%'; };
      const art = u => { $('#lh-prev', f).style.backgroundImage = u ? 'url("' + u + '")' : 'none'; }; art(x.art); prev();
      ['#lh-t', '#lh-b', '#lh-l', '#lh-r'].forEach(i => $(i, f).oninput = prev); $('#fx', f).onclick = () => f.close();
      $('#lh-file', f).onchange = async e => { const fl = e.target.files[0]; if (!fl) return; if (!/^image\/(png|jpeg)$/.test(fl.type)) { $('#er', f).textContent = 'Use a PNG or JPG picture'; e.target.value = ''; return; } try { pic = await X.fileObj(fl); art('data:' + fl.type + ';base64,' + pic.data); $('#er', f).textContent = ''; } catch (er) { $('#er', f).textContent = er.message; } };
      $('#lh-save', f).onclick = async () => { const name = $('#lh-name', f).value.trim(); if (!name) { $('#er', f).textContent = 'Give the letterhead a name'; return; }
        const data = { name, pad_top: num('#lh-t'), pad_bottom: num('#lh-b'), pad_left: num('#lh-l'), pad_right: num('#lh-r'), is_default: $('#lh-def', f).checked }; if (pic) { data.art_data = pic.data; data.art_type = pic.type; }
        try { await api('finance/letterheads' + (h ? '/' + h.id : ''), { method: h ? 'PATCH' : 'POST', data }); f.close(); load(); toast('Letterhead saved'); } catch (er) { $('#er', f).textContent = er.message; } };
    }
  }
  X.letterheads = manager;
})();
