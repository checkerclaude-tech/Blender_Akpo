/* Aerosub Office: the task horn. The person who gave a task presses 📯 Horn on it; the person it is assigned to gets a trumpet blast and a pop-up with
 * the task and the sender's message in bold. They can snooze it or answer: On it, Paused, 😠, Almost done!. The sound is made in the browser (no files). No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._hornLoaded) return; X._hornLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];

  /* ---------------- the trumpet ---------------- */
  let ctx = null, unlocked = false;
  const audio = () => { if (!ctx) { const A = window.AudioContext || window.webkitAudioContext; if (!A) return null; try { ctx = new A(); } catch { return null; } } return ctx; };
  const unlock = () => { const c = audio(); if (!c) return; if (c.state === 'suspended') c.resume().then(() => { unlocked = c.state === 'running'; }).catch(() => {}); else unlocked = true; };
  ['pointerdown', 'keydown', 'touchstart'].forEach(ev => document.addEventListener(ev, unlock, { passive: true }));
  /** One trumpet note: two slightly detuned saw waves through a filter that opens as the note "speaks", with a little vibrato. */
  function note(c, out, freq, t0, dur, vol) {
    const g = c.createGain(), f = c.createBiquadFilter(); f.type = 'lowpass'; f.Q.value = 3.5;
    f.frequency.setValueAtTime(freq * 1.2, t0); f.frequency.exponentialRampToValueAtTime(freq * 7, t0 + 0.07); f.frequency.exponentialRampToValueAtTime(freq * 3.2, t0 + dur);
    g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(vol, t0 + 0.035); g.gain.setValueAtTime(vol, t0 + Math.max(0.05, dur - 0.09)); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    const lfo = c.createOscillator(), lg = c.createGain(); lfo.frequency.value = 5.6; lg.gain.value = freq * 0.006; lfo.connect(lg);
    [0, 6].forEach(cents => { const o = c.createOscillator(); o.type = 'sawtooth'; o.frequency.value = freq; o.detune.value = cents; lg.connect(o.frequency); o.connect(f); o.start(t0); o.stop(t0 + dur + 0.05); });
    const sq = c.createOscillator(); sq.type = 'square'; sq.frequency.value = freq / 2; const sg = c.createGain(); sg.gain.value = 0.25; sq.connect(sg); sg.connect(f); sq.start(t0); sq.stop(t0 + dur + 0.05);
    f.connect(g); g.connect(out); lfo.start(t0); lfo.stop(t0 + dur + 0.05);
  }
  /** The call: da-da-da-DAAA (G4 C5 E5 G5, the last held). Returns false if the browser still blocks sound. */
  function blare(times) {
    const c = audio(); if (!c) return false; if (c.state === 'suspended') { c.resume().catch(() => {}); } if (c.state !== 'running') return false;
    const out = c.createGain(); out.gain.value = 0.9; const comp = c.createDynamicsCompressor(); comp.threshold.value = -14; comp.ratio.value = 6; out.connect(comp); comp.connect(c.destination);
    const seq = [[392, 0, 0.16], [523.25, 0.2, 0.16], [659.25, 0.4, 0.16], [784, 0.6, 0.95]]; let t = c.currentTime + 0.03;
    for (let r = 0; r < (times || 1); r++) { seq.forEach(([f, o, d]) => note(c, out, f, t + o, d, 0.38)); t += 1.9; }
    try { if (navigator.vibrate) navigator.vibrate([250, 100, 250, 100, 600]); } catch {}
    return true;
  }
  X.hornBlare = blare; X.hornNote = note;

  /* ---------------- the pop-up on the person who has the task ---------------- */
  const shown = new Map();   // horn id -> { el, timer, plays }
  const REPLIES = [['on_it', 'On it 💪'], ['paused', 'Paused ⏸️'], ['angry', '😠'], ['almost', 'Almost done! 🏁']];
  function popup(h) {
    if (shown.has(h.id)) return;
    const el = document.createElement('div'); el.className = 'horn-pop'; el.setAttribute('role', 'alertdialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-labelledby', 'hp-t' + h.id);
    el.innerHTML = '<div class="horn-box"><div class="horn-ic" aria-hidden="true">📯</div><div class="horn-from">' + esc(h.from) + ' is blowing the horn' + (h.snoozes ? ' <span class="muted small">(snoozed ' + h.snoozes + '×)</span>' : '') + '</div>' +
      '<div class="horn-msg" id="hp-t' + h.id + '">' + esc(h.message) + '</div><div class="horn-task">Task: <a href="#/tasks/' + h.task_id + '" data-open="1">' + esc(h.task) + '</a></div>' +
      '<div class="horn-btns">' + REPLIES.map(([k, l]) => '<button class="horn-b" data-r="' + k + '">' + l + '</button>').join('') + '</div>' +
      '<div class="horn-snz"><label class="small" for="hp-s' + h.id + '">😴 Snooze</label><select id="hp-s' + h.id + '"><option value="5">5 minutes</option><option value="15" selected>15 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option></select><button class="horn-b ghost" data-snz="1">Snooze</button><button class="horn-b ghost" data-snd="1" title="Play the horn again">🔊</button></div><div class="horn-hint small" hidden>🔇 Tap anywhere to let the horn sound on this screen.</div></div>';
    document.body.appendChild(el); const st = { el, plays: 0, timer: null }; shown.set(h.id, st); document.documentElement.classList.add('horn-on');
    const hint = $('.horn-hint', el); const play = () => { const ok = blare(1); st.plays++; hint.hidden = ok; return ok; };
    play(); st.timer = setInterval(() => { if (st.plays < 6) play(); else clearInterval(st.timer); }, 25000);   // keeps calling every 25 s, up to six times, until answered
    const close = () => { clearInterval(st.timer); el.remove(); shown.delete(h.id); if (!shown.size) document.documentElement.classList.remove('horn-on'); };
    const send = async (data, thanks) => { $$('button', el).forEach(b => b.disabled = true); try { await api('horn/' + h.id + '/reply', { method: 'POST', data }); close(); toast(thanks); } catch (e) { $$('button', el).forEach(b => b.disabled = false); toast(e.message); } };
    $$('[data-r]', el).forEach(b => b.onclick = () => send({ action: b.dataset.r }, 'Sent: ' + b.textContent));
    $('[data-snz]', el).onclick = () => { const m = $('select', el).value; send({ action: 'snooze', minutes: +m }, 'Snoozed for ' + (m === '60' ? '1 hour' : m + ' minutes')); };
    $('[data-snd]', el).onclick = () => { unlock(); play(); };
    $('[data-open]', el).onclick = e => { e.preventDefault(); location.hash = '#/tasks/' + h.task_id; };
    el.addEventListener('pointerdown', () => { if (st.plays && hint && !hint.hidden) { unlock(); setTimeout(play, 60); } }, { once: false });
    const f = $('.horn-b', el); if (f) f.focus();
  }
  async function check() {
    if (!S.user || S.offline || document.hidden && shown.size) return; let r; try { r = await api('horn/inbox'); } catch { return; }
    const ids = new Set(r.horns.map(h => h.id)); shown.forEach((st, id) => { if (!ids.has(id)) { clearInterval(st.timer); st.el.remove(); shown.delete(id); } });   // answered on another screen
    if (!shown.size) document.documentElement.classList.remove('horn-on'); r.horns.slice().reverse().forEach(popup);
  }
  setTimeout(check, 2500); setInterval(check, 10000); document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });

  /* ---------------- the horn button on a task (for the person who gave it) ---------------- */
  const prev = X.onTask;
  X.onTask = (m, t) => {
    if (prev) prev(m, t); if (!S.user) return;
    const me = +S.user.id, isCreator = +t.creator_id === me, isAssignee = +t.assignee_id === me, boss = isCreator || ['super_admin', 'manager'].includes(S.user.role);
    const to = isAssignee && !isCreator ? { id: t.creator_id, name: t.creator_name } : { id: t.assignee_id, name: t.assignee_name };
    if (!(boss || isAssignee) || !to.id || +to.id === me || ['done', 'canceled'].includes(t.status)) return;
    const h3 = $$('h3', m).find(x => /Comments/.test(x.textContent)); if (!h3 || $('#horn-btn', m)) return;
    const b = document.createElement('button'); b.className = 'btn ghost sm'; b.id = 'horn-btn'; b.type = 'button'; b.innerHTML = '📯 Horn'; b.title = 'Blow a horn at ' + (to.name || 'them') + (isAssignee && !isCreator ? ' to get their attention' : ' to remind them'); b.style.margin = '6px 0';
    h3.before(b); b.onclick = () => hornForm(t, to);
  };
  const EMO = ['🔥', '⏰', '🚨', '🙏', '👀', '💪', '📣', '❗'];
  async function hornForm(t, to) {
    const m = modal('<div class="row"><h2 class="grow">📯 Blow the horn</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 10px">It sounds a trumpet and pops up on <b>' + esc((to && to.name) || 'their') + '</b>\'s screen with your message in bold. Task: ' + esc(t.title) + '</div>' +
      '<div class="field"><label for="hm">Your message</label><input id="hm" maxlength="120" value="Please check this task!" autocomplete="off"></div><div class="row" style="gap:6px;flex-wrap:wrap;margin-bottom:8px">' + EMO.map(e => '<button type="button" class="btn ghost sm" data-e="' + e + '" aria-label="Add ' + e + '">' + e + '</button>').join('') + '</div>' +
      '<div class="horn-prev" aria-live="polite"><b id="hv"></b></div><div id="hh" class="small muted" style="margin-top:8px"></div><div class="err" id="er" role="alert"></div><div class="row" style="margin-top:12px"><button class="btn" id="hs">📯 Blow the horn</button></div>');
    const inp = $('#hm', m), pv = () => { $('#hv', m).textContent = inp.value || 'Please check this task!'; }; inp.oninput = pv; pv(); inp.select();
    $$('[data-e]', m).forEach(b => b.onclick = () => { inp.value = (inp.value + ' ' + b.dataset.e).slice(0, 120); pv(); inp.focus(); });
    $('#cx', m).onclick = () => m.close();
    api('horn/task/' + t.id).then(r => { if (r.horns.length) $('#hh', m).innerHTML = '<b>Earlier</b><br>' + r.horns.slice(0, 4).map(x => esc(x.to) + ': “' + esc(x.message) + '” · ' + (x.reply ? esc(x.reply) : x.status === 'snoozed' ? '😴 snoozed' : x.status === 'open' ? 'waiting for an answer' : 'answered')).join('<br>'); }).catch(() => {});
    $('#hs', m).onclick = async () => { unlock(); $('#hs', m).disabled = true; $('#er', m).textContent = '';
      try { await api('horn', { method: 'POST', data: { task_id: t.id, message: inp.value.trim() } }); blare(1); m.close(); toast('📯 Horn blown at ' + ((to && to.name) || 'them') + '. You will be told how they answer.'); }
      catch (e) { $('#er', m).textContent = e.message; $('#hs', m).disabled = false; } };
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#hs', m).click(); } });
  }
})();
