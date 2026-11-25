/* Aerosub Office: the Mail tab's three panes (folders, message list, reading pane) can be dragged wider or narrower and folded away.
 * Drag the bar between panes, or focus it and use the arrow keys; press the little arrow on the bar (or Enter) to fold or unfold a pane; double-click to reset.
 * Sizes are remembered on this device. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._mpLoaded) return; X._mpLoaded = 1;
  const $ = (s, r) => (r || document).querySelector(s);
  const get = () => { try { return Object.assign({ f: 180, l: 340, cf: 0, cl: 0 }, JSON.parse(localStorage.getItem('mailpanes') || '{}')); } catch { return { f: 180, l: 340, cf: 0, cl: 0 }; } };
  const put = o => { try { localStorage.setItem('mailpanes', JSON.stringify(o)); } catch {} };
  const LIM = { f: [120, 360], l: [220, 720] };
  function apply(root, st) {
    const W = root.clientWidth; const rest = 300;   // the reading pane always keeps at least this much
    let f = st.cf ? 0 : st.f, l = st.cl ? 0 : st.l; if (f + l + 20 > W - rest) { const over = f + l + 20 - (W - rest); l = Math.max(st.cl ? 0 : LIM.l[0], l - over); if (f + l + 20 > W - rest) f = Math.max(st.cf ? 0 : LIM.f[0], f - (f + l + 20 - (W - rest))); }
    root.style.setProperty('--mf', f + 'px'); root.style.setProperty('--ml', l + 'px'); root.classList.toggle('cf', !!st.cf); root.classList.toggle('cl', !!st.cl);
    const b1 = $('[data-sp=f] .mp-btn', root), b2 = $('[data-sp=l] .mp-btn', root); if (b1) { b1.textContent = st.cf ? '›' : '‹'; b1.title = st.cf ? 'Show the folders' : 'Fold the folders away'; b1.setAttribute('aria-label', b1.title); } if (b2) { b2.textContent = st.cl ? '›' : '‹'; b2.title = st.cl ? 'Show the message list' : 'Fold the message list away'; b2.setAttribute('aria-label', b2.title); }
  }
  function setup(root) {
    if (root.classList.contains('mp')) return; const mf = $('.m-folders', root), ml = $('.m-list', root), mr = $('.m-read', root); if (!mf || !ml || !mr) return; root.classList.add('mp'); let st = get();
    const mk = key => { const d = document.createElement('div'); d.className = 'mp-split'; d.dataset.sp = key; d.setAttribute('role', 'separator'); d.setAttribute('aria-orientation', 'vertical'); d.tabIndex = 0; d.title = 'Drag to resize. Double-click to reset.'; d.innerHTML = '<button type="button" class="mp-btn" tabindex="-1"></button>'; return d; };
    const s1 = mk('f'), s2 = mk('l'); mf.after(s1); ml.after(s2); apply(root, st);
    const key = k => k === 'f' ? 'cf' : 'cl', sz = k => k;
    [s1, s2].forEach(sp => { const k = sp.dataset.sp;
      sp.querySelector('.mp-btn').onclick = e => { e.stopPropagation(); st[key(k)] = st[key(k)] ? 0 : 1; put(st); apply(root, st); };
      sp.addEventListener('pointerdown', e => { if (e.target.closest('.mp-btn')) return; e.preventDefault(); if (st[key(k)]) { st[key(k)] = 0; put(st); apply(root, st); return; } sp.setPointerCapture(e.pointerId); const x0 = e.clientX, w0 = st[sz(k)]; root.classList.add('dragging');
        const mv = ev => { st[sz(k)] = Math.round(Math.max(LIM[k][0], Math.min(LIM[k][1], w0 + ev.clientX - x0))); apply(root, st); }, up = () => { sp.removeEventListener('pointermove', mv); sp.removeEventListener('pointerup', up); root.classList.remove('dragging'); put(st); }; sp.addEventListener('pointermove', mv); sp.addEventListener('pointerup', up); });
      sp.addEventListener('dblclick', e => { if (e.target.closest('.mp-btn')) return; st[sz(k)] = k === 'f' ? 180 : 340; st[key(k)] = 0; put(st); apply(root, st); });
      sp.addEventListener('keydown', e => { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); st[key(k)] = 0; st[sz(k)] = Math.max(LIM[k][0], Math.min(LIM[k][1], st[sz(k)] + (e.key === 'ArrowRight' ? 20 : -20))); put(st); apply(root, st); } else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); st[key(k)] = st[key(k)] ? 0 : 1; put(st); apply(root, st); } });
    });
    window.addEventListener('resize', () => { if (root.isConnected) apply(root, st); });
  }
  let busy = false; new MutationObserver(() => { if (busy) return; busy = true; setTimeout(() => { try { const r = $('#mail'); if (r) setup(r); } finally { busy = false; } }, 50); }).observe(document.body, { childList: true, subtree: true });
})();
