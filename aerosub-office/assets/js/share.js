/* Aerosub Office: sharing. My Documents -> colleagues (a private hand-over you can take back) or email; letters -> email (Word file attached) or a task.
 * Nothing is shared unless the owner chooses it, one person at a time. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._shLoaded) return; X._shLoaded = 1;
  const { api, modal, toast, esc } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const when = t => t ? new Date(String(t).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';

  /** The email part of a share form. Returns { html, get() }. */
  function mailPane(mail, subject) {
    const mine = mail && mail.mine;
    return { html: '<div class="field"><label>To (one or more email addresses, separated by commas)</label><input id="sm-to" placeholder="name@company.com, another@company.com" autocomplete="off"></div>' +
      '<div class="field"><label>Subject</label><input id="sm-sub" maxlength="300" value="' + esc(subject || '') + '"></div>' +
      '<div class="field"><label>Message</label><textarea id="sm-msg" rows="4" maxlength="5000" placeholder="Say a few words about it (optional)"></textarea></div>' +
      '<div class="field"><label>Send from</label><label class="sh-radio"><input type="radio" name="sm-via" value="mine"' + (mine ? ' checked' : ' disabled') + '> My mailbox' + (mine ? ' (' + esc(mine) + ')' : ' <span class="muted small">(not connected yet in the Mail tab)</span>') + '</label>' +
      '<label class="sh-radio"><input type="radio" name="sm-via" value="system"' + (mine ? '' : ' checked') + '> Aerosub office sender <span class="muted small">(your name, replies come to you)</span></label></div>',
      get: root => ({ to: $('#sm-to', root).value.trim(), subject: $('#sm-sub', root).value.trim(), message: $('#sm-msg', root).value.trim(), via: ($('input[name=sm-via]:checked', root) || {}).value || 'system' }) };
  }
  const tabs = (m, names) => { $$('[data-shtab]', m).forEach(b => b.onclick = () => { $$('[data-shtab]', m).forEach(x => x.classList.toggle('on', x === b)); $$('[data-shpane]', m).forEach(p => p.hidden = p.dataset.shpane !== b.dataset.shtab); }); };

  /** Share a letter: by email (the .docx attached) or onto a task. */
  X.shareLetter = async (letter) => {
    let mail = { mine: null }, tasks = [];
    try { mail = (await api('finance/letterheads')).mail; } catch {}
    try { const t = await api('tasks'); tasks = (Array.isArray(t) ? t : (t.tasks || [])).filter(x => !['done', 'canceled'].includes(x.status)).slice(0, 400); } catch {}
    const mp = mailPane(mail, letter.title);
    const m = modal('<div class="row"><h2 class="grow">Share “' + esc(letter.title) + '”</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">The editable Word file (.docx) on the letterhead is attached.</div>' +
      '<div class="chips"><span class="chip on" data-shtab="mail" role="button" tabindex="0">✉ Email</span><span class="chip" data-shtab="task" role="button" tabindex="0">☑ Task</span></div>' +
      '<div data-shpane="mail" style="margin-top:10px">' + mp.html + '</div>' +
      '<div data-shpane="task" hidden style="margin-top:10px"><div class="field"><label>Find a task</label><input id="st-q" placeholder="Type to filter your tasks"></div><div class="field"><select id="st-sel" size="7" style="width:100%"></select></div><div class="field"><label>Note on the task (optional)</label><textarea id="st-note" rows="2" maxlength="1000"></textarea></div></div>' +
      '<div class="err" id="er" role="alert"></div><div class="row" style="margin-top:12px"><button class="btn" id="go">Send</button><span class="muted small" id="shn"></span></div>');
    tabs(m); $('#cx', m).onclick = () => m.close();
    const fill = () => { const q = $('#st-q', m).value.trim().toLowerCase(); $('#st-sel', m).innerHTML = tasks.filter(t => !q || String(t.title).toLowerCase().includes(q) || String(t.id) === q).slice(0, 60).map(t => '<option value="' + t.id + '">#' + t.id + ' · ' + esc(t.title) + '</option>').join('') || '<option disabled>No tasks match</option>'; };
    fill(); $('#st-q', m).oninput = fill;
    $('#go', m).onclick = async () => {
      const onTask = $('[data-shtab=task]', m).classList.contains('on'); const data = {}; $('#er', m).textContent = '';
      if (onTask) { const sel = $('#st-sel', m).value; if (!sel) { $('#er', m).textContent = 'Choose a task'; return; } data.task = { task_id: +sel, note: $('#st-note', m).value.trim() }; }
      else { const g = mp.get(m); if (!g.to) { $('#er', m).textContent = 'Type the email address to send it to'; return; } data.mail = g; }
      $('#go', m).disabled = true; $('#shn', m).textContent = 'Sending…';
      try { const r = await api('finance/letters/' + letter.id + '/share', { method: 'POST', data }); m.close(); toast((r.done || ['Shared']).join(' · ')); }
      catch (er) { $('#er', m).textContent = er.message; $('#go', m).disabled = false; $('#shn', m).textContent = ''; }
    };
  };

  /** Share one of my own documents: with colleagues, or by email. */
  X.shareVault = async (it) => {
    let people = [], mail = { mine: null }, cur = [];
    try { const p = await api('myfiles/people'); people = p.people; mail = p.mail; } catch (er) { toast(er.message); return; }
    try { cur = (await api('myfiles/' + it.id + '/shares')).shares; } catch {}
    const mp = mailPane(mail, it.title); const picked = new Set();
    const m = modal('<div class="row"><h2 class="grow">Share “' + esc(it.title) + '”</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">Only the people you choose can open it. You can take it back at any time. Everything else in My Documents stays private.</div>' +
      '<div class="chips"><span class="chip on" data-shtab="team" role="button" tabindex="0">👥 Colleagues</span><span class="chip" data-shtab="mail" role="button" tabindex="0">✉ Email</span></div>' +
      '<div data-shpane="team" style="margin-top:10px"><div id="sh-cur"></div><div class="field"><label>Choose colleagues</label><input id="sh-q" placeholder="Search by name or department"></div><div class="sh-list" id="sh-list"></div><div class="field" style="margin-top:8px"><label>Note (optional)</label><input id="sh-note" maxlength="300" placeholder="Why you are sharing it"></div></div>' +
      '<div data-shpane="mail" hidden style="margin-top:10px">' + mp.html + '</div>' +
      '<div class="err" id="er" role="alert"></div><div class="row" style="margin-top:12px"><button class="btn" id="go">Share</button><span class="muted small" id="shn"></span></div>');
    tabs(m); $('#cx', m).onclick = () => m.close();
    const have = new Set(cur.map(c => c.user_id));
    const paintCur = () => { $('#sh-cur', m).innerHTML = cur.length ? '<div class="sh-have"><b class="small">Shared with</b> ' + cur.map(c => '<span class="chip">' + esc(c.name) + ' <button class="sh-x" data-un="' + c.user_id + '" title="Stop sharing with ' + esc(c.name) + '" aria-label="Stop sharing with ' + esc(c.name) + '">×</button></span>').join(' ') + '</div>' : '';
      $$('[data-un]', m).forEach(b => b.onclick = async () => { try { await api('myfiles/' + it.id + '/share/' + b.dataset.un, { method: 'DELETE' }); cur = cur.filter(c => String(c.user_id) !== b.dataset.un); have.delete(+b.dataset.un); paintCur(); paint(); toast('Stopped sharing'); } catch (er) { toast(er.message); } }); };
    const paint = () => { const q = $('#sh-q', m).value.trim().toLowerCase(); $('#sh-list', m).innerHTML = people.filter(p => !have.has(p.id) && (!q || (p.name + ' ' + (p.department || '')).toLowerCase().includes(q))).map(p => '<label class="sh-row"><input type="checkbox" data-u="' + p.id + '"' + (picked.has(p.id) ? ' checked' : '') + '> <span>' + esc(p.name) + '</span> <span class="muted small">' + esc(p.department || '') + '</span></label>').join('') || '<div class="muted small" style="padding:8px">Nobody to add.</div>';
      $$('[data-u]', m).forEach(c => c.onchange = () => { c.checked ? picked.add(+c.dataset.u) : picked.delete(+c.dataset.u); }); };
    paintCur(); paint(); $('#sh-q', m).oninput = paint;
    $('#go', m).onclick = async () => {
      const onMail = $('[data-shtab=mail]', m).classList.contains('on'); $('#er', m).textContent = '';
      try {
        if (onMail) { const g = mp.get(m); if (!g.to) { $('#er', m).textContent = 'Type the email address to send it to'; return; } $('#go', m).disabled = true; $('#shn', m).textContent = 'Sending…'; const r = await api('myfiles/' + it.id + '/email', { method: 'POST', data: g }); m.close(); toast((r.done || ['Sent']).join(' · ')); }
        else { if (!picked.size) { $('#er', m).textContent = 'Tick at least one colleague'; return; } const r = await api('myfiles/' + it.id + '/share', { method: 'POST', data: { user_ids: [...picked], note: $('#sh-note', m).value.trim() } }); m.close(); toast('Shared with ' + (r.shared || picked.size) + ' colleague' + ((r.shared || picked.size) > 1 ? 's' : '') + ' — they were notified'); }
      } catch (er) { $('#er', m).textContent = er.message; $('#go', m).disabled = false; $('#shn', m).textContent = ''; }
    };
  };

  /** Called by the My Documents page once it is drawn: adds a Share button to every item and the "Shared with me" list. */
  X.myShareInit = async (v, items) => {
    $$('.my-item', v).forEach(card => { const id = card.dataset.id, it = items[id]; if (!it || card.querySelector('[data-share]')) return; const row = card.querySelector('[data-open]') && card.querySelector('[data-open]').parentElement; if (!row) return;
      const b = document.createElement('button'); b.className = 'btn ghost sm'; b.dataset.share = id; b.textContent = 'Share'; b.onclick = () => X.shareVault(it); const del = row.querySelector('[data-del]'); del ? row.insertBefore(b, del) : row.appendChild(b); });
    let sh = []; try { sh = (await api('myfiles/shared')).items; } catch { return; }
    const old = $('#mysh', v); if (old) old.remove(); if (!sh.length) return;
    const card = document.createElement('div'); card.className = 'card'; card.id = 'mysh'; card.style.marginBottom = '12px';
    card.innerHTML = '<h3 style="margin:0 0 6px">Shared with me <span class="muted small">(' + sh.length + ')</span></h3><div class="muted small" style="margin-bottom:8px">Colleagues handed these over to you. They stay theirs; you can remove them from your list.</div>' + sh.map(s => '<div class="sh-in" data-s="' + s.share_id + '"><div class="grow"><b>' + esc(s.title) + '</b><div class="muted small">' + esc(s.owner) + ' · ' + when(s.shared_at) + (s.note ? ' · “' + esc(s.note) + '”' : '') + '</div></div>' +
      '<button class="btn sm" data-so="' + s.share_id + '">' + (s.kind === 'file' ? 'Download' : s.kind === 'link' ? 'Visit' : 'Read') + '</button><button class="btn ghost sm" data-sx="' + s.share_id + '" title="Remove from my list">Remove</button></div>').join('');
    const anchor = $('.my-bar', v); anchor ? anchor.after(card) : v.appendChild(card); const by = Object.fromEntries(sh.map(s => [s.share_id, s]));
    $$('[data-so]', card).forEach(b => b.onclick = () => { const s = by[b.dataset.so];
      if (s.kind === 'file') { if (s.image) { const mm = modal('<div class="row"><h2 class="grow">' + esc(s.title) + '</h2><a class="btn ghost sm" href="api/myfiles/shared/' + s.share_id + '/file" download>Download</a><button class="btn ghost sm" id="cx">Close</button></div><img src="api/myfiles/shared/' + s.share_id + '/file?view=1" alt="" style="max-width:100%;border-radius:10px;margin-top:10px">'); $('#cx', mm).onclick = () => mm.close(); } else location.href = 'api/myfiles/shared/' + s.share_id + '/file'; }
      else if (s.kind === 'link') window.open(s.body, '_blank', 'noopener,noreferrer');
      else { const mm = modal('<div class="row"><h2 class="grow">' + esc(s.title) + '</h2><button class="btn ghost sm" id="cx">Close</button></div><p style="white-space:pre-wrap;margin-top:10px">' + esc(s.body) + '</p>'); $('#cx', mm).onclick = () => mm.close(); } });
    $$('[data-sx]', card).forEach(b => b.onclick = async () => { try { await api('myfiles/shared/' + b.dataset.sx, { method: 'DELETE' }); const r = b.closest('.sh-in'); r.remove(); if (!$('.sh-in', card)) card.remove(); } catch (er) { toast(er.message); } });
  };
})();
