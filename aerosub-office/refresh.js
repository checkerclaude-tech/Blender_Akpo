// belt and braces for browsers that ignore the Clear-Site-Data header: remove the service worker and saved copies ourselves, then open the app
(async () => {
  try { const rs = await navigator.serviceWorker.getRegistrations(); await Promise.all(rs.map(r => r.unregister())); } catch (e) {}
  try { const ks = await caches.keys(); await Promise.all(ks.map(k => caches.delete(k))); } catch (e) {}
  setTimeout(() => { location.replace('index.html?fresh=' + Date.now() + '#/version'); }, 1200);
})();
