const $ = s => document.querySelector(s);
const RATE = { low: 1e6, medium: 2.5e6, high: 5e6 };
let durationMs = 300000, settings = {}, tab, timer;

const fmt = ms => { const s = Math.max(0, Math.floor(ms / 1000)), h = Math.floor(s / 3600);
  return (h ? h + ':' : '') + String(Math.floor(s / 60) % 60).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
const fmtSize = b => b > 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.round(b / 1e6) + ' MB';

function estimate() {
  const bps = (RATE[settings.quality] || RATE.medium) + 128000;
  $('#est').textContent = durationMs ? `≈ ${fmtSize(bps / 8 * durationMs / 1000)} on disk` : 'Size grows until you press Stop';
}

async function init() {
  [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  settings = (await chrome.storage.local.get('settings')).settings || {};
  $('#tabTitle').textContent = tab?.title || '';
  $('#cComments').checked = settings.comments !== false;
  $('#cCaptions').checked = settings.captions !== false;
  $('#cWhisper').disabled = !settings.openaiKey;
  $('#cWhisper').checked = !!settings.openaiKey && settings.whisper !== false;
  $('#wHint').textContent = settings.openaiKey ? '' : '(add API key in Settings)';
  const chips = [...document.querySelectorAll('#chips button')];
  const pick = b => { chips.forEach(c => c.classList.toggle('on', c === b)); durationMs = +b.dataset.ms; estimate(); };
  chips.forEach(b => b.onclick = () => pick(b));
  pick(chips[0]);
  refresh();
  setInterval(refresh, 1000);
}

async function refresh() {
  const { active } = await chrome.runtime.sendMessage({ target: 'bg', type: 'status' });
  $('#idle').hidden = !!active; $('#busy').hidden = !active;
  if (!active) return;
  $('#recTitle').textContent = active.title || '';
  const el = Date.now() - active.startedAt;
  $('#clock').textContent = fmt(el);
  $('#remain').textContent = active.durationMs ? `${fmt(active.durationMs - el)} remaining` : 'Recording until stopped';
}

$('#start').onclick = async () => {
  $('#err').textContent = '';
  try {
    if (!tab || /^(chrome|edge|about|chrome-extension):/.test(tab.url || '')) throw new Error('Open the stream in a normal web page tab first.');
    const streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tab.id });
    const r = await chrome.runtime.sendMessage({ target: 'bg', type: 'start', tabId: tab.id, streamId, durationMs,
      title: tab.title, url: tab.url, favIcon: tab.favIconUrl,
      opts: { comments: $('#cComments').checked, captions: $('#cCaptions').checked, whisper: $('#cWhisper').checked } });
    if (!r.ok) throw new Error(r.error);
    refresh();
  } catch (e) { $('#err').textContent = e.message; }
};
$('#stop').onclick = async () => { await chrome.runtime.sendMessage({ target: 'bg', type: 'stop' }); setTimeout(refresh, 800); };
const addNote = async () => {
  const text = $('#note').value.trim(); if (!text) return;
  await chrome.runtime.sendMessage({ target: 'bg', type: 'note', text }); $('#note').value = '';
};
$('#addNote').onclick = addNote;
$('#note').onkeydown = e => e.key === 'Enter' && addNote();
init();
