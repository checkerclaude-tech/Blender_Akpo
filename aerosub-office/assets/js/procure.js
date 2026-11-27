/* Aerosub Office: Procurement. One menu for the buying-and-selling paperwork: RFQ & PO tracker, delivery notes, quotations (proforma), commercials and invoices.
 * The RFQ tracker and delivery notes moved here from Finance; the documents are the same ones Finance keeps, listed by type. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || X._pcLoaded) return; X._pcLoaded = 1;
  const { api, esc, S } = X; const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const can = () => !!(S.user && S.user.finance);
  const TABS = [['rfqs', 'RFQs & POs'], ['delivery', 'Delivery notes'], ['proforma', 'Quotations'], ['commercial', 'Commercials'], ['invoice', 'Invoices']];
  const ICON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/></svg>';
  const prevNav = X.nav; X.nav = (items, icons) => { if (prevNav) prevNav(items, icons); if (can()) { const at = items.findIndex(x => x[0] === 'finance'); items.splice(at < 0 ? items.length : at, 0, ['procurement', 'Procurement', ICON]); } };
  const DF = { proforma: { q: '', status: '' }, commercial: { q: '', status: '' }, invoice: { q: '', status: '' } };
  const NOUN = { proforma: ['Quotations', 'quotation', 'Proforma invoices sent to clients as quotations.'], commercial: ['Commercials', 'commercial', 'Commercial offers and quotations with their breakdown.'], invoice: ['Invoices', 'invoice', 'Invoices to clients.'] };

  X.views.procurement = async () => {
    const v = $('#view'); if (!can()) { v.innerHTML = '<div class="card empty">Procurement is for Finance and administrators.</div>'; return; }
    const p = location.hash.split('/'), tab = TABS.some(t => t[0] === p[2]) ? p[2] : 'rfqs';
    v.innerHTML = '<div class="chips fin-tabs pc-tabs" role="tablist" style="margin-bottom:12px">' + TABS.map(([k, l]) => '<button type="button" role="tab" class="chip' + (tab === k ? ' on' : '') + '" data-pt="' + k + '" aria-selected="' + (tab === k) + '">' + l + '</button>').join('') + '</div><div id="pc-body"><div class="empty">Loading…</div></div>';
    $$('[data-pt]', v).forEach(b => b.onclick = () => { location.hash = '#/procurement/' + b.dataset.pt; });
    try { if (tab === 'rfqs') await X.rfqViews.rfqs(); else if (tab === 'delivery') await X.rfqViews.delivery(); else await docList(tab); } catch (e) { $('#pc-body').innerHTML = '<div class="card empty">' + esc(e.message || 'Something went wrong') + '</div>'; }
  };

  async function docList(type) {
    const box = $('#pc-body'), f = DF[type], [title, one, hint] = NOUN[type]; box.innerHTML = '<div class="empty">Loading…</div>';
    const qs = new URLSearchParams({ type }); if (f.q) qs.set('q', f.q); if (f.status) qs.set('status', f.status); const d = await api('finance/docs?' + qs.toString());
    const FS = { draft: 'Draft', sent: 'Sent', paid: 'Paid', void: 'Void' }, tag = s => '<span class="tag ' + (s === 'paid' ? 'done' : s === 'sent' ? 'in_progress' : s === 'void' ? 'blocked' : '') + '">' + esc(FS[s] || s) + '</span>';
    box.innerHTML = '<div class="top"><div><h1>' + title + '</h1><div class="muted">' + hint + ' They are the same documents as in Finance.</div></div>' + (X.readOnly() ? '' : '<button class="btn right" id="pc-new">+ New ' + one + '</button>') + '</div>' +
      '<div class="row" style="margin-bottom:12px;gap:8px;flex-wrap:wrap"><input id="pc-q" placeholder="Search number, client, PO, RFQ…" style="max-width:300px" value="' + esc(f.q) + '"><select id="pc-s" style="width:auto"><option value="">All statuses</option>' + Object.entries(FS).map(([k, l]) => '<option value="' + k + '"' + (f.status === k ? ' selected' : '') + '>' + l + '</option>').join('') + '</select><span class="muted small">' + d.docs.length + ' document' + (d.docs.length === 1 ? '' : 's') + '</span></div>' +
      '<div class="card"><div class="tablewrap"><table><thead><tr><th>Number</th><th>Client</th><th>PO / RFQ</th><th>Date</th><th>Status</th><th style="text-align:right">Total</th></tr></thead><tbody id="fr">' + (d.docs.length ? d.docs.map(x => '<tr style="cursor:pointer" data-d="' + x.id + '" tabindex="0"><td><b>' + esc(x.number) + '</b></td><td>' + esc(x.client_name) + '</td><td class="small">' + esc([x.po_number && 'PO ' + x.po_number, x.rfq_ref && 'RFQ ' + x.rfq_ref].filter(Boolean).join(' · ')) + '</td><td>' + esc(X.fmtDate(x.issue_date)) + '</td><td>' + tag(x.status) + '</td><td style="text-align:right">' + esc(X.fmoney(x.total, x.currency, x.currency_symbol)) + '</td></tr>').join('') : '<tr><td colspan="6" class="empty">No ' + title.toLowerCase() + ' yet.</td></tr>') + '</tbody></table></div></div>';
    let tm; $('#pc-q', box).oninput = e => { f.q = e.target.value.trim(); clearTimeout(tm); tm = setTimeout(() => docList(type), 350); }; $('#pc-s', box).onchange = e => { f.status = e.target.value; docList(type); };
    $$('[data-d]', box).forEach(r => { const go = () => { location.hash = '#/finance/' + r.dataset.d; }; r.onclick = go; r.onkeydown = e => { if (e.key === 'Enter') go(); }; });
    const nb = $('#pc-new', box); if (nb) nb.onclick = () => { if (window.OfficeFin) window.OfficeFin.form(null, { doc_type: type }); else location.hash = '#/finance'; };
  }
})();
