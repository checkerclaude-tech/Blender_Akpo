importScripts('db.js');

const getState = async () => (await chrome.storage.session.get('active')).active || null;
const setState = a => a ? chrome.storage.session.set({ active: a }) : chrome.storage.session.remove('active');
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function ensureOffscreen() {
  if (await chrome.offscreen.hasDocument()) return;
  await chrome.offscreen.createDocument({
    url: 'offscreen.html',
    reasons: ['USER_MEDIA'],
    justification: 'Record the captured tab stream with MediaRecorder'
  });
}
const closeOffscreen = async () => { if (await chrome.offscreen.hasDocument()) await chrome.offscreen.closeDocument(); };

// The offscreen page may not have registered its listener yet right after creation.
async function toOffscreen(msg, tries = 15) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await chrome.runtime.sendMessage({ target: 'offscreen', ...msg });
      if (r) return r;
    } catch (_) { /* not ready */ }
    await sleep(150);
  }
  return { ok: false, error: 'Recorder did not respond' };
}

const tellTab = (tabId, msg) => chrome.tabs.sendMessage(tabId, { target: 'content', ...msg }).catch(() => {});

async function start(m) {
  if (await getState()) return { ok: false, error: 'A recording is already running.' };
  const { settings = {} } = await chrome.storage.local.get('settings');
  const recId = 'rec_' + Date.now().toString(36);
  await DB.put('recordings', {
    id: recId, title: m.title || 'Untitled stream', url: m.url, favIcon: m.favIcon,
    createdAt: Date.now(), status: 'starting', requestedMs: m.durationMs, opts: m.opts
  });
  await ensureOffscreen();
  const r = await toOffscreen({
    type: 'start', recId, streamId: m.streamId, durationMs: m.durationMs,
    settings: { quality: settings.quality || 'medium', chunkSec: settings.chunkSec || 30,
      whisperModel: settings.whisperModel, language: settings.language,
      openaiKey: m.opts.whisper ? settings.openaiKey : '' }
  });
  if (!r.ok) { await DB.remove(recId); await closeOffscreen(); return r; }

  const active = { recId, tabId: m.tabId, startedAt: r.t0, durationMs: m.durationMs, title: m.title,
    opts: m.opts, selectors: settings.selectors || {} };
  await setState(active);
  chrome.action.setBadgeBackgroundColor({ color: '#d93025' });
  chrome.action.setBadgeText({ text: 'REC' });
  if (m.durationMs) chrome.alarms.create('autostop', { when: r.t0 + m.durationMs + 20000 });
  tellTab(m.tabId, { type: 'rec-start', ...active });
  return { ok: true, recId };
}

async function stop() {
  const a = await getState();
  if (!a) return { ok: true };
  const r = await toOffscreen({ type: 'stop' }, 3);
  if (!r.ok) await finished(a.recId, true); // recorder is gone; clean up
  return { ok: true };
}

async function finished(recId, orphaned) {
  const a = await getState();
  if (orphaned) {
    const meta = await DB.get('recordings', recId);
    if (meta && meta.status !== 'done') { meta.status = 'partial'; meta.endedAt = Date.now(); await DB.put('recordings', meta); }
  }
  if (a) tellTab(a.tabId, { type: 'rec-stop' });
  await setState(null);
  chrome.action.setBadgeText({ text: '' });
  chrome.alarms.clear('autostop');
  await closeOffscreen();
}

let lastCaption = '';
async function addEvent(a, type, t, extra) {
  await DB.addEvent({ recId: a.recId, type, t: Math.max(0, t - a.startedAt), ...extra });
}

chrome.runtime.onMessage.addListener((m, sender, respond) => {
  if (m.target !== 'bg') return;
  (async () => {
    const a = await getState();
    switch (m.type) {
      case 'start': return respond(await start(m));
      case 'stop': return respond(await stop());
      case 'status': return respond({ active: a });
      case 'hello': // a (re)loaded content script asks if its tab is being recorded
        return respond(a && sender.tab && sender.tab.id === a.tabId ? { target: 'content', type: 'rec-start', ...a } : null);
      case 'event':
        if (a && sender.tab && sender.tab.id === a.tabId) {
          if (m.kind === 'caption') { if (m.text === lastCaption) return respond({}); lastCaption = m.text; }
          await addEvent(a, m.kind, m.wall, { author: m.author, text: m.text });
        }
        return respond({});
      case 'note':
        if (a) await addEvent(a, 'note', Date.now(), { author: 'You', text: m.text });
        return respond({ ok: !!a });
      case 'finished': await finished(m.recId); return respond({});
      case 'hb': return respond({});
    }
    respond({});
  })();
  return true;
});

chrome.commands.onCommand.addListener(async cmd => {
  const a = await getState();
  if (cmd === 'add-bookmark' && a) await addEvent(a, 'note', Date.now(), { author: 'You', text: '🔖 Bookmark' });
});
chrome.alarms.onAlarm.addListener(async al => { if (al.name === 'autostop') await stop(); });
chrome.tabs.onRemoved.addListener(async id => { const a = await getState(); if (a && a.tabId === id) await stop(); });
