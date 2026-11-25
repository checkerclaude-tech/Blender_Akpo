/* Aerosub Office: team chat. One-to-one chats and groups, files from your device or My Documents, and tasks shared into the conversation.
 * Private to the people in each conversation. Updates every few seconds while open; a badge and a small pop-up tell you about new messages elsewhere. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._chLoaded) return; X._chLoaded = 1;
  const { api, modal, toast, esc, S, views } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';
  const SL = { todo: 'To do', in_progress: 'In progress', blocked: 'Waiting', done: 'Done', deferred: 'Deferred', canceled: 'Cancelled', transferred: 'Transferred' };
  const ini = n => (n || '?').split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  const tm = t => new Date(String(t).replace(' ', 'T')).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  const dayLabel = t => { const d = new Date(String(t).replace(' ', 'T')), n = new Date(); const sd = (a, b) => a.toDateString() === b.toDateString(); const y = new Date(n.getTime() - 864e5); return sd(d, n) ? 'Today' : sd(d, y) ? 'Yesterday' : d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }); };
  const short = t => { const d = new Date(String(t).replace(' ', 'T')), n = new Date(); return d.toDateString() === n.toDateString() ? tm(t) : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); };
  const size = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  const linkify = t => esc(t).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
  const CH = { rooms: [], cur: 0, info: null, last: 0, read: {}, timer: 0, t2: 0, msgs: [] };
  const stop = () => { if (CH.timer) { clearInterval(CH.timer); CH.timer = 0; } if (CH.t2) { clearInterval(CH.t2); CH.t2 = 0; } };

  /* ---- menu entry ---- */
  const prevNav = X.nav; X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); const i = items.findIndex(x => x[0] === 'mail'); items.splice(i < 0 ? items.length : i + 1, 0, ['chat', 'Chat', ICON]); };
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); items.unshift(['chat', 'Chat', null]); };

  /* ---- the page ---- */
  views.chat = async () => {
    stop(); const v = $('#view'); const m = location.hash.match(/^#\/chat\/(\d+)/); CH.cur = m ? +m[1] : 0; CH.msgs = []; CH.last = 0;
    v.innerHTML = '<div class="top"><div><h1>Chat</h1><div class="muted">Talk to colleagues, make groups, and share files and tasks. Only the people in a conversation can read it.</div></div><span class="right row"><button class="btn ghost" id="ch-grp">+ New group</button><button class="btn" id="ch-new">+ New chat</button></span></div>' +
      '<div class="ch' + (CH.cur ? ' open' : '') + '" id="ch"><aside class="ch-side card"><input id="ch-q" placeholder="Search chats" aria-label="Search chats"><div id="ch-rooms" role="list"><div class="empty">Loading…</div></div></aside><section class="ch-main card" id="ch-main"><div class="ch-none"><div style="font-size:42px">💬</div><b>Pick a conversation</b><div class="muted small">or start a new chat with a colleague</div></div></section></div>';
    $('#ch-new').onclick = () => pick(false); $('#ch-grp').onclick = () => pick(true); $('#ch-q').oninput = paintRooms;
    await loadRooms(); if (CH.cur) await openRoom(CH.cur);
    CH.t2 = setInterval(() => { if (!/^#\/chat/.test(location.hash)) return stop(); if (!document.hidden) loadRooms(); }, 8000);
    CH.timer = setInterval(() => { if (!/^#\/chat/.test(location.hash)) return stop(); if (!document.hidden && CH.cur) poll(); }, 3000);
  };
  async function loadRooms() { try { CH.rooms = (await api('chat/rooms')).rooms; } catch { return; } paintRooms(); setBadge(CH.rooms.reduce((a, r) => a + (r.muted ? 0 : r.unread), 0)); }
  function paintRooms() {
    const box = $('#ch-rooms'); if (!box) return; const q = (($('#ch-q') || {}).value || '').trim().toLowerCase(); const list = CH.rooms.filter(r => !q || r.title.toLowerCase().includes(q));
    box.innerHTML = list.length ? list.map(r => '<button type="button" class="ch-room' + (r.id === CH.cur ? ' on' : '') + '" data-r="' + r.id + '" role="listitem"><span class="ch-av' + (r.kind === 'group' ? ' g' : '') + '">' + (r.kind === 'group' ? '👥' : esc(ini(r.title))) + (r.online ? '<i class="on" title="Online"></i>' : '') + '</span><span class="grow ch-rt"><b>' + esc(r.title) + '</b><span class="muted small">' + (r.last ? esc(r.last.from + ': ' + r.last.text) : 'No messages yet') + '</span></span><span class="ch-rm small muted">' + (r.last ? esc(short(r.last.at)) : '') + (r.unread ? '<em>' + r.unread + '</em>' : '') + '</span></button>').join('') : '<div class="empty small">' + (CH.rooms.length ? 'No chat matches.' : 'No chats yet. Press “New chat”.') + '</div>';
    $$('[data-r]', box).forEach(b => b.onclick = () => { location.hash = '#/chat/' + b.dataset.r; });
  }
  window.addEventListener('hashchange', () => { if (/^#\/chat/.test(location.hash) && $('#ch')) { const m = location.hash.match(/^#\/chat\/(\d+)/); const id = m ? +m[1] : 0; if (id !== CH.cur) { CH.cur = id; $('#ch').classList.toggle('open', !!id); if (id) openRoom(id); paintRooms(); } } });

  /* ---- one conversation ---- */
  async function openRoom(id) {
    CH.cur = id; CH.msgs = []; CH.last = 0; $('#ch').classList.add('open'); const main = $('#ch-main'); main.innerHTML = '<div class="empty">Loading…</div>';
    let info, d; try { [info, d] = await Promise.all([api('chat/rooms/' + id), api('chat/rooms/' + id + '/messages')]); } catch (e) { main.innerHTML = '<div class="ch-none"><b>' + esc(e.message) + '</b></div>'; return; }
    CH.info = info; CH.msgs = d.messages; CH.read = d.read || {}; CH.last = d.messages.length ? d.messages[d.messages.length - 1].id : 0;
    main.innerHTML = '<header class="ch-h"><button type="button" class="btn ghost sm ch-back" id="ch-back" aria-label="Back to chats">←</button><div class="grow"><b>' + esc(info.title) + '</b><div class="muted small" id="ch-sub"></div></div><button type="button" class="btn ghost sm" id="ch-info" aria-label="People and options" title="People and options">⋯</button></header><div class="ch-log" id="ch-log" aria-live="polite"></div>' +
      '<form class="ch-comp" id="ch-form"><div class="ch-att"><button type="button" class="btn ghost sm" id="ch-clip" aria-label="Attach a file" title="Attach a file">📎</button><button type="button" class="btn ghost sm" id="ch-task" aria-label="Share a task" title="Share a task">☑</button></div><textarea id="ch-in" rows="1" maxlength="4000" placeholder="Write a message…" aria-label="Message"></textarea><button class="btn" id="ch-send" aria-label="Send">➤</button><input type="file" id="ch-file" hidden multiple></form>';
    $('#ch-back').onclick = () => { $('#ch').classList.remove('open'); history.replaceState(null, '', '#/chat'); CH.cur = 0; paintRooms(); }; $('#ch-info').onclick = roomMenu;
    $('#ch-sub').textContent = info.kind === 'group' ? info.members.length + ' people: ' + info.members.map(m => m.name.split(' ')[0]).slice(0, 6).join(', ') + (info.members.length > 6 ? '…' : '') : (CH.rooms.find(r => r.id === id) || {}).online ? 'Online' : '';
    paintLog(true); const inp = $('#ch-in'); inp.focus(); inp.addEventListener('input', () => { inp.style.height = 'auto'; inp.style.height = Math.min(140, inp.scrollHeight) + 'px'; });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#ch-form').requestSubmit(); } });
    $('#ch-form').onsubmit = async e => { e.preventDefault(); const t = inp.value.trim(); if (!t) return; inp.value = ''; inp.style.height = 'auto'; await send({ body: t }); };
    $('#ch-clip').onclick = attachMenu; $('#ch-task').onclick = taskPicker;
    $('#ch-file').onchange = async e => { for (const f of e.target.files) { try { const b = await X.fileObj(f); await send({ file: { name: b.name, type: b.type, data: b.data } }); } catch (er) { toast(er.message); } } e.target.value = ''; };
    const log = $('#ch-log'); ['dragover', 'drop'].forEach(ev => log.addEventListener(ev, e => { e.preventDefault(); if (ev === 'drop') $('#ch-file').files = e.dataTransfer.files, $('#ch-file').dispatchEvent(new Event('change')); }));
    loadRooms();
  }
  async function send(data) { try { await api('chat/rooms/' + CH.cur + '/messages', { method: 'POST', data }); await poll(true); } catch (e) { toast(e.message); } }
  async function poll(force) {
    if (!CH.cur) return; let d; try { d = await api('chat/rooms/' + CH.cur + '/messages?after=' + CH.last); } catch { return; } CH.read = d.read || CH.read;
    if (d.messages.length) { CH.msgs = CH.msgs.concat(d.messages); CH.last = d.messages[d.messages.length - 1].id; paintLog(false); loadRooms(); } else if (force) paintLog(false);
  }
  function msgHtml(m, prev, info) {
    const mine = +m.user_id === +S.user.id, day = !prev || dayLabel(prev.at) !== dayLabel(m.at); let h = day ? '<div class="ch-day"><span>' + esc(dayLabel(m.at)) + '</span></div>' : '';
    if (m.kind === 'system') return h + '<div class="ch-sys">' + esc(m.body) + '</div>';
    const head = !mine && info.kind === 'group' && (!prev || prev.user_id !== m.user_id || day) ? '<div class="ch-nm">' + esc(m.name) + '</div>' : '';
    let body;
    if (m.deleted) body = '<i class="muted">This message was deleted</i>';
    else if (m.kind === 'file') body = (m.file.image ? '<a href="api/chat/files/' + m.id + '?view=1" target="_blank" rel="noopener"><img class="ch-img" src="api/chat/files/' + m.id + '?view=1" alt="' + esc(m.file.name) + '" loading="lazy"></a>' : '') + '<a class="ch-file" href="api/chat/files/' + m.id + '" download><span aria-hidden="true">📄</span><span><b>' + esc(m.file.name) + '</b><br><span class="small muted">' + size(m.file.size) + ' · Download</span></span></a>' + (m.body ? '<div>' + linkify(m.body) + '</div>' : '');
    else if (m.kind === 'task') body = m.task && !m.task.gone ? '<a class="ch-task" href="#/tasks/' + m.task.id + '"><span aria-hidden="true">☑</span><span><b>' + esc(m.task.title) + '</b><br><span class="small muted">' + esc(SL[m.task.status] || m.task.status) + (m.task.due_date ? ' · due ' + esc(m.task.due_date) : '') + (m.task.shared ? ' · shared with everyone here' : '') + '</span></span></a>' : '<i class="muted">Task no longer available</i>';
    else body = linkify(m.body || '') + (m.edited ? ' <span class="small muted">(edited)</span>' : '');
    const seen = mine && info.kind === 'dm' && Object.values(CH.read).some(r => +r >= m.id) ? ' · Seen' : '';
    return h + '<div class="ch-m ' + (mine ? 'me' : 'them') + '" data-m="' + m.id + '">' + head + '<div class="ch-b">' + body + '</div><div class="ch-t small muted">' + esc(tm(m.at)) + seen + (mine && m.kind === 'text' && !m.deleted ? ' · <a href="#" data-ed="' + m.id + '">Edit</a>' : '') + (mine && !m.deleted ? ' · <a href="#" data-del="' + m.id + '">Delete</a>' : '') + '</div></div>';
  }
  function paintLog(bottom) {
    const log = $('#ch-log'); if (!log) return; const atEnd = bottom || log.scrollHeight - log.scrollTop - log.clientHeight < 80;
    log.innerHTML = CH.msgs.length ? CH.msgs.map((m, i) => msgHtml(m, CH.msgs[i - 1], CH.info)).join('') : '<div class="ch-none"><div class="muted">No messages yet. Say hello 👋</div></div>';
    $$('[data-del]', log).forEach(a => a.onclick = async e => { e.preventDefault(); if (!confirm('Delete this message?')) return; try { await api('chat/messages/' + a.dataset.del, { method: 'DELETE' }); const m = CH.msgs.find(x => x.id == a.dataset.del); if (m) { m.deleted = true; m.body = null; } paintLog(false); } catch (er) { toast(er.message); } });
    $$('[data-ed]', log).forEach(a => a.onclick = e => { e.preventDefault(); const m = CH.msgs.find(x => x.id == a.dataset.ed); const nv = prompt('Edit your message', m.body); if (nv == null || !nv.trim()) return; api('chat/messages/' + m.id, { method: 'PATCH', data: { body: nv.trim() } }).then(() => { m.body = nv.trim(); m.edited = true; paintLog(false); }).catch(er => toast(er.message)); });
    if (atEnd) log.scrollTop = log.scrollHeight;
  }

  /* ---- pickers ---- */
  async function pick(group) {
    let people; try { people = (await api('chat/people')).people; } catch (e) { toast(e.message); return; } const sel = new Set();
    const m = modal('<div class="row"><h2 class="grow">' + (group ? 'New group' : 'New chat') + '</h2><button class="btn ghost sm" id="cx">✕</button></div>' + (group ? '<div class="field" style="margin-top:8px"><label>Group name</label><input id="g-n" maxlength="80" placeholder="e.g. Website team"></div>' : '') +
      '<div class="field"><label>' + (group ? 'Add people' : 'Who do you want to talk to?') + '</label><input id="p-q" placeholder="Search by name or department"></div><div class="sh-list" id="p-l"></div><div class="err" id="er" role="alert"></div>' + (group ? '<div class="row" style="margin-top:10px"><button class="btn" id="go">Create group</button><span class="muted small" id="cnt"></span></div>' : ''));
    $('#cx', m).onclick = () => m.close(); const paint = () => { const q = $('#p-q', m).value.trim().toLowerCase(); $('#p-l', m).innerHTML = people.filter(p => !q || (p.name + ' ' + (p.department || '')).toLowerCase().includes(q)).map(p => '<label class="sh-row">' + (group ? '<input type="checkbox" data-u="' + p.id + '"' + (sel.has(p.id) ? ' checked' : '') + '>' : '') + '<span>' + esc(p.name) + '</span><span class="muted small">' + esc(p.department || '') + '</span></label>').join('') || '<div class="muted small" style="padding:8px">Nobody found.</div>';
      $$('[data-u]', m).forEach(c => c.onchange = () => { c.checked ? sel.add(+c.dataset.u) : sel.delete(+c.dataset.u); $('#cnt', m).textContent = sel.size + ' selected'; }); if (!group) $$('.sh-row', m).forEach((r, i) => r.onclick = async () => { const p = people.filter(p => !q || (p.name + ' ' + (p.department || '')).toLowerCase().includes(q))[i]; try { const r2 = await api('chat/rooms', { method: 'POST', data: { user_id: p.id } }); m.close(); location.hash = '#/chat/' + r2.id; } catch (e) { $('#er', m).textContent = e.message; } }); };
    paint(); $('#p-q', m).oninput = paint; $('#p-q', m).focus();
    if (group) $('#go', m).onclick = async () => { try { const r = await api('chat/rooms', { method: 'POST', data: { name: $('#g-n', m).value.trim(), user_ids: [...sel] } }); m.close(); location.hash = '#/chat/' + r.id; } catch (e) { $('#er', m).textContent = e.message; } };
  }
  function attachMenu() {
    const b = $('#ch-clip'); const old = $('.nd-menu'); if (old) old.remove(); const m = document.createElement('div'); m.className = 'nd-menu'; const hasVault = !!(S.user.tabs && S.user.tabs.myfiles);
    m.innerHTML = '<button type="button" data-a="dev"><span class="ic">💻</span><span><b>From this device</b></span></button>' + (hasVault ? '<button type="button" data-a="vault"><span class="ic">📁</span><span><b>From My Documents</b><span class="sub">Only what you pick is shared</span></span></button>' : ''); document.body.appendChild(m);
    const r = b.getBoundingClientRect(); m.style.top = (window.scrollY + r.top - m.offsetHeight - 6) + 'px'; m.style.left = (window.scrollX + r.left) + 'px'; const close = () => { m.remove(); document.removeEventListener('mousedown', away, true); }, away = e => { if (!m.contains(e.target)) close(); }; document.addEventListener('mousedown', away, true);
    $$('[data-a]', m).forEach(x => x.onclick = () => { close(); x.dataset.a === 'dev' ? $('#ch-file').click() : vaultPicker(); });
  }
  async function vaultPicker() {
    let items; try { items = (await api('myfiles')).items; } catch (e) { toast(e.message); return; } const m = modal('<div class="row"><h2 class="grow">Share from My Documents</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 8px">The person or group you are chatting with will get a copy of what you pick. The rest of My Documents stays private.</div><div class="sh-list" id="vl"></div>');
    $('#cx', m).onclick = () => m.close(); $('#vl', m).innerHTML = items.length ? items.map(i => '<button type="button" class="sh-row" data-v="' + i.id + '" style="width:100%;background:none;border:0;border-bottom:1px solid var(--line,#eef0f4);color:inherit;font:inherit;text-align:left"><span>' + ({ file: '📄', note: '📝', link: '🔗' })[i.kind] + ' ' + esc(i.title) + '</span><span class="muted small">' + (i.kind === 'file' ? size(i.size) : i.kind) + '</span></button>').join('') : '<div class="muted small" style="padding:10px">Nothing in My Documents yet.</div>';
    $$('[data-v]', m).forEach(b => b.onclick = async () => { m.close(); await send({ vault_id: +b.dataset.v }); });
  }
  async function taskPicker() {
    let tasks = []; try { const t = await api('tasks'); tasks = (Array.isArray(t) ? t : t.tasks || []).filter(x => !['canceled', 'transferred'].includes(x.status)).slice(0, 300); } catch (e) { toast(e.message); return; }
    const m = modal('<div class="row"><h2 class="grow">Share a task</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="field" style="margin-top:8px"><input id="tq" placeholder="Find one of your tasks"></div><div class="sh-list" id="tl"></div>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" id="tsh" style="width:auto" checked> Give the people in this chat access to the task <span class="muted">(so they can open it)</span></label><div class="err" id="er" role="alert"></div>'); $('#cx', m).onclick = () => m.close();
    const paint = () => { const q = $('#tq', m).value.trim().toLowerCase(); $('#tl', m).innerHTML = tasks.filter(t => !q || String(t.title).toLowerCase().includes(q)).slice(0, 60).map(t => '<button type="button" class="sh-row" data-t="' + t.id + '" style="width:100%;background:none;border:0;border-bottom:1px solid var(--line,#eef0f4);color:inherit;font:inherit;text-align:left"><span>☑ ' + esc(t.title) + '</span><span class="muted small">' + esc(SL[t.status] || t.status) + '</span></button>').join('') || '<div class="muted small" style="padding:10px">No tasks match.</div>';
      $$('[data-t]', m).forEach(b => b.onclick = async () => { try { await api('chat/rooms/' + CH.cur + '/messages', { method: 'POST', data: { task_id: +b.dataset.t, share_task: $('#tsh', m).checked } }); m.close(); poll(true); } catch (e) { $('#er', m).textContent = e.message; } }); };
    paint(); $('#tq', m).oninput = paint; $('#tq', m).focus();
  }
  async function roomMenu() {
    const i = CH.info, me = +S.user.id; const m = modal('<div class="row"><h2 class="grow">' + esc(i.title) + '</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 8px">' + (i.kind === 'group' ? 'Group · ' : 'Chat · ') + i.members.length + ' people</div><div class="list">' + i.members.map(p => '<div class="item"><span class="ch-av">' + esc(ini(p.name)) + '</span><div class="grow"><b>' + esc(p.name) + '</b>' + (p.role === 'owner' && i.kind === 'group' ? ' <span class="tag">Owner</span>' : '') + '<div class="muted small">' + esc(p.department || '') + '</div></div>' + (i.kind === 'group' && i.owner && p.id !== me ? '<button class="btn ghost sm" data-rm="' + p.id + '">Remove</button>' : '') + '</div>').join('') + '</div>' +
      '<div class="row" style="margin-top:12px;flex-wrap:wrap">' + (i.kind === 'group' && i.owner ? '<button class="btn ghost" id="add">+ Add people</button><button class="btn ghost" id="ren">Rename</button>' : '') + '<button class="btn ghost" id="mute">' + (i.muted ? '🔔 Unmute' : '🔕 Mute') + '</button>' + (i.kind === 'group' ? '<button class="btn ghost" id="leave" style="color:var(--bad)">Leave group</button>' : '') + '</div>'); $('#cx', m).onclick = () => m.close();
    $$('[data-rm]', m).forEach(b => b.onclick = async () => { try { await api('chat/rooms/' + i.id + '/members/' + b.dataset.rm, { method: 'DELETE' }); m.close(); openRoom(i.id); } catch (e) { toast(e.message); } });
    const lv = $('#leave', m); if (lv) lv.onclick = async () => { if (!confirm('Leave this group?')) return; try { await api('chat/rooms/' + i.id + '/members/' + me, { method: 'DELETE' }); m.close(); history.replaceState(null, '', '#/chat'); X.render(); } catch (e) { toast(e.message); } };
    $('#mute', m).onclick = async () => { try { await api('chat/rooms/' + i.id, { method: 'PATCH', data: { muted: !i.muted } }); i.muted = !i.muted; m.close(); toast(i.muted ? 'Muted: no badge or pop-ups from this chat' : 'Unmuted'); loadRooms(); } catch (e) { toast(e.message); } };
    const rn = $('#ren', m); if (rn) rn.onclick = async () => { const n = prompt('New group name', i.title); if (!n || !n.trim()) return; try { await api('chat/rooms/' + i.id, { method: 'PATCH', data: { name: n.trim() } }); m.close(); openRoom(i.id); loadRooms(); } catch (e) { toast(e.message); } };
    const ad = $('#add', m); if (ad) ad.onclick = async () => { const people = (await api('chat/people')).people.filter(p => !i.members.some(x => x.id === p.id)); const f = modal('<div class="row"><h2 class="grow">Add people</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="sh-list" id="pl" style="margin-top:8px"></div><div class="row" style="margin-top:10px"><button class="btn" id="go">Add</button></div>'); $('#cx', f).onclick = () => f.close();
      $('#pl', f).innerHTML = people.map(p => '<label class="sh-row"><input type="checkbox" data-u="' + p.id + '"><span>' + esc(p.name) + '</span><span class="muted small">' + esc(p.department || '') + '</span></label>').join('') || '<div class="muted small" style="padding:8px">Everyone is already in.</div>'; $('#go', f).onclick = async () => { const ids = $$('[data-u]:checked', f).map(c => +c.dataset.u); if (!ids.length) return; try { await api('chat/rooms/' + i.id + '/members', { method: 'POST', data: { user_ids: ids } }); f.close(); m.close(); openRoom(i.id); } catch (e) { toast(e.message); } }; };
  }

  /* ---- badge and pop-up everywhere ---- */
  function setBadge(n) { S.badges = S.badges || {}; S.badges.chat = n; const a = $('.nav a[href="#/chat"]'); if (!a) return; let b = $('.badge', a); if (n) { if (!b) { b = document.createElement('span'); b.className = 'badge'; a.appendChild(b); } b.textContent = n; } else if (b) b.remove(); }
  const seen = new Set(JSON.parse(sessionStorage.getItem('ch-seen') || '[]'));
  async function watch() {
    if (!S.user || S.offline || (S.user.tabs && S.user.tabs.chat === false)) return; let u; try { u = await api('chat/unread'); } catch { return; } setBadge(u.total);
    const l = u.latest; if (l && !seen.has(l.id)) { seen.add(l.id); try { sessionStorage.setItem('ch-seen', JSON.stringify([...seen].slice(-100))); } catch {} const inRoom = /^#\/chat\/(\d+)/.exec(location.hash); if (!(inRoom && +inRoom[1] === l.room_id && !document.hidden)) toast('💬 ' + l.from + (l.room ? ' in ' + l.room : '') + ': ' + l.text); }
  }
  setTimeout(watch, 3000); setInterval(watch, 15000); document.addEventListener('visibilitychange', () => { if (!document.hidden) watch(); });
})();
