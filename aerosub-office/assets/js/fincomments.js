/* Aerosub Office: the conversation on a finance document. Everyone working on the document can write a full comment, to everybody on it or to one person.
 * A comment to one person is seen only by the sender and that person (and the Root Admin). The receiver is notified and can reply. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._fcLoaded) return; X._fcLoaded = 1;
  const { api, toast, esc } = X;
  const when = t => t ? new Date(String(t).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
  const prev = X.finDocHook;
  X.finDocHook = (d, v, id) => {
    if (prev) prev(d, v, id); if (d.doc_type === 'receipt') return;
    const card = document.createElement('div'); card.className = 'card noprint fc-card'; card.id = 'fdcomments'; card.style.margin = '12px 0'; card.innerHTML = '<div class="muted small">Loading comments…</div>';
    const anchor = v.querySelector('.fin-sheet'); if (anchor) v.insertBefore(card, anchor); else v.appendChild(card);
    const paint = async (focus) => {
      let r; try { r = await api('finance/doc/' + id + '/comments'); } catch { card.remove(); return; }
      if (!r.comments.length && !r.can_comment) { card.remove(); return; }
      const me = X.S.user.id;
      card.innerHTML = '<div class="row" style="flex-wrap:wrap;gap:8px"><h3 class="grow" style="margin:0">Comments <span class="muted small">(' + r.comments.length + ')</span></h3></div>' +
        (r.comments.length ? '<div class="fc-list">' + r.comments.map(c => '<div class="fc-msg' + (c.mine ? ' mine' : '') + '"><div class="small"><b>' + esc(c.mine ? 'You' : c.name) + '</b>' + (c.to_name ? ' <span class="muted">to</span> <b>' + esc(c.to_user_id === me ? 'you' : c.to_name) + '</b> <span class="tag" title="Only the two of you can see this">private</span>' : ' <span class="muted">to everyone on this document</span>') + ' <span class="muted">· ' + esc(when(c.created_at)) + '</span></div><div class="fc-body">' + esc(c.body) + '</div>' + (!c.mine && r.can_comment ? '<button class="btn ghost sm" data-reply="' + c.user_id + '">Reply</button>' : '') + '</div>').join('') + '</div>' : '<div class="muted small" style="margin-top:8px">No comments yet.</div>') +
        (r.can_comment ? '<div class="fc-compose"><div class="row" style="gap:8px;flex-wrap:wrap;margin-bottom:6px"><label class="small muted" for="fcto">To</label><select id="fcto" style="width:auto;max-width:100%"><option value="">Everyone on this document</option>' + r.people.map(p => '<option value="' + p.id + '">' + esc(p.name) + ' (private)</option>').join('') + '</select></div><textarea id="fcb" rows="6" maxlength="4000" placeholder="Write your comment. You can use several lines." style="width:100%;min-height:140px;resize:vertical"></textarea><div class="row" style="gap:10px;flex-wrap:wrap;margin-top:6px"><label class="small"><input type="checkbox" id="fce" style="width:auto" checked> Also send by email</label><span class="grow"></span><button class="btn" id="fcs">Send comment</button></div><div class="err" id="fcer"></div></div>' : '');
      card.querySelectorAll('[data-reply]').forEach(b => b.onclick = () => { const s = card.querySelector('#fcto'); if (s) { s.value = b.dataset.reply; } const t = card.querySelector('#fcb'); if (t) t.focus(); });
      const send = card.querySelector('#fcs'); if (send) send.onclick = async () => { const body = card.querySelector('#fcb').value.trim(); if (!body) { card.querySelector('#fcer').textContent = 'Write your comment first.'; return; } send.disabled = true;
        try { const x = await api('finance/doc/' + id + '/comments', { method: 'POST', data: { body, to_user_id: card.querySelector('#fcto').value || null, email: card.querySelector('#fce').checked ? 1 : 0 } }); toast('Sent' + (x.notified ? ' · ' + x.notified + ' notified' : '')); paint(true); } catch (er) { card.querySelector('#fcer').textContent = er.message; send.disabled = false; } };
      if (focus) { const t = card.querySelector('#fcb'); if (t) t.scrollIntoView({ block: 'nearest' }); }
    };
    paint(false);
  };
})();
