/* Personal agent: moving encrypted messages between this phone and the laptop through the office server's mailbox.
 * No screens in this file. The server sees only envelopes. Protocol: agent-box/personal/app/service.py. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { pcrypto: C, pstore: S } = AA;
  const uid = () => (crypto.randomUUID ? crypto.randomUUID() : C.b64u(crypto.getRandomValues(new Uint8Array(16))));

  const P = AA.psync = {
    newId: uid,

    /** Pair using the one-time code. The code only opens the first exchange: the laptop answers inside it with a fresh secret and this
     *  browser's own tag (a "rekey"), which pull() then adopts. Until then the code's key is held as "pending", and the old key (if any) keeps
     *  working. The answer waits in a mailbox named by a tag derived from the same code, so no other page can collect or delete it. */
    async pair(code) {
      const secret = C.codeToSecret(code), key = await C.deriveKey(secret);
      await S.setPending(key, await C.pairingTag(secret));
      await AA.call('agent-owner/personal/send', { method: 'POST', data: { envelope: await C.seal(key, C.P2L, { t: 'sync' }) } });
    },

    /** Everything sent carries this browser's own tag, so the laptop knows which browser it came from. The phone paired before tags existed has
     *  none and keeps sending the old format. */
    async post(message) {
      const key = await S.key(); if (!key) throw new Error('Pair this browser first');
      await AA.call('agent-owner/personal/send', { method: 'POST', data: { envelope: await C.seal(key, C.P2L, message, undefined, (await S.tag()) || undefined) } });
    },

    /** Ask the laptop for the earlier conversations again (the Try again button). */
    async requestHistory() { await S.askedHistory(); await P.post({ t: 'sync' }); },

    /** Send a question. It is kept on this phone straight away, then handed to the mailbox. */
    async ask(chatId, text) {
      const m = { t: 'msg', chat: chatId, id: uid(), ts: Date.now(), body: text };
      await P.post(m);
      await S.add({ ...m, role: 'owner' });
      return m;
    },

    async deleteChat(chatId) {
      await P.post({ t: 'delete', chat: chatId });       // if the laptop cannot be told, nothing is deleted here either
      await S.removeChat(chatId);
    },

    /** Collect whatever the laptop has sent, store it, and let the server delete its copy. Returns what changed.
     *  Each mailbox is asked for by tag: this browser's own, and while pairing is open the pairing mailbox (and the old untagged one, for a
     *  laptop that has not been upgraded). Only messages this browser could actually open are acknowledged (deleted on the server): a
     *  message meant for another browser is left exactly where it is. */
    async pull() {
      const key = await S.key(), tag = await S.tag(), pending = await S.pending(), pairTag = await S.pendingTag();
      const out = { added: 0, agent: 0, hello: false, deleted: 0, types: new Set() };
      if (!key && !pending) return out;
      const boxes = new Map();                                                                 // mailbox tag ('' = the old untagged box) -> keys to try
      const watch = (box, k, isPending) => boxes.set(box, (boxes.get(box) || []).concat([[k, isPending]]));
      if (key) watch(tag || '', key, false);
      if (pending) { if (pairTag) watch(pairTag, pending, true); watch('', pending, true); }
      let removed = false;
      for (const [box, tries] of boxes) {
        const { messages } = await AA.call('agent-owner/personal/inbox' + (box ? '?tag=' + encodeURIComponent(box) : ''));
        const mine = [];
        for (const item of messages) {
          let m, viaPending = false;
          for (const [k, isPending] of tries) {
            if (m) break;
            try { m = await C.open(k, C.L2P, item.envelope); viaPending = isPending; } catch { /* not this key */ }
          }
          if (!m) continue;                                                                       // not for this browser: left alone, never deleted
          mine.push(item.id);
          if (!(await S.firstTime(C.nonceOf(item.envelope)))) continue;                           // a copy of a message already seen (a replay): ignored
          if (viaPending) {
            // the laptop answering the one-time code with the real, fresh secret and this browser's own tag
            if (m.t === 'rekey' && typeof m.secret === 'string') {
              try {
                const raw = C.unb64u(m.secret);
                if (raw.length === 20) { await S.setKey(await C.deriveKey(raw), /^[A-Za-z0-9_-]{11}$/.test(m.tag || '') ? m.tag : undefined); out.types.add('paired'); }
              } catch { /* bad secret: stay pending */ }
            }
            continue;
          }
          if (m.t === 'removed') { await S.markRemoved(); removed = true; out.types.add('removed'); break; }   // only the laptop can seal this with our key
          await P.apply(m, out);
        }
        if (mine.length) await AA.call('agent-owner/personal/ack', { method: 'POST', data: { ids: mine, ...(box ? { tag: box } : {}) } });
        if (removed) break;
      }
      if (removed && location.hash.startsWith('#/agents/personal')) AA.go();                      // whatever screen is open now shows "This browser was removed"
      // whoever collected it, the screen that is open hears about it (a screen the person already left may have been the one to collect)
      if (out.types.size) window.dispatchEvent(new CustomEvent('aa-personal', { detail: out.types }));
      return out;
    },

    /** One message from the laptop, opened with this browser's own key: keep what it says. */
    async apply(m, out) {
      if (m.t === 'msg' && m.chat && m.id && typeof m.body === 'string' && (m.role === 'owner' || m.role === 'agent')) {
        if (await S.add({ id: String(m.id), chat: String(m.chat), role: m.role, body: m.body, sources: Array.isArray(m.sources) ? m.sources : [], ts: +m.ts || Date.now() })) { out.added++; if (m.role === 'agent') out.agent++; }
      } else if (m.t === 'deleted' && m.chat) { await S.removeChat(String(m.chat)); out.deleted++; }
      else if (m.t === 'hello') { await S.confirm(); out.hello = true; }
      // what the laptop says about the profile, the CV and the brief is kept on this browser so the screens work while the laptop is off
      else if (m.t === 'profile') { await S.set('profile', { ...m, savedAt: Date.now() }); out.types.add('profile'); }
      else if (m.t === 'brief') { await S.set('brief', { ...m, savedAt: Date.now() }); out.types.add('brief'); }
      else if (m.t === 'scan') { await S.set('scan', { ...m, savedAt: Date.now() }); out.types.add('scan'); }
      else if (m.t === 'progress') { await S.set('progress', { ...m, savedAt: Date.now() }); out.types.add('progress'); }
      else if (m.t === 'cv') { await S.set('cvStatus', { ok: !!m.ok, message: String(m.message || ''), name: String(m.name || ''), suggestions: +m.suggestions || 0, at: Date.now() }); out.types.add('cv'); }
      else if (m.t === 'error') { await S.set('lastError', { for: String(m.for || ''), message: String(m.message || ''), at: Date.now() }); out.types.add('error:' + String(m.for || '')); }
    },

    /** Send something and wait (up to ms) for the laptop's answer of a given kind. False if it did not answer in time (laptop off). */
    async request(message, wantType, ms = 20000) {
      await P.post(message);
      const end = Date.now() + ms;
      while (Date.now() < end) {
        await new Promise(r => setTimeout(r, 2000));
        const got = await P.pull();
        if (got.types.has(wantType) || got.types.has('error:' + wantType)) return true;
      }
      return false;
    },

    /** Send a CV in small encrypted pieces (a message is small; a CV is not). Calls onProgress(done, total). */
    async uploadCv(file, onProgress) {
      if (file.size > 500000) throw new Error('That file is too big. Use a CV under 500 KB.');
      const bytes = new Uint8Array(await file.arrayBuffer()), upload = uid(), size = 12000, total = Math.ceil(bytes.length / size) || 1;
      for (let i = 0; i < total; i++) {
        let bin = ''; for (const b of bytes.subarray(i * size, (i + 1) * size)) bin += String.fromCharCode(b);
        await P.post({ t: 'cv_chunk', upload, n: i, of: total, name: file.name, data: btoa(bin) });
        if (onProgress) onProgress(i + 1, total);
      }
      return upload;
    },

    /** While a Personal screen is open, look for whatever the laptop sends every few seconds and call onTypes(set of kinds). One watcher at a time. */
    watch(onTypes, every = 4000) {
      P.unwatch(); const mine = (P._w = {});
      const heard = e => { if (P._w === mine) onTypes(e.detail); else window.removeEventListener('aa-personal', heard); };
      window.addEventListener('aa-personal', heard);
      (async () => {
        while (P._w === mine && location.hash.startsWith('#/agents/personal')) {
          await new Promise(r => setTimeout(r, every));
          if (P._w !== mine || document.hidden) continue;
          try { await P.pull(); }
          catch (e) { if (e.step === 'code') { P._w = null; AA.go(); } }
        }
      })();
    },
    unwatch() { P._w = null; },

    status: () => AA.call('agent-owner/personal/status'),
  };
})();
