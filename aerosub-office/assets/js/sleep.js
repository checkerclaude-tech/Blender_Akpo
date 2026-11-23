/* Aerosub Office: screen sleep for phones. After a quiet moment the screen shows a clock, the task you are on, your latest notification
 * and a scrolling news bar. It follows the phone when you turn it (portrait or landscape). Touch anywhere to wake it.
 * Each person chooses: how long to wait, a theme, their own wallpaper (a picture or a looping video), the dimming and what to show.
 * Settings: More > Screen sleep. Choices and the wallpaper stay on the phone (private, nothing is uploaded). No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, esc, S, views, toast } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const get = (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch { return d; } };
  const put = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
  const isPhone = () => { try { return matchMedia('(pointer: coarse)').matches && Math.min(screen.width, screen.height) <= 640; } catch { return false; } };

  /* ---------- this person's choices ---------- */
  const THEMES = { ocean: 'Ocean', midnight: 'Midnight', sunrise: 'Sunrise', forest: 'Forest', graphite: 'Graphite', light: 'Daylight' };
  const DELAYS = [[0, 'Never (off)'], [15, '15 seconds'], [30, '30 seconds'], [60, '1 minute'], [120, '2 minutes'], [180, '3 minutes'], [300, '5 minutes'], [600, '10 minutes'], [900, '15 minutes'], [1800, '30 minutes']];
  const DEF = { sec: 120, theme: 'ocean', dim: 35, h12: false, secs: true, task: true, note: true, news: true, wp: 'none' };
  const prefs = () => { let p = {}; try { p = JSON.parse(get('aero_sleep_prefs', '{}')) || {}; } catch {} const old = get('aero_sleep_min', null); if (p.sec == null && old !== null) p.sec = Math.max(0, +old || 0) * 60; return { ...DEF, ...p }; };
  const save = p => put('aero_sleep_prefs', JSON.stringify(p));

  /* ---------- the wallpaper lives in the phone's own storage (IndexedDB) ---------- */
  const idb = () => new Promise((ok, no) => { try { const r = indexedDB.open('aero_sleep', 1); r.onupgradeneeded = () => r.result.createObjectStore('wp'); r.onsuccess = () => ok(r.result); r.onerror = () => no(r.error); } catch (e) { no(e); } });
  const wpGet = async () => { try { const db = await idb(); return await new Promise(ok => { const q = db.transaction('wp').objectStore('wp').get('main'); q.onsuccess = () => ok(q.result || null); q.onerror = () => ok(null); }); } catch { return null; } };
  const wpPut = async v => { const db = await idb(); return new Promise((ok, no) => { const t = db.transaction('wp', 'readwrite'); if (v) t.objectStore('wp').put(v, 'main'); else t.objectStore('wp').delete('main'); t.oncomplete = ok; t.onerror = () => no(t.error); }); };
  const shrinkPic = f => new Promise((ok, no) => {   // a still picture is scaled to the screen so it stays small and sharp enough
    const im = new Image(), u = URL.createObjectURL(f); im.onload = () => { const k = Math.min(1, 1800 / Math.max(im.width, im.height)), c = document.createElement('canvas'); c.width = Math.round(im.width * k); c.height = Math.round(im.height * k); c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); URL.revokeObjectURL(u); c.toBlob(b => b ? ok(b) : no(new Error('That picture could not be used')), 'image/jpeg', 0.86); };
    im.onerror = () => { URL.revokeObjectURL(u); no(new Error('That picture could not be read')); }; im.src = u; });
  async function wallpaperEl(p) {      // the picture or video behind everything, or null
    if (p.wp === 'none') return null; const w = await wpGet(); if (!w || !w.blob) return null; const url = URL.createObjectURL(w.blob);
    if (w.kind === 'video') { const v = document.createElement('video'); v.className = 'sl-bg'; v.muted = true; v.loop = true; v.autoplay = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.setAttribute('muted', ''); v.src = url; v.play().catch(() => {}); v._u = url; return v; }
    const d = document.createElement('div'); d.className = 'sl-bg'; d.style.backgroundImage = 'url("' + url + '")'; d._u = url; return d;
  }

  /* ---------- the sleep screen ---------- */
  let idleT = 0, clockT = 0, dataT = 0, on = false, el = null;
  const delay = () => prefs().sec;
  const busy = () => /\/(test)$|^#\/games\/\d+/.test(location.hash) || document.hidden || !S.user || !!$('.scrim, .modal');     // never over a test, a live game or an open dialog
  let armed = false;
  function arm() { clearTimeout(idleT); armed = false; const d = delay(); if (!isPhone() || d === 0 || !S.user) return; armed = true; idleT = setTimeout(() => { armed = false; if (busy()) return arm(); show(); }, d * 1000); }
  const tick = () => { if (!el) return; const p = prefs(), d = new Date(); $('.sl-time', el).innerHTML = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: !!p.h12 }) + (p.secs ? '<small>' + String(d.getSeconds()).padStart(2, '0') + '</small>' : ''); $('.sl-date', el).textContent = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }); };
  async function data() {
    if (!el) return; const p = prefs(); const [tasks, notes, news] = await Promise.all([p.task ? api('tasks?assignee=me').catch(() => []) : [], p.note ? api('notifications').catch(() => []) : [], p.news ? api('news').catch(() => []) : []]); if (!el) return;
    const open = (tasks || []).filter(t => !['done', 'canceled', 'transferred', 'deferred'].includes(t.status)), cur = open.find(t => t.status === 'in_progress') || open[0];
    $('.sl-task', el).innerHTML = cur ? '<span class="sl-k">You are on</span><b>' + esc(cur.title) + '</b><span class="sl-s">' + esc(String(cur.status).replace('_', ' ')) + (cur.due_date ? ' · due ' + esc(String(cur.due_date).slice(0, 10)) : '') + '</span>' : '<span class="sl-k">Tasks</span><b>Nothing open. Well done.</b>';
    const unread = (notes || []).filter(n => !n.read_at), n0 = unread[0] || (notes || [])[0];
    $('.sl-note', el).innerHTML = n0 ? '<span class="sl-k">Notifications' + (unread.length ? ' · ' + unread.length + ' new' : '') + '</span><b>' + esc(n0.title) + '</b><span class="sl-s">' + esc((n0.body || '').slice(0, 120)) + '</span>' : '<span class="sl-k">Notifications</span><b>All caught up</b>';
    const txt = (news || []).map(b => b.title + (b.body ? ': ' + String(b.body).replace(/\s+/g, ' ').slice(0, 140) : '')).join('   ●   ');
    $('.sl-news-in', el).textContent = txt || 'Aerosub Office: better data, better insights, better decisions.'; const w = $('.sl-news-in', el); w.style.animationDuration = Math.max(14, Math.round(w.textContent.length / 5)) + 's';
  }
  async function show(force) {
    if (on || (!force && !isPhone())) return; on = true; const p = prefs(); el = document.createElement('div'); el.className = 'sl'; el.dataset.t = THEMES[p.theme] ? p.theme : 'ocean'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Screen sleep. Touch to wake.');
    el.innerHTML = '<div class="sl-dim"></div><div class="sl-clock"><div class="sl-time"></div><div class="sl-date"></div></div><div class="sl-cards">' + (p.task ? '<div class="sl-card sl-task"></div>' : '') + (p.note ? '<div class="sl-card sl-note"></div>' : '') + '</div>' + (p.news ? '<div class="sl-news" aria-live="off"><div class="sl-news-in"></div></div>' : '<div></div>') + '<div class="sl-hint">Touch to wake</div>';
    el.querySelector('.sl-dim').style.opacity = String(Math.max(0, Math.min(80, +p.dim)) / 100);
    const bg = await wallpaperEl(p); if (!on) { if (bg && bg._u) URL.revokeObjectURL(bg._u); return; } if (bg) { el.classList.add('has-wp'); el.insertBefore(bg, el.firstChild); }
    document.body.appendChild(el); document.documentElement.classList.add('sl-on'); tick(); clockT = setInterval(tick, 1000); data(); dataT = setInterval(data, 60000);
    try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(l => { el && (el._wl = l); if (!el) l.release(); }).catch(() => {}); } catch {}
    ['pointerdown', 'touchstart', 'keydown'].forEach(ev => el.addEventListener(ev, wake, { once: true }));
  }
  function wake(e) { if (e && e.preventDefault) e.preventDefault(); if (!on) return; on = false; clearInterval(clockT); clearInterval(dataT); try { el._wl && el._wl.release(); } catch {} const bg = el.querySelector('.sl-bg'); if (bg && bg._u) URL.revokeObjectURL(bg._u); el.remove(); el = null; document.documentElement.classList.remove('sl-on'); arm(); }
  ['pointerdown', 'touchstart', 'keydown', 'scroll', 'click', 'input'].forEach(ev => document.addEventListener(ev, () => { if (!on) arm(); }, { passive: true, capture: true }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) arm(); });
  addEventListener('hashchange', () => { if (!on) arm(); });
  arm(); setInterval(() => { if (!on && !armed) arm(); }, 2000);   // also starts the countdown once sign-in has finished, and after a dialog closes

  /* ---------- More > Screen sleep: the person's own settings ---------- */
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (isPhone()) items.push(['sleep', 'Screen sleep', null]); };
  views.sleep = async () => {
    const v = $('#view'); let p = prefs(), wp = await wpGet(), prevUrl = null;
    const chk = (id, on_, label) => '<label class="small" style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" id="' + id + '" style="width:auto"' + (on_ ? ' checked' : '') + '> ' + label + '</label>';
    v.innerHTML = '<div style="margin-bottom:6px"><a href="#/more" class="small">‹ More</a></div><h1 style="margin-bottom:6px">Screen sleep</h1><div class="muted" style="margin-bottom:14px">When you stop touching the phone, a calm screen shows the time, the task you are on, your latest notification and the news. Touch it to go back. Make it yours below.</div>' +
      '<div class="card"><div class="field"><label for="slm">Start after</label><select id="slm">' + DELAYS.map(([s, l]) => '<option value="' + s + '"' + (p.sec === s ? ' selected' : '') + '>' + l + '</option>').join('') + (DELAYS.some(d => d[0] === p.sec) ? '' : '<option value="' + p.sec + '" selected>' + Math.round(p.sec / 60) + ' min</option>') + '</select></div></div>' +
      '<div class="card" style="margin-top:12px"><h3 style="margin:0 0 8px">Theme</h3><div class="sl-themes" id="slt">' + Object.entries(THEMES).map(([k, l]) => '<button type="button" class="sl-sw" data-t="' + k + '" aria-pressed="' + (p.theme === k) + '"><span class="sl-sw-c sl-th-' + k + '"></span><span class="small">' + l + '</span></button>').join('') + '</div></div>' +
      '<div class="card" style="margin-top:12px"><h3 style="margin:0 0 4px">Wallpaper</h3><div class="muted small" style="margin-bottom:8px">Your own picture, or a short video that plays on a loop (it uses more battery). It stays on this phone and is never uploaded.</div>' +
      '<div class="sl-prev" id="slp"></div><div class="row" style="gap:8px;flex-wrap:wrap;margin-top:8px"><label class="btn ghost sm" style="cursor:pointer;margin:0">🖼 Choose a picture<input type="file" id="slfi" accept="image/png,image/jpeg,image/webp,image/gif" hidden></label><label class="btn ghost sm" style="cursor:pointer;margin:0">🎞 Choose a video<input type="file" id="slfv" accept="video/mp4,video/webm,video/quicktime" hidden></label><button class="btn ghost sm" id="slrm" type="button" style="color:var(--bad)">Remove</button></div>' +
      '<div class="field" style="margin-top:12px"><label for="sld">Dim the wallpaper so the words stay easy to read: <b id="sldv">' + p.dim + '%</b></label><input type="range" id="sld" min="0" max="80" value="' + p.dim + '"></div><div class="err" id="er"></div></div>' +
      '<div class="card" style="margin-top:12px"><h3 style="margin:0 0 4px">What to show</h3>' + chk('slc1', p.task, 'The task I am on') + chk('slc2', p.note, 'My latest notification') + chk('slc3', p.news, 'The scrolling news bar') + chk('slc4', p.secs, 'Seconds on the clock') + chk('slc5', p.h12, '12-hour clock (AM/PM)') + '</div>' +
      '<div class="row" style="margin-top:14px"><button class="btn" id="slgo">Preview now</button></div>' + (isPhone() ? '' : '<div class="muted small" style="margin-top:8px">Sleep starts by itself on phones only. You can still preview it here.</div>');
    const paintPrev = async () => { wp = await wpGet(); const box = $('#slp'); if (prevUrl) { URL.revokeObjectURL(prevUrl); prevUrl = null; } if (!wp || !wp.blob) { box.innerHTML = '<div class="muted small" style="padding:18px;text-align:center">No wallpaper. The theme colours are used.</div>'; $('#slrm').style.display = 'none'; return; }
      prevUrl = URL.createObjectURL(wp.blob); box.innerHTML = wp.kind === 'video' ? '<video src="' + prevUrl + '" muted loop autoplay playsinline style="width:100%;max-height:220px;object-fit:cover;border-radius:12px"></video>' : '<img src="' + prevUrl + '" alt="Your wallpaper" style="width:100%;max-height:220px;object-fit:cover;border-radius:12px">'; const vd = box.querySelector('video'); if (vd) vd.play().catch(() => {}); $('#slrm').style.display = ''; };
    paintPrev();
    const upd = o => { p = { ...p, ...o }; save(p); arm(); };
    $('#slm').onchange = e => { upd({ sec: +e.target.value }); toast(+e.target.value ? 'Screen sleep after ' + (DELAYS.find(d => d[0] === +e.target.value) || [0, e.target.value + ' s'])[1] : 'Screen sleep is off'); };
    $$('#slt .sl-sw', v).forEach(b => b.onclick = () => { upd({ theme: b.dataset.t }); $$('#slt .sl-sw', v).forEach(x => x.setAttribute('aria-pressed', x === b)); });
    $('#sld').oninput = e => { $('#sldv').textContent = e.target.value + '%'; }; $('#sld').onchange = e => upd({ dim: +e.target.value });
    [['slc1', 'task'], ['slc2', 'note'], ['slc3', 'news'], ['slc4', 'secs'], ['slc5', 'h12']].forEach(([id, k]) => $('#' + id).onchange = e => upd({ [k]: e.target.checked }));
    $('#slfi').onchange = async e => { const f = e.target.files[0]; e.target.value = ''; if (!f) return; $('#er').textContent = ''; try {
      if (f.size > 25e6) throw new Error('That picture is larger than 25 MB'); const animated = /^image\/gif$/.test(f.type) || /\.(gif)$/i.test(f.name); const blob = animated && f.size <= 8e6 ? f : await shrinkPic(f);
      await wpPut({ kind: 'image', blob, name: f.name }); upd({ wp: 'image' }); await paintPrev(); toast('Wallpaper saved on this phone'); } catch (er) { $('#er').textContent = er.message || 'Could not use that picture'; } };
    $('#slfv').onchange = async e => { const f = e.target.files[0]; e.target.value = ''; if (!f) return; $('#er').textContent = ''; try {
      if (f.size > 60e6) throw new Error('Keep the video under 60 MB (a short loop of 10 to 20 seconds is best)');
      const ok = await new Promise(res => { const t = document.createElement('video'), u = URL.createObjectURL(f); t.preload = 'metadata'; t.onloadedmetadata = () => { URL.revokeObjectURL(u); res(true); }; t.onerror = () => { URL.revokeObjectURL(u); res(false); }; t.src = u; }); if (!ok) throw new Error('This phone cannot play that video. MP4 (H.264) works everywhere.');
      await wpPut({ kind: 'video', blob: f, name: f.name }); upd({ wp: 'video' }); await paintPrev(); toast('Live wallpaper saved on this phone'); } catch (er) { $('#er').textContent = er.message || 'Could not use that video'; } };
    $('#slrm').onclick = async () => { await wpPut(null); upd({ wp: 'none' }); await paintPrev(); toast('Wallpaper removed'); };
    $('#slgo').onclick = () => setTimeout(() => show(true), 150);
    const leave = () => { if (prevUrl) URL.revokeObjectURL(prevUrl); removeEventListener('hashchange', leave); }; addEventListener('hashchange', leave);
  };
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
})();
