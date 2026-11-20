// Shared IndexedDB layer (service worker, offscreen document and library page all use it).
// recordings: metadata | chunks: video data in 5s slices | events: transcript/caption/comment/note rows
var DB = (() => {
  let p;
  const open = () => p || (p = new Promise((res, rej) => {
    const r = indexedDB.open('streamclip', 1);
    r.onupgradeneeded = () => {
      const d = r.result;
      d.createObjectStore('recordings', { keyPath: 'id' });
      d.createObjectStore('chunks', { keyPath: ['recId', 'idx'] });
      d.createObjectStore('events', { autoIncrement: true }).createIndex('rec', 'recId');
    };
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  }));

  const run = async (stores, mode, fn) => {
    const d = await open();
    return new Promise((res, rej) => {
      const t = d.transaction(stores, mode);
      let out;
      const rq = fn(t);
      if (rq && 'onsuccess' in rq) rq.onsuccess = () => (out = rq.result);
      t.oncomplete = () => res(out);
      t.onerror = t.onabort = () => rej(t.error);
    });
  };

  return {
    put: (store, v) => run(store, 'readwrite', t => t.objectStore(store).put(v)),
    get: (store, k) => run(store, 'readonly', t => t.objectStore(store).get(k)),
    getAll: store => run(store, 'readonly', t => t.objectStore(store).getAll()),
    addChunk: (recId, idx, blob) => run('chunks', 'readwrite', t => t.objectStore('chunks').put({ recId, idx, blob })),
    addEvent: ev => run('events', 'readwrite', t => t.objectStore('events').add(ev)),
    async getEvents(recId) {
      const ev = await run('events', 'readonly', t => t.objectStore('events').index('rec').getAll(recId));
      return ev.sort((a, b) => a.t - b.t);
    },
    async getBlob(recId, type) {
      const rows = await run('chunks', 'readonly', t =>
        t.objectStore('chunks').getAll(IDBKeyRange.bound([recId, 0], [recId, Infinity])));
      return new Blob(rows.map(r => r.blob), { type: type || 'video/webm' });
    },
    remove: recId => run(['recordings', 'chunks', 'events'], 'readwrite', t => {
      t.objectStore('recordings').delete(recId);
      t.objectStore('chunks').delete(IDBKeyRange.bound([recId, 0], [recId, Infinity]));
      const c = t.objectStore('events').index('rec').openKeyCursor(IDBKeyRange.only(recId));
      c.onsuccess = () => { const cur = c.result; if (cur) { t.objectStore('events').delete(cur.primaryKey); cur.continue(); } };
    }),
    // Rolling auto-captions repeat growing text; merge those into single lines.
    collapseCaptions(events) {
      const out = [];
      for (const e of events) {
        const prev = out[out.length - 1];
        if (prev && prev.type === 'caption' && e.type === 'caption') {
          if (e.text.startsWith(prev.text)) { prev.text = e.text; prev.end = e.t; continue; }
          if (prev.text.includes(e.text)) continue;
        }
        out.push({ ...e });
      }
      return out;
    }
  };
})();
