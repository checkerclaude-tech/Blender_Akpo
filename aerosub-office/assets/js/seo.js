/* Aerosub Office: SEO autopilot feed for the Campaigns team (menu: SEO, next to Campaigns).
 * Shows what the daily SEO run did on aerosub.co: ranking updates, changes made, weekly reports, and requests that need approval.
 * The server decides who may approve (api/lib/seo.php); this only shows it. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, S, views, render } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5M8.5 12.5l2-2 1.5 1.5 2.5-3"/></svg>';
  const KIND = { approval: 'Needs approval', change: 'Change made', ranking: 'Ranking update', report: 'Weekly report' };
  const ST = { pending: ['Waiting for you', 'urgent'], approved: ['Approved', 'in_progress'], rejected: ['Rejected', 'blocked'], done: ['Done', 'done'], info: ['', ''] };
  const TABS = [['all', 'Everything'], ['approval', 'Approvals'], ['change', 'Changes made'], ['ranking', 'Rankings'], ['report', 'Reports'], ['settings', 'Competitors & schedule']];
  const DAYS = [['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday']];
  const when = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const text = v => esc(v || '').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  let tab = 'all'; try { tab = localStorage.getItem('seotab') || 'all'; } catch {}

  /* ---------- menu: right after Campaigns, for the same people ---------- */
  const show = () => S.user && S.user.campaigns;
  const prevNav = X.nav; X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); if (show()) { const at = items.findIndex(x => x[0] === 'campaigns'); items.splice(at < 0 ? items.length : at + 1, 0, ['seo', 'SEO', ICON]); } };
  const prevMore = X.more; X.more = items => { if (prevMore) prevMore(items); if (show()) { const at = items.findIndex(x => x[0] === 'campaigns'); items.splice(at < 0 ? items.length : at + 1, 0, ['seo', 'SEO (aerosub.co)', null]); } };

  function card(e, canDecide) {
    const st = ST[e.status] || ['', ''];
    const head = '<div class="row seo-head"><span class="seo-kind seo-' + esc(e.kind) + '">' + esc(KIND[e.kind] || e.kind) + '</span>' + (st[0] ? '<span class="tag ' + st[1] + '">' + esc(st[0]) + '</span>' : '') +
      '<span class="grow"></span><span class="muted small">' + esc(when(e.created_at)) + '</span></div>';
    const link = e.url ? '<div class="small seo-url"><a href="' + esc(e.url) + '" target="_blank" rel="noopener noreferrer">' + esc(e.url.replace(/^https?:\/\//, '')) + '</a></div>' : '';
    const body = e.body ? '<div class="seo-body">' + text(e.body) + '</div>' : '';
    let foot = '';
    if (e.kind === 'approval' && e.status === 'pending' && canDecide) foot = '<div class="row seo-act"><button class="btn sm" data-ok="' + e.id + '">Approve</button><button class="btn ghost sm" data-no="' + e.id + '">Reject</button></div>';
    else if (e.kind === 'approval' && e.status === 'pending') foot = '<div class="muted small">A manager, the Founder or the Super Admin can approve this.</div>';
    else if (e.decided_at) foot = '<div class="muted small">' + esc(st[0]) + ' by ' + esc(e.decided_by_name || 'someone') + ', ' + esc(when(e.decided_at)) + (e.decision_comment ? ': “' + esc(e.decision_comment) + '”' : '') + (e.done_at ? ' · applied ' + esc(when(e.done_at)) : '') + '</div>';
    return '<div class="card seo-card' + (e.status === 'pending' ? ' seo-pending' : '') + '">' + head + '<h3 class="seo-title">' + esc(e.title) + '</h3>' + link + body + foot + '</div>';
  }

  async function decide(id, decision) {
    let comment = '';
    if (decision === 'reject') { comment = prompt('Why not? (the autopilot reads this before its next run)') || ''; if (!comment.trim()) return; }
    try { await api('seo/entries/' + id + '/decide', { method: 'POST', data: { decision, comment } }); toast(decision === 'approve' ? 'Approved: it goes live on the next run' : 'Rejected'); render(); }
    catch (er) { toast(er.message); }
  }

  function newKey() {
    const m = modal('<div class="row"><h2 class="grow">Connect the SEO autopilot</h2><button class="btn ghost sm" id="sk-x">✕</button></div>' +
      '<p class="muted small">A key lets the daily SEO run post here and read your approvals and settings. It can do nothing else in Office. You see a new key once: put it in the SEO run\'s environment as <b>OFFICE_SEO_TOKEN</b>.</p>' +
      '<div id="sk-list" class="small"></div><div class="row" style="margin-top:10px"><button class="btn" id="sk-go">Create key</button></div><div id="sk-out"></div>');
    const list = async () => {
      try {
        const ts = (await api('agent-tokens')).filter(t => t.scope === 'seo' && !t.revoked_at);
        $('#sk-list', m).innerHTML = ts.length ? '<b>Active keys</b>' + ts.map(t => '<div class="row seo-key"><span class="grow">' + esc(t.label) + ' · made ' + esc(when(t.created_at)) + (t.last_used_at ? ' · last used ' + esc(when(t.last_used_at)) : ' · never used') + '</span><button class="btn ghost sm" data-rv="' + t.id + '">Revoke</button></div>').join('') : '<span class="muted">No active key yet.</span>';
        $$('[data-rv]', m).forEach(b => b.onclick = async () => { if (!confirm('Revoke this key? The SEO run stops posting here until it gets a new one.')) return; try { await api('agent-tokens/' + b.dataset.rv, { method: 'DELETE' }); toast('Key revoked'); list(); } catch (er) { toast(er.message); } });
      } catch (er) { $('#sk-list', m).innerHTML = '<span class="muted">' + esc(er.message) + '</span>'; }
    };
    list();
    $('#sk-x', m).onclick = () => m.close();
    $('#sk-go', m).onclick = async () => {
      try { const t = await api('agent-tokens', { method: 'POST', data: { label: 'SEO autopilot', scope: 'seo' } });
        $('#sk-go', m).disabled = true;
        $('#sk-out', m).innerHTML = '<div class="field" style="margin-top:12px"><label>New key (shown once)</label><input readonly id="sk-k" value="' + esc(t.token) + '"></div>';
        $('#sk-k', m).select(); list();
      } catch (er) { toast(er.message); }
    };
  }

  /* ---------- competitors and run times: the daily run reads these at the start of every run ---------- */
  function settingsHtml(st) {
    const ro = !st.can_edit, dis = ro ? ' disabled' : '';
    const row = (c, i) => '<div class="row seo-comp" data-i="' + i + '"><input class="grow" data-f="name" placeholder="Company name" maxlength="80" value="' + esc(c.name || '') + '"' + dis + '><input class="grow" data-f="url" placeholder="Website (optional)" maxlength="200" value="' + esc(c.url || '') + '"' + dis + '>' + (ro ? '' : '<button type="button" class="btn ghost sm" data-rm="' + i + '" aria-label="Remove ' + esc(c.name || 'competitor') + '">Remove</button>') + '</div>';
    return '<div class="card seo-card"><h3 class="seo-title">Competitors</h3><p class="muted small">The SEO run watches these sites for new pages and compares rankings. Leave it empty and it finds competitors by itself.</p>' +
      '<div id="seo-comps">' + (st.competitors.length ? st.competitors.map(row).join('') : '<div class="muted small">None added yet.</div>') + '</div>' +
      (ro ? '' : '<button type="button" class="btn ghost sm" id="seo-add" style="margin-top:8px">+ Add competitor</button>') + '</div>' +
      '<div class="card seo-card"><h3 class="seo-title">Schedule</h3><div class="seo-sched"><div class="field"><label for="seo-rt">Daily run time (' + esc(st.timezone) + ')</label><input type="time" id="seo-rt" value="' + esc(st.run_time) + '"' + dis + '></div>' +
      '<div class="field"><label for="seo-rd">Weekly report day</label><select id="seo-rd"' + dis + '>' + DAYS.map(d => '<option value="' + d[0] + '"' + (d[0] === st.report_day ? ' selected' : '') + '>' + d[1] + '</option>').join('') + '</select></div></div>' +
      '<p class="muted small">A new run time takes effect from the next day’s run.</p></div>' +
      (ro ? '<div class="muted small">Only a manager, the Founder or the Super Admin can change these.</div>' : '<div class="row"><button class="btn" id="seo-save">Save</button>' + (st.updated_at ? '<span class="muted small">Last changed ' + esc(when(st.updated_at)) + '</span>' : '') + '</div>');
  }
  function wireSettings(v, st) {
    const read = () => $$('.seo-comp', v).map(r => ({ name: $('[data-f=name]', r).value.trim(), url: $('[data-f=url]', r).value.trim() }));
    const redraw = list => { st.competitors = list; $('#seo-body', v).innerHTML = settingsHtml(st); wireSettings(v, st); };
    const add = $('#seo-add', v);
    if (add) add.onclick = () => { redraw(read().concat([{ name: '', url: '' }])); const r = $$('.seo-comp', v); if (r.length) $('[data-f=name]', r[r.length - 1]).focus(); };
    $$('[data-rm]', v).forEach(b => b.onclick = () => { const list = read(); list.splice(+b.dataset.rm, 1); redraw(list); });
    const save = $('#seo-save', v);
    if (save) save.onclick = async () => {
      save.disabled = true;
      try { const r = await api('seo/settings', { method: 'PUT', data: { competitors: read().filter(c => c.name), run_time: $('#seo-rt', v).value, report_day: $('#seo-rd', v).value } }); toast('Saved. The next SEO run uses these.'); st.updated_at = r.updated_at; redraw(r.competitors); }
      catch (er) { toast(er.message); save.disabled = false; }
    };
  }

  views.seo = async () => {
    const v = $('#view');
    if (!show()) { v.innerHTML = '<div class="empty">You do not have access to Campaigns, so SEO is hidden too.</div>'; return; }
    const isSet = tab === 'settings';
    const q = tab === 'all' || isSet ? '' : '?kind=' + encodeURIComponent(tab);
    const [sum, list, st] = await Promise.all([api('seo/summary'), isSet ? { items: [] } : api('seo/entries' + q), isSet ? api('seo/settings') : null]);
    v.innerHTML = '<div class="top"><div><h1>SEO · aerosub.co</h1><div class="muted">What the daily SEO run found, changed and needs from you' + (sum.last_at ? ' · last update ' + esc(when(sum.last_at)) : '') + '</div></div>' +
      (S.user.role === 'super_admin' ? '<button class="btn ghost sm" id="seo-key">Connect autopilot</button>' : '') + '</div>' +
      '<div class="seo-stats"><div class="stat' + (sum.pending ? ' seo-hot' : '') + '"><b>' + sum.pending + '</b><span>waiting for approval</span></div><div class="stat"><b>' + sum.changes7 + '</b><span>changes this week</span></div><div class="stat"><b>' + sum.rankings7 + '</b><span>ranking updates this week</span></div></div>' +
      '<div class="ac-seg seo-tabs" role="tablist">' + TABS.map(t => '<button type="button" class="ac-b' + (t[0] === tab ? ' sel' : '') + '" data-t="' + t[0] + '">' + t[1] + '</button>').join('') + '</div>' +
      '<div id="seo-body">' + (st ? settingsHtml(st) : list.items.length ? list.items.map(e => card(e, list.can_decide)).join('') : '<div class="empty">Nothing here yet. The SEO run posts after its next daily check.</div>') + '</div>';
    $$('[data-t]', v).forEach(b => b.onclick = () => { tab = b.dataset.t; try { localStorage.setItem('seotab', tab); } catch {} render(); });
    $$('[data-ok]', v).forEach(b => b.onclick = () => decide(b.dataset.ok, 'approve'));
    $$('[data-no]', v).forEach(b => b.onclick = () => decide(b.dataset.no, 'reject'));
    const k = $('#seo-key', v); if (k) k.onclick = newKey;
    if (st) wireSettings(v, st);
    const sel = $('.seo-tabs .sel', v); if (sel && sel.scrollIntoView) sel.scrollIntoView({ inline: 'center', block: 'nearest' });
  };
})();
