/* Agents area: Personal agent > Chat (mockup M6). Answers use only the owner's own profile and say so, or say "I don't know".
 * Messages are encrypted on this phone before they leave it. If the laptop is off, a question waits in the mailbox and is answered
 * when the laptop starts ("Waiting for the agent"). */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;
  let timer = 0;
  const SUGGEST = ['What should I focus on this month?', 'How can I strengthen my CV?', 'What goals have I set?'];

  const source = s => '<span class="ag-source priv">' + esc(String(s.text || '')) + '</span>';
  const bubble = m => m.role === 'owner'
    ? '<div class="ag-msg me">' + esc(m.body) + '</div>'
    : '<div class="ag-msg bot">' + esc(m.body) + ((m.sources || []).length ? '<div class="ag-sources">' + m.sources.map(source).join('') + '</div>' : '') + '</div>';

  AA.personal.tabs.chat = async box => {
    clearTimeout(timer); AA.psync.unwatch();          // the chat looks for its own messages; no other screen may collect them first
    let chatId = null, msgs = [], status = null, confirmed = true, sending = false, asked = 0;
    const waiting = () => msgs.length && msgs[msgs.length - 1].role === 'owner';
    /** A newly paired browser is waiting for the laptop's "hello", which comes after the earlier conversations. While it is within the wait it
     *  says it is getting them; after that it says it could not, keeps the chat usable, and offers Try again. */
    const history = () => asked && Date.now() - asked > AA.personal.timing.historyWaitMs
      ? AA.banner('warn', AA.icon.clock, "Couldn't get your earlier conversations. They are still safe on your laptop.") + '<div class="ag-actions"><button class="btn" id="aghist-retry" type="button">Try again</button></div>'
      : '<div id="aghist">' + AA.banner('priv', AA.icon.lock, 'Getting your earlier conversations from your laptop…') + AA.skeleton(2) + '</div>';
    box.innerHTML = '<div class="ag-chatbar"><button class="btn ghost sm" id="agnew" hidden>New chat</button><button class="btn ghost sm danger-text" id="agdel" hidden aria-label="Delete this conversation">Delete</button><a class="btn ghost sm" href="#/agents/personal/pair">Pairing</a></div>' +
      '<div id="agthread" class="ag-thread" role="log" aria-live="polite" aria-label="Conversation"></div><div id="agwait"></div>' +
      '<div class="ag-sugg" id="agsugg">' + SUGGEST.map((t, i) => '<button class="chip" data-s="' + i + '">' + esc(t) + '</button>').join('') + '</div>' +
      '<form class="ag-compose" id="agform" autocomplete="off"><input id="agmsg" maxlength="1000" placeholder="Ask about your goals, your CV or what to do next…" aria-label="Message your personal agent"><button class="ag-send priv" type="submit" aria-label="Send">&#10148;</button></form><div id="agerr" class="ag-err" role="alert"></div>';

    const draw = () => {
      const th = $('#agthread'); if (!th) return;
      th.innerHTML = msgs.length ? msgs.map(bubble).join('') : '<div class="ag-empty">Ask about your goals, your CV or what to do next.</div>';
      $('#agnew').hidden = $('#agdel').hidden = !chatId;
      const w = $('#agwait'); let html = '';
      if (!confirmed) html += history();
      if (status && !status.has_token) html += AA.banner('warn', AA.icon.lock, 'The personal agent has not been set up on the laptop yet.');
      else if (waiting() && status && !status.online) html += AA.banner('warn', AA.icon.clock, 'Waiting for the agent. Your personal agent needs the office laptop, which is off' + (status.laptop_last_seen ? '. Last online ' + esc(AA.rel(status.laptop_last_seen)) : '') + '.');
      else if (waiting()) html += '<div class="muted small" aria-live="polite">The agent is thinking…</div>';
      w.innerHTML = html;
      th.scrollTop = th.scrollHeight;
    };
    const fail = (e, fallback) => { if (e.step === 'code') return AA.go(); const el = $('#agerr'); if (el) el.textContent = e.offline ? e.message : (e.status ? e.message : fallback); };

    const load = async () => {
      try {
        confirmed = await AA.pstore.confirmed(); asked = await AA.pstore.historyAskedAt();
        chatId = await AA.pstore.latestChat(); msgs = chatId ? await AA.pstore.chat(chatId) : []; draw();
        await refresh(); schedule();
      } catch (e) { const th = $('#agthread'); if (th) th.innerHTML = AA.empty(e.message || "Couldn't open your chat."); }
    };
    const refresh = async () => {
      if (!$('#agthread')) return;
      try {
        const [got, st] = await Promise.all([AA.psync.pull(), AA.psync.status().catch(() => null)]);
        status = st; confirmed = await AA.pstore.confirmed(); asked = await AA.pstore.historyAskedAt();
        // always re-read from this phone's storage: another screen's check may already have collected the reply
        chatId = chatId || await AA.pstore.latestChat(); msgs = chatId ? await AA.pstore.chat(chatId) : []; confirmed = await AA.pstore.confirmed();
      } catch (e) { if (e.step === 'code') AA.go(); }
      draw();
    };
    // While a question is unanswered (or pairing is unconfirmed), look again every few seconds so the answer appears by itself.
    const schedule = () => { clearTimeout(timer); if (waiting() || !confirmed) timer = setTimeout(async () => { if (!$('#agthread') || !location.hash.startsWith('#/agents/personal')) return; if (!document.hidden) await refresh(); schedule(); }, 5000); };

    const send = async text => {
      text = text.trim(); if (sending || !text) return; sending = true; $('#agerr').textContent = '';
      const input = $('#agmsg'); input.value = '';
      try { if (!chatId) chatId = AA.psync.newId(); await AA.psync.ask(chatId, text); msgs = await AA.pstore.chat(chatId); }
      catch (e) { input.value = text; if (!msgs.length) chatId = null; fail(e, 'Not sent. Try again.'); }
      sending = false; draw(); schedule();
    };

    $('#agform').onsubmit = e => { e.preventDefault(); send($('#agmsg').value); };
    $('#agwait').onclick = async e => {                                              // the Try again button is redrawn with the banner, so listen on the box it lives in
      if (!e.target.closest || !e.target.closest('#aghist-retry')) return;
      try { await AA.psync.requestHistory(); asked = await AA.pstore.historyAskedAt(); } catch (err) { fail(err, "Couldn't ask your laptop. Try again."); }
      draw(); schedule();
    };
    box.querySelectorAll('[data-s]').forEach(b => b.onclick = () => send(SUGGEST[+b.dataset.s]));
    $('#agnew').onclick = () => { chatId = null; msgs = []; draw(); $('#agmsg').focus(); };
    $('#agdel').onclick = () => AA.sheet('<h2>Delete this conversation?</h2><p>It will be removed from this browser and from your laptop for good.</p><div class="ag-actions"><button class="btn danger" id="agyes">Delete</button><button class="btn ghost" id="agcancel">Keep</button></div>', s => {
      $('#agcancel', s).onclick = s.close;
      $('#agyes', s).onclick = async () => { try { await AA.psync.deleteChat(chatId); s.close(); chatId = null; msgs = []; draw(); AA.X.toast('Conversation deleted.'); } catch (e) { s.close(); fail(e, "Couldn't delete it. Try again."); } };
    });
    await load();
  };
})();
