/* Agents area: the authenticator step (design spec A9). The person is already signed in to the office app. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;

  /** Ask the server who this is and whether the area is open. A non-owner learns nothing more than "not the owner". */
  AA.refreshStatus = async () => {
    try {
      const s = await AA.call('agent-auth/status');
      Object.assign(AA.state, { owner: !!s.owner, codeRequired: !!s.code_required, enrolled: !!s.enrolled, unlocked: !!s.unlocked });
    } catch (e) { if (e.offline) throw e; AA.state.owner = false; }
  };
  AA.go = () => AA.X.render();

  AA.renderPrivate = v => {
    v.innerHTML = '<div class="ag-narrow"><div class="card ag-center"><h1 class="ag-title">This site is private</h1><p class="muted">Your account doesn\'t have access.</p>' +
      '<a class="btn" href="#/home">Go to the pipeline</a></div></div>';
  };

  const codeForm = (id, label, hint, button) =>
    '<form id="' + id + '" class="ag-form" autocomplete="off"><label for="' + id + 'c">' + esc(label) + '</label>' +
    '<input id="' + id + 'c" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required aria-describedby="' + id + 'e">' +
    (hint || '') + '<div id="' + id + 'e" class="ag-err" role="alert"></div><button class="btn block" type="submit">' + esc(button) + '</button></form>';

  /** First time: add the key to an authenticator app, prove it works with a code, then save the recovery codes. */
  AA.renderSetup = async v => {
    v.innerHTML = '<div class="ag-narrow">' + AA.skeleton(3) + '</div>';
    let s;
    try { s = await AA.call('agent-auth/setup', { method: 'POST' }); }
    catch (e) { if (e.status === 409) { await AA.refreshStatus(); return AA.go(); } v.innerHTML = '<div class="ag-narrow">' + AA.errorBox('the setup', 'agretry') + '</div>'; $('#agretry').onclick = () => AA.renderSetup(v); return; }
    v.innerHTML = '<div class="ag-narrow"><div class="card"><h1 class="ag-title">Set up your authenticator app</h1>' +
      '<p class="muted">The Agents area asks for a 6-digit code each time you open it. Add this key to an authenticator app first (Google Authenticator, Microsoft Authenticator or similar).</p>' +
      '<div class="ag-key" aria-label="Setup key">' + esc(s.secret.replace(/(.{4})/g, '$1 ').trim()) + '</div>' +
      '<p><a class="btn ghost sm" href="' + esc(s.otpauth_uri) + '">Open in my authenticator app</a></p>' +
      codeForm('agsu', '6-digit code from the app', '', 'Confirm and continue') + '</div></div>';
    $('#agsu').onsubmit = async e => {
      e.preventDefault(); const btn = $('button', e.target), err = $('#agsue');
      btn.disabled = true; btn.textContent = 'Working…'; err.textContent = '';
      try { const r = await AA.call('agent-auth/confirm', { method: 'POST', data: { code: $('#agsuc').value } }); AA.state.unlocked = true; AA.state.enrolled = true; recoveryCodes(v, r.recovery_codes); }
      catch (er) { err.textContent = er.message; btn.disabled = false; btn.textContent = 'Confirm and continue'; }
    };
  };

  const recoveryCodes = (v, codes) => {
    v.innerHTML = '<div class="ag-narrow"><div class="card"><h1 class="ag-title">Save your recovery codes</h1>' +
      '<p class="muted">Each code works once if you lose your phone. They will not be shown again.</p>' +
      '<ul class="ag-codes">' + codes.map(c => '<li>' + esc(c) + '</li>').join('') + '</ul>' +
      '<button class="btn block" id="agsaved">I have saved them. Continue</button></div></div>';
    $('#agsaved').onclick = AA.go;
  };

  /** Normal entry: a code from the app, or one recovery code. */
  AA.renderCode = v => {
    let recovery = false;
    const draw = msg => {
      v.innerHTML = '<div class="ag-narrow"><div class="card"><h1 class="ag-title">' + AA.icon.lock + ' Agents</h1>' +
        (msg ? '<p class="muted">' + esc(msg) + '</p>' : '') +
        codeForm('agcode', recovery ? 'Recovery code' : '6-digit code from your authenticator app', '', 'Continue') +
        '<p class="small"><a href="#" id="agalt">' + (recovery ? 'Use my authenticator app instead' : 'Use a recovery code instead') + '</a></p></div></div>';
      const f = $('#agcode'), inp = $('#agcodec'); inp.focus();
      if (recovery) { inp.inputMode = 'text'; inp.maxLength = 20; }
      $('#agalt').onclick = e => { e.preventDefault(); recovery = !recovery; draw(msg); };
      f.onsubmit = async e => {
        e.preventDefault(); const btn = $('button', f), err = $('#agcodee');
        btn.disabled = true; btn.textContent = 'Working…'; err.textContent = '';
        try { await AA.call('agent-auth/unlock', { method: 'POST', data: { code: inp.value } }); AA.state.unlocked = true; AA.go(); }
        catch (er) { err.textContent = er.message; btn.disabled = false; btn.textContent = 'Continue'; inp.select(); }
      };
    };
    draw(location.hash.split('/').length > 2 ? 'Enter your code to continue' : '');
  };
})();
