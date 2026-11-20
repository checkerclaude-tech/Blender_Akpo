const $ = s => document.querySelector(s);
const fmt = ms => { const s = Math.floor(ms / 1000), h = Math.floor(s / 3600);
  return (h ? h + ':' : '') + String(Math.floor(s / 60) % 60).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
const size = b => !b ? '–' : b > 1e9 ? (b / 1e9).toFixed(2) + ' GB' : Math.round(b / 1e6) + ' MB';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const ICON = { transcript: '🗣', caption: '💬', comment: '👤', note: '📝' };
const COLOR = { transcript: '#4285f4', caption: '#9aa0a6', comment: '#fbbc04', note: '#34a853' };
let cur = null, url = null;

async function loadList() {
  const recs = (await DB.getAll('recordings')).sort((a, b) => b.createdAt - a.createdAt);
  $('#list').innerHTML = recs.map(r => `<div class="rec ${cur?.id === r.id ? 'sel' : ''}" data-id="${r.id}">
    <b>${esc(r.title)}</b><span class="badge">${new Date(r.createdAt).toLocaleString()} · ${fmt(r.duration || 0)} · ${size(r.size)} · ${r.status}</span></div>`).join('')
    || '<p class="hint">No recordings yet.</p>';
  document.querySelectorAll('.rec').forEach(el => el.onclick = () => open(el.dataset.id));
}

async function open(id) {
  const rec = await DB.get('recordings', id);
  const events = DB.collapseCaptions(await DB.getEvents(id));
  cur = rec; loadList();
  if (url) URL.revokeObjectURL(url);
  const blob = await DB.getBlob(id, rec.mimeType);
  url = URL.createObjectURL(blob);
  const dur = rec.duration || 1;
  const counts = t => events.filter(e => e.type === t).length;
  $('#main').innerHTML = `<h1>${esc(rec.title)}</h1>
    <p class="sub"><a href="${esc(rec.url)}" target="_blank">${esc(rec.url)}</a></p>
    <video id="v" controls src="${url}"></video>
    <div class="bar" id="bar">${events.filter(e => e.type !== 'caption' && e.type !== 'transcript').map(e =>
      `<i title="${esc(e.text)}" style="left:${(e.t / dur * 100).toFixed(2)}%;background:${COLOR[e.type]}"></i>`).join('')}</div>
    <p class="hint">🗣 ${counts('transcript')} AI transcript · 💬 ${counts('caption')} captions · 👤 ${counts('comment')} comments · 📝 ${counts('note')} notes${rec.transcriptErrors ? ' · ⚠ ' + rec.transcriptErrors + ' transcript chunk(s) failed' : ''}${rec.error ? ' · ⚠ ' + esc(rec.error) : ''}</p>
    <div class="tools">
      <button data-x="video">⬇ Video</button><button data-x="srt">⬇ Transcript .srt</button><button data-x="vtt">⬇ .vtt</button>
      <button data-x="txt">⬇ Transcript + comments .txt</button><button data-x="csv">⬇ Comments .csv</button><button data-x="json">⬇ All .json</button>
      <button data-x="del" class="danger" style="width:auto;margin:0">🗑 Delete</button></div>
    <div class="tools"><label><input type="checkbox" id="f_transcript" checked> 🗣 Transcript</label>
      <label><input type="checkbox" id="f_caption" checked> 💬 Captions</label>
      <label><input type="checkbox" id="f_comment" checked> 👤 Comments</label>
      <label><input type="checkbox" id="f_note" checked> 📝 Notes</label></div>
    <div class="feed" id="feed"></div>`;
  const v = $('#v'), feed = $('#feed');
  // MediaRecorder WebM has no duration header; force the browser to compute it so seeking works.
  v.addEventListener('loadedmetadata', () => {
    if (v.duration === Infinity) { v.currentTime = 1e101; v.addEventListener('timeupdate', function f() { v.removeEventListener('timeupdate', f); v.currentTime = 0; }); }
  });
  const render = () => {
    const on = t => $('#f_' + t).checked;
    feed.innerHTML = events.map((e, i) => on(e.type) ? `<div class="it" data-i="${i}" data-t="${e.t}"><span class="t">${fmt(e.t)}</span>
      <span class="k">${ICON[e.type]}</span><span>${e.author ? `<span class="a">${esc(e.author)}: </span>` : ''}${esc(e.text)}</span></div>` : '').join('')
      || '<p class="hint" style="padding:10px">Nothing captured for this recording.</p>';
    feed.querySelectorAll('.it').forEach(el => el.onclick = () => { v.currentTime = el.dataset.t / 1000; v.play(); });
  };
  render();
  document.querySelectorAll('[id^=f_]').forEach(c => c.onchange = render);
  $('#bar').onclick = e => { const r = $('#bar').getBoundingClientRect(); v.currentTime = (e.clientX - r.left) / r.width * dur / 1000; };
  let last = null;
  v.ontimeupdate = () => {
    const ms = v.currentTime * 1000; let hit = null;
    for (const el of feed.querySelectorAll('.it')) { if (+el.dataset.t <= ms) hit = el; else break; }
    if (hit !== last) { last?.classList.remove('now'); hit?.classList.add('now'); hit?.scrollIntoView({ block: 'nearest' }); last = hit; }
  };
  document.querySelectorAll('[data-x]').forEach(b => b.onclick = () => exportAs(b.dataset.x, rec, events, blob));
}

// ---------- exports ----------
const ts = (ms, sep) => { const p = (n, l = 2) => String(Math.floor(n)).padStart(l, '0');
  return `${p(ms / 3600000)}:${p(ms / 60000 % 60)}:${p(ms / 1000 % 60)}${sep}${p(ms % 1000, 3)}`; };
const csv = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';

function cues(events) {
  const sp = events.filter(e => e.type === 'transcript' || e.type === 'caption');
  return sp.map((e, i) => ({ start: e.t, end: e.end > e.t ? e.end : Math.min(sp[i + 1]?.t ?? e.t + 4000, e.t + 6000), text: e.text }));
}
function save(name, data, type) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(data instanceof Blob ? data : new Blob([data], { type }));
  a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 60000);
}
async function exportAs(kind, rec, events, blob) {
  const base = rec.title.replace(/[^\w\-]+/g, '_').slice(0, 60) || 'stream';
  if (kind === 'video') return save(base + '.webm', blob);
  if (kind === 'srt') return save(base + '.srt', cues(events).map((c, i) => `${i + 1}\n${ts(c.start, ',')} --> ${ts(c.end, ',')}\n${c.text}\n`).join('\n'), 'text/plain');
  if (kind === 'vtt') return save(base + '.vtt', 'WEBVTT\n\n' + cues(events).map(c => `${ts(c.start, '.')} --> ${ts(c.end, '.')}\n${c.text}\n`).join('\n'), 'text/vtt');
  if (kind === 'txt') return save(base + '.txt', `${rec.title}\n${rec.url}\n\n` + events.map(e =>
    `[${fmt(e.t)}] ${{ transcript: 'SPEECH', caption: 'CAPTION', comment: 'COMMENT', note: 'NOTE' }[e.type]}${e.author ? ' ' + e.author : ''}: ${e.text}`).join('\n'), 'text/plain');
  if (kind === 'csv') return save(base + '_comments.csv', 'time_ms,time,type,author,text\n' + events.filter(e => e.type === 'comment' || e.type === 'note')
    .map(e => [e.t, fmt(e.t), e.type, e.author, e.text].map(csv).join(',')).join('\n'), 'text/csv');
  if (kind === 'json') return save(base + '.json', JSON.stringify({ recording: rec, events }, null, 2), 'application/json');
  if (kind === 'del' && confirm('Delete this recording permanently?')) {
    await DB.remove(rec.id); cur = null; $('#main').innerHTML = '<p class="hint">Deleted.</p>'; loadList();
  }
}

loadList();
const q = new URLSearchParams(location.search).get('id');
if (q) open(q);
