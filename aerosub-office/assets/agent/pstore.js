/* Personal agent: what the phone keeps on this device (IndexedDB). The decryption key is stored as a non-extractable CryptoKey,
 * so the browser can use it but nothing can read its bytes. Conversations are kept for 90 days, then removed (PRD N13).
 * Nothing from here is ever sent to the server except as an encrypted envelope. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const DB = 'aerosub-personal', KEEP_DAYS = 90;
  let dbp = null;

  const open = () => dbp || (dbp = new Promise((resolve, reject) => {
    if (!window.indexedDB) return reject(new Error('This browser cannot keep private data on your phone. Try another browser or a normal (not private) window.'));
    const r = indexedDB.open(DB, 1);
    r.onupgradeneeded = () => {
      const d = r.result;
      d.createObjectStore('kv');
      const m = d.createObjectStore('msgs', { keyPath: 'seq', autoIncrement: true });
      m.createIndex('id', 'id', { unique: true }); m.createIndex('chat', 'chat');
    };
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(new Error('This browser cannot keep private data on your phone. Try another browser or a normal (not private) window.'));
  }));
  const run = async (store, mode, fn) => {
    const d = await open();
    return new Promise((resolve, reject) => {
      const tx = d.transaction(store, mode), s = tx.objectStore(store);
      let out; try { out = fn(s); } catch (e) { return reject(e); }
      tx.oncomplete = () => resolve(out && typeof out === 'object' && 'result' in out ? out.result : out);   // a read that finds nothing gives undefined, never the request object
      tx.onerror = tx.onabort = () => reject(tx.error || new Error('storage failed'));                       // (this line used to sit inside the comment above, so a failed write was never reported)
    });
  };
  const req = r => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });

  const S = AA.pstore = {
    async get(k) { return run('kv', 'readonly', s => s.get(k)); },
    async set(k, v) { return run('kv', 'readwrite', s => s.put(v, k)); },
    async key() { return (await S.get('key')) || null; },
    /** The key made from a one-time pairing code, held only until the laptop hands over the real secret. Expires after 15 minutes.
     *  pairingTag names the mailbox the laptop's answer will be in (derived from the same code); the real tag comes inside that answer. */
    async setPending(cryptoKey, pairingTag) { await S.set('pendingKey', cryptoKey); await S.set('pendingTag', pairingTag || null); await S.set('pendingAt', Date.now()); },
    async pending() { const at = await S.get('pendingAt'); return at && Date.now() - at < 15 * 60 * 1000 ? (await S.get('pendingKey')) || null : null; },
    async pendingTag() { return (await S.pending()) ? (await S.get('pendingTag')) || null : null; },
    /** This browser's own tag, given by the laptop at pairing. null for the phone that was paired before browsers had tags: it keeps the old format. */
    async tag() { return (await S.get('tag')) || null; },
    /** True the first time a message's one-time value is seen on this phone; false for a copy (a replay). Remembers the last 500. */
    async firstTime(nonce) {
      const seen = (await S.get('nonces')) || [];
      if (!nonce || seen.includes(nonce)) return false;
      await S.set('nonces', seen.concat(nonce).slice(-500)); return true;
    },
    async setKey(cryptoKey, tag) { await run('kv', 'readwrite', s => { s.put(cryptoKey, 'key'); if (tag) s.put(tag, 'tag'); else s.delete('tag'); s.put(Date.now(), 'pairedAt'); s.put(false, 'confirmed'); s.put([], 'deleted'); s.delete('pendingKey'); s.delete('pendingTag'); s.delete('pendingAt'); s.delete('historyAsked'); s.delete('removed'); ['profile', 'brief', 'scan', 'progress', 'cvStatus', 'lastError'].forEach(k => s.delete(k)); }); },
    async confirmed() { return !!(await S.get('confirmed')); },
    async confirm() { return S.set('confirmed', true); },
    /** When the earlier conversations were last asked for (pairing, or the Try again button). Used to tell "still loading" from "did not come". */
    async historyAskedAt() { return (await S.get('historyAsked')) || (await S.get('pairedAt')) || 0; },
    async askedHistory() { return S.set('historyAsked', Date.now()); },

    /** The laptop removed this browser. Everything stored here goes (the chat, the key, the tag, the copies of profile and brief) and only a
     *  note that this browser was removed stays, so the next visit says so instead of showing an empty chat. Pairing again clears the note. */
    async markRemoved() { const d = await open(); await new Promise((res, rej) => { const tx = d.transaction(['kv', 'msgs'], 'readwrite'); tx.objectStore('kv').clear(); tx.objectStore('msgs').clear(); tx.objectStore('kv').put(Date.now(), 'removed'); tx.oncomplete = res; tx.onerror = tx.onabort = () => rej(tx.error); }); },
    async removed() { return !!(await S.get('removed')); },

    /** Adds a message. Returns false if it is already here or its conversation was deleted. */
    async add(m) {
      const d = await open();
      const deleted = (await S.get('deleted')) || [];
      if (deleted.includes(m.chat)) return false;
      return new Promise(resolve => {
        const tx = d.transaction('msgs', 'readwrite');
        const r = tx.objectStore('msgs').add({ id: m.id, chat: m.chat, role: m.role, body: m.body, sources: m.sources || [], ts: m.ts });
        r.onerror = e => { e.preventDefault(); resolve(false); };                       // same id again: ignore
        tx.oncomplete = () => resolve(true);
      });
    },
    async all() { const d = await open(); return req(d.transaction('msgs').objectStore('msgs').getAll()); },
    async latestChat() { const a = await S.all(); return a.length ? a[a.length - 1].chat : null; },
    async chat(chatId) { return (await S.all()).filter(m => m.chat === chatId); },
    async removeChat(chatId) {
      const d = await open(); const all = await S.all();
      await new Promise((res, rej) => { const tx = d.transaction('msgs', 'readwrite'); all.filter(m => m.chat === chatId).forEach(m => tx.objectStore('msgs').delete(m.seq)); tx.oncomplete = res; tx.onerror = () => rej(tx.error); });
      const deleted = (await S.get('deleted')) || []; if (!deleted.includes(chatId)) await S.set('deleted', deleted.concat(chatId).slice(-200));
    },
    /** Conversations with nothing newer than 90 days are removed. */
    async purge() {
      const all = await S.all(), newest = {};
      all.forEach(m => { newest[m.chat] = Math.max(newest[m.chat] || 0, m.ts); });
      const cut = Date.now() - KEEP_DAYS * 864e5; let n = 0;
      for (const [chat, ts] of Object.entries(newest)) if (ts < cut) { await S.removeChat(chat); n++; }
      return n;
    },
    /** Forget this pairing and every message on this phone. */
    async wipe() { const d = await open(); await new Promise((res, rej) => { const tx = d.transaction(['kv', 'msgs'], 'readwrite'); tx.objectStore('kv').clear(); tx.objectStore('msgs').clear(); tx.oncomplete = res; tx.onerror = () => rej(tx.error); }); },
  };
})();
