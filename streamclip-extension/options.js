const $ = s => document.getElementById(s);
const F = ['quality', 'openaiKey', 'whisperModel', 'language'];
(async () => {
  const s = (await chrome.storage.local.get('settings')).settings || {};
  F.forEach(k => { if (s[k]) $(k).value = s[k]; });
  if (s.chunkSec) $('chunkSec').value = s.chunkSec;
  ['item', 'author', 'text'].forEach(k => $('s_' + k).value = (s.selectors || {})[k] || '');
  $('comments').checked = s.comments !== false; $('captions').checked = s.captions !== false;
})();
$('save').onclick = async () => {
  const s = { chunkSec: Math.min(120, Math.max(10, +$('chunkSec').value || 30)),
    selectors: { item: $('s_item').value.trim(), author: $('s_author').value.trim(), text: $('s_text').value.trim() },
    comments: $('comments').checked, captions: $('captions').checked };
  F.forEach(k => s[k] = $(k).value.trim());
  await chrome.storage.local.set({ settings: s });
  $('ok').textContent = 'Saved ✓'; setTimeout(() => $('ok').textContent = '', 2000);
};
