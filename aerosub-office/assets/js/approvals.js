/* Aerosub Office: approvals and signatures for finance documents (commercials, proformas / quotations, invoices).
 * Review > Request signature > Sign (or Revert) along the chain of command. The server decides who may do what; this only shows it.
 * No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16M6 16c2-6 4-9 5-9s0 5 2 5 2-3 5-3"/></svg>';
  const TYPE = { commercial: 'Commercial', proforma: 'Proforma / quotation', invoice: 'Invoice', receipt: 'Receipt' };
  const ST = { in_review: ['In review', 'warn'], awaiting_sign: ['Waiting for signature', 'warn'], signed: ['Signed', 'done'], reverted: ['Reverted', 'blocked'], canceled: ['Withdrawn', ''] };
  const when = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const tag = s => '<span class="tag ' + (ST[s] ? ST[s][1] : '') + '">' + esc(ST[s] ? ST[s][0] : s) + '</span>';

  /* ---------- menu ---------- */
  const show = () => S.user && ((+S.user.approvals || 0) > 0 || S.user.finance || ['super_admin', 'founder', 'manager'].includes(S.user.role));
  const label = () => 'Approvals' + (+S.user.approvals ? ' (' + S.user.approvals + ')' : '');
  const prevNav = X.nav; X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); if (show()) { const at = items.findIndex(x => x[0] === 'finance'); items.splice(at < 0 ? items.length : at + 1, 0, ['approvals', label(), ICON]); } };
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (show()) items.push(['approvals', label(), null]); };

  /* ---------- signature pad: draw, type, or use the saved one ---------- */
  function signPad(opts) {   // resolves with { mode:'saved'|'new', image, save, comment } or null
    return new Promise(async resolve => {
      let saved = null; try { const r = await api('finance/signature'); if (r.has) saved = r.image; } catch {}
      const m = modal('<div class="row"><h2 class="grow">Sign ' + esc(opts.title || 'document') + '</h2><button class="btn ghost sm" id="sx">✕</button></div>' +
        '<div class="muted small" style="margin:4px 0 10px">By signing you confirm you have read it and approve it as shown. Your name, the date and a fingerprint of the document are recorded.</div>' +
        '<div class="ac-seg" role="tablist" style="margin-bottom:10px">' + (saved ? '<button type="button" class="ac-b sel" data-t="saved">My saved signature</button>' : '') + '<button type="button" class="ac-b' + (saved ? '' : ' sel') + '" data-t="draw">Draw</button><button type="button" class="ac-b" data-t="type">Type</button></div>' +
        '<div id="sp-saved"' + (saved ? '' : ' hidden') + '><div class="sg-box"><img src="' + (saved || '') + '" alt="Your saved signature" style="max-width:100%;max-height:140px"></div></div>' +
        '<div id="sp-draw" hidden><canvas id="sgc" width="900" height="260" class="sg-canvas" aria-label="Signature drawing area"></canvas><div class="row small"><span class="muted grow">Draw with your mouse, finger or pen.</span><button type="button" class="btn ghost sm" id="sgx">Clear</button></div></div>' +
        '<div id="sp-type" hidden><div class="field"><label>Type your name</label><input id="sgn" maxlength="60" value="' + esc(S.user.name) + '"></div><div class="sg-box"><canvas id="sgt" width="900" height="260" class="sg-canvas" style="cursor:default"></canvas></div><div class="row small" style="gap:6px;margin-top:6px"><span class="muted">Style:</span><select id="sgf" style="width:auto"><option value="Segoe Script,Brush Script MT,Snell Roundhand,cursive">Script</option><option value="Lucida Handwriting,Apple Chancery,cursive">Handwriting</option><option value="Georgia,serif">Classic</option></select></div></div>' +
        '<label class="small" id="sgsv" style="display:block;margin:10px 0 4px"' + (saved ? ' hidden' : '') + '><input type="checkbox" id="sgs" style="width:auto" checked> Save as my signature for next time</label>' +
        '<div class="field" style="margin-top:8px"><label>Comment (optional)</label><textarea id="sgm" rows="2" maxlength="1000"></textarea></div><div class="err" id="er"></div>' +
        '<div class="row"><button class="btn" id="sgo">Sign</button><button class="btn ghost" id="sgc2">Cancel</button></div>');
      let tab = saved ? 'saved' : 'draw'; const done = v => { m.close(); resolve(v); };
      $('#sx', m).onclick = $('#sgc2', m).onclick = () => done(null);
      const paint = () => { ['saved', 'draw', 'type'].forEach(k => { const e = $('#sp-' + k, m); if (e) e.hidden = k !== tab; }); $$('.ac-b', m).forEach(b => b.classList.toggle('sel', b.dataset.t === tab)); $('#sgsv', m).hidden = tab === 'saved'; if (tab === 'type') renderType(); };
      $$('.ac-b', m).forEach(b => b.onclick = () => { tab = b.dataset.t; paint(); });
      // drawing
      const cv = $('#sgc', m), cx = cv.getContext('2d'); cx.lineWidth = 5; cx.lineCap = 'round'; cx.lineJoin = 'round'; cx.strokeStyle = '#0B1B4D'; let down = false, ink = false;
      const pt = e => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
      cv.style.touchAction = 'none';
      cv.onpointerdown = e => { down = true; cv.setPointerCapture(e.pointerId); const [x, y] = pt(e); cx.beginPath(); cx.moveTo(x, y); cx.lineTo(x + .1, y + .1); cx.stroke(); ink = true; };
      cv.onpointermove = e => { if (!down) return; const [x, y] = pt(e); cx.lineTo(x, y); cx.stroke(); }; cv.onpointerup = cv.onpointercancel = () => { down = false; };
      $('#sgx', m).onclick = () => { cx.clearRect(0, 0, cv.width, cv.height); ink = false; };
      // typed
      const tc = $('#sgt', m), tx = tc.getContext('2d');
      function renderType() { tx.clearRect(0, 0, tc.width, tc.height); const t = $('#sgn', m).value.trim(); if (!t) return; let size = 130; tx.fillStyle = '#0B1B4D'; tx.textBaseline = 'middle'; do { tx.font = size + 'px ' + $('#sgf', m).value; size -= 6; } while (tx.measureText(t).width > tc.width - 40 && size > 30); tx.fillText(t, 20, tc.height / 2); }
      $('#sgn', m).oninput = renderType; $('#sgf', m).onchange = renderType;
      const trim = c => { const x = c.getContext('2d'), d = x.getImageData(0, 0, c.width, c.height).data; let x0 = c.width, y0 = c.height, x1 = 0, y1 = 0; for (let y = 0; y < c.height; y++) for (let i = 0; i < c.width; i++) if (d[(y * c.width + i) * 4 + 3] > 10) { if (i < x0) x0 = i; if (i > x1) x1 = i; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        if (x1 <= x0) return null; const p = 12, w = Math.min(c.width, x1 - x0 + 2 * p), h = Math.min(c.height, y1 - y0 + 2 * p), o = document.createElement('canvas'); o.width = w; o.height = h; o.getContext('2d').drawImage(c, Math.max(0, x0 - p), Math.max(0, y0 - p), w, h, 0, 0, w, h); return o.toDataURL('image/png'); };
      $('#sgo', m).onclick = () => {
        const comment = $('#sgm', m).value.trim();
        if (tab === 'saved') return done({ mode: 'saved', comment });
        const img = tab === 'draw' ? (ink ? trim(cv) : null) : trim(tc); if (!img) { $('#er', m).textContent = tab === 'draw' ? 'Draw your signature first.' : 'Type your name first.'; return; }
        done({ mode: 'new', image: img, save: $('#sgs', m).checked, comment });
      };
      paint();
    });
  }

  /* ---------- the approval card and the signatures on the document ---------- */
  const stepIcon = s => s.status === 'signed' ? '✍' : s.status === 'approved' ? '✓' : s.status === 'reverted' ? '↩' : s.current ? '●' : '○';
  function chainHtml(a) {
    return '<div class="ap-chain">' + a.steps.map(s => '<div class="ap-step ' + s.status + (s.current ? ' cur' : '') + '"><div class="ap-ic" aria-hidden="true">' + stepIcon(s) + '</div><div class="grow"><b>' + esc(s.name) + '</b> <span class="muted small">' + esc(s.title) + '</span><div class="small">' + (s.kind === 'sign' ? 'Signs' : 'Reviews') + (s.status === 'pending' ? (s.current ? ' · <b>their turn</b>' : ' · waiting') : ' · ' + esc({ approved: 'approved', signed: 'signed', reverted: 'reverted' }[s.status]) + (s.acted_at ? ' ' + esc(when(s.acted_at)) : '')) + '</div>' +
      (s.comment ? '<div class="small" style="white-space:pre-wrap;margin-top:2px">“' + esc(s.comment) + '”</div>' : '') + '</div></div>').join('') + '</div>';
  }
  function cardHtml(d) {
    const A = d.approval, c = A.current; let h = '<div class="card noprint ap-card" id="apcard"><div class="row" style="gap:10px;flex-wrap:wrap"><h2 class="grow" style="margin:0">Approval &amp; signature</h2>' + (c ? tag(c.status) : '<span class="tag">Not requested</span>') + '</div>';
    if (!c || c.status === 'canceled') h += '<div class="muted small" style="margin-top:6px">' + (A.can_request ? 'Send this document along the chain of command: reviewers first, then the person who signs.' : 'This document has not been sent for approval.') + '</div>' + (A.can_request ? '<div class="row" style="margin-top:10px"><button class="btn" id="apreq">Request approval…</button></div>' : '');
    else {
      h += '<div class="muted small" style="margin:4px 0 8px">Requested by ' + esc(c.requester) + ' · ' + esc(when(c.created_at)) + ' · fingerprint <code>' + esc(c.fingerprint) + '</code></div>' + chainHtml(c);
      if (c.status === 'reverted') { const rv = c.steps.find(s => s.status === 'reverted'); h += '<div class="banner" style="margin-top:8px;background:rgba(200,69,63,.12);color:var(--ink);border:1px solid rgba(200,69,63,.35)"><span><b>Sent back by ' + esc(rv ? rv.name : 'a reviewer') + '.</b> ' + esc(rv && rv.comment ? rv.comment : '') + '<br><span class="small">Edit the document, then ask for approval again.</span></span></div>' + (A.can_request ? '<div class="row" style="margin-top:10px"><button class="btn" id="apreq">Request approval again…</button></div>' : ''); }
      if (c.status === 'signed') h += '<div class="banner" style="margin-top:8px;background:rgba(31,166,122,.12);color:var(--ink);border:1px solid rgba(31,166,122,.35)"><span>🔒 <b>Signed.</b> The document is locked and exactly as it was when it was approved. To change anything, void it and issue a new one.</span></div>';
      if (c.my_turn) h += '<div class="row" style="margin-top:12px;gap:8px;flex-wrap:wrap">' + (c.my_turn.kind === 'review' ? '<button class="btn" id="apok">' + (c.my_turn.next_is_sign ? 'Approve and request signature' : 'Approve review') + '</button>' : '<button class="btn" id="apsign">Sign…</button>') + '<button class="btn ghost" id="aprev" style="color:var(--bad)">Revert…</button></div>';
      else if (['in_review', 'awaiting_sign'].includes(c.status) && (S.user.id === c.created_by || S.user.finance || S.user.is_root)) h += '<div class="row" style="margin-top:12px"><button class="btn ghost sm" id="apcan">Withdraw request</button></div>';
    }
    if (c && c.events && c.events.length) h += '<details style="margin-top:10px"><summary class="small muted" style="cursor:pointer">History (' + c.events.length + ')</summary><div class="list small" style="margin-top:6px">' + c.events.map(e => '<div class="item" style="padding:6px 10px"><div class="grow"><b>' + esc(e.name || '') + '</b> ' + esc({ requested: 'asked for approval', review_approved: 'approved the review', signature_requested: 'requested a signature', signed: 'signed', reverted: 'reverted it', canceled: 'withdrew the request' }[e.action] || e.action) + (e.comment ? ' <span class="muted">· ' + esc(e.comment) + '</span>' : '') + '</div><span class="muted">' + esc(when(e.created_at)) + '</span></div>').join('') + '</div></details>';
    return h + '</div>';
  }
  // put the signatures on the printed document: on the "For Aerosub" line of invoices and proformas, at the end of a commercial
  function stamp(v, d) {
    const c = d.approval && d.approval.current; if (!c || c.status !== 'signed') return; const sg = c.steps.filter(s => s.status === 'signed' && s.signature); if (!sg.length) return;
    const imgs = sg.map(s => '<img class="ap-img" src="' + esc(s.signature) + '" alt="Signature of ' + esc(s.name) + '">').join('');
    const cap = '<div class="ap-cap">' + sg.map(s => 'Signed by <b>' + esc(s.name) + '</b>' + (s.title ? ', ' + esc(s.title) : '') + ' · ' + esc(when(s.acted_at)) + ' · ref ' + esc(s.fingerprint || '')).join('<br>') + '</div>';
    const sign = v.querySelector('.inv-sign > div'); if (sign) { const line = sign.querySelector('.line'); if (line) { line.classList.add('ap-line'); line.insertAdjacentHTML('afterbegin', '<span class="ap-imgs">' + imgs + '</span>'); line.insertAdjacentHTML('afterend', cap); return; } }
    const foot = v.querySelector('.cm-foot') || v.querySelector('.fin-sheet'); if (foot) foot.insertAdjacentHTML('beforeend', '<div class="ap-block"><div class="ap-imgs">' + imgs + '</div>' + cap + '</div>');
  }
  async function request(d, id) {
    const A = d.approval, el = A.eligible || []; let rows = (A.suggest || []).map(s => ({ ...s }));
    const m = modal('<div class="row"><h2 class="grow">Request approval</h2><button class="btn ghost sm" id="rx">✕</button></div><div class="muted small" style="margin:4px 0 8px">' + esc(TYPE[d.doc_type]) + ' ' + esc(d.number) + ' · ' + esc(d.client_name) + '. People act in the order below: first the reviewers, then the signer(s). The document is locked until it is signed or sent back.</div><div id="rr"></div>' +
      '<div class="row" style="gap:8px;margin-top:8px"><button class="btn ghost sm" type="button" id="ra1">+ Reviewer</button><button class="btn ghost sm" type="button" id="ra2">+ Signer</button></div><div class="field" style="margin-top:10px"><label>Message to the first person (optional)</label><textarea id="rn" rows="2" maxlength="500"></textarea></div><div class="err" id="er"></div><div class="row"><button class="btn" id="rg">Send</button><button class="btn ghost" id="rc">Cancel</button></div>');
    const paint = () => { $('#rr', m).innerHTML = rows.map((s, i) => '<div class="row" style="gap:8px;margin-top:6px"><span class="tag ' + (s.kind === 'sign' ? 'done' : '') + '" style="min-width:64px;text-align:center">' + (i + 1) + '. ' + (s.kind === 'sign' ? 'Sign' : 'Review') + '</span><select data-i="' + i + '" class="grow">' + '<option value="">Choose a person…</option>' + el.filter(p => p.id !== S.user.id).map(p => '<option value="' + p.id + '"' + (p.id === s.user_id ? ' selected' : '') + '>' + esc(p.name) + ' · ' + esc(p.title) + '</option>').join('') + '</select><button class="btn ghost sm" type="button" data-rm="' + i + '" aria-label="Remove">✕</button></div>').join('') || '<div class="muted small">Add at least one signer.</div>';
      $$('select[data-i]', m).forEach(s => s.onchange = () => { rows[+s.dataset.i].user_id = +s.value || 0; }); $$('[data-rm]', m).forEach(b => b.onclick = () => { rows.splice(+b.dataset.rm, 1); paint(); }); };
    $('#ra1', m).onclick = () => { const at = rows.findIndex(r => r.kind === 'sign'); rows.splice(at < 0 ? rows.length : at, 0, { kind: 'review', user_id: 0 }); paint(); }; $('#ra2', m).onclick = () => { rows.push({ kind: 'sign', user_id: 0 }); paint(); };
    $('#rx', m).onclick = $('#rc', m).onclick = () => m.close(); paint();
    $('#rg', m).onclick = async e => { if (rows.some(r => !r.user_id)) { $('#er', m).textContent = 'Choose a person for every step (or remove the empty one).'; return; } e.target.disabled = true;
      try { await api('finance/doc/' + id + '/approval', { method: 'POST', data: { steps: rows, note: $('#rn', m).value } }); m.close(); toast('Sent. ' + (el.find(p => p.id === rows[0].user_id) || { name: 'The first person' }).name + ' has been notified'); X.render(); } catch (er) { $('#er', m).textContent = er.message; e.target.disabled = false; } };
  }
  async function act(id, data, msg) { try { await api('finance/doc/' + id + '/approval/act', { method: 'POST', data }); toast(msg); try { const me = await api('me'); S.user = me.user; } catch {} X.render(); } catch (er) { toast(er.message); } }
  function askReason(title, hint, cb) {
    const m = modal('<h2>' + esc(title) + '</h2><div class="muted small" style="margin:4px 0 8px">' + esc(hint) + '</div><div class="field"><label>Reason</label><textarea id="rr" rows="4" maxlength="1000" required></textarea></div><div class="err" id="er"></div><div class="row"><button class="btn" id="rg" style="background:var(--bad)">Revert</button><button class="btn ghost" id="rc">Cancel</button></div>');
    $('#rc', m).onclick = () => m.close(); $('#rg', m).onclick = () => { const v = $('#rr', m).value.trim(); if (!v) { $('#er', m).textContent = 'Please say what needs to change.'; return; } m.close(); cb(v); };
  }
  X.finDocHook = (d, v, id) => {
    if (!d.approval || d.doc_type === 'receipt') return; const A = d.approval; const doc = v.children[2];
    const holder = document.createElement('div'); holder.innerHTML = cardHtml(d); if (doc) v.insertBefore(holder.firstChild, doc); else v.appendChild(holder.firstChild);
    stamp(v, d);
    if (A.locked) ['#fed', '#fdl'].forEach(s => { const b = $(s, v); if (b) b.style.display = 'none'; });
    const q = s => $(s, v), title = (TYPE[d.doc_type] || 'document') + ' ' + d.number;
    const rq = q('#apreq'); if (rq) rq.onclick = () => request(d, id);
    const ok = q('#apok'); if (ok) ok.onclick = () => act(id, { action: 'approve' }, 'Review approved');
    const sg = q('#apsign'); if (sg) sg.onclick = async () => { const r = await signPad({ title }); if (r) act(id, { action: 'sign', comment: r.comment, signature: { mode: r.mode, image: r.image, save: r.save } }, 'Signed'); };
    const rv = q('#aprev'); if (rv) rv.onclick = () => askReason('Revert ' + title, 'It goes back to ' + (A.current.requester || 'the author') + ' with your reason, who can fix it and ask again.', t => act(id, { action: 'revert', comment: t }, 'Sent back'));
    const cn = q('#apcan'); if (cn) cn.onclick = () => { if (confirm('Withdraw the approval request? The document becomes editable again.')) act(id, { action: 'cancel' }, 'Request withdrawn'); };
  };

  /* ---------- Approvals page: what waits for me ---------- */
  views.approvals = async () => {
    const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>'; let D; try { D = await api('finance/approvals'); } catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; return; }
    const row = (x, right) => '<a class="item" href="#/finance/' + x.doc_id + '" style="color:inherit"><div class="grow"><b>' + esc(TYPE[x.doc_type] || x.doc_type) + ' ' + esc(x.number) + '</b><div class="muted small">' + esc(x.client_name || '') + (right ? ' · ' + right : '') + '</div></div><span class="muted">›</span></a>';
    v.innerHTML = '<div class="top"><div><h1>Approvals</h1><div class="muted">Documents waiting for your review or signature, and the ones you sent.</div></div></div>' +
      '<div class="card"><h2>Waiting for you</h2><div class="list">' + (D.waiting.map(x => row(x, (x.kind === 'sign' ? '<b>Needs your signature</b>' : '<b>Needs your review</b>') + ' · from ' + esc(x.requester) + ' · ' + esc(when(x.requested_at)))).join('') || '<div class="empty">Nothing is waiting for you.</div>') + '</div></div>' +
      '<div class="two" style="margin-top:14px"><div class="card"><h2>My requests</h2><div class="list">' + (D.mine.map(x => row(x, (ST[x.status] ? ST[x.status][0] : x.status) + (x.waiting_for && ['in_review', 'awaiting_sign'].includes(x.status) ? ' · waiting for ' + esc(x.waiting_for) : ''))).join('') || '<div class="empty">You have not asked for approval yet.</div>') + '</div></div>' +
      '<div class="card"><h2>Done by me</h2><div class="list">' + (D.done.map(x => row(x, esc({ approved: 'You approved', signed: 'You signed', reverted: 'You reverted' }[x.status] || x.status) + ' · ' + esc(when(x.acted_at)))).join('') || '<div class="empty">Nothing yet.</div>') + '</div></div></div>';
  };
})();
