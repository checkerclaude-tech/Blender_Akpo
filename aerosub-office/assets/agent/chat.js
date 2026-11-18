/* Agents area: Research agent > Chat (mockup M2). Answers come from the agent's own checked documents and inbox, with the
 * source named, or "I don't know". Instructions show up as a request card that waits for the owner's Approve. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  let timer = 0;
  const SUGGEST = [["What's waiting for me?", "What's waiting for me?"], ['Explain claim 1', 'Explain claim 1'], ['Check this link', 'Check this link https://']];
  const CARD_STATE = { approved: ['warn', '…', 'Approved. Waiting for the agent'], done: ['ok', '✓', 'Done'], dismissed: ['', '–', 'Not now'], failed: ['bad', '!', "Couldn't be done"] };
  const safeHref = h => (/^#\//.test(h) || /^https?:\/\/[^\s]+$/i.test(h)) ? h : '';

  const source = s => { const h = safeHref(s.href); if (!h) return ''; const ext = /^https?:/i.test(h);
    return '<a class="ag-source" href="' + esc(h) + '"' + (ext ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + esc(s.text) + (ext ? '<span class="ag-sr"> (opens in a new tab)</span>' : '') + '</a>'; };

  const bubble = m => {
    if (m.role === 'owner') return '<div class="ag-msg me">' + esc(m.body) + '</div>';
    const a = m.action;
    const card = !a ? '' : a.state === 'pending'
      ? '<div class="ag-req"><b>Request for your approval</b><div class="muted small">' + esc(a.kind === 'research_company' ? 'Research ' + (a.company || '') : 'Open ' + (a.url || '')) + '</div>' +
        '<div class="ag-actions"><button class="btn sm" data-ok="' + m.id + '">Approve</button><button class="btn ghost sm" data-no="' + m.id + '">Not now</button></div></div>'
      : '<div class="ag-req done">' + (CARD_STATE[a.state] ? AA.chip(CARD_STATE[a.state][0], CARD_STATE[a.state][1], CARD_STATE[a.state][2]) : '') + '</div>';
    return '<div class="ag-msg bot">' + esc(m.body) + ((m.sources || []).length ? '<div class="ag-sources">' + m.sources.map(source).join('') + '</div>' : '') + '</div>' + card;
  };

  AA.research.tabs.chat = async box => {
    clearTimeout(timer);
    let chatId = null, msgs = [], sending = false;
    const waitingForLaptop = () => msgs.some(m => m.action && m.action.state === 'approved');
    box.innerHTML = '<div class="ag-chatbar"><button class="btn ghost sm" id="agnew" hidden>New conversation</button><button class="btn ghost sm danger-text" id="agdel" hidden>Delete conversation</button></div>' +
      '<div id="agthread" class="ag-thread" role="log" aria-live="polite" aria-label="Conversation">' + AA.skeleton(2) + '</div><div id="agwait"></div>' +
      '<div class="ag-sugg" id="agsugg">' + SUGGEST.map(([l, t], i) => '<button class="chip" data-s="' + i + '">' + esc(l) + '</button>').join('') + '</div>' +
      '<form class="ag-compose" id="agform" autocomplete="off"><a class="ag-attach" href="#/agents/research/files" aria-label="Upload a file to check in Files">' + AA.icon.upload + '</a>' +
      '<input id="agmsg" maxlength="1000" placeholder="Ask the agent…" aria-label="Message the agent"><button class="ag-send" type="submit" aria-label="Send">&#10148;</button></form><div id="agerr" class="ag-err" role="alert"></div>';

    const draw = () => {
      const th = $('#agthread'); if (!th) return;
      th.innerHTML = msgs.length ? msgs.map(bubble).join('') : '<div class="ag-empty">Ask about a document or company. Try: What\'s waiting for me?</div>';
      $('#agnew').hidden = $('#agdel').hidden = !chatId;
      const w = $('#agwait'), h = AA.research.health;
      w.innerHTML = waitingForLaptop() && h && !h.online ? AA.banner('warn', AA.icon.clock, 'Waiting for the agent. The office laptop is off. It will answer when it starts.') : '';
      th.scrollTop = th.scrollHeight;
      th.querySelectorAll('[data-ok]').forEach(b => b.onclick = () => act(b.dataset.ok, 'approve'));
      th.querySelectorAll('[data-no]').forEach(b => b.onclick = () => act(b.dataset.no, 'dismiss'));
    };
    const fail = (e, fallback) => { if (e.step === 'code') return AA.go(); const el = $('#agerr'); if (el) el.textContent = e.offline ? e.message : (e.status ? e.message : fallback); };

    const load = async () => {
      try { const r = await AA.call('agent-owner/chat'); chatId = r.chat_id; msgs = r.messages; draw(); schedule(); }
      catch (e) { const th = $('#agthread'); if (th) { th.innerHTML = AA.errorBox('the conversation', 'agretry'); $('#agretry').onclick = load; } if (e.step === 'code') AA.go(); }
    };
    // While something is waiting for the laptop, look again every few seconds so its answer appears by itself.
    const schedule = () => { clearTimeout(timer); if (waitingForLaptop()) timer = setTimeout(async () => { if (!$('#agthread') || !location.hash.startsWith('#/agents/research/chat')) return; try { const r = await AA.call('agent-owner/chat'); if (r.chat_id === chatId) { msgs = r.messages; draw(); } } catch {} schedule(); }, 6000); };

    const send = async text => {
      if (sending || !text.trim()) return; sending = true; $('#agerr').textContent = '';
      const input = $('#agmsg'); input.value = '';
      msgs.push({ id: 'tmp', role: 'owner', body: text }); draw();
      try { const r = await AA.call('agent-owner/chat/messages', { method: 'POST', data: { body: text, chat_id: chatId } }); chatId = r.chat_id; msgs = msgs.filter(m => m.id !== 'tmp').concat(r.messages); }
      catch (e) { msgs = msgs.filter(m => m.id !== 'tmp'); input.value = text; fail(e, 'Not sent. Try again.'); }
      sending = false; draw(); schedule();
    };
    const act = async (id, what) => {
      try {
        const r = await AA.call('agent-owner/chat/actions/' + id + '/' + what, { method: 'POST' });
        msgs = msgs.map(m => (r.updated || []).find(u => u.id === m.id) || m).concat(r.messages || []); draw(); schedule();
        if (what === 'approve') { try { AA.research.health = await AA.call('agent-owner/health'); draw(); } catch {} }
      } catch (e) { fail(e, "Couldn't save that. Try again."); }
    };

    $('#agform').onsubmit = e => { e.preventDefault(); send($('#agmsg').value); };
    box.querySelectorAll('[data-s]').forEach(b => b.onclick = () => { const t = SUGGEST[+b.dataset.s][1]; if (/https:\/\/$/.test(t)) { const i = $('#agmsg'); i.value = t; i.focus(); } else send(t); });
    $('#agnew').onclick = () => { chatId = null; msgs = []; draw(); $('#agmsg').focus(); };
    $('#agdel').onclick = () => AA.sheet('<h2>Delete this conversation?</h2><p>It will be removed for good.</p><div class="ag-actions"><button class="btn danger" id="agyes">Delete</button><button class="btn ghost" id="agcancel">Keep</button></div>', s => {
      $('#agcancel', s).onclick = s.close;
      $('#agyes', s).onclick = async () => { try { await AA.call('agent-owner/chat/' + chatId, { method: 'DELETE' }); s.close(); chatId = null; msgs = []; draw(); AA.X.toast('Conversation deleted.'); } catch (e) { s.close(); fail(e, "Couldn't delete it. Try again."); } };
    });
    await load();
  };
})();
