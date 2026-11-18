/* Aerosub Office: team games (Chess, Ludo, Scrabble, Crazy Eights). Lobby, invites, game boards, turn pop-ups, admin settings.
 * The server owns all rules and sends each player only what they may see. Loaded after app.js; no inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const store = { get: (k, d) => { try { const v = localStorage.getItem('ac:' + k); return v === null ? d : v; } catch { return d; } }, set: (k, v) => { try { localStorage.setItem('ac:' + k, v); } catch {} } };
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="10" rx="3"/><path d="M8 11v4M6 13h4M15.5 12h.01M18 14h.01"/><path d="M8 8l1-3h6l1 3"/></svg>';
  const GM = { skin: store.get('gmskin', ''), timer: 0, seen: new Set(JSON.parse(sessionStorage.getItem('gm-seen') || '[]')), watcher: 0 };
  const stop = () => { if (GM.timer) { clearInterval(GM.timer); GM.timer = 0; } };
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';

  /* ---------- menu + turn pop-ups ---------- */
  const prevNav = X.nav;
  X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); if (S.user && S.user.games) { const at = items.findIndex(x => x[0] === 'schedule'); items.splice(at < 0 ? items.length : at + 1, 0, ['games', 'Games', ICON]); startWatcher(); } };
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (S.user && S.user.games) items.unshift(['games', 'Games', null]); };

  function startWatcher() {
    if (GM.watcher) return; GM.watcher = 1; const tick = () => check(); setTimeout(tick, 1500); setInterval(tick, 12000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  }
  async function check() {
    if (!S.user || !S.user.games) return; let r; try { r = await api('games/turns'); } catch { return; } if (!r || !r.items) return;
    const mineOpen = location.hash.match(/^#\/games\/(\d+)/); const turns = r.items.filter(i => i.kind === 'turn').length + r.items.filter(i => i.kind === 'invite').length;
    S.badges = S.badges || {}; S.badges.games = turns; const a = $('.nav a[href="#/games"]'); if (a) { let b = $('.badge', a); if (turns) { if (!b) { b = document.createElement('span'); b.className = 'badge'; a.appendChild(b); } b.textContent = turns; } else if (b) b.remove(); }
    document.title = (turns ? '(' + turns + ') ' : '') + document.title.replace(/^\(\d+\)\s*/, '');
    const fresh = r.items.filter(i => !GM.seen.has(i.key)); if (!fresh.length) return;
    r.items.forEach(i => GM.seen.add(i.key)); try { sessionStorage.setItem('gm-seen', JSON.stringify([...GM.seen].slice(-200))); } catch {}
    for (const i of fresh) {
      if (mineOpen && +mineOpen[1] === i.match_id && !document.hidden) continue; // they are already looking at it
      if (!document.hidden) toast('🎮 ' + i.title);
      if (('Notification' in window) && Notification.permission === 'granted' && (document.hidden || !document.hasFocus())) {
        try { const reg = await navigator.serviceWorker?.getRegistration(); const o = { body: i.kind === 'turn' ? 'It is your move. Click to open the game.' : 'Click to accept or decline.', icon: 'assets/icon-192.png', tag: 'game-' + i.key, data: { link: '#/games/' + i.match_id } }; reg ? reg.showNotification(i.title, o) : new Notification(i.title, o); } catch {}
      }
    }
  }

  /* ---------- wrapper and themes ---------- */
  const SK = () => window.OfficeSkins || { list: [], chips: () => '', current: () => '', apply() {} };
  const root = inner => '<div class="gm-root" ' + (GM.skin ? 'data-skin="' + esc(GM.skin) + '"' : '') + '>' + inner + '</div>';
  const themeBar = () => '<div class="gm-theme"><span class="small muted">Theme</span><div class="sk-chips" id="gmsk">' + SK().chips(GM.skin, 'data-gs') + '</div><span id="gmuse">' + useBtn() + '</span></div>';
  const useBtn = () => GM.skin !== SK().current() ? '<button class="btn sm" id="gmuseb">Use this as my app theme</button>' : (GM.skin ? '<span class="tag done">This is your app theme</span>' : '');
  function wireTheme() {
    $$('[data-gs]').forEach(b => b.onclick = () => { GM.skin = b.dataset.gs; store.set('gmskin', GM.skin); const r = $('.gm-root'); if (GM.skin) r.dataset.skin = GM.skin; else delete r.dataset.skin; $$('#gmsk .sk-chip').forEach(c => c.classList.toggle('on', c === b)); $('#gmuse').innerHTML = useBtn(); wireUse(); });
    wireUse();
  }
  function wireUse() { const b = $('#gmuseb'); if (b) b.onclick = () => { SK().apply(GM.skin); toast('Theme applied to the whole app'); $('#gmuse').innerHTML = useBtn(); }; }

  views.games = async () => {
    stop(); const p = location.hash.split('/'); const v = $('#view'); v.innerHTML = '<div class="empty">Loading…</div>';
    try { if (p[2] === 'settings') return await settingsView(); if (p[2] && /^\d+$/.test(p[2])) return await matchView(+p[2]); return await lobby(); }
    catch (e) { v.innerHTML = '<div class="card empty">' + esc(e.message || 'Games are not available') + '</div>'; }
  };

  /* ---------- lobby ---------- */
  async function lobby() {
    const v = $('#view'); const D = await api('games'); const acc = D.access; const my = D.matches;
    const status = m => m.status === 'waiting' ? (m.mine === 'invited' ? '<span class="tag in_progress">Invited</span>' : '<span class="tag">Waiting for players</span>') : m.status === 'active' ? (m.my_turn ? '<span class="tag done">Your turn</span>' : '<span class="tag">In play</span>') : m.status === 'finished' ? '<span class="tag">Finished</span>' : '<span class="tag">Ended</span>';
    const card = m => '<a class="gm-match" href="#/games/' + m.id + '"><span class="gm-emoji" aria-hidden="true">' + m.emoji + '</span><div class="grow"><b>' + esc(m.name) + '</b><div class="small muted">' + m.players.filter(p => p.status !== 'declined').map(p => esc(p.name) + (p.status === 'invited' ? ' (invited)' : '')).join(' · ') + '</div></div>' + status(m) + '</a>';
    const active = my.filter(m => m.status === 'waiting' || m.status === 'active'), done = my.filter(m => m.status === 'finished' || m.status === 'cancelled');
    const perm = ('Notification' in window) ? Notification.permission : 'unsupported';
    v.innerHTML = root('<div class="gm-head"><div><h1>Games</h1><div class="muted small">Play with your team. ' + (acc.open ? '<span class="tag done">Open now</span>' : '<span class="tag blocked">Closed right now</span>') + ' ' + esc(acc.text) + '</div></div><span class="right row">' + (D.admin ? '<a class="btn ghost" href="#/games/settings">⚙ Settings</a>' : '') + '</span></div>' + themeBar() +
      (perm === 'default' ? '<div class="gm-banner">🔔 Want a pop-up on this computer when it is your turn, so you can carry on with other work? <button class="btn sm" id="gmperm">Turn on pop-ups</button></div>' : perm === 'denied' ? '<div class="gm-banner muted small">Pop-ups are blocked in this browser. Allow notifications for this site to get a ping when it is your turn.</div>' : '') +
      (!acc.open ? '<div class="gm-banner">The games are closed right now. ' + esc(acc.text) + '.' + (D.admin ? ' (You can still play as the Super Admin.)' : '') + '</div>' : '') +
      '<div class="two gm-two"><div class="col"><div class="card"><h2 style="margin-bottom:8px">Your games</h2><div class="list" id="gml">' + (active.length ? active.map(card).join('') : '<div class="empty">Nothing in play. Start a game on the right.</div>') + '</div></div>' + (done.length ? '<div class="card"><h2 style="margin-bottom:8px">Recently finished</h2><div class="list">' + done.map(card).join('') + '</div></div>' : '') + '</div>' +
      '<div class="card" id="gmnew"><h2 style="margin-bottom:8px">Start a game</h2>' + (D.defs.length ? '<div class="gm-tiles">' + D.defs.map(d => '<button type="button" class="gm-tile" data-g="' + d.id + '" data-min="' + d.min + '" data-max="' + d.max + '"><span class="gm-big" aria-hidden="true">' + d.emoji + '</span><b>' + esc(d.name) + '</b><span class="small muted">' + d.min + (d.max > d.min ? ' to ' + d.max : '') + ' players</span></button>').join('') + '</div>' : '<div class="empty">No games are switched on.</div>') +
      '<div id="gmpick" style="display:none"><div class="muted small" id="gmhint" style="margin:10px 0 6px"></div><input id="gmq" placeholder="Search people" aria-label="Search people" style="margin-bottom:8px"><div class="rp-pick" id="gmpl">' + D.people.map(p => '<label class="rp-pickrow" data-name="' + esc(p.name.toLowerCase()) + '"><input type="checkbox" value="' + p.id + '"><span>' + esc(p.name) + '</span><span class="muted small">' + esc(p.department || p.job_title || '') + '</span></label>').join('') + '</div><div class="err" id="er"></div><button class="btn" id="gmgo"' + (acc.open ? '' : ' disabled') + '>Send invitations</button></div></div></div>');
    wireTheme(); let pick = null;
    const pb = $('#gmperm'); if (pb) pb.onclick = async () => { try { await Notification.requestPermission(); } catch {} X.render(); };
    $$('.gm-tile').forEach(t => t.onclick = () => { $$('.gm-tile').forEach(x => x.classList.toggle('on', x === t)); pick = { g: t.dataset.g, min: +t.dataset.min, max: +t.dataset.max }; $('#gmpick').style.display = ''; $('#gmhint').textContent = 'Choose ' + (pick.min === pick.max ? 'exactly ' + (pick.max - 1) + ' person' : 'from ' + (pick.min - 1) + ' to ' + (pick.max - 1) + ' people') + ' to invite. They get a notification.'; });
    const q = $('#gmq'); if (q) q.oninput = e => { const s = e.target.value.toLowerCase(); $$('#gmpl .rp-pickrow').forEach(l => l.style.display = l.dataset.name.includes(s) ? '' : 'none'); };
    const go = $('#gmgo'); if (go) go.onclick = async () => { if (!pick) return; const ids = $$('#gmpl input:checked').map(i => +i.value); go.disabled = true; try { const r = await api('games', { method: 'POST', data: { game: pick.g, invite_ids: ids } }); toast('Invitations sent'); location.hash = '#/games/' + r.id; } catch (er) { $('#er').textContent = er.message; go.disabled = false; } };
    GM.timer = setInterval(async () => { if (!/^#\/games\/?$/.test(location.hash)) return stop(); if (document.hidden) return; try { const n = await api('games'); const sig = JSON.stringify(n.matches.map(m => [m.id, m.version, m.status, m.my_turn, m.mine])); if (sig !== GM.sig) { if (GM.sig) { const l = $('#gml'); if (l) l.innerHTML = n.matches.filter(m => m.status === 'waiting' || m.status === 'active').map(card).join('') || '<div class="empty">Nothing in play.</div>'; } GM.sig = sig; } } catch {} }, 6000);
    GM.sig = JSON.stringify(my.map(m => [m.id, m.version, m.status, m.my_turn, m.mine]));
  }

  /* ---------- one game ---------- */
  async function matchView(id) {
    const v = $('#view'); let M = await api('games/' + id); const draw = () => render(v, M, id, refresh);
    async function refresh(force) { try { const n = await api('games/' + id + (force ? '' : '?v=' + M.version + '&s=' + M.status)); if (n.unchanged) return; M = n; draw(); } catch (e) { if (/not found/i.test(e.message)) { stop(); location.hash = '#/games'; } } }
    draw();
    GM.timer = setInterval(() => { if (location.hash !== '#/games/' + id) return stop(); if (!document.hidden) refresh(false); }, 2500);
  }
  const people = (M, turnSeat) => '<div class="gm-players">' + M.players.filter(p => p.status !== 'declined').map(p => '<span class="gm-pl' + (p.seat !== null && p.seat === turnSeat ? ' turn' : '') + (p.user_id === S.user.id ? ' me' : '') + '"><span class="gm-dot s' + (p.seat ?? 9) + '"></span>' + esc(p.name) + (p.status === 'invited' ? ' <span class="muted small">(invited)</span>' : '') + '</span>').join('') + '</div>';
  function render(v, M, id, refresh) {
    const send = async mv => { try { await api('games/' + id + '/move', { method: 'POST', data: { version: M.version, move: mv } }); await refresh(true); } catch (er) { toast(er.message); await refresh(true); } };
    const head = '<div class="gm-head"><div><a href="#/games" class="small">‹ Games</a><h1>' + M.emoji + ' ' + esc(M.name) + '</h1></div><span class="right row">' + (M.status === 'active' && M.my_turn ? '<span class="tag done gm-turn">Your turn</span>' : '') + ((M.is_creator || S.user.role === 'super_admin') && (M.status === 'waiting' || M.status === 'active') ? '<button class="btn ghost sm" id="gmend">End game</button>' : '') + '</span></div>';
    let body = '';
    if (M.status === 'waiting') {
      const mine = M.mine === 'invited';
      body = '<div class="card"><h2>Waiting for players</h2><div class="muted small" style="margin:4px 0 10px">' + esc(M.desc) + '</div>' + people(M, -1) + (mine ? '<div class="row" style="margin-top:14px"><button class="btn" id="gmy">Accept</button><button class="btn ghost" id="gmn">Decline</button></div>' : '') +
        (M.is_creator ? '<div class="row" style="margin-top:14px"><button class="btn" id="gmstart"' + (M.can_start ? '' : ' disabled') + '>Start the game</button><span class="muted small">' + (M.can_start ? 'You can start now, or wait for the others.' : 'At least ' + M.min + ' players must accept first.') + '</span></div>' : '<div class="muted small" style="margin-top:12px">The host will start the game once everyone has answered.</div>') + '</div>';
    } else if (M.status === 'cancelled') body = '<div class="card empty">This game ended' + (M.result ? ': ' + esc(M.result) : '') + '.</div>';
    else {
      const res = M.view.over ? '<div class="gm-result">' + (M.view.winners.length ? '🏆 ' + M.view.winners.map(s => esc((M.players.find(p => p.seat === s) || {}).name || '?')).join(' and ') + ' won' : 'It ended in a draw') + (M.view.result ? ' <span class="muted">· ' + esc(M.view.result) + '</span>' : '') + '</div>' : '';
      body = res + '<div id="gmboard"></div>';
    }
    v.innerHTML = root(head + themeBar() + body); wireTheme();
    const call = (path, data) => api('games/' + id + '/' + path, { method: 'POST', data: data || {} }).then(() => refresh(true)).catch(er => toast(er.message));
    const y = $('#gmy'); if (y) y.onclick = () => call('respond', { accept: true }); const n = $('#gmn'); if (n) n.onclick = async () => { await call('respond', { accept: false }); location.hash = '#/games'; };
    const st = $('#gmstart'); if (st) st.onclick = () => call('start'); const en = $('#gmend'); if (en) en.onclick = async () => { if (!confirm('End this game for everybody?')) return; await call('cancel'); };
    const box = $('#gmboard'); if (box) ({ chess: chessUI, ludo: ludoUI, scrabble: scrabbleUI, cards: cardsUI })[M.game](box, M, send);
  }

  /* ---------- chess ---------- */
  const GLY = { K: '♚', Q: '♛', R: '♜', B: '♝', N: '♞', P: '♟' };
  function chessUI(box, M, send) {
    const V = M.view, seat = M.seat, flip = seat === 1; let sel = null, promo = null;
    const legal = V.legal || {}; const kingSq = V.check ? V.board.findIndex(p => p === (V.turn === 0 ? 'wK' : 'bK')) : -1; const names = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    const who = s => (M.players.find(p => p.seat === s) || {}).name || '?';
    const cap = c => V.captured[c].map(t => '<span class="gm-cap ' + (c === 'w' ? 'w' : 'b') + '">' + GLY[t] + '︎</span>').join('');
    const draw = () => {
      const order = [...Array(64).keys()]; if (flip) order.reverse(); const tg = sel !== null ? (legal[sel] || []) : [];
      box.innerHTML = '<div class="gm-chess"><div><div class="gm-bname">' + esc(who(flip ? 0 : 1)) + ' <span class="muted">' + (flip ? 'White' : 'Black') + '</span> ' + cap(flip ? 'b' : 'w') + '</div><div class="gm-board" role="grid" aria-label="Chess board">' +
        order.map(i => { const r = Math.floor(i / 8), c = i % 8, p = V.board[i], dark = (r + c) % 2 === 1; const cls = ['gm-sq', dark ? 'd' : 'l', V.last && (V.last[0] === i || V.last[1] === i) ? 'last' : '', i === sel ? 'sel' : '', tg.includes(i) ? (p ? 'cap' : 'tgt') : '', i === kingSq ? 'chk' : ''].join(' ');
          const coord = ((flip ? c === 7 : c === 0) ? '<i class="rk">' + (8 - r) + '</i>' : '') + ((flip ? r === 0 : r === 7) ? '<i class="fl">' + names[c] + '</i>' : '');
          return '<button type="button" class="' + cls + '" data-i="' + i + '" aria-label="' + names[c] + (8 - r) + (p ? ' ' + ({ w: 'white', b: 'black' })[p[0]] + ' ' + ({ K: 'king', Q: 'queen', R: 'rook', B: 'bishop', N: 'knight', P: 'pawn' })[p[1]] : ' empty') + '">' + coord + (p ? '<span class="gm-piece ' + p[0] + '">' + GLY[p[1]] + '︎</span>' : '') + '</button>'; }).join('') + '</div>' +
        '<div class="gm-bname">' + esc(who(flip ? 1 : 0)) + ' <span class="muted">' + (flip ? 'Black' : 'White') + '</span> ' + cap(flip ? 'w' : 'b') + '</div></div>' +
        '<div class="gm-side"><div class="card">' + people(M, V.turn) + '<div class="small" style="margin-top:8px">' + (V.over ? '' : V.check ? '<b style="color:var(--bad)">Check!</b> ' : '') + (V.over ? '' : (V.turn === seat ? 'Your move.' : 'Waiting for ' + esc(who(V.turn)) + '.')) + '</div>' +
        (!V.over && seat >= 0 ? '<div class="row" style="margin-top:10px">' + (V.draw_offer !== null && V.draw_offer !== seat ? '<span class="small">Draw offered</span><button class="btn sm" id="dacc">Accept</button><button class="btn ghost sm" id="ddec">Decline</button>' : V.draw_offer === seat ? '<span class="small muted">You offered a draw</span>' : '<button class="btn ghost sm" id="doff">Offer a draw</button>') + '<button class="btn ghost sm" id="dres" style="color:var(--bad)">Resign</button></div>' : '') + '</div>' +
        '<div class="card"><h3 style="margin-bottom:6px">Moves</h3><ol class="gm-log" id="gmlog">' + V.log.map(m => '<li>' + esc(m) + '</li>').join('') + '</ol></div></div></div>' + (promo ? '<div class="gm-promo" role="dialog" aria-label="Promote to">' + ['Q', 'R', 'B', 'N'].map(t => '<button class="btn" data-pr="' + t + '"><span class="gm-piece ' + (seat === 0 ? 'w' : 'b') + '">' + GLY[t] + '︎</span></button>').join('') + '</div>' : '');
      const lg = $('#gmlog'); if (lg) lg.scrollTop = lg.scrollHeight;
      $$('.gm-sq', box).forEach(b => b.onclick = () => { const i = +b.dataset.i; if (V.over || V.turn !== seat) return;
        if (sel !== null && (legal[sel] || []).includes(i)) { if ((V.promo || []).includes(sel + '-' + i)) { promo = { from: sel, to: i }; draw(); } else { const f = sel; sel = null; send({ from: f, to: i }); } return; }
        sel = legal[i] ? i : null; draw(); });
      $$('[data-pr]', box).forEach(b => b.onclick = () => { const m = { ...promo, promo: b.dataset.pr }; promo = null; sel = null; send(m); });
      const a = (id, mv, ask) => { const e = $('#' + id, box); if (e) e.onclick = () => { if (ask && !confirm(ask)) return; send({ type: mv }); }; }; a('doff', 'offer_draw'); a('dacc', 'accept_draw'); a('ddec', 'decline_draw'); a('dres', 'resign', 'Resign this game?');
    };
    draw();
  }

  /* ---------- ludo ---------- */
  const RING = []; { const seg = (r, c, dr, dc, n) => { for (let i = 0; i < n; i++) RING.push([r + dr * i, c + dc * i]); }; seg(6, 1, 0, 1, 5); seg(5, 6, -1, 0, 6); seg(0, 7, 0, 1, 2); seg(1, 8, 1, 0, 5); seg(6, 9, 0, 1, 6); seg(7, 14, 1, 0, 2); seg(8, 13, 0, -1, 5); seg(9, 8, 1, 0, 6); seg(14, 7, 0, -1, 2); seg(13, 6, -1, 0, 5); seg(8, 5, 0, -1, 6); seg(7, 0, -1, 0, 2); }
  const HOMECOL = [[[7, 1], [7, 2], [7, 3], [7, 4], [7, 5]], [[1, 7], [2, 7], [3, 7], [4, 7], [5, 7]], [[7, 13], [7, 12], [7, 11], [7, 10], [7, 9]], [[13, 7], [12, 7], [11, 7], [10, 7], [9, 7]]];
  const BASES = [[[2, 2], [2, 4], [4, 2], [4, 4]], [[2, 10], [2, 12], [4, 10], [4, 12]], [[10, 10], [10, 12], [12, 10], [12, 12]], [[10, 2], [10, 4], [12, 2], [12, 4]]];
  const LCOL = ['#E53935', '#2E9E4F', '#F2B700', '#1E6FD9'], LNAME = ['Red', 'Green', 'Yellow', 'Blue'], SAFE = [0, 13, 26, 39, 8, 21, 34, 47];
  function ludoUI(box, M, send) {
    const V = M.view, seat = M.seat, Z = 40, who = s => (M.players.find(p => p.seat === s) || {}).name || '?';
    const xy = (color, pos, i) => { if (pos === -1) { const [r, c] = BASES[color][i]; return [(c + .5) * Z, (r + .5) * Z]; } if (pos <= 50) { const [r, c] = RING[(color * 13 + pos) % 52]; return [(c + .5) * Z, (r + .5) * Z]; } if (pos <= 55) { const [r, c] = HOMECOL[color][pos - 51]; return [(c + .5) * Z, (r + .5) * Z]; } return [7.5 * Z + [-0.42, 0.42, 0.42, -0.42][color] * Z, 7.5 * Z + [-0.42, -0.42, 0.42, 0.42][color] * Z]; };
    let g = '<rect width="' + 15 * Z + '" height="' + 15 * Z + '" rx="10" fill="var(--card)" stroke="var(--line)"/>';
    [[0, 0], [0, 9], [9, 9], [9, 0]].forEach(([r, c], k) => { g += '<rect x="' + c * Z + '" y="' + r * Z + '" width="' + 6 * Z + '" height="' + 6 * Z + '" fill="' + LCOL[k] + '" opacity=".9"/><rect x="' + (c + 1) * Z + '" y="' + (r + 1) * Z + '" width="' + 4 * Z + '" height="' + 4 * Z + '" rx="14" fill="#fff" opacity=".92"/>'; BASES[k].forEach(([rr, cc]) => { g += '<circle cx="' + (cc + .5) * Z + '" cy="' + (rr + .5) * Z + '" r="' + Z * .38 + '" fill="' + LCOL[k] + '" opacity=".28"/>'; }); });
    RING.forEach(([r, c], i) => { const start = i % 13 === 0; g += '<rect x="' + c * Z + '" y="' + r * Z + '" width="' + Z + '" height="' + Z + '" fill="' + (start ? LCOL[i / 13] : '#fff') + '" stroke="#9aa3bd" stroke-width="1"/>' + (SAFE.includes(i) ? '<text x="' + (c + .5) * Z + '" y="' + ((r + .5) * Z + 6) + '" text-anchor="middle" font-size="18" fill="' + (start ? '#fff' : '#5a6490') + '">★</text>' : ''); });
    HOMECOL.forEach((cells, k) => cells.forEach(([r, c]) => { g += '<rect x="' + c * Z + '" y="' + r * Z + '" width="' + Z + '" height="' + Z + '" fill="' + LCOL[k] + '" stroke="#fff" stroke-width="1"/>'; }));
    const c0 = 6 * Z, c1 = 9 * Z, mid = 7.5 * Z; [[c0, c0, c1, c0, mid, mid, 0], [c1, c0, c1, c1, mid, mid, 1], [c1, c1, c0, c1, mid, mid, 2], [c0, c1, c0, c0, mid, mid, 3]].forEach(([x1, y1, x2, y2, mx, my, k]) => { g += '<polygon points="' + x1 + ',' + y1 + ' ' + x2 + ',' + y2 + ' ' + mx + ',' + my + '" fill="' + LCOL[[0, 1, 2, 3][k]] + '" stroke="#fff"/>'; });
    const seen = {}; let toks = '';
    Object.keys(V.tokens).forEach(sk => { const s = +sk, color = V.colors[s]; V.tokens[sk].forEach((pos, i) => { const [x, y] = xy(color, pos, i); const key = Math.round(x) + ',' + Math.round(y); const n = seen[key] = (seen[key] || 0) + 1; const ox = (n - 1) % 2 * 9 - (n > 1 ? 4 : 0), oy = Math.floor((n - 1) / 2) * 9;
      const can = s === seat && V.movable.includes(i); toks += '<g class="gm-tok' + (can ? ' can' : '') + '"' + (can ? ' role="button" tabindex="0" aria-label="Move your ' + LNAME[color] + ' token ' + (i + 1) + '" data-t="' + i + '"' : '') + ' transform="translate(' + (x + ox) + ',' + (y + oy) + ')"><circle r="' + Z * .31 + '" fill="' + LCOL[color] + '" stroke="#fff" stroke-width="3"/><circle r="' + Z * .31 + '" fill="none" stroke="#111" stroke-opacity=".35"/><text y="5" text-anchor="middle" font-size="15" font-weight="700" fill="#fff" style="paint-order:stroke;stroke:#000;stroke-width:2px">' + (i + 1) + '</text></g>'; }); });
    const pips = n => ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅'][n] || '';
    const cur = V.turn, myTurn = !V.over && cur === seat;
    box.innerHTML = '<div class="gm-ludo"><div class="gm-lboard"><svg viewBox="0 0 ' + 15 * Z + ' ' + 15 * Z + '" role="img" aria-label="Ludo board">' + g + toks + '</svg></div><div class="gm-side"><div class="card">' + '<div class="gm-players">' + M.players.filter(p => p.status !== 'declined').map(p => '<span class="gm-pl' + (p.seat === cur && !V.over ? ' turn' : '') + (p.user_id === S.user.id ? ' me' : '') + '"><span class="gm-dot" style="background:' + LCOL[V.colors[p.seat]] + '"></span>' + esc(p.name) + '</span>').join('') + '</div>' +
      '<div class="gm-dice" aria-live="polite"><span class="gm-die">' + (V.dice ? pips(V.dice) : '🎲') + '</span><div>' + (V.over ? '' : myTurn ? (V.phase === 'roll' ? 'Throw the dice' : (V.movable.length > 1 ? 'Choose a token to move' : 'Move your token')) : 'Waiting for ' + esc(who(cur))) + (V.last && V.last.note ? '<div class="small muted">' + esc(V.last.note) + '</div>' : '') + '</div></div>' +
      (myTurn && V.phase === 'roll' ? '<button class="btn" id="roll" style="width:100%">Throw the dice</button>' : '') + (myTurn && V.phase === 'move' && V.movable.length === 1 ? '<button class="btn" id="mv1" style="width:100%">Move token ' + (V.movable[0] + 1) + '</button>' : '') + '</div><div class="card"><h3 style="margin-bottom:6px">What happened</h3><ol class="gm-log" id="gmlog">' + V.log.map(m => '<li>' + esc(m) + '</li>').join('') + '</ol></div></div></div>';
    const lg = $('#gmlog'); if (lg) lg.scrollTop = lg.scrollHeight; const r = $('#roll'); if (r) r.onclick = () => { r.disabled = true; send({ type: 'roll' }); }; const m1 = $('#mv1'); if (m1) m1.onclick = () => send({ type: 'move', token: V.movable[0] });
    $$('.gm-tok.can', box).forEach(t => { const go = () => send({ type: 'move', token: +t.dataset.t }); t.onclick = go; t.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }; });
  }

  /* ---------- cards (Crazy Eights) ---------- */
  const SUIT = { S: ['♠', false, 'Spades'], H: ['♥', true, 'Hearts'], D: ['♦', true, 'Diamonds'], C: ['♣', false, 'Clubs'] };
  const face = c => '<span class="gm-cardface' + (SUIT[c[1]][1] ? ' red' : '') + '"><b>' + (c[0] === 'T' ? '10' : c[0]) + '</b><span>' + SUIT[c[1]][0] + '</span></span>';
  function cardsUI(box, M, send) {
    const V = M.view, seat = M.seat, who = s => (M.players.find(p => p.seat === s) || {}).name || '?', myTurn = !V.over && V.turn === seat;
    box.innerHTML = '<div class="gm-cards"><div class="card"><div class="gm-opp">' + M.players.filter(p => p.status !== 'declined' && p.seat !== seat).map(p => '<div class="gm-pl' + (p.seat === V.turn && !V.over ? ' turn' : '') + '"><b>' + esc(p.name) + '</b><span class="small muted">' + V.counts[p.seat] + ' card' + (V.counts[p.seat] === 1 ? '' : 's') + '</span><span class="gm-fan">' + '<i></i>'.repeat(Math.min(V.counts[p.seat], 8)) + '</span></div>').join('') + '</div>' +
      '<div class="gm-table"><button type="button" class="gm-pile stock' + (V.can_draw ? ' can' : '') + '" id="stock" aria-label="Draw a card"' + (V.can_draw ? '' : ' disabled') + '><span>' + V.stock + '</span><small>draw</small></button><div class="gm-pile top">' + face(V.top) + '</div><div class="gm-suit' + (SUIT[V.suit][1] ? ' red' : '') + '" title="Current suit: ' + SUIT[V.suit][2] + '"><span>' + SUIT[V.suit][0] + '</span><small>suit</small></div></div>' +
      '<div class="small" style="text-align:center;margin:6px 0 10px">' + (V.over ? '' : myTurn ? (V.can_draw ? 'You have nothing to play: draw a card.' : V.drawn ? 'You drew a card that fits: play it or pass.' : 'Play a card that matches the suit or number. An 8 is wild.') : 'Waiting for ' + esc(who(V.turn)) + '.') + '</div>' +
      '<div class="gm-hand" role="group" aria-label="Your cards">' + (seat < 0 ? '<span class="muted">You are watching.</span>' : V.hand.map(c => { const ok = myTurn && V.playable.includes(c); return '<button type="button" class="gm-card' + (ok ? ' ok' : '') + (SUIT[c[1]][1] ? ' red' : '') + '" data-c="' + c + '"' + (ok ? '' : ' disabled') + ' aria-label="' + (c[0] === 'T' ? '10' : c[0]) + ' of ' + SUIT[c[1]][2] + '">' + face(c) + '</button>'; }).join('')) + '</div>' +
      (myTurn && V.drawn ? '<div class="row" style="justify-content:center;margin-top:10px"><button class="btn ghost" id="pass">Pass</button></div>' : '') + (V.over && V.hands ? '<div class="small muted" style="margin-top:10px">Cards left: ' + M.players.filter(p => p.seat !== null).map(p => esc(p.name) + ' ' + (V.hands[p.seat] || []).length).join(' · ') + '</div>' : '') + '</div><div class="card"><h3 style="margin-bottom:6px">What happened</h3><ol class="gm-log" id="gmlog">' + V.log.map(m => '<li>' + esc(m) + '</li>').join('') + '</ol></div></div>';
    const lg = $('#gmlog'); if (lg) lg.scrollTop = lg.scrollHeight; const st = $('#stock'); if (st) st.onclick = () => send({ type: 'draw' }); const ps = $('#pass'); if (ps) ps.onclick = () => send({ type: 'pass' });
    $$('.gm-card.ok', box).forEach(b => b.onclick = () => { const c = b.dataset.c; if (c[0] !== '8') return send({ type: 'play', card: c });
      const m = modal('<h2>Choose a suit for your 8</h2><div class="row" style="margin-top:12px">' + Object.entries(SUIT).map(([k, s]) => '<button class="btn ghost gm-suitbtn' + (s[1] ? ' red' : '') + '" data-s="' + k + '" aria-label="' + s[2] + '">' + s[0] + '</button>').join('') + '</div>'); $$('[data-s]', m).forEach(x => x.onclick = () => { m.close(); send({ type: 'play', card: c, suit: x.dataset.s }); }); });
  }

  /* ---------- scrabble ---------- */
  function scrabbleUI(box, M, send) {
    const V = M.view, seat = M.seat, who = s => (M.players.find(p => p.seat === s) || {}).name || '?', myTurn = !V.over && V.turn === seat && !V.challenge, vals = V.values;
    const prem = V.premium || {}; let sel = null, pend = {}, order = V.rack.map((_, i) => i);
    const lab = { TW: '3W', DW: '2W', TL: '3L', DL: '2L' };
    const used = () => new Set(Object.values(pend).map(p => p.ri));
    const draw = () => {
      const u = used(); const rack = order.filter(i => !u.has(i)); const lastCells = new Set((V.last && V.last.cells) || []);
      let g = ''; for (let i = 0; i < 225; i++) { const ch = V.board[i], p = pend[i], pr = prem[i]; const letter = p ? p.l : ch; const blank = p ? p.blank : (ch && ch === ch.toLowerCase());
        g += '<button type="button" class="gm-cell' + (pr ? ' ' + pr : '') + (i === 112 && !ch ? ' star' : '') + (letter ? ' has' : '') + (p ? ' pend' : '') + (lastCells.has(i) ? ' lastp' : '') + '" data-i="' + i + '" aria-label="Row ' + (Math.floor(i / 15) + 1) + ' column ' + (i % 15 + 1) + (letter ? ', ' + letter.toUpperCase() : (pr ? ', ' + lab[pr] : ''))  + '">' + (letter ? '<span class="gm-tl' + (blank ? ' blank' : '') + '">' + letter.toUpperCase() + '<sub>' + (blank ? 0 : vals[letter.toUpperCase()] || 0) + '</sub></span>' : (i === 112 ? '★' : (pr ? '<small>' + lab[pr] + '</small>' : ''))) + '</button>'; }
      box.innerHTML = '<div class="gm-scr"><div><div class="gm-sboard" role="grid" aria-label="Scrabble board">' + g + '</div>' +
        '<div class="gm-rack" role="group" aria-label="Your tiles">' + (seat < 0 ? '<span class="muted">You are watching.</span>' : rack.map(i => { const t = V.rack[i]; return '<button type="button" class="gm-tile-r' + (sel === i ? ' sel' : '') + '" data-r="' + i + '" aria-label="Tile ' + (t === '?' ? 'blank' : t) + '"' + (myTurn ? '' : ' disabled') + '><span class="gm-tl' + (t === '?' ? ' blank' : '') + '">' + (t === '?' ? '' : t) + '<sub>' + (vals[t] ?? 0) + '</sub></span></button>'; }).join('')) + '</div>' +
        (myTurn ? '<div class="row gm-acts"><button class="btn" id="play"' + (Object.keys(pend).length ? '' : ' disabled') + '>Play word</button><button class="btn ghost" id="recall"' + (Object.keys(pend).length ? '' : ' disabled') + '>Recall tiles</button><button class="btn ghost" id="shuf">Shuffle</button><button class="btn ghost" id="exch"' + (V.bag >= 7 ? '' : ' disabled') + '>Exchange…</button><button class="btn ghost" id="pass">Pass</button></div>' : '') +
        (V.can_challenge ? '<div class="gm-banner">Think a word is not valid? <button class="btn sm" id="chal">Challenge ' + esc(V.last.words.join(', ')) + '</button></div>' : '') +
        (V.challenge ? '<div class="gm-banner">' + (V.turn === seat ? '<b>' + esc(who(V.challenge.by)) + '</b> challenges <b>' + esc(V.challenge.words.join(', ')) + '</b>. <button class="btn sm" id="back">Take it back</button> <button class="btn ghost sm" id="keep">Keep it</button> <span class="small muted">(keeping a word that was challenged wrongly costs them their turn)</span>' : 'Waiting for ' + esc(who(V.challenge.on)) + ' to answer the challenge.') + '</div>' : '') + '</div>' +
        '<div class="gm-side"><div class="card"><div class="gm-score">' + M.players.filter(p => p.status !== 'declined').map(p => '<div class="gm-pl' + (p.seat === V.turn && !V.over ? ' turn' : '') + (p.user_id === S.user.id ? ' me' : '') + '"><span>' + esc(p.name) + '</span><b>' + V.scores[p.seat] + '</b></div>').join('') + '</div><div class="small muted" style="margin-top:8px">' + V.bag + ' tiles in the bag · ' + (V.over ? '' : myTurn ? 'Your move.' : V.challenge ? '' : 'Waiting for ' + esc(who(V.turn)) + '.') + '</div>' +
        (V.last && V.last.type === 'play' ? '<div class="small" style="margin-top:8px">Last: <b>' + esc(V.last.words.join(', ')) + '</b> for ' + V.last.score + (V.last.bingo ? ' (+50 all seven tiles)' : '') + '</div>' : '') + '<div class="small muted" style="margin-top:8px">Words are not checked automatically. If you doubt one, challenge it.</div></div><div class="card"><h3 style="margin-bottom:6px">What happened</h3><ol class="gm-log" id="gmlog">' + V.log.map(m => '<li>' + esc(m) + '</li>').join('') + '</ol></div></div></div>';
      const lg = $('#gmlog'); if (lg) lg.scrollTop = lg.scrollHeight;
      $$('.gm-tile-r', box).forEach(b => b.onclick = () => { sel = sel === +b.dataset.r ? null : +b.dataset.r; draw(); });
      $$('.gm-cell', box).forEach(b => b.onclick = () => { if (!myTurn) return; const i = +b.dataset.i;
        if (pend[i]) { delete pend[i]; draw(); return; } if (V.board[i] || sel === null) return; const t = V.rack[sel]; let l = t, blank = false;
        if (t === '?') { const a = (prompt('Which letter should the blank be? (A to Z)') || '').trim().toUpperCase(); if (!/^[A-Z]$/.test(a)) return; l = a; blank = true; }
        pend[i] = { l, blank, ri: sel }; sel = null; draw(); });
      const bind = (id, fn) => { const e = $('#' + id, box); if (e) e.onclick = fn; };
      bind('play', () => send({ type: 'play', tiles: Object.entries(pend).map(([i, p]) => ({ r: Math.floor(i / 15), c: i % 15, l: p.l, blank: p.blank })) })); bind('recall', () => { pend = {}; sel = null; draw(); });
      bind('shuf', () => { order = order.slice().sort(() => Math.random() - .5); draw(); }); bind('pass', () => { if (confirm('Pass your turn?')) send({ type: 'pass' }); }); bind('chal', () => send({ type: 'challenge' }));
      bind('back', () => send({ type: 'answer', take_back: true })); bind('keep', () => send({ type: 'answer', take_back: false }));
      bind('exch', () => { const m = modal('<h2>Exchange tiles</h2><div class="muted small" style="margin:4px 0 10px">Choose the tiles to swap. This uses your turn.</div><div class="gm-rack" id="xr">' + V.rack.map((t, i) => '<button type="button" class="gm-tile-r" data-x="' + i + '"><span class="gm-tl' + (t === '?' ? ' blank' : '') + '">' + (t === '?' ? '' : t) + '<sub>' + (vals[t] ?? 0) + '</sub></span></button>').join('') + '</div><div class="row" style="margin-top:12px"><button class="btn" id="xg">Exchange</button><button class="btn ghost" id="xc">Cancel</button></div>');
        $$('[data-x]', m).forEach(b => b.onclick = () => b.classList.toggle('sel')); $('#xc', m).onclick = () => m.close(); $('#xg', m).onclick = () => { const l = $$('[data-x].sel', m).map(b => V.rack[+b.dataset.x]); if (!l.length) return; m.close(); send({ type: 'exchange', letters: l }); }; });
    };
    draw();
  }

  /* ---------- admin settings ---------- */
  async function settingsView() {
    if (S.user.role !== 'super_admin') { location.hash = '#/games'; return; }
    const v = $('#view'); const D = await api('games/settings'); await X.loadRefs(); const s = D.settings, sel = new Set(D.user_ids), days = new Set(String(s.days).split(',').map(Number)); const enabled = new Set(String(s.games).split(','));
    const users = S.users.filter(u => +u.active === 1 && u.role !== 'super_admin'); let preset = s.preset;
    v.innerHTML = '<div class="row" style="margin-bottom:8px"><a href="#/games" class="small">‹ Games</a></div><div class="top"><div><h1>Games settings</h1><div class="muted">Right now the games are ' + (D.open_now ? '<b style="color:var(--ok)">open</b>' : '<b style="color:var(--bad)">closed</b>') + ' for staff (server time ' + esc(D.now) + '). You can always play.</div></div></div>' +
      '<form class="card" id="gf"><h2 style="margin-bottom:8px">Who sees the Games tab</h2><div class="cr-checks"><label><input type="radio" name="visibility" value="none"' + (s.visibility === 'none' ? ' checked' : '') + '> Hidden: only you see it</label><label><input type="radio" name="visibility" value="all"' + (s.visibility === 'all' ? ' checked' : '') + '> Shown to everyone</label><label><input type="radio" name="visibility" value="selected"' + (s.visibility === 'selected' ? ' checked' : '') + '> Shown only to the people I choose</label></div>' +
      '<div id="gsel" style="' + (s.visibility === 'selected' ? '' : 'display:none') + '"><input id="gq" placeholder="Search people" aria-label="Search people" style="margin:8px 0"><div class="rp-pick" id="gl">' + users.map(u => '<label class="rp-pickrow" data-name="' + esc(u.name.toLowerCase()) + '"><input type="checkbox" value="' + u.id + '"' + (sel.has(+u.id) ? ' checked' : '') + '><span>' + esc(u.name) + '</span><span class="muted small">' + esc(u.department || u.job_title || '') + '</span></label>').join('') + '</div></div>' +
      '<h2 style="margin:18px 0 8px">When the games are open</h2><div class="chips" id="gpre">' + [['always', 'Always'], ['midday', 'Midday (12:00 to 14:00)'], ['close', 'Close of work (17:00 to 22:00)'], ['custom', 'Pick the times']].map(([k, l]) => '<span class="chip' + (preset === k ? ' on' : '') + '" data-p="' + k + '" role="button" tabindex="0">' + l + '</span>').join('') + '</div>' +
      '<div id="gwin" style="' + (preset === 'always' ? 'display:none' : '') + '"><div class="grid2" style="margin-top:10px"><div class="field"><label>Opens at</label><input type="time" name="start_time" value="' + esc(s.start_time) + '"></div><div class="field"><label>Closes at</label><input type="time" name="end_time" value="' + esc(s.end_time) + '"></div></div><div class="chips">' + [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']].map(([d, l]) => '<label class="chip" style="cursor:pointer"><input type="checkbox" data-d="' + d + '" style="width:auto"' + (days.has(d) ? ' checked' : '') + '> ' + l + '</label>').join('') + '</div><div class="muted small" style="margin-top:6px">Outside these times staff can look at the lobby but cannot start games or move. Games they are in simply wait.</div></div>' +
      '<h2 style="margin:18px 0 8px">Games available</h2><div class="cr-checks">' + Object.entries(D.defs).map(([k, d]) => '<label><input type="checkbox" data-g="' + k + '"' + (enabled.has(k) ? ' checked' : '') + '> ' + d.emoji + ' ' + esc(d.name) + ' <span class="muted small">(' + d.min + (d.max > d.min ? ' to ' + d.max : '') + ' players)</span></label>').join('') + '</div><div class="err" id="er"></div><div class="row" style="margin-top:12px"><button class="btn">Save settings</button></div></form>';
    const f = $('#gf'); $$('[name=visibility]', f).forEach(r => r.onchange = () => { $('#gsel').style.display = f.visibility.value === 'selected' ? '' : 'none'; });
    $('#gq').oninput = e => { const q = e.target.value.toLowerCase(); $$('#gl .rp-pickrow').forEach(l => l.style.display = l.dataset.name.includes(q) ? '' : 'none'); };
    const PRE = { midday: ['12:00', '14:00'], close: ['17:00', '22:00'] };
    $$('[data-p]', f).forEach(c => c.onclick = () => { preset = c.dataset.p; $$('[data-p]', f).forEach(x => x.classList.toggle('on', x === c)); $('#gwin').style.display = preset === 'always' ? 'none' : ''; if (PRE[preset]) { f.start_time.value = PRE[preset][0]; f.end_time.value = PRE[preset][1]; } });
    f.onsubmit = async e => { e.preventDefault(); const data = { visibility: f.visibility.value, mode: preset === 'always' ? 'always' : 'window', preset, start_time: f.start_time.value, end_time: f.end_time.value, days: $$('[data-d]:checked', f).map(i => +i.dataset.d), games: $$('[data-g]:checked', f).map(i => i.dataset.g), user_ids: $$('#gl input:checked', f).map(i => +i.value) };
      if (data.mode === 'window' && !data.days.length) { $('#er').textContent = 'Pick at least one day.'; return; } try { await api('games/settings', { method: 'PATCH', data }); toast('Saved'); X.render(); } catch (er) { $('#er').textContent = er.message; } };
  }
})();
