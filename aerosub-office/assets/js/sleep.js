/* Aerosub Office: screen sleep for phones. After a quiet moment the screen shows a clock, the task you are on, your latest notification
 * and a scrolling news bar. It follows the phone when you turn it (portrait or landscape). Touch anywhere to wake it.
 * Phones and small touch screens only. Settings: More > Screen sleep. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const get = (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch { return d; } };
  const put = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
  const isPhone = () => { try { return matchMedia('(pointer: coarse)').matches && Math.min(screen.width, screen.height) <= 640; } catch { return false; } };
  const delay = () => { const v = +get('aero_sleep_min', '2'); return v >= 0 ? v : 2; };      // minutes; 0 = off
  let idleT = 0, clockT = 0, dataT = 0, on = false, el = null;

  const busy = () => /\/(test)$|^#\/games\/\d+/.test(location.hash) || document.hidden || !S.user || !!$('.scrim, .modal');     // never over a test, a live game or an open dialog
  function arm() { clearTimeout(idleT); if (!isPhone() || delay() === 0 || !S.user) return; idleT = setTimeout(() => { if (busy()) return arm(); show(); }, delay() * 60000); }
  const tick = () => { if (!el) return; const d = new Date(); $('.sl-time', el).innerHTML = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + '<small>' + String(d.getSeconds()).padStart(2, '0') + '</small>'; $('.sl-date', el).textContent = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }); };
  async function data() {
    if (!el) return; const [tasks, notes, news] = await Promise.all([api('tasks?assignee=me').catch(() => []), api('notifications').catch(() => []), api('news').catch(() => [])]); if (!el) return;
    const open = (tasks || []).filter(t => !['done', 'canceled', 'transferred', 'deferred'].includes(t.status)), cur = open.find(t => t.status === 'in_progress') || open[0];
    $('.sl-task', el).innerHTML = cur ? '<span class="sl-k">You are on</span><b>' + esc(cur.title) + '</b><span class="sl-s">' + esc(String(cur.status).replace('_', ' ')) + (cur.due_date ? ' · due ' + esc(String(cur.due_date).slice(0, 10)) : '') + '</span>' : '<span class="sl-k">Tasks</span><b>Nothing open. Well done.</b>';
    const unread = (notes || []).filter(n => !n.read_at), n0 = unread[0] || (notes || [])[0];
    $('.sl-note', el).innerHTML = n0 ? '<span class="sl-k">Notifications' + (unread.length ? ' · ' + unread.length + ' new' : '') + '</span><b>' + esc(n0.title) + '</b><span class="sl-s">' + esc((n0.body || '').slice(0, 120)) + '</span>' : '<span class="sl-k">Notifications</span><b>All caught up</b>';
    const txt = (news || []).map(b => b.title + (b.body ? ': ' + String(b.body).replace(/\s+/g, ' ').slice(0, 140) : '')).join('   ●   ');
    $('.sl-news-in', el).textContent = txt || 'Aerosub Office: better data, better insights, better decisions.'; const w = $('.sl-news-in', el); w.style.animationDuration = Math.max(14, Math.round(w.textContent.length / 5)) + 's';
  }
  function show() {
    if (on || !isPhone()) return; on = true; el = document.createElement('div'); el.className = 'sl'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Screen sleep. Touch to wake.');
    el.innerHTML = '<div class="sl-clock"><div class="sl-time"></div><div class="sl-date"></div></div><div class="sl-cards"><div class="sl-card sl-task"></div><div class="sl-card sl-note"></div></div><div class="sl-news" aria-live="off"><div class="sl-news-in"></div></div><div class="sl-hint">Touch to wake</div>';
    document.body.appendChild(el); document.documentElement.classList.add('sl-on'); tick(); clockT = setInterval(tick, 1000); data(); dataT = setInterval(data, 60000);
    try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(l => { el && (el._wl = l); if (!el) l.release(); }).catch(() => {}); } catch {}
    ['pointerdown', 'touchstart', 'keydown'].forEach(ev => el.addEventListener(ev, wake, { once: true }));
  }
  function wake(e) { if (e && e.preventDefault) e.preventDefault(); if (!on) return; on = false; clearInterval(clockT); clearInterval(dataT); try { el._wl && el._wl.release(); } catch {} el.remove(); el = null; document.documentElement.classList.remove('sl-on'); arm(); }
  ['pointerdown', 'touchstart', 'keydown', 'scroll', 'click', 'input'].forEach(ev => document.addEventListener(ev, () => { if (!on) arm(); }, { passive: true, capture: true }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) arm(); });
  addEventListener('hashchange', () => { if (!on) arm(); });
  arm(); setInterval(() => { if (!on && !idleT) arm(); }, 30000);

  /* More > Screen sleep: choose how long to wait, or switch it off; try it now */
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (isPhone()) items.push(['sleep', 'Screen sleep', null]); };
  views.sleep = async () => {
    const v = $('#view'), cur = delay(), opt = (m, l) => '<option value="' + m + '"' + (cur === m ? ' selected' : '') + '>' + l + '</option>';
    v.innerHTML = '<div style="margin-bottom:6px"><a href="#/more" class="small">‹ More</a></div><h1 style="margin-bottom:6px">Screen sleep</h1><div class="muted" style="margin-bottom:14px">When you stop touching the phone for a while, a calm screen shows the time, the task you are on, your latest notification and the news. Touch it to go back.</div>' +
      '<div class="card"><div class="field"><label for="slm">Start after</label><select id="slm">' + opt(0, 'Never (off)') + opt(1, '1 minute') + opt(2, '2 minutes') + opt(5, '5 minutes') + opt(10, '10 minutes') + '</select></div><div class="row"><button class="btn" id="slgo">Show it now</button></div>' + (isPhone() ? '' : '<div class="muted small" style="margin-top:8px">This only runs on phones.</div>') + '</div>';
    $('#slm').onchange = e => { put('aero_sleep_min', e.target.value); arm(); X.toast(+e.target.value ? 'Screen sleep after ' + e.target.value + ' min' : 'Screen sleep is off'); };
    $('#slgo').onclick = () => { if (!isPhone()) return X.toast('Screen sleep runs on phones only'); setTimeout(show, 150); };
  };
})();
