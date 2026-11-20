// Runs in every frame. While the tab is being recorded it forwards live chat/comments and
// on-page captions (with wall-clock time) to the background, which converts them to
// recording-relative timestamps.
(() => {
  if (window.__streamclip) return;
  window.__streamclip = true;

  const ADAPTERS = [
    { // YouTube live chat (the chat iframe)
      match: () => /youtube\.com\/live_chat/.test(location.href),
      item: 'yt-live-chat-text-message-renderer, yt-live-chat-paid-message-renderer',
      author: '#author-name', text: '#message'
    },
    { // Twitch
      match: () => /(^|\.)twitch\.tv$/.test(location.hostname),
      item: '.chat-line__message, .vod-message',
      author: '.chat-author__display-name, [data-a-target="chat-message-username"]',
      text: '[data-a-target="chat-message-text"], .text-fragment'
    },
    { // Kick
      match: () => /(^|\.)kick\.com$/.test(location.hostname),
      item: '[data-chat-entry], .chat-entry',
      author: '.chat-entry-username, [class*="username"]', text: '.chat-entry-content, [class*="content"]'
    }
  ];
  const CAPTION_SELECTORS = ['.ytp-caption-segment', '.player-timedtext', '.captions-text',
    '.vjs-text-track-cue', '.caption-visual-line', '[data-a-target="player-captions"]'];

  let cfg = null, mo = null, poll = null;
  const seen = new WeakSet();
  const cueSeen = new Set();

  const send = m => { try { chrome.runtime.sendMessage({ target: 'bg', type: 'event', wall: Date.now(), ...m }).catch(() => {}); } catch (_) {} };
  const txt = (root, sel) => sel && root ? [...root.querySelectorAll(sel)].map(n => n.textContent.trim()).filter(Boolean).join(' ') : '';

  function handle(el, a) {
    if (seen.has(el)) return;
    seen.add(el);
    setTimeout(() => { // let the site finish rendering the message
      const text = txt(el, a.text);
      if (text) send({ kind: 'comment', author: txt(el, a.author), text });
    }, 60);
  }

  function startComments() {
    const adapters = ADAPTERS.filter(a => a.match());
    const s = cfg.selectors || {};
    if (s.item && s.text) adapters.push({ item: s.item, author: s.author, text: s.text });
    if (!adapters.length) return;
    const scan = node => {
      if (node.nodeType !== 1) return;
      for (const a of adapters) {
        if (node.matches(a.item)) handle(node, a);
        node.querySelectorAll(a.item).forEach(n => handle(n, a));
      }
    };
    mo = new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(scan)));
    mo.observe(document.documentElement, { childList: true, subtree: true });
    // Messages already on screen are history, not part of this recording.
    for (const a of adapters) document.querySelectorAll(a.item).forEach(n => seen.add(n));
  }

  function startCaptions() {
    let last = '';
    poll = setInterval(() => {
      // 1) real <track> cues (HLS/DASH subtitles, WebVTT, etc.)
      document.querySelectorAll('video').forEach(v => {
        for (const tr of v.textTracks) {
          if (tr.kind !== 'captions' && tr.kind !== 'subtitles') continue;
          if (tr.mode === 'disabled') tr.mode = 'hidden';
          for (const cue of tr.activeCues || []) {
            const text = (cue.text || '').replace(/<[^>]+>/g, '').trim();
            const key = tr.label + cue.startTime + text;
            if (text && !cueSeen.has(key)) { cueSeen.add(key); send({ kind: 'caption', text }); }
          }
        }
      });
      // 2) caption text rendered into the page (YouTube live captions, Netflix-style overlays...)
      const text = CAPTION_SELECTORS.flatMap(s => [...document.querySelectorAll(s)]).map(n => n.textContent.trim())
        .filter(Boolean).join(' ');
      if (text && text !== last) send({ kind: 'caption', text });
      last = text;
    }, 700);
  }

  function begin(c) {
    end();
    cfg = c;
    if (c.opts && c.opts.comments) startComments();
    if (c.opts && c.opts.captions) startCaptions();
  }
  function end() {
    if (mo) mo.disconnect();
    if (poll) clearInterval(poll);
    mo = poll = cfg = null;
  }

  chrome.runtime.onMessage.addListener(m => {
    if (m.target !== 'content') return;
    if (m.type === 'rec-start') begin(m);
    if (m.type === 'rec-stop') end();
  });
  // Page (re)loaded mid-recording: pick the session back up.
  try { chrome.runtime.sendMessage({ target: 'bg', type: 'hello' }).then(r => r && begin(r)).catch(() => {}); } catch (_) {}
})();
