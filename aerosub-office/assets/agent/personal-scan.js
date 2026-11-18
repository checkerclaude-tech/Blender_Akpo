/* Agents area: Personal agent > Scan (mockup A7c): what the agent found on the public web today that fits you, with a fit score and why.
 * Open, Save and "Not for me" are how the agent learns what you like. The search text uses only your role, specialisms and location. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  const U = () => AA.personal.util;
  const KIND = { role: 'Job', consultancy: 'Consultancy', board: 'Board seat', speaking: 'Speaking', course: 'Course', certification: 'Certification' };
  const safeUrl = u => /^https?:\/\/[^\s"'<>]+$/i.test(u) ? u : '';
  const when = iso => { const t = new Date().toISOString().slice(0, 10); return iso === t ? 'Today' : U().day(iso); };

  AA.personal.tabs.scan = async box => {
    let snap = (await AA.pstore.get('scan')) || { t: 'scan', items: [], ready: null, last_run: null, queries: [], likes: [], skips: [] }, status = null, busy = false;

    const card = it => {
      const href = safeUrl(it.url);
      return '<div class="card ag-opp' + (it.state === 'saved' ? ' saved' : '') + '"><div class="ag-row">' + AA.tag('priv', KIND[it.kind] || AA.humanise(it.kind)) + AA.tag('info', 'Fit ' + (+it.score)) +
        (it.state === 'saved' ? AA.tag('ok', 'Saved') : '') + '<span class="grow"></span><span class="muted small">' + esc(when(it.found_on)) + '</span></div>' +
        '<b class="ag-block">' + esc(it.title) + '</b><div class="muted small">' + esc(it.domain) + '</div>' +
        (it.snippet ? '<p class="small">' + esc(it.snippet) + '</p>' : '') +
        ((it.why || []).length ? '<div class="muted small">Why: ' + esc(it.why.join(' · ')) + '</div>' : '') +
        '<div class="ag-actions">' + (href ? '<a class="btn sm" data-open="' + esc(it.id) + '" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">Open<span class="ag-sr"> (opens in a new tab)</span></a>' : '') +
        '<button class="btn ghost sm" data-save="' + esc(it.id) + '" data-st="' + esc(it.state) + '">' + (it.state === 'saved' ? 'Unsave' : 'Save') + '</button>' +
        '<button class="btn ghost sm danger-text" data-no="' + esc(it.id) + '">Not for me</button></div></div>';
    };

    const draw = () => {
      const el = $('#agscan'); if (!el) return;
      let body;
      if (snap.ready === false) body = AA.empty('Add your role or specialisms in Profile and I will know what to look for.') + '<div class="ag-actions"><a class="btn" href="#/agents/personal/profile">Open Profile</a></div>';
      else if (!snap.items.length) body = AA.empty(snap.last_run ? 'Nothing new fits you yet. I look again every day.' : 'Your first scan runs today. You can also start one now.');
      else body = snap.items.map(card).join('');
      const learned = (snap.likes.length || snap.skips.length) ? '<p class="muted small">Learning from you: ' + (snap.likes.length ? 'more ' + esc(snap.likes.map(k => (KIND[k] || k).toLowerCase()).join(', ')) : '') + (snap.likes.length && snap.skips.length ? '; ' : '') + (snap.skips.length ? 'fewer ' + esc(snap.skips.map(k => (KIND[k] || k).toLowerCase()).join(', ')) : '') + '.</p>' : '';
      el.innerHTML = U().offline(status) +
        '<div class="ag-actions"><button class="btn sm" id="agscan-now"' + (busy ? ' disabled' : '') + '>' + (busy ? 'Looking…' : 'Scan now') + '</button></div>' +
        '<p class="muted small">Searches the public web only, with just your role, specialisms and location. Never your name or your CV. Nothing is opened for you.</p>' + learned +
        body + '<div id="agperr" class="ag-err" role="alert"></div>' +
        (snap.queries.length ? '<details class="small"><summary>What I searched for</summary><ul>' + snap.queries.map(q => '<li>' + esc(q) + '</li>').join('') + '</ul></details>' : '') + U().saved(snap);
      $('#agscan-now').onclick = scanNow;
      box.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', () => { AA.psync.post({ t: 'opp_act', id: a.dataset.open, act: 'open' }).catch(() => {}); }));
      box.querySelectorAll('[data-save]').forEach(b => b.onclick = () => act(b.dataset.save, b.dataset.st === 'saved' ? 'unsave' : 'save'));
      box.querySelectorAll('[data-no]').forEach(b => b.onclick = () => act(b.dataset.no, 'dismiss'));
    };
    const act = async (id, what) => {
      try {
        await AA.psync.post({ t: 'opp_act', id, act: what });
        const it = snap.items.find(x => x.id === id);
        if (it) { if (what === 'dismiss') snap.items = snap.items.filter(x => x.id !== id); else it.state = what === 'save' ? 'saved' : 'opened'; }
        draw();
      } catch (e) { const el = $('#agperr'); if (el) el.textContent = e.message || "Couldn't save that. Try again."; }
    };
    const scanNow = async () => {
      busy = true; draw();
      try {
        const answered = await AA.psync.request({ t: 'scan_run' }, 'scan', 90000);
        const err = await AA.pstore.get('lastError');
        snap = (await AA.pstore.get('scan')) || snap; busy = false; draw();
        const el = $('#agperr');
        if (el) el.textContent = !answered ? 'Asked. Your laptop will look when it is on.' : (err && err.for === 'scan' && Date.now() - err.at < 120000 ? err.message : '');
      } catch (e) { busy = false; draw(); const el = $('#agperr'); if (el) el.textContent = e.message || "Couldn't start a scan. Try again."; }
    };
    const refresh = async () => {
      try { await AA.psync.request({ t: 'scan_get' }, 'scan', 12000); status = await AA.psync.status().catch(() => null); }
      catch (e) { if (e.step === 'code') return AA.go(); }
      snap = (await AA.pstore.get('scan')) || snap; draw();
    };

    box.innerHTML = '<div id="agscan">' + AA.skeleton(2) + '</div>';
    draw(); refresh();
    AA.psync.watch(async types => { if (types.has('scan') && $('#agscan')) { snap = (await AA.pstore.get('scan')) || snap; busy = false; draw(); } });
  };
})();
