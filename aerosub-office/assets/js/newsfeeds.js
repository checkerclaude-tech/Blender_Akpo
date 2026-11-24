/* Aerosub Office: outside news feeds (RSS / Atom) on the News page and as a rotating headline on every page.
 * Managers and admins add a feed address and keywords; only matching items are kept, old ones expire and each feed keeps only its newest few, so the list keeps changing. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._nfLoaded) return; X._nfLoaded = 1;
  const { api, modal, toast, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const ago = t => { if (!t) return ''; const s = (Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 1000; if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; };
  const canManage = () => S.user && ['super_admin', 'manager'].includes(S.user.role);
  let cache = null;
  const hidden = () => { try { return JSON.parse(localStorage.getItem('nfhide:' + S.user.id) || '[]'); } catch { return []; } };
  const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u; } };

  /* ---- News page ---- */
  const orig = X.views.news;
  X.views.news = async (...a) => {
    await orig(...a); const v = $('#view'); if (!v || !$('h1', v)) return;
    let items = []; try { items = await api('news/feed-items'); } catch {}
    cache = { t: Date.now(), l: items }; const old = $('#feedtick'); if (old) old.remove();
    const card = document.createElement('div'); card.className = 'card'; card.id = 'nf-items'; card.style.marginTop = '14px';
    const paint = () => { const gone = hidden(); const list = items.filter(i => !gone.includes(i.id));
      card.innerHTML = '<div class="row"><h2 class="grow" style="margin:0">From your feeds</h2><span class="muted small">Always the latest; older stories drop off</span></div><div class="list" style="margin-top:8px">' + (list.length ? list.map(i => '<div class="item" style="align-items:flex-start"><div class="grow"><b>' + (i.link ? '<a href="' + esc(i.link) + '" target="_blank" rel="noopener noreferrer" style="color:inherit">' + esc(i.title) + '</a>' : esc(i.title)) + '</b><div class="muted small">' + esc(i.source) + ' · ' + ago(i.published_at) + (i.matched ? ' · <span class="tag">' + esc(i.matched) + '</span>' : '') + '</div>' + (i.summary ? '<div class="small" style="margin-top:3px">' + esc(i.summary) + '</div>' : '') + '</div><button class="btn ghost sm" data-nfh="' + i.id + '" title="Hide this story" aria-label="Hide this story">✕</button></div>').join('') : '<div class="empty">No matching stories right now.' + (canManage() ? ' Add a feed below.' : '') + '</div>') + '</div>';
      $$('[data-nfh]', card).forEach(b => b.onclick = () => { const g = hidden(); g.push(+b.dataset.nfh); try { localStorage.setItem('nfhide:' + S.user.id, JSON.stringify(g.slice(-300))); } catch {} paint(); }); };
    paint(); const first = $('.card', v); first ? first.after(card) : v.appendChild(card);
    if (canManage()) { const mc = document.createElement('div'); mc.className = 'card'; mc.id = 'nf-manage'; mc.style.marginTop = '14px'; v.appendChild(mc); manage(mc, () => X.render()); }
  };

  /* ---- manager ---- */
  async function manage(mc, done) {
    let feeds = []; try { feeds = await api('news/feeds'); } catch (e) { mc.innerHTML = '<div class="muted">' + esc(e.message) + '</div>'; return; }
    mc.innerHTML = '<div class="row"><h2 class="grow" style="margin:0">News feeds</h2><button class="btn" id="nf-add">+ Add a feed</button></div><div class="muted small" style="margin:4px 0 8px">Paste the address of an RSS or Atom feed and the words you care about. Only stories that mention them are kept.</div><div class="list">' +
      (feeds.length ? feeds.map(f => '<div class="item" style="align-items:flex-start"><div class="grow"><b>' + esc(f.name) + '</b>' + (f.active ? '' : ' <span class="tag">paused</span>') + '<div class="muted small">' + esc(host(f.url)) + ' · ' + esc(f.department || 'Everyone') + ' · ' + f.items + ' kept (newest ' + f.max_items + ', for ' + f.keep_days + ' days) · ' + (f.last_fetch ? 'read ' + ago(f.last_fetch) : 'not read yet') + '</div>' +
        '<div class="small">' + (f.keywords ? 'Keywords: ' + esc(f.keywords) : 'All stories') + (f.exclude_words ? ' · Skip: ' + esc(f.exclude_words) : '') + '</div>' + (f.last_error ? '<div class="small" style="color:var(--bad)">⚠ ' + esc(f.last_error) + '</div>' : '') + '</div><button class="btn ghost sm" data-nfr="' + f.id + '">Refresh</button><button class="btn ghost sm" data-nfe="' + f.id + '">Edit</button></div>').join('') : '<div class="empty">No feeds yet.</div>') + '</div>';
    const by = Object.fromEntries(feeds.map(f => [f.id, f])); $('#nf-add', mc).onclick = () => form(null, done);
    $$('[data-nfe]', mc).forEach(b => b.onclick = () => form(by[b.dataset.nfe], done));
    $$('[data-nfr]', mc).forEach(b => b.onclick = async () => { b.disabled = true; b.textContent = 'Reading…'; try { const r = await api('news/feeds/' + b.dataset.nfr + '/refresh', { method: 'POST', data: {} }); toast(r.error ? r.error : r.added + ' new ' + (r.added === 1 ? 'story' : 'stories')); } catch (e) { toast(e.message); } done(); });
  }
  function form(f, done) {
    const su = S.user.role === 'super_admin', mine = (S.user.department_ids || []).map(Number), dl = (su ? [['', 'Everyone']] : []).concat((S.depts || []).filter(d => su || mine.includes(d.id)).map(d => [d.id, d.name])); const x = f || { max_items: 12, keep_days: 7, every_min: 60, active: 1 };
    const sel = (id, arr, cur) => '<select id="' + id + '">' + arr.map(([v, l]) => '<option value="' + v + '"' + (+v === +cur ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>';
    const m = modal('<div class="row"><h2 class="grow">' + (f ? 'Edit feed' : 'Add a feed') + '</h2><button class="btn ghost sm" id="cx">✕</button></div><div style="margin-top:10px">' +
      '<div class="field"><label>Feed address (RSS or Atom)</label><div class="row"><input id="nf-url" class="grow" placeholder="https://example.com/feed.xml" value="' + esc(x.url || '') + '"><button class="btn ghost" type="button" id="nf-test">Check</button></div><div class="muted small">Most news sites have one; try adding /feed or /rss to the site address.</div></div><div id="nf-prev"></div>' +
      '<div class="field"><label>Name (optional)</label><input id="nf-name" maxlength="160" value="' + esc(x.name || '') + '"></div>' +
      '<div class="field"><label>Keywords: keep stories that mention any of these</label><textarea id="nf-kw" rows="2" maxlength="600" placeholder="drone, inspection, oil and gas, tender">' + esc(x.keywords || '') + '</textarea><div class="muted small">Separate with commas. Leave empty to keep every story.</div></div>' +
      '<div class="field"><label>Skip stories that mention</label><input id="nf-ex" maxlength="400" placeholder="sport, celebrity" value="' + esc(x.exclude_words || '') + '"></div>' +
      '<div class="grid2"><div class="field"><label>Who sees it</label><select id="nf-dept">' + dl.map(([v, l]) => '<option value="' + v + '"' + (String(v) === String(x.department_id ?? '') ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div><div class="field"><label>Keep at most</label>' + sel('nf-max', [[5, '5 stories'], [8, '8 stories'], [12, '12 stories'], [20, '20 stories'], [30, '30 stories']], x.max_items) + '</div></div>' +
      '<div class="grid2"><div class="field"><label>Drop stories older than</label>' + sel('nf-keep', [[1, '1 day'], [3, '3 days'], [7, '1 week'], [14, '2 weeks'], [30, '1 month']], x.keep_days) + '</div><div class="field"><label>Look for new stories</label>' + sel('nf-every', [[30, 'every 30 minutes'], [60, 'every hour'], [180, 'every 3 hours'], [360, 'every 6 hours'], [1440, 'once a day']], x.every_min) + '</div></div>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nf-on" style="width:auto"' + (x.active ? ' checked' : '') + '> Active</label><div class="err" id="er" role="alert"></div>' +
      '<div class="row" style="margin-top:12px"><button class="btn" id="nf-save">Save</button>' + (f ? '<button class="btn ghost right" id="nf-del" style="color:var(--bad)">Delete</button>' : '') + '</div></div>');
    $('#cx', m).onclick = () => m.close(); const data = () => ({ url: $('#nf-url', m).value.trim(), name: $('#nf-name', m).value.trim(), keywords: $('#nf-kw', m).value.trim(), exclude_words: $('#nf-ex', m).value.trim(), department_id: $('#nf-dept', m).value || null, max_items: +$('#nf-max', m).value, keep_days: +$('#nf-keep', m).value, every_min: +$('#nf-every', m).value, active: $('#nf-on', m).checked });
    $('#nf-test', m).onclick = async () => { const d = data(); if (!d.url) { $('#er', m).textContent = 'Paste the feed address first'; return; } $('#er', m).textContent = ''; $('#nf-prev', m).innerHTML = '<div class="muted small">Checking…</div>';
      try { const r = await api('news/feeds/test', { method: 'POST', data: d }); if (!$('#nf-name', m).value) $('#nf-name', m).value = r.title || '';
        $('#nf-prev', m).innerHTML = '<div class="card" style="margin-bottom:10px"><b>' + r.kept + ' of ' + r.total + ' stories match</b>' + (r.sample.length ? '<ul style="margin:6px 0 0 18px">' + r.sample.map(s => '<li>' + esc(s.title) + (s.matched ? ' <span class="tag">' + esc(s.matched) + '</span>' : '') + '</li>').join('') + '</ul>' : '<div class="muted small">Nothing matches right now. Try other words, or leave the keywords empty.</div>') + '</div>'; }
      catch (e) { $('#nf-prev', m).innerHTML = ''; $('#er', m).textContent = e.message; } };
    $('#nf-save', m).onclick = async () => { const d = data(); if (!d.url) { $('#er', m).textContent = 'Paste the feed address'; return; } $('#nf-save', m).disabled = true;
      try { const r = await api('news/feeds' + (f ? '/' + f.id : ''), { method: f ? 'PATCH' : 'POST', data: d }); m.close(); toast(r.error ? 'Saved, but: ' + r.error : 'Saved · ' + (r.added || 0) + ' stories kept'); done(); } catch (e) { $('#er', m).textContent = e.message; $('#nf-save', m).disabled = false; } };
    if (f) $('#nf-del', m).onclick = async () => { if (!confirm('Delete this feed and its stories?')) return; try { await api('news/feeds/' + f.id, { method: 'DELETE' }); m.close(); done(); } catch (e) { toast(e.message); } };
  }

  /* ---- the rotating headline above every page ---- */
  let idx = Math.floor(Date.now() / 3600000), timer = null;
  async function items() { if (!cache || Date.now() - cache.t > 300000) { try { cache = { t: Date.now(), l: await api('news/feed-items') }; } catch { cache = { t: Date.now(), l: [] }; } } const gone = hidden(); return cache.l.filter(i => !gone.includes(i.id)).slice(0, 10); }
  async function tick() {
    const b = $('#banners'); if (!b || !S.user || S.offline) return; let el = $('#feedtick'); const list = await items();
    if (!list.length) { if (el) el.remove(); return; }
    if (!el) { el = document.createElement('div'); el.id = 'feedtick'; el.className = 'noprint'; el.style.cssText = 'display:flex;gap:10px;align-items:center;padding:7px 12px;border-radius:12px;margin-bottom:10px;font-size:13.5px;background:var(--card,#fff);border:1px solid var(--line,#dfe3ea)'; b.after(el); }
    const i = list[idx % list.length]; el.innerHTML = '<span aria-hidden="true">📰</span><div class="grow" style="min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><b>' + (i.link ? '<a href="' + esc(i.link) + '" target="_blank" rel="noopener noreferrer" style="color:inherit">' + esc(i.title) + '</a>' : esc(i.title)) + '</b> <span class="muted small">' + esc(i.source) + ' · ' + ago(i.published_at) + '</span></div><a class="small" href="#/news">All news</a>';
  }
  let busy = false; new MutationObserver(() => { if (busy) return; busy = true; setTimeout(async () => { try { if ($('#banners') && !$('#feedtick')) await tick(); } finally { busy = false; } }, 120); }).observe(document.body, { childList: true, subtree: true });
  timer = setInterval(() => { idx++; tick(); }, 12000);
})();
