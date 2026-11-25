/* Aerosub Office: the floating assistant. A bubble on every page; click it to ask about tasks, events, people, research, competitors, or the web.
 * It can summarise, explain a task, and prepare a task or event that you confirm with one tap. Answers come from Claude through the company's API key. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._aiLoaded) return; X._aiLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const lsGet = (k, d) => { try { const v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch { return d; } }, lsSet = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} };
  let bubble = null, panel = null, st = null, busy = false, chatId = 0;

  /* ---- a small, safe text renderer: **bold**, `code`, lists, links ---- */
  function md(t) {
    const inl = s => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>').replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>');
    let out = '', list = null; const close = () => { if (list) { out += '</' + list + '>'; list = null; } };
    String(t).split('\n').forEach(line => { const b = line.match(/^\s*[-*•]\s+(.*)/), n = line.match(/^\s*\d+[.)]\s+(.*)/), h = line.match(/^#{1,4}\s+(.*)/);
      if (b) { if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += '<li>' + inl(b[1]) + '</li>'; } else if (n) { if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += '<li>' + inl(n[1]) + '</li>'; }
      else { close(); if (h) out += '<p><b>' + inl(h[1]) + '</b></p>'; else if (line.trim()) out += '<p>' + inl(line) + '</p>'; } }); close(); return out;
  }
  const when = s => { const d = new Date(String(s).replace(' ', 'T')); return d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };

  /* ---- the bubble ---- */
  let vis = null, visT = 0;   // does the Super Admin show the bubble to staff?
  async function visible() { if (vis && Date.now() - visT < 120000) return vis; try { vis = await api('assistant/status'); } catch { vis = vis || { bubble: true, can_admin: false }; } visT = Date.now(); return vis; }
  async function ensureBubble() {
    const want0 = !!(S && S.user && $('#view') && !S.offline); let want = want0, dim = false;
    if (want0) { const v = await visible(); if (!v.bubble) { if (v.can_admin) dim = true; else want = false; } }
    if (!want) { if (bubble) { bubble.remove(); bubble = null; } if (panel) { panel.remove(); panel = null; } return; }
    if (bubble) { bubble.classList.toggle('dim', dim); bubble.title = dim ? 'Hidden from staff. Only you can see this bubble (Settings: show it again).' : 'Ask the assistant'; return; }
    bubble = document.createElement('button'); bubble.id = 'ai-bubble'; bubble.type = 'button'; bubble.className = 'noprint'; bubble.setAttribute('aria-label', 'Ask the assistant'); bubble.title = dim ? 'Hidden from staff. Only you can see this bubble (Settings: show it again).' : 'Ask the assistant'; bubble.classList.toggle('dim', dim); bubble.innerHTML = '<span aria-hidden="true">✨</span>';
    const pos = lsGet('ai-pos', null); if (pos) { bubble.style.left = Math.min(pos.x, innerWidth - 64) + 'px'; bubble.style.top = Math.min(pos.y, innerHeight - 64) + 'px'; bubble.style.right = 'auto'; bubble.style.bottom = 'auto'; }
    document.body.appendChild(bubble);
    let drag = null; bubble.addEventListener('pointerdown', e => { const r = bubble.getBoundingClientRect(); drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, x: e.clientX, y: e.clientY, moved: false }; bubble.setPointerCapture(e.pointerId); });
    bubble.addEventListener('pointermove', e => { if (!drag) return; if (!drag.moved && Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 6) return; drag.moved = true; const x = Math.max(4, Math.min(innerWidth - 60, e.clientX - drag.dx)), y = Math.max(4, Math.min(innerHeight - 60, e.clientY - drag.dy)); Object.assign(bubble.style, { left: x + 'px', top: y + 'px', right: 'auto', bottom: 'auto' }); if (panel) place(); });
    bubble.addEventListener('pointerup', e => { if (!drag) return; const moved = drag.moved; drag = null; if (moved) { const r = bubble.getBoundingClientRect(); lsSet('ai-pos', { x: r.left, y: r.top }); } else toggle(); });
    bubble.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    if (sessionStorage.getItem('ai-open') === '1') toggle();
  }
  function place() {
    if (!panel || !bubble) return; if (innerWidth < 560) { panel.style.cssText = ''; return; }
    const r = bubble.getBoundingClientRect(), w = 390, h = Math.min(600, innerHeight - 24); let left = r.right - w, top = r.top - h - 10; if (top < 8) top = Math.min(innerHeight - h - 8, r.bottom + 10); if (left < 8) left = 8; if (left + w > innerWidth - 8) left = innerWidth - w - 8;
    Object.assign(panel.style, { left: left + 'px', top: top + 'px', width: w + 'px', height: h + 'px' });
  }
  function toggle() { if (panel) { panel.remove(); panel = null; sessionStorage.setItem('ai-open', '0'); bubble.classList.remove('on'); return; } sessionStorage.setItem('ai-open', '1'); bubble.classList.add('on'); open(); }

  /* ---- the panel ---- */
  async function open() {
    panel = document.createElement('section'); panel.id = 'ai-panel'; panel.className = 'noprint'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Assistant');
    panel.innerHTML = '<header><b class="grow">✨ Aerosub Assistant</b><button type="button" id="ai-gear" title="Settings" aria-label="Assistant settings" hidden>⚙</button><button type="button" id="ai-clear" title="Clear this conversation" aria-label="Clear conversation">🗑</button><button type="button" id="ai-x" title="Close" aria-label="Close">✕</button></header><div id="ai-log" aria-live="polite"><div class="ai-empty">Loading…</div></div>' +
      '<div id="ai-sug"></div><form id="ai-form"><textarea id="ai-in" rows="1" maxlength="2000" placeholder="Ask anything…" aria-label="Your question"></textarea><button type="submit" id="ai-send" aria-label="Send">➤</button></form><div class="ai-foot">AI can make mistakes. Check important details. Your questions go to Claude (Anthropic).</div>';
    document.body.appendChild(panel); place(); $('#ai-x', panel).onclick = toggle;
    $('#ai-clear', panel).onclick = async () => { if (!confirm('Clear this conversation?')) return; try { await api('assistant/chat', { method: 'DELETE' }); paintLog([]); } catch (e) { toast(e.message); } };
    $('#ai-gear', panel).onclick = settings; const inp = $('#ai-in', panel); inp.addEventListener('input', () => { inp.style.height = 'auto'; inp.style.height = Math.min(120, inp.scrollHeight) + 'px'; });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#ai-form', panel).requestSubmit(); } if (e.key === 'Escape') toggle(); });
    $('#ai-form', panel).onsubmit = e => { e.preventDefault(); const v = inp.value.trim(); if (v) { inp.value = ''; inp.style.height = 'auto'; ask(v); } };
    try { st = await api('assistant'); } catch (e) { $('#ai-log', panel).innerHTML = '<div class="ai-empty">' + esc(e.message) + '</div>'; return; }
    chatId = st.chat_id; if (st.can_admin) $('#ai-gear', panel).hidden = false; paintLog(st.messages); inp.focus();
  }
  function suggestions() {
    const s = ['Summarise my day', 'What is overdue?', 'What is on my calendar this week?', 'Which tasks are waiting on someone else?', 'What do we know about our competitors?']; if (st && st.web_search) s.push('Search the web for the latest oil and gas inspection news');
    return s;
  }
  function paintLog(msgs) {
    const log = $('#ai-log', panel); if (!log) return;
    if (st && !st.enabled) { log.innerHTML = '<div class="ai-empty"><div style="font-size:34px">✨</div><b>The assistant is not switched on yet.</b><p>' + (st.can_admin ? 'Open the settings (⚙ above) and add the API key to start.' : 'Ask a Super Admin to switch it on.') + '</p></div>'; $('#ai-sug', panel).innerHTML = ''; $('#ai-form', panel).style.display = 'none'; return; }
    log.innerHTML = msgs.length ? msgs.map(bubbleHtml).join('') : '<div class="ai-empty"><div style="font-size:34px">✨</div><b>Hi ' + esc((S.user.name || '').split(' ')[0]) + ', how can I help?</b><p>I can summarise your day, explain a task, look things up in our research and competitor notes' + (st && st.web_search ? ', search the web' : '') + ', and set up tasks or events for you to confirm.</p></div>';
    $('#ai-sug', panel).innerHTML = msgs.length ? '' : suggestions().map(t => '<button type="button" class="ai-chip">' + esc(t) + '</button>').join(''); $$('.ai-chip', panel).forEach(c => c.onclick = () => ask(c.textContent));
    log.scrollTop = log.scrollHeight;
  }
  function bubbleHtml(m) {
    if (m.role === 'user') return '<div class="ai-m me"><div class="ai-b">' + esc(m.text).replace(/\n/g, '<br>') + '</div></div>';
    return '<div class="ai-m bot" data-mid="' + m.id + '"><div class="ai-b">' + md(m.text) + (m.sources && m.sources.length ? '<div class="ai-src"><b>Sources</b>' + m.sources.slice(0, 6).map(s => '<a href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer">' + esc(s.title || s.url) + '</a>').join('') + '</div>' : '') + '</div>' + (m.proposals || []).map((p, i) => card(p, i)).join('') + '</div>';
  }
  function card(p, i) {
    const done = p.state === 'done', gone = p.state === 'dismissed', ro = st && st.read_only;
    const body = p.kind === 'task' ? '<b>📋 New task</b><div class="ai-t">' + esc(p.title) + '</div><div class="small muted">For ' + esc(p.assignee || 'you') + (p.due_date ? ' · due ' + esc(p.due_date) : '') + ' · ' + esc(p.priority) + '</div>' + (p.description ? '<div class="small">' + esc(p.description) + '</div>' : '')
      : '<b>📅 New ' + (p.event_kind === 'meeting' ? 'appointment' : 'event') + '</b><div class="ai-t">' + esc(p.title) + '</div><div class="small muted">' + esc(when(p.starts_at)) + ' to ' + esc(when(p.ends_at)) + (p.location ? ' · ' + esc(p.location) : '') + '</div>' + (p.attendees && p.attendees.length ? '<div class="small">With ' + esc(p.attendees.map(a => a.name).join(', ')) + '</div>' : '');
    return '<div class="ai-card' + (done || gone ? ' fin' : '') + '" data-i="' + i + '">' + body + (done ? '<div class="small" style="color:var(--ok,#1f9d57)">✓ Created' + (p.link ? ' · <a href="' + esc(p.link) + '">Open</a>' : '') + '</div>' : gone ? '<div class="small muted">Not created</div>' : (ro ? '<div class="small muted">Your account is read-only.</div>' : '<div class="row" style="margin-top:6px"><button type="button" class="btn sm" data-go>' + (p.kind === 'task' ? 'Create task' : 'Create event') + '</button><button type="button" class="btn ghost sm" data-no>Not now</button></div>')) + '</div>';
  }
  async function create(p) {
    if (p.kind === 'task') { const r = await api('tasks', { method: 'POST', data: { title: p.title, description: p.description || '', assignee_id: p.assignee_id, due_date: p.due_date || null, priority: p.priority } }); return { link: '#/tasks/' + r.id }; }
    await api('events', { method: 'POST', data: { title: p.title, starts_at: p.starts_at.replace(' ', 'T'), ends_at: p.ends_at.replace(' ', 'T'), location: p.location, description: p.description, kind: p.event_kind, scope: 'private', attendees: (p.attendees || []).map(a => a.id) } }); return { link: '#/schedule' };
  }
  async function ask(text) {
    if (busy || !panel) return; busy = true; const log = $('#ai-log', panel); const first = $('.ai-empty', log); if (first) log.innerHTML = ''; $('#ai-sug', panel).innerHTML = '';
    log.insertAdjacentHTML('beforeend', bubbleHtml({ role: 'user', text })); log.insertAdjacentHTML('beforeend', '<div class="ai-m bot" id="ai-wait"><div class="ai-b"><span class="ai-dots"><i></i><i></i><i></i></span> <span class="muted small" id="ai-sec">Thinking…</span></div></div>'); log.scrollTop = log.scrollHeight;
    const t0 = Date.now(), tm = setInterval(() => { const el = $('#ai-sec'); if (el) { const s = Math.round((Date.now() - t0) / 1000); el.textContent = s < 4 ? 'Thinking…' : s < 12 ? 'Looking things up…' : 'Still working (' + s + 's)…'; } }, 1000); $('#ai-send', panel).disabled = true;
    try { const r = await api('assistant/chat', { method: 'POST', data: { message: text } }); const w = $('#ai-wait'); if (w) w.remove(); const m = { id: r.id, role: 'assistant', text: r.text, sources: r.sources, proposals: r.proposals }; log.insertAdjacentHTML('beforeend', bubbleHtml(m));
      const el = log.lastElementChild; $$('.ai-card', el).forEach((c, i) => { c._p = r.proposals[i]; }); bindCardsFor(el); if (st) st.remaining = r.remaining; if (r.remaining <= 3) log.insertAdjacentHTML('beforeend', '<div class="ai-note">' + r.remaining + ' question' + (r.remaining === 1 ? '' : 's') + ' left today.</div>'); }
    catch (e) { const w = $('#ai-wait'); if (w) w.remove(); log.insertAdjacentHTML('beforeend', '<div class="ai-m bot"><div class="ai-b err">' + esc(e.message) + '</div></div>'); }
    finally { clearInterval(tm); busy = false; if (panel) { $('#ai-send', panel).disabled = false; $('#ai-in', panel).focus(); } log.scrollTop = log.scrollHeight; }
  }
  function bindCardsFor(el) { $$('.ai-card:not(.fin)', el).forEach(c => { const mid = el.dataset.mid, i = +c.dataset.i; const mark = async s => { try { await api('assistant/messages/' + mid + '/proposal', { method: 'POST', data: { index: i, state: s } }); } catch {} };
    const no = $('[data-no]', c); if (no) no.onclick = async () => { await mark('dismissed'); c.classList.add('fin'); c.querySelector('.row').outerHTML = '<div class="small muted">Not created</div>'; };
    const go = $('[data-go]', c); if (go) go.onclick = async () => { go.disabled = true; try { const r = await create(c._p); await mark('done'); c.classList.add('fin'); c.querySelector('.row').outerHTML = '<div class="small" style="color:var(--ok,#1f9d57)">✓ Created · <a href="' + esc(r.link) + '">Open</a></div>'; toast(c._p.kind === 'task' ? 'Task created' : 'Event created'); } catch (e) { go.disabled = false; toast(e.message); } }; }); }
  // cards on messages loaded from history need their data too
  const origPaint = paintLog; paintLog = function (msgs) { origPaint(msgs); const log = $('#ai-log', panel); if (!log) return; $$('[data-mid]', log).forEach(el => { const m = msgs.find(x => String(x.id) === el.dataset.mid); if (m) $$('.ai-card', el).forEach((c, i) => { c._p = m.proposals[i]; }); bindCardsFor(el); }); };

  /* ---- settings (Super Admin) ---- */
  async function settings() {
    let s; try { s = await api('assistant/settings'); } catch (e) { toast(e.message); return; }
    const m = modal('<div class="row"><h2 class="grow">Assistant settings</h2><button class="btn ghost sm" id="cx">✕</button></div>' +
      '<div class="fl-info" style="margin:8px 0"><b>How it is powered</b><div class="small" style="margin-top:4px">The assistant uses the Claude API, which has its own key and pay-as-you-go billing (console.anthropic.com → API keys). A Claude Pro or Max chat subscription cannot be used for this; that plan only covers claude.ai and Claude Code on a person\'s own login.</div></div>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="as-on" style="width:auto"' + (s.enabled ? ' checked' : '') + '> Switch the assistant on for everyone</label>' +
      '<div class="field" style="margin-top:8px"><label>Anthropic API key ' + (s.has_key ? '<span class="muted small">(saved ' + esc(s.key_hint || '') + '; leave empty to keep it)</span>' : '') + '</label><input id="as-key" type="password" autocomplete="off" placeholder="sk-ant-…"></div>' +
      '<div class="grid2"><div class="field"><label>Model</label><select id="as-model">' + Object.entries(s.models).map(([k, v]) => '<option value="' + k + '"' + (k === s.model ? ' selected' : '') + '>' + esc(v) + '</option>').join('') + '</select></div><div class="field"><label>Questions per person per day</label><input id="as-lim" type="number" min="1" max="500" value="' + s.daily_limit + '"></div></div>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" id="as-bub" style="width:auto"' + (s.show_bubble ? ' checked' : '') + '> Show the ✨ bubble to staff <span class="muted">(untick to hide it for everyone; you will still see a faded one to get back here)</span></label>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="as-web" style="width:auto"' + (s.web_search ? ' checked' : '') + '> Allow web search <span class="muted">(must also be enabled for your organisation in the Anthropic console)</span></label>' +
      '<div class="field" style="margin-top:8px"><label>Extra instructions (optional)</label><textarea id="as-ins" rows="3" maxlength="2000" placeholder="e.g. Always answer in British English. Our main clients are …">' + esc(s.instructions || '') + '</textarea></div>' +
      '<div class="muted small">Today: ' + (s.usage_today.people || 0) + ' people asked ' + (s.usage_today.msgs || 0) + ' questions (' + ((+s.usage_today.i || 0) + (+s.usage_today.o || 0)).toLocaleString() + ' tokens).</div><div class="err" id="er" role="alert"></div>' +
      '<div class="row" style="margin-top:10px"><button class="btn" id="as-save">Save</button><button class="btn ghost" id="as-test">Save and test</button>' + (s.has_key ? '<button class="btn ghost right" id="as-rm" style="color:var(--bad)">Remove key</button>' : '') + '</div>');
    $('#cx', m).onclick = () => m.close();
    const save = async extra => { const d = Object.assign({ enabled: $('#as-on', m).checked, api_key: $('#as-key', m).value.trim(), model: $('#as-model', m).value, daily_limit: +$('#as-lim', m).value || 40, web_search: $('#as-web', m).checked, show_bubble: $('#as-bub', m).checked, instructions: $('#as-ins', m).value.trim() }, extra || {}); await api('assistant/settings', { method: 'PUT', data: d }); };
    $('#as-save', m).onclick = async () => { try { await save(); m.close(); toast('Saved'); st = await api('assistant'); vis = null; ensureBubble(); if (panel) paintLog(st.messages); } catch (e) { $('#er', m).textContent = e.message; } };
    $('#as-test', m).onclick = async () => { $('#er', m).textContent = ''; try { await save(); const r = await api('assistant/settings/test', { method: 'POST', data: {} }); toast('Connected ✓ The assistant answered: “' + r.reply + '”'); st = await api('assistant'); if (panel) paintLog(st.messages); } catch (e) { $('#er', m).textContent = e.message; } };
    const rm = $('#as-rm', m); if (rm) rm.onclick = async () => { if (!confirm('Remove the key and switch the assistant off?')) return; try { await save({ remove_key: true, enabled: false }); m.close(); toast('Key removed'); st = await api('assistant'); if (panel) paintLog(st.messages); } catch (e) { $('#er', m).textContent = e.message; } };
  }

  setInterval(ensureBubble, 1500); setTimeout(ensureBubble, 800); window.addEventListener('resize', () => { if (bubble) { const r = bubble.getBoundingClientRect(); if (r.right > innerWidth) bubble.style.left = Math.max(4, innerWidth - 60) + 'px'; if (r.bottom > innerHeight) bubble.style.top = Math.max(4, innerHeight - 60) + 'px'; } place(); });
})();
