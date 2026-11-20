// Runs the actual recording. Video is written to IndexedDB in 5s slices so a 5-hour
// stream never has to fit in memory.
const QUALITY = { low: [1280, 720, 1000000], medium: [1920, 1080, 2500000], high: [1920, 1080, 5000000] };
let S = null;
const toBg = m => chrome.runtime.sendMessage({ target: 'bg', ...m }).catch(() => {});

chrome.runtime.onMessage.addListener((m, _s, respond) => {
  if (m.target !== 'offscreen') return;
  if (m.type === 'start') start(m).then(r => respond({ ok: true, ...r }), e => respond({ ok: false, error: String(e.message || e) }));
  else if (m.type === 'stop') { stop(); respond({ ok: true }); }
  return true;
});

async function start({ recId, streamId, durationMs, settings }) {
  if (S) throw new Error('Already recording');
  const [w, h, vbps] = QUALITY[settings.quality] || QUALITY.medium;
  const c = id => ({ mandatory: { chromeMediaSource: 'tab', chromeMediaSourceId: id } });
  const media = await navigator.mediaDevices.getUserMedia({
    audio: c(streamId),
    video: { mandatory: { chromeMediaSource: 'tab', chromeMediaSourceId: streamId, maxWidth: w, maxHeight: h, maxFrameRate: 30 } }
  });
  // Capturing a tab mutes it; route the audio back out so the user can still hear the stream.
  let ac = null;
  if (media.getAudioTracks().length) { ac = new AudioContext(); ac.createMediaStreamSource(media).connect(ac.destination); }

  const mimeType = ['video/webm;codecs=vp8,opus', 'video/webm'].find(t => MediaRecorder.isTypeSupported(t));
  const rec = new MediaRecorder(media, { mimeType, videoBitsPerSecond: vbps, audioBitsPerSecond: 128000 });
  S = { recId, media, rec, ac, mimeType, idx: 0, size: 0, pending: Promise.resolve(), tx: [], txErrors: 0,
    stopping: false, settings, t0: 0 };

  rec.ondataavailable = e => {
    if (!e.data.size) return;
    const i = S.idx++; S.size += e.data.size;
    S.pending = S.pending.then(() => DB.addChunk(recId, i, e.data))
      .catch(err => { S.error = 'Storage error: ' + err; stop(); });
  };
  rec.onstop = finish;
  media.getVideoTracks()[0].onended = () => stop(); // tab closed / capture revoked

  await new Promise(res => { rec.onstart = res; rec.start(5000); });
  S.t0 = Date.now();
  if (durationMs) S.timer = setTimeout(stop, durationMs);
  S.hb = setInterval(heartbeat, 20000);
  if (settings.openaiKey) startWhisper();
  heartbeat();
  return { t0: S.t0 };
}

async function heartbeat() {
  if (!S) return;
  const meta = await DB.get('recordings', S.recId);
  if (meta) {
    Object.assign(meta, { status: 'recording', size: S.size, duration: Date.now() - S.t0, mimeType: S.mimeType });
    await DB.put('recordings', meta);
  }
  toBg({ type: 'hb' }); // also keeps the service worker alive
}

function stop() {
  if (!S || S.stopping) return;
  S.stopping = true;
  clearTimeout(S.timer); clearInterval(S.hb); clearTimeout(S.wTimer);
  if (S.wRec && S.wRec.state !== 'inactive') S.wRec.stop();
  if (S.rec.state !== 'inactive') S.rec.stop(); else finish();
}

async function finish() {
  const s = S;
  await s.pending;
  await Promise.all(s.tx);
  const meta = (await DB.get('recordings', s.recId)) || { id: s.recId };
  Object.assign(meta, {
    status: s.error ? 'partial' : 'done', endedAt: Date.now(), duration: Date.now() - s.t0,
    size: s.size, mimeType: s.mimeType, error: s.error, transcriptErrors: s.txErrors
  });
  await DB.put('recordings', meta);
  s.media.getTracks().forEach(t => t.stop());
  if (s.ac) s.ac.close();
  S = null;
  toBg({ type: 'finished', recId: s.recId });
}

// ---- Whisper transcription: cut the audio into standalone chunks and send each to the API ----
function startWhisper() {
  const tracks = S.media.getAudioTracks();
  if (!tracks.length) return;
  const audio = new MediaStream(tracks);
  const ms = Math.max(10, S.settings.chunkSec) * 1000;
  const loop = () => {
    if (!S || S.stopping) return;
    const r = new MediaRecorder(audio, { mimeType: 'audio/webm;codecs=opus' });
    const parts = [], offset = Date.now() - S.t0, s = S;
    r.ondataavailable = e => e.data.size && parts.push(e.data);
    r.onstop = () => { const b = new Blob(parts, { type: 'audio/webm' }); if (b.size > 2000) s.tx.push(transcribe(s, b, offset)); };
    r.start();
    S.wRec = r;
    S.wTimer = setTimeout(() => { r.stop(); loop(); }, ms);
  };
  loop();
}

async function transcribe(s, blob, offset) {
  try {
    const f = new FormData();
    f.append('file', blob, 'chunk.webm');
    f.append('model', s.settings.whisperModel || 'whisper-1');
    f.append('response_format', 'verbose_json');
    if (s.settings.language) f.append('language', s.settings.language);
    const r = await fetch('https://api.openai.com/v1/audio/transcriptions', {
      method: 'POST', headers: { Authorization: 'Bearer ' + s.settings.openaiKey }, body: f
    });
    if (!r.ok) throw new Error(r.status + ' ' + (await r.text()).slice(0, 200));
    const j = await r.json();
    const segs = j.segments && j.segments.length ? j.segments : (j.text ? [{ start: 0, end: 0, text: j.text }] : []);
    for (const g of segs) {
      const text = (g.text || '').trim();
      if (text) await DB.addEvent({ recId: s.recId, type: 'transcript', t: offset + g.start * 1000, end: offset + g.end * 1000, text });
    }
  } catch (e) { s.txErrors++; console.warn('Transcription failed', e); }
}
