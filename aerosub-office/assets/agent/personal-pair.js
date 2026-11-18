/* Agents area: Personal agent > Pair this phone. The code comes from the laptop's own screen (QR or typed). It is turned into a key
 * on this phone and is never sent to the office server. A scanned link carries it after a #, which a browser never sends. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $ } = AA;
  const HERE = '#/agents/personal/pair';
  let inflight = null;                                    // the app may draw this screen twice at start-up; pair only once
  const stale = () => !location.hash.startsWith(HERE);    // the person (or a finished pairing) has moved on

  const run = code => (inflight = AA.psync.pair(code).then(() => ({ ok: true }), e => ({ err: e })));
  const message = e => /not valid/.test(e.message) ? "That code isn't right. Check it against the laptop screen and try again." : (e.offline ? e.message : "Couldn't pair. Try again.");

  /** After a pairing code is used: wait for the laptop to answer with the fresh secret, then open the chat. */
  AA.personal.renderPending = async v => {
    const started = Date.now();
    v.innerHTML = AA.header('Pairing', { back: '#/agents', right: AA.tag('priv', 'Only you') }) +
      AA.banner('priv', AA.icon.lock, 'Finishing the pairing. Your laptop is swapping the one-time code for a secret only this browser and your laptop will know.') +
      '<div id="agpend">' + AA.skeleton(1) + '</div>';
    AA.psync.unwatch();
    while (location.hash.startsWith('#/agents/personal') && !location.hash.startsWith(HERE)) {
      try { await AA.psync.pull(); } catch (e) { if (e.step === 'code') return AA.go(); }
      if (await AA.pstore.key()) { AA.X.toast('Paired. Getting your earlier conversations…'); return AA.renderPersonal(v, ['chat']); }
      if (!(await AA.pstore.pending())) break;
      if (Date.now() - started > 120000) {
        const el = $('#agpend'); if (el) el.innerHTML = AA.empty("Your laptop hasn't answered. Is it on, and is the pairing page still open? A code works once and for 10 minutes.") + '<div class="ag-actions"><a class="btn" href="#/agents/personal/pair">Try again</a></div>';
        return;
      }
      await new Promise(r => setTimeout(r, 3000));
    }
  };

  AA.personal.renderPair = async (v, codeFromLink) => {
    const bar = back => AA.header('Pair this browser', { back, right: AA.tag('priv', 'Only you') });
    if (!AA.pcrypto.supported()) { v.innerHTML = bar('#/agents') + AA.empty('Pairing needs a secure connection (https). Open the office app from its normal https address.'); return; }
    let linkError = '';
    if (codeFromLink) history.replaceState(null, '', HERE);                 // the secret leaves the address bar and history at once
    if (codeFromLink || inflight) {
      v.innerHTML = bar('#/agents') + AA.skeleton(2);
      const r = await (inflight && !codeFromLink ? inflight : run(codeFromLink));
      inflight = null;
      if (r.ok) { if (!stale()) { AA.X.toast('Paired. Getting your earlier conversations…'); location.hash = '#/agents/personal/chat'; } return; }
      linkError = message(r.err);
    }
    if (stale()) return;
    let paired = false; try { paired = !!(await AA.pstore.key()); } catch (e) { v.innerHTML = bar('#/agents') + AA.empty(e.message); return; }
    if (stale()) return;

    v.innerHTML = bar(paired ? '#/agents/personal/chat' : '#/agents') +
      AA.banner('priv', AA.icon.lock, 'Your personal chat is scrambled with a secret that only this browser and your office laptop hold. Pairing sets that up once.') +
      '<div class="card"><ol class="ag-steps"><li>On the office laptop, open <b>http://127.0.0.1:8001/pair</b>, name this browser and press <b>Create a pairing code</b>.</li>' +
      '<li>Scan the code, or type the 32 letters and numbers below.</li></ol>' +
      '<form id="agpair" autocomplete="off"><label class="small muted" for="agcode">Pairing code</label>' +
      '<input id="agcode" class="ag-code" inputmode="text" autocapitalize="characters" spellcheck="false" maxlength="40" placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" aria-describedby="agperr">' +
      '<div id="agperr" class="ag-err" role="alert">' + AA.esc(linkError) + '</div><div class="ag-actions"><button class="btn" type="submit">Pair this browser</button></div></form></div>' +
      (paired ? '<div class="card"><b>This browser is already paired.</b><p class="muted small">Pairing again replaces only this browser\'s secret. Your other browsers are not affected.</p>' +
        '<button class="btn ghost danger-text" id="agforget">Forget this pairing and delete the chat from this browser</button></div>' : '');

    $('#agpair').onsubmit = async e => {
      e.preventDefault(); const err = $('#agperr'); err.textContent = '';
      const r = await run($('#agcode').value); inflight = null;
      if (r.ok) { AA.X.toast('Paired. Getting your earlier conversations…'); location.hash = '#/agents/personal/chat'; } else err.textContent = message(r.err);
    };
    if (paired) $('#agforget').onclick = () => AA.sheet('<h2>Forget this pairing?</h2><p>The Personal chat is removed from this browser only. Your other browsers keep working. To stop this browser for good, also remove it on the laptop.</p><div class="ag-actions"><button class="btn danger" id="agyes">Forget</button><button class="btn ghost" id="agno">Keep</button></div>', s => {
      $('#agno', s).onclick = s.close;
      $('#agyes', s).onclick = async () => { try { await AA.pstore.wipe(); s.close(); AA.X.toast('This phone has forgotten the pairing.'); AA.personal.renderPair(v); } catch { s.close(); } };
    });
  };
})();
