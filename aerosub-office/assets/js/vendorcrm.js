/* Aerosub Office: Vendors and Partners in the Pipeline.
 * Companies are who we sell to. Vendors are who we buy from. Partners are vendors we have a long-term agreement with.
 * Adds the Vendors and Partners tabs, "Sort leads" on the Companies tab (move a wrongly scraped company to Vendors) and, in the RFQ settings,
 * the choice of which customers may send us RFQs. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._vcLoaded || !X.pipeViews) return; X._vcLoaded = 1;
  const { api, modal, toast, esc } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const ro = () => !!(X.readOnly && X.readOnly());
  const DEAL = { not_yet: ['Not dealing yet', ''], active: ['Dealing with them', 'done'], paused: ['Paused', 'warn'], stopped: ['Stopped', 'blocked'] };
  const dtag = d => '<span class="tag ' + (DEAL[d] || DEAL.not_yet)[1] + '">' + esc((DEAL[d] || DEAL.not_yet)[0]) + '</span>';
  const fmt = d => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
  const agTag = v => { if (!v.agreement_end) return '<span class="muted small">No end date</span>'; const n = v.agreement_days_left; return '<span class="tag ' + (n < 0 ? 'blocked' : n <= 60 ? 'warn' : 'done') + '">' + (n < 0 ? 'Ended ' + fmt(v.agreement_end) : 'Until ' + fmt(v.agreement_end) + (n <= 60 ? ' · ' + n + ' days' : '')) + '</span>'; };
  const F = { vendors: { q: '', dealing: '' }, partners: { q: '', dealing: '' }, regulators: { q: '', dealing: '' } };
  const NOUN = { vendors: 'Vendor', partners: 'Partner', regulators: 'Regulatory body' };

  /* ---- the two tabs ---- */
  async function list(kind) {
    const f = F[kind], isP = kind === 'partners', isR = kind === 'regulators';
    $('#pb').innerHTML = '<div class="vc-note muted small">' + (isR ? 'Regulatory bodies are the authorities we answer to (licensing, certification, standards). Their people are regulatory contacts. You can also move companies, vendors and partners here with “Select to delete” → Move to.' : isP ? 'Partners are vendors we have a long-term agreement with. Open a vendor and choose “Partner” to move it here, or select several and use Move to.' : 'Vendors are the companies we buy from. Emails from suppliers and vendors found while prospecting land here instead of in Companies.') + '</div>' +
      '<div class="row" style="margin:8px 0 12px;flex-wrap:wrap;gap:8px"><input id="vq" placeholder="Search ' + kind + '…" value="' + esc(f.q) + '" style="max-width:260px"><select id="vd" style="width:auto"><option value="">All</option>' + Object.entries(DEAL).map(([k, v]) => '<option value="' + k + '"' + (f.dealing === k ? ' selected' : '') + '>' + v[0] + '</option>').join('') + '</select><span id="vc-cnt" class="muted small"></span>' + (ro() ? '' : '<span class="right row"><button class="btn sm" id="vnew">+ ' + NOUN[kind] + '</button></span>') + '</div><div class="card"><div class="tablewrap" id="vt"><div class="empty">Loading…</div></div></div>';
    const load = async () => {
      const qs = ['kind=' + kind]; if (f.q) qs.push('q=' + encodeURIComponent(f.q)); if (f.dealing) qs.push('dealing=' + f.dealing); let D;
      try { D = await api('vendors?' + qs.join('&')); } catch (e) { $('#vt').innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; return; }
      $('#vc-cnt').textContent = D.counts.total + ' in all · ' + D.counts.partners + ' partner' + (+D.counts.partners === 1 ? '' : 's') + ' · ' + D.counts.active + ' dealing with now';
      $('#vt').innerHTML = D.vendors.length ? '<table><thead><tr><th>Name</th><th>' + (isP ? 'Agreement' : isR ? 'Area' : 'Category') + '</th><th>Contact</th><th>Dealing</th><th>Last dealt</th><th>People</th></tr></thead><tbody>' + D.vendors.map(v => '<tr data-v="' + v.id + '" data-grp="' + kind + '" class="vc-row"><td><b>' + esc(v.name) + '</b>' + (v.is_partner && !isP ? ' <span class="tag done">Partner</span>' : '') + (v.source && v.source !== 'manual' ? '<div class="muted small">from ' + esc(({ email: 'an email', prospecting: 'prospecting', moved: 'Sort leads' })[v.source] || v.source) + '</div>' : '') + '</td><td>' + (isP ? esc(v.partner_type || '') + '<div>' + agTag(v) + '</div>' : esc(v.category || '—')) + '</td><td class="small">' + esc(v.email || v.contact_name || '—') + '</td><td>' + dtag(v.dealing) + '</td><td class="small">' + fmt(v.last_dealt_at) + '</td><td>' + (v.contacts || 0) + '</td></tr>').join('') + '</tbody></table>' : '<div class="empty">' + (f.q || f.dealing ? 'Nothing matches.' : 'No ' + kind + ' yet.') + '</div>';
      $$('[data-v]', $('#vt')).forEach(r => r.onclick = () => detail(+r.dataset.v, D));
    };
    let tm; $('#vq').oninput = e => { f.q = e.target.value; clearTimeout(tm); tm = setTimeout(load, 250); }; $('#vd').onchange = e => { f.dealing = e.target.value; load(); };
    const nb = $('#vnew'); if (nb) nb.onclick = () => detail(0, null, isP ? 'partners' : isR ? 'regulators' : ''); load();
  }
  X.pipeViews.vendors = () => list('vendors'); X.pipeViews.partners = () => list('partners'); X.pipeViews.regulators = () => list('regulators');

  /* ---- one vendor / partner ---- */
  async function detail(id, D0, asKind) {
    let v = { name: '', email: '', dealing: 'not_yet', is_partner: asKind === 'partners', is_regulator: asKind === 'regulators', contact_list: [], documents: [], products: [] };
    if (id) { try { v = (await api('vendors/' + id)).vendor; } catch (e) { toast(e.message); return; } }
    const w = ro(), dis = w ? ' disabled' : '', fld = (k, l, val, ph, type) => '<div class="field"><label>' + l + '</label><input data-k="' + k + '"' + (type ? ' type="' + type + '"' : '') + ' value="' + esc(val || '') + '" placeholder="' + esc(ph || '') + '"' + dis + '></div>';
    const m = modal('<div class="row"><h2 class="grow">' + (id ? esc(v.name) : 'New ' + (asKind === 'partners' ? 'partner' : asKind === 'regulators' ? 'regulatory body' : 'vendor')) + '</h2><button class="btn ghost sm" id="cx">✕</button></div>' +
      '<div class="grid2">' + fld('name', 'Name', v.name, 'Company we buy from') + fld('category', v.is_regulator ? 'Area they regulate' : 'What they supply', v.category, v.is_regulator ? 'Licensing, certification, marine safety…' : 'Pumps, cables, ROV parts…') + fld('email', 'Main email', v.email, 'sales@vendor.com', 'email') + fld('phone', 'Phone', v.phone) + fld('website', 'Website', v.website, 'vendor.com') + fld('address', 'Address', v.address) + '</div>' +
      '<div class="vc-box"><div class="row" style="gap:10px;flex-wrap:wrap"><div class="field" style="margin:0"><label>Do we deal with them?</label><select data-k="dealing"' + dis + '>' + Object.entries(DEAL).map(([k, x]) => '<option value="' + k + '"' + (v.dealing === k ? ' selected' : '') + '>' + x[0] + '</option>').join('') + '</select></div>' + fld('last_dealt_at', 'Last dealt on', (v.last_dealt_at || '').slice(0, 10), '', 'date') + (id && !w ? '<button type="button" class="btn ghost sm" id="vdealt" style="align-self:flex-end">We dealt today</button>' : '') + '</div>' + fld('dealing_note', 'Note on the relationship', v.dealing_note, 'e.g. supplied 2 RFQs in March, good lead time') + '</div>' +
      '<div class="field" style="margin:10px 0"><label>This is a</label><select id="vkind"' + dis + '><option value="vendor"' + (!v.is_partner && !v.is_regulator ? ' selected' : '') + '>Vendor (we buy from them)</option><option value="partner"' + (v.is_partner ? ' selected' : '') + '>Partner (long-term agreement)</option><option value="regulator"' + (v.is_regulator ? ' selected' : '') + '>Regulatory body</option></select></div>' +
      '<div id="vpbox" class="vc-box"' + (v.is_partner ? '' : ' hidden') + '><div class="grid2">' + fld('partner_type', 'Type of agreement', v.partner_type, 'Framework, distributor, teaming…') + fld('agreement_url', 'Agreement link', v.agreement_url, 'Link to the signed agreement') + fld('agreement_start', 'Starts', (v.agreement_start || '').slice(0, 10), '', 'date') + fld('agreement_end', 'Ends', (v.agreement_end || '').slice(0, 10), '', 'date') + '</div><div class="field"><label>Agreement notes</label><textarea data-k="agreement_notes" rows="2"' + dis + '>' + esc(v.agreement_notes || '') + '</textarea></div></div>' +
      '<div class="field"><label>Notes</label><textarea data-k="notes" rows="2"' + dis + '>' + esc(v.notes || '') + '</textarea></div>' +
      (id ? '<h3 style="margin:10px 0 4px">' + (v.is_regulator ? 'Regulatory contacts at ' : 'People at ') + esc(v.name) + '</h3><div class="sh-list" id="vpeople">' + (v.contact_list.length ? v.contact_list.map(c => '<div class="sh-row"><span><b>' + esc(c.name) + '</b> <span class="muted small">' + esc(c.email || '') + (c.phone ? ' · ' + esc(c.phone) : '') + '</span></span>' + (w ? '' : '<button class="btn ghost sm" data-rm="' + c.id + '">Remove</button>') + '</div>').join('') : '<div class="muted small">No one yet. Emails from this vendor add their people automatically.</div>') + '</div>' +
        (w ? '' : '<div class="row" style="margin:6px 0;gap:6px"><input id="vcn" placeholder="Name" style="max-width:170px"><input id="vce" type="email" placeholder="Email" style="max-width:220px"><button class="btn ghost sm" id="vca">+ Add person</button></div>') +
        (v.documents.length ? '<div class="small" style="margin-top:8px"><b>Requisitions:</b> ' + v.documents.map(d => '<a href="#/finance/' + d.id + '">' + esc(d.number) + '</a>').join(' · ') + '</div>' : '') + (v.products.length ? '<div class="small muted" style="margin-top:4px">' + v.products.length + ' catalogue item' + (v.products.length === 1 ? '' : 's') + ' bought from them (Finance → Catalogue).</div>' : '') : '') +
      '<div class="err" id="er" role="alert"></div><div class="row" style="margin-top:10px">' + (w ? '' : '<button class="btn" id="vsave">Save</button>') + (id && !w ? '<button class="btn ghost" id="vdel" style="margin-left:auto;color:var(--bad)">Delete</button>' : '') + '</div>');
    const read = () => { const o = {}; $$('[data-k]', m).forEach(i => o[i.dataset.k] = i.value); const k = $('#vkind', m).value; o.is_partner = k === 'partner'; o.is_regulator = k === 'regulator'; return o; };
    $('#cx', m).onclick = () => m.close(); $('#vkind', m).onchange = e => { $('#vpbox', m).hidden = e.target.value !== 'partner'; if (e.target.value === 'partner' && $('[data-k=dealing]', m).value === 'not_yet') $('[data-k=dealing]', m).value = 'active'; };
    const sv = $('#vsave', m); if (sv) sv.onclick = async () => { try { if (id) await api('vendors/' + id, { method: 'PATCH', data: read() }); else await api('vendors', { method: 'POST', data: read() }); m.close(); toast('Saved'); X.render(); } catch (e) { $('#er', m).textContent = e.message; } };
    const dl = $('#vdealt', m); if (dl) dl.onclick = async () => { await api('vendors/' + id + '/dealt', { method: 'POST', data: {} }); m.close(); toast('Marked as dealt today'); X.render(); };
    const dd = $('#vdel', m); if (dd) dd.onclick = async () => { if (!confirm('Delete ' + v.name + '? Its catalogue items stay, without a vendor.')) return; try { await api('vendors/' + id, { method: 'DELETE' }); m.close(); X.render(); } catch (e) { $('#er', m).textContent = e.message; } };
    const ad = $('#vca', m); if (ad) ad.onclick = async () => { try { await api('vendors/' + id + '/contacts', { method: 'POST', data: { name: $('#vcn', m).value, email: $('#vce', m).value } }); m.close(); detail(id); } catch (e) { $('#er', m).textContent = e.message; } };
    $$('[data-rm]', m).forEach(b => b.onclick = async () => { await api('vendors/' + id + '/contacts/' + b.dataset.rm, { method: 'DELETE' }); m.close(); detail(id); });
  }

  /* ---- Sort leads: companies that are really suppliers ---- */
  async function sortLeads() {
    let D; try { D = await api('vendors/suspects'); } catch (e) { toast(e.message); return; }
    const m = modal('<div class="row"><h2 class="grow">Sort leads</h2><button class="btn ghost sm" id="cx">✕</button></div><div class="muted small" style="margin:4px 0 8px">These companies were added automatically from an email or a search. Tick the ones we buy from: they move to Vendors (or Partners) together with their people.</div>' +
      (D.companies.length ? '<div class="sh-list" style="max-height:340px;overflow:auto">' + D.companies.map(c => '<label class="sh-row" style="cursor:pointer"><span><input type="checkbox" data-c="' + c.id + '" style="width:auto"' + (c.known_vendor ? ' checked' : '') + '> <b>' + esc(c.name) + '</b> <span class="muted small">' + esc(c.emails || c.website || '') + '</span>' + (c.known_vendor ? ' <span class="tag warn">Already a vendor</span>' : '') + '</span><span class="muted small">' + esc(c.note) + '</span></label>').join('') + '</div><div class="err" id="er" role="alert"></div><div class="row" style="margin-top:10px;gap:8px"><button class="btn" id="tov">Move to Vendors</button><button class="btn ghost" id="top">Move to Partners</button></div>' : '<div class="empty">Nothing to sort. Every company here was added by hand or has been worked on.</div>'));
    $('#cx', m).onclick = () => m.close(); if (!D.companies.length) return;
    const go = async partner => { const ids = $$('[data-c]:checked', m).map(c => +c.dataset.c); if (!ids.length) { $('#er', m).textContent = 'Tick at least one company.'; return; } let n = 0; for (const id of ids) { try { await api('vendors/from-company', { method: 'POST', data: { company_id: id, partner } }); n++; } catch (e) { $('#er', m).textContent = e.message; } } if (n) { m.close(); toast(n + ' moved to ' + (partner ? 'Partners' : 'Vendors')); X.render(); } };
    $('#tov', m).onclick = () => go(false); $('#top', m).onclick = () => go(true);
  }
  const prevCo = X.pipeViews.companies;
  X.pipeViews.companies = async (...a) => { const r = await prevCo(...a); try { const bar = $('#pb .row'); if (bar && !ro() && !$('#vsort')) { const b = document.createElement('button'); b.className = 'btn ghost sm'; b.id = 'vsort'; b.type = 'button'; b.textContent = 'Sort leads'; b.title = 'Move companies that are really suppliers to Vendors'; b.onclick = sortLeads; bar.appendChild(b); } } catch (e) { console.error(e); } return r; };
  X.sortLeads = sortLeads;

  /* ---- RFQ settings: which customers may send us RFQs ---- */
  X.rfqPeople = async function (host, onChange) {
    host.innerHTML = '<input id="rp-q" placeholder="Search customers and contacts…" style="margin-bottom:6px"><div class="sh-list" id="rp-l" style="max-height:200px;overflow:auto"><div class="muted small">Loading…</div></div>';
    const load = async () => { let D; try { D = await api('vendors/rfq-people?q=' + encodeURIComponent($('#rp-q', host).value)); } catch (e) { $('#rp-l', host).innerHTML = '<div class="muted small">' + esc(e.message) + '</div>'; return; }
      const row = (k, o, t, s) => '<label class="sh-row" style="cursor:pointer"><span><input type="checkbox" data-k="' + k + '" data-id="' + o.id + '" style="width:auto"' + (o.on ? ' checked' : '') + '> ' + t + ' <span class="muted small">' + esc(s || '') + '</span></span></label>';
      $('#rp-l', host).innerHTML = (D.companies.map(c => row('company', c, '<b>' + esc(c.name) + '</b>', c.website ? '@' + String(c.website).replace(/^https?:\/\/(www\.)?/i, '').split('/')[0] + ' (everyone there)' : 'company')).join('') + D.contacts.map(c => row('contact', c, esc(c.name), c.email + (c.company ? ' · ' + c.company : ''))).join('')) || '<div class="muted small">No customers or contacts yet. Add them in the Pipeline first.</div>';
      $$('[data-k]', host).forEach(i => i.onchange = async () => { try { await api('vendors/rfq-ok', { method: 'POST', data: { kind: i.dataset.k, id: +i.dataset.id, on: i.checked } }); if (onChange) onChange(); } catch (e) { toast(e.message); i.checked = !i.checked; } }); };
    let tm; $('#rp-q', host).oninput = () => { clearTimeout(tm); tm = setTimeout(load, 250); }; load();
  };
})();
