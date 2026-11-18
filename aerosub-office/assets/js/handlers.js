/* Small page-wide handlers that replace inline `onclick=` / `onerror=` attributes, so the site can use a strict
 * Content Security Policy (script-src 'self'). Markup asks for a behaviour with a data- attribute:
 *   data-rm            an <img> that removes itself if it fails to load
 *   data-go="#/path"   a clickable row that opens that page
 *   data-stop          a link inside a clickable row: the click must not also open the row
 *   data-select        a read-only field that selects its text when tapped
 *   data-close-scrim   closes the open dialog when clicked (used on links that leave a dialog)
 * Do not add inline event attributes anywhere: the policy blocks them. */
(() => {
  'use strict';
  // Image load errors do not bubble, so listen while the event travels down.
  document.addEventListener('error', e => { const t = e.target; if (t && t.tagName === 'IMG' && t.hasAttribute('data-rm')) t.remove(); }, true);
  // Runs first (capture) so the row's own click handler never sees a click on the link inside it. The link still opens.
  document.addEventListener('click', e => { if (e.target.closest && e.target.closest('[data-stop]')) e.stopPropagation(); }, true);
  document.addEventListener('click', e => {
    const t = e.target; if (!t.closest) return;
    const sel = t.closest('[data-select]'); if (sel && sel.select) sel.select();
    const sc = t.closest('[data-close-scrim]'); if (sc) { const s = document.querySelector('.scrim'); if (s) s.remove(); }
    const go = t.closest('[data-go]'); if (go && !t.closest('a,button,input,select,textarea') && /^#\//.test(go.dataset.go)) location.hash = go.dataset.go;
  });
})();
