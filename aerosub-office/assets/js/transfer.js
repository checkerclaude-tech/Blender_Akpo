/* Aerosub Office: a transferred task has to be accepted. The person it is offered to sees Accept / Decline at the top of the task; the person who offered it sees
 * that it is waiting and can take the offer back. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._trLoaded) return; X._trLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s);
  const prev = X.onTask;
  X.onTask = (m, t) => {
    if (prev) prev(m, t); const st = t.transfer_state; if (!st || $('#tr-banner', m)) return; const head = $('.row', m); if (!head) return;
    const b = document.createElement('div'); b.id = 'tr-banner'; b.className = 'fl-info'; b.style.margin = '8px 0';
    if (st === 'pending' && +t.assignee_id === +S.user.id) {
      b.innerHTML = '<b>📨 This task was offered to you</b><div class="small" style="margin:4px 0 8px">It stays with the person who has it now until you answer.</div><div class="row"><button class="btn" id="tr-yes">Accept the task</button><button class="btn ghost" id="tr-no">Decline</button></div>';
      head.after(b);
      $('#tr-yes', b).onclick = async () => { try { await api('tasks/' + t.id + '/transfer/accept', { method: 'POST', data: {} }); m.close(); toast('Accepted. It is yours now.'); X.render(); } catch (e) { toast(e.message); } };
      $('#tr-no', b).onclick = () => { const f = modal('<div class="row"><h2 class="grow">Decline this task</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="field" style="margin-top:10px"><label>Reason (optional)</label><input id="why" maxlength="300" placeholder="e.g. I am on leave that week"></div><div class="row"><button class="btn" id="go">Decline</button></div>'); $('#cx', f).onclick = () => f.close();
        $('#go', f).onclick = async () => { try { await api('tasks/' + t.id + '/transfer/decline', { method: 'POST', data: { reason: $('#why', f).value.trim() } }); f.close(); m.close(); toast('Declined. It stays with the person who offered it.'); X.render(); } catch (e) { toast(e.message); } }; };
    } else if (st === 'offered') {
      b.innerHTML = '<b>⏳ Waiting for the new person to accept</b><div class="small" style="margin:4px 0 8px">This task stays with ' + esc(t.assignee_name || 'its owner') + ' until they do.</div>' + (t.can_edit ? '<button class="btn ghost sm" id="tr-back">Take the offer back</button>' : '');
      head.after(b); const bk = $('#tr-back', b); if (bk) bk.onclick = async () => { if (!confirm('Take this offer back?')) return; try { await api('tasks/' + t.id + '/transfer/withdraw', { method: 'POST', data: {} }); m.close(); toast('Offer taken back'); X.render(); } catch (e) { toast(e.message); } };
    }
  };
})();
