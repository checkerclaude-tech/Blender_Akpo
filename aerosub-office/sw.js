// Aerosub Office service worker: makes the app shell load offline. API calls are never cached here
// (the app keeps its own per-user offline cache in the browser).
const V = 'aero-office-v53';
const SHELL = ['./', 'index.html', 'assets/app.css?v=25', 'assets/app.js?v=35', 'assets/icon.svg', 'assets/icon-192.png', 'manifest.webmanifest'];

self.addEventListener('install', e => { e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.pathname.includes('/api/') || /\/(install|cron)\.php$/.test(u.pathname)) return;
  if (u.origin !== location.origin && !/fonts\.(googleapis|gstatic)\.com$/.test(u.hostname)) return;
  if (r.mode === 'navigate' || u.pathname.endsWith('/index.html')) { // pages: network first so updates show immediately, cache only when offline
    e.respondWith(fetch(r).then(res => { if (res.ok) { const copy = res.clone(); caches.open(V).then(c => c.put('index.html', copy)); } return res; }).catch(() => caches.match('index.html')));
    return;
  }
  e.respondWith(
    caches.match(r, { ignoreSearch: false }).then(hit => {
      const net = fetch(r).then(res => { if (res.ok) { const copy = res.clone(); caches.open(V).then(c => c.put(r, copy)); } return res; }).catch(() => hit || (r.mode === 'navigate' ? caches.match('index.html') : undefined));
      return hit || net;
    })
  );
});
self.addEventListener('push', e => { // a message from the server, even while the app is closed
  let d = {}; try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
    if (cs.some(c => c.visibilityState === 'visible' && c.focused)) return; // the app is open and in front: it shows its own alert
    return self.registration.showNotification(d.title || 'Aerosub Office', { body: d.body || '', icon: d.icon || 'assets/icon-192.png', badge: 'assets/icon-192.png', tag: d.tag || undefined, data: { link: d.link || '#/alerts' } });
  }));
});
self.addEventListener('pushsubscriptionchange', e => { e.waitUntil(self.clients.matchAll({ type: 'window' }).then(cs => cs.forEach(c => c.postMessage({ resubscribe: true })))); });
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const link = (e.notification.data && e.notification.data.link) || '#/alerts';
  e.waitUntil(self.clients.matchAll({ type: 'window' }).then(cs => {
    for (const c of cs) { if ('focus' in c) { c.navigate(new URL(link, self.registration.scope).href); return c.focus(); } }
    return self.clients.openWindow(new URL(link, self.registration.scope).href);
  }));
});
