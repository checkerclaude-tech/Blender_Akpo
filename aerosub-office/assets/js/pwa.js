// Install as an app (Android/Chrome prompt, iPhone "Add to Home Screen" guide) and ask people to allow notifications.
// Push messages arrive even when the app is closed, once the person allows them (on iPhone this needs the installed app, iOS 16.4+).
(function () {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, esc } = X, $ = s => document.querySelector(s);
  const lsGet = k => { try { return localStorage.getItem('pwa_' + k); } catch { return null; } };
  const lsSet = (k, v) => { try { localStorage.setItem('pwa_' + k, v); } catch {} };
  const ua = navigator.userAgent || '';
  const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  let deferred = null;
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e; X.pwaStart && X.S.user && showBar(); });
  window.addEventListener('appinstalled', () => { deferred = null; lsSet('installed', '1'); closeBar(); const c = $('#pwa-install'); if (c) c.innerHTML = ''; });

  const canPush = () => 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
  const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(r, c => c.charCodeAt(0)); };
  async function subscribe() {
    const reg = await navigator.serviceWorker.ready; let sub = await reg.pushManager.getSubscription();
    if (!sub) { const { key } = await api('push/key'); sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(key) }); }
    const j = sub.toJSON(); await api('push/subscribe', { method: 'POST', data: { endpoint: j.endpoint, keys: j.keys } }); return true;
  }
  // true when the person ended up with notifications on
  X.enablePush = async function (fromClick) {
    if (!('Notification' in window)) { X.toast('This browser cannot show notifications'); return false; }
    if (isIOS && !standalone()) { X.toast('On iPhone, add the app to your Home Screen first, then turn notifications on from there'); return false; }
    let perm = Notification.permission; if (perm === 'default') perm = await Notification.requestPermission();
    if (perm !== 'granted') { if (fromClick) X.toast(perm === 'denied' ? 'Notifications are blocked: allow them in your browser or phone settings for this site' : 'Notifications were not turned on'); return false; }
    try { if (canPush()) await subscribe(); } catch (e) { /* the in-app alerts still work without server push */ }
    if (fromClick) X.toast('Notifications are on'); return true;
  };

  function closeBar() { const b = $('#pwa-bar'); if (b) b.remove(); }
  function bar(html) { closeBar(); const d = document.createElement('div'); d.id = 'pwa-bar'; d.className = 'pwa-bar'; d.innerHTML = '<img src="assets/icon-192.png" alt=""><div class="t">' + html + '</div><button class="x" aria-label="Close" data-x>&times;</button>'; document.body.appendChild(d); return d; }
  function iosGuide() {
    return '<b>Add Aerosub Office to your Home Screen</b>' + (isSafari ? '' : '<span class="muted">Open this page in <b>Safari</b> first.</span>') + '<ol class="pwa-steps"><li>Tap the <span class="pwa-ico">Share</span> button (square with an arrow) in Safari</li><li>Scroll and tap <b>Add to Home Screen</b></li><li>Tap <b>Add</b>, then open the new Aerosub icon</li></ol>';
  }
  function showBar() {
    if (!X.S.user || standalone() || lsGet('installed') || Date.now() - +(lsGet('later_install') || 0) < 7 * 864e5) return;
    if (deferred) {
      const d = bar('<b>Install Aerosub Office</b>Get it on your home screen: opens full screen, faster, with alerts.<div class="a"><button class="btn sm" data-go>Install</button><button class="btn ghost sm" data-x>Not now</button></div>');
      d.querySelector('[data-go]').onclick = async () => { closeBar(); try { deferred.prompt(); const r = await deferred.userChoice; if (r.outcome !== 'accepted') lsSet('later_install', Date.now()); } catch {} deferred = null; };
      d.querySelectorAll('[data-x]').forEach(x => x.onclick = () => { lsSet('later_install', Date.now()); closeBar(); });
    } else if (isIOS) {
      const d = bar(iosGuide() + '<div class="a"><button class="btn ghost sm" data-x>Got it</button></div>');
      d.querySelectorAll('[data-x]').forEach(x => x.onclick = () => { lsSet('later_install', Date.now()); closeBar(); });
    }
  }
  function askNotify() {
    if (!('Notification' in window) || Notification.permission !== 'default' || lsGet('later_notify') && Date.now() - +lsGet('later_notify') < 3 * 864e5) return false;
    if (isIOS && !standalone()) return false; // iPhone only allows it from the installed app
    const d = bar('<b>Turn on notifications?</b>Get an alert when a task is given to you, a report is shared, or it is your turn in a game, even when the app is closed.<div class="a"><button class="btn sm" data-go>Turn on</button><button class="btn ghost sm" data-x>Later</button></div>');
    d.querySelector('[data-go]').onclick = async () => { closeBar(); await X.enablePush(true); };
    d.querySelectorAll('[data-x]').forEach(x => x.onclick = () => { lsSet('later_notify', Date.now()); closeBar(); });
    return true;
  }
  X.pwaStart = function () {
    if (X.pwaRan) return; X.pwaRan = true;
    // keep this device's subscription fresh if it was already allowed
    if ('Notification' in window && Notification.permission === 'granted' && canPush()) subscribe().catch(() => {});
    navigator.serviceWorker && navigator.serviceWorker.addEventListener('message', e => { if (e.data && e.data.resubscribe) subscribe().catch(() => {}); });
    setTimeout(() => { if (!X.S.user) return; if (standalone() || !(isIOS || deferred)) { if (!askNotify()) showBar(); } else showBar(); }, 3500);
  };
  X.pwaProfile = function () {
    const c = $('#pwa-install'); if (!c) return;
    let h = '';
    if (standalone()) h = '<div class="pwa-card"><b>Installed ✓</b><div class="muted small">You are using Aerosub Office as an app.</div></div>';
    else if (deferred) h = '<div class="pwa-card"><b>Install Aerosub Office</b><div class="muted small" style="margin:4px 0 8px">Add it to your home screen or desktop.</div><button class="btn sm" id="pwa-go">Install app</button></div>';
    else if (isIOS) h = '<div class="pwa-card"><b>Install on iPhone / iPad</b><div class="muted small">' + iosGuide().replace(/^<b>.*?<\/b>/, '') + '</div></div>';
    c.innerHTML = h; const g = $('#pwa-go'); if (g) g.onclick = async () => { try { deferred.prompt(); await deferred.userChoice; } catch {} deferred = null; X.render(); };
    const en = $('#en'); if (en && isIOS && !standalone()) en.insertAdjacentHTML('afterend', '<div class="muted small" style="margin-top:6px">On iPhone, notifications work once the app is added to your Home Screen.</div>');
    if (Notification && Notification.permission === 'granted' && canPush()) {
      api('push/status').then(s => { const card = en ? en.parentNode : null; (card || c).insertAdjacentHTML('beforeend', '<div class="small" style="margin-top:8px"><button class="btn ghost sm" id="pwa-test">Send a test notification</button></div>'); const t = $('#pwa-test'); if (t) t.onclick = async () => { await subscribe().catch(() => {}); const r = await api('push/test', { method: 'POST', data: {} }).catch(() => ({ sent: 0 })); X.toast(r.sent ? 'Test sent' : 'No device is registered yet. Reload and try again.'); }; }).catch(() => {});
    }
  };
})();
