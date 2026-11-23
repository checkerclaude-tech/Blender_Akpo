/* Aerosub Office: @mentions in tasks. Type @ in a task's details or in a comment and pick a person, a department, or everyone.
 * Tagged people are added to the task and notified (the server does that). The text stays plain, e.g. "@Emma Employee please check @Finance".
 * X.mnHtml(escapedText) highlights the tags when the text is shown. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { S, esc } = X;
  const FIELD = 'textarea[name=description], #cf input[name=body], form#cf input, #rcf input[name=body], .rs-ed';   // where tagging works
  const canAll = () => { const u = S.user || {}; return ['super_admin', 'founder', 'manager'].includes(u.role) || !!u.is_root || !!(u.head_of && u.head_of.length); };
  let menu = null, items = [], sel = 0, ctx = null;

  const options = q => {
    q = q.toLowerCase().trim(); const has = n => !q || n.toLowerCase().split(/\s+/).some(w => w.startsWith(q)) || n.toLowerCase().startsWith(q); const out = [];
    if (canAll() && has('everyone')) out.push({ k: 'all', name: 'everyone', sub: 'Everybody in the company', ic: '👥' });
    (S.depts || []).filter(d => has(d.name)).slice(0, 5).forEach(d => out.push({ k: 'dept', name: d.name, sub: 'Whole department', ic: '🏢' }));
    (S.users || []).filter(u => +u.active === 1 && +u.id !== +(S.user && S.user.id) && has(u.name)).slice(0, 8).forEach(u => out.push({ k: 'user', name: u.name, sub: u.department || u.job_title || '', ic: '👤' }));
    return out.slice(0, 10);
  };
  const close = () => { if (menu) { menu.remove(); menu = null; } ctx = null; items = []; };
  const caretText = el => el.isContentEditable ? (() => { const s = getSelection(); if (!s.rangeCount) return ''; const r = s.getRangeAt(0).cloneRange(); r.selectNodeContents(el); r.setEnd(s.getRangeAt(0).endContainer, s.getRangeAt(0).endOffset); return r.toString(); })() : el.value.slice(0, el.selectionStart);
  function draw(el) {
    if (!menu) { menu = document.createElement('div'); menu.className = 'mn-menu'; menu.setAttribute('role', 'listbox'); menu.addEventListener('mousedown', e => e.preventDefault()); document.body.appendChild(menu); }
    const r = el.getBoundingClientRect(), below = innerHeight - r.bottom > 230; menu.style.left = Math.max(8, Math.min(r.left, innerWidth - 276)) + 'px'; menu.style.width = Math.min(268, innerWidth - 16) + 'px';
    if (below) { menu.style.top = Math.min(r.bottom + 4, innerHeight - 8) + 'px'; menu.style.bottom = ''; } else { menu.style.bottom = Math.max(8, innerHeight - r.top + 4) + 'px'; menu.style.top = ''; }
    menu.innerHTML = items.map((o, i) => '<div class="mn-o' + (i === sel ? ' on' : '') + '" role="option" data-i="' + i + '"><span class="mn-ic" aria-hidden="true">' + o.ic + '</span><span class="grow"><b>' + esc(o.name) + '</b><span class="muted small"> ' + esc(o.sub) + '</span></span></div>').join('');
    menu.querySelectorAll('.mn-o').forEach(n => n.onclick = () => pick(+n.dataset.i));
  }
  function pick(i) {
    const o = items[i], el = ctx && ctx.el; if (!o || !el) return close(); const ins = '@' + o.name + ' ';
    if (el.isContentEditable) { const s = getSelection(), rg = s.getRangeAt(0), n = rg.endContainer; if (n.nodeType === 3) { const before = n.textContent.slice(0, rg.endOffset), at = before.lastIndexOf('@'); n.textContent = before.slice(0, at) + ins + n.textContent.slice(rg.endOffset); const nr = document.createRange(); nr.setStart(n, at + ins.length); nr.collapse(true); s.removeAllRanges(); s.addRange(nr); } }
    else { const pos = el.selectionStart, before = el.value.slice(0, pos), at = before.lastIndexOf('@'); el.value = before.slice(0, at) + ins + el.value.slice(pos); el.selectionStart = el.selectionEnd = at + ins.length; el.dispatchEvent(new Event('input', { bubbles: true })); }
    el.focus(); close();
  }
  document.addEventListener('input', e => {
    const el = e.target; if (!el.matches || !el.matches(FIELD)) return;
    const m = /(?:^|\s)@([\p{L}\p{N}.' -]{0,30})$/u.exec(caretText(el)); if (!m) return close();
    ctx = { el }; items = options(m[1]); sel = 0; if (!items.length) return close(); draw(el);
  }, true);
  document.addEventListener('keydown', e => {
    if (!menu) return; if (e.key === 'Escape') { e.preventDefault(); return close(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; draw(ctx.el); }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); pick(sel); }
  }, true);
  document.addEventListener('focusout', () => setTimeout(() => { if (menu && !(document.activeElement && ctx && document.activeElement === ctx.el)) close(); }, 120), true);
  addEventListener('hashchange', close);

  /* show the tags highlighted in text that was already escaped with esc() */
  const re = () => { const names = [...(S.users || []).filter(u => +u.active === 1).map(u => u.name), ...(S.depts || []).map(d => d.name), 'everyone', 'everybody', 'all'].filter(Boolean).map(n => esc(n).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).sort((a, b) => b.length - a.length); return names.length ? new RegExp('(^|[\\s(])@(' + names.join('|') + ')(?![\\p{L}\\p{N}])', 'giu') : null; };
  X.mnHtml = html => { const r = re(); return r ? String(html).replace(r, (m, pre, name) => pre + '<span class="mn">@' + name + '</span>') : html; };
})();
