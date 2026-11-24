/* Aerosub Office: invoice and commercial templates (forms and print layouts), loaded after app.js.
 * app.js hands these three jobs to window.OfficeFin when it is present: settings(), form(doc, preset), html(doc).
 * Layouts follow the Aerosub invoice (A4 portrait) and commercial (A4 landscape) Word templates. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X || !X.fmoney) return;
  const { api, modal, toast, esc, S, fmoney, fmtDate, FTYPE, today } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const opt = (v, l, sel) => '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>';
  const DAILY = ['day rate', 'per day', 'daily'];
  const FREQS = ['Fixed', 'Day rate', 'Rate per hour', 'Per person/day', 'Flat rate/day'];
  const NOTES = ['As incurred', 'Optional', 'As applicable'];

  /* ---------- helpers ---------- */
  const lineOf = (i, type) => { // [shown, counted]: same rules as the server. Quantity x unit rate; for a day rate the quantity is the number of days.
    const base = Math.round((+i.qty || 0) * (+i.unit_price || 0) * 100) / 100;
    if (type !== 'commercial') return [base, base];
    if (String(i.amount_note || '').trim()) return [0, 0];
    return [base, base];
  };
  const ordinalDate = v => { if (!v) return ''; const d = new Date(String(v).slice(0, 10) + 'T00:00:00'), n = d.getDate(), sfx = n % 10 === 1 && n !== 11 ? 'st' : n % 10 === 2 && n !== 12 ? 'nd' : n % 10 === 3 && n !== 13 ? 'rd' : 'th'; return n + sfx + ' ' + d.toLocaleDateString('en-GB', { month: 'long' }) + ', ' + d.getFullYear(); };
  const ONES = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
  const TENS = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
  const below100 = n => n < 20 ? ONES[n] : TENS[Math.floor(n / 10)] + (n % 10 ? '-' + ONES[n % 10] : '');
  const below1000 = n => { const h = Math.floor(n / 100), r = n % 100; return (h ? ONES[h] + ' HUNDRED' + (r ? ' AND ' : '') : '') + (r ? below100(r) : ''); };
  const SCALE = ['', ' THOUSAND', ' MILLION', ' BILLION', ' TRILLION'];
  /** Whole number to British-style words: "NINE MILLION, ONE HUNDRED AND FORTY-TWO THOUSAND, TWO HUNDRED AND THIRTY". */
  const words = n => {
    n = Math.floor(Math.abs(+n) || 0); if (n === 0) return 'ZERO';
    const groups = []; for (let i = 0; n > 0; i++, n = Math.floor(n / 1000)) { const g = n % 1000; if (g) groups.unshift({ g, i }); }
    return groups.map((x, k) => { const w = below1000(x.g) + SCALE[x.i]; return k === 0 ? w : ((k === groups.length - 1 && x.i === 0 && x.g < 100) ? ' AND ' : ', ') + w; }).join('');
  };
  const CUR = { NGN: ['NAIRA', 'KOBO'], USD: ['DOLLARS', 'CENTS'], GBP: ['POUNDS', 'PENCE'], EUR: ['EUROS', 'CENTS'] };
  const amountWords = (amt, cur) => {
    const cents = Math.round(Math.abs(+amt || 0) * 100), whole = Math.floor(cents / 100), fr = cents % 100, [maj, min] = CUR[String(cur).toUpperCase()] || [String(cur).toUpperCase(), 'SUBUNITS'];
    return (+amt < 0 ? 'MINUS ' : '') + words(whole) + ' ' + maj + (fr ? ' AND ' + words(fr) + ' ' + min : '') + ' ONLY';
  };
  const nl = v => esc(v || '').replace(/\n/g, '<br>');
  const SCOPE = { product: 'Products', service: 'Services', both: 'Products & services' };
  const scopeOf = d => d.scope || (() => { const k = [...new Set((d.items || []).map(i => i.kind || 'service'))]; return k.length > 1 ? 'both' : (k[0] || 'service'); })();
  const num = v => { const n = +v; return Number.isFinite(n) ? (Math.round(n * 1000) / 1000).toString() : ''; };

  /* ---------- company details (the seller on every document) ---------- */
  async function settings() {
    const { settings: s } = await api('finance/settings'); const F = (n, l, t, ph) => '<div class="field"><label>' + l + '</label>' + (t === 'ta' ? '<textarea name="' + n + '" rows="3">' + esc(s[n] || '') + '</textarea>' : '<input name="' + n + '" value="' + esc(s[n] ?? '') + '"' + (ph ? ' placeholder="' + esc(ph) + '"' : '') + '>') + '</div>';
    const m = modal('<h2>Company details on documents</h2><form id="fsf" style="margin-top:10px"><div class="grid2">' + F('company_name', 'Company name') + F('rc_number', 'RC number') + '</div><div class="grid2">' + F('tax_id', 'VAT registration number') + F('website', 'Website') + '</div>' + F('address', 'Address', 'ta') + '<div class="grid2">' + F('email', 'Email') + F('phone', 'Phone') + '</div>' +
      '<h3 style="margin:10px 0 6px">Payment should be made to</h3><div class="grid2">' + F('bank_name', 'Bank name') + F('account_name', 'Account name') + '</div><div class="grid2">' + F('account_number', 'Account number') + F('inv_next', 'Next invoice number', '', 'e.g. 100026706') + '</div><div class="muted small" style="margin:-4px 0 10px">Invoice numbers are given out automatically, one after another. Change the next number only if you need to jump ahead.</div>' + F('bank_details', 'Other payment instructions (optional)', 'ta') +
      '<div class="grid2"><div class="field"><label>Default currency</label><select id="scur">' + curOptions(s.currency || 'NGN') + '</select><div id="scur2" class="grid2" style="margin-top:6px;display:none"><input id="scc" maxlength="5" placeholder="Code, e.g. GHS" aria-label="Currency code"><input id="scs" maxlength="6" placeholder="Sign, e.g. GH₵" aria-label="Currency sign"></div></div>' + F('tax_label', 'Tax name (e.g. VAT)') + '</div><div class="grid2">' + F('tax_rate', 'Default tax rate (%)') + '<div></div></div>' + F('terms', 'Default terms and conditions', 'ta') + F('footer', 'Footer line') + '<div class="err" id="er"></div><div class="row"><button class="btn">Save</button><button class="btn ghost" type="button" id="cx">Cancel</button></div></form>');
    $('#cx', m).onclick = () => m.close();
    const sc = $('#scur', m), box = $('#scur2', m); if (sc.value === 'OTHER') { box.style.display = ''; $('#scc', m).value = s.currency || ''; $('#scs', m).value = s.currency_symbol || ''; } sc.onchange = () => { box.style.display = sc.value === 'OTHER' ? '' : 'none'; };
    $('#fsf', m).onsubmit = async e => { e.preventDefault(); const data = Object.fromEntries(new FormData(e.target)); if (sc.value === 'OTHER') { data.currency = ($('#scc', m).value || '').replace(/[^A-Za-z]/g, '').toUpperCase() || 'NGN'; data.currency_symbol = $('#scs', m).value.trim(); } else { data.currency = sc.value; data.currency_symbol = (CUR_LIST.find(c => c[0] === sc.value) || [])[1] || ''; }
      try { await api('finance/settings', { method: 'PATCH', data }); m.close(); toast('Saved'); } catch (er) { $('#er', m).textContent = er.message; } };
  }

  /* ---------- the form ---------- */
  async function form(d, pre) {
    pre = pre || {}; await X.loadRefs(); let st = {}; try { st = (await api('finance/settings')).settings; } catch {} let cat = []; try { cat = (await api('catalog')).items.filter(i => i.published == 1); } catch {}
    const type = d ? d.doc_type : (pre.doc_type || 'commercial'); let tpls = []; if (!d) { try { tpls = (await api('finance/templates?type=' + type)).templates || []; } catch {} } const x = d || Object.assign({ client_email: pre.client_email || '', attention: pre.attention || '' }, pre.fields || {}), cur = x.currency || st.currency || 'USD', comm = type === 'commercial', req = type === 'requisition';
    const F = (n, l, v, o) => { o = o || {}; return '<div class="field"' + (o.id ? ' id="' + o.id + '"' : '') + '><label>' + l + '</label>' + (o.ta ? '<textarea name="' + n + '" rows="' + o.ta + '">' + esc(v || '') + '</textarea>' : '<input name="' + n + '"' + (o.type ? ' type="' + o.type + '"' : '') + (o.list ? ' list="' + o.list + '"' : '') + (o.ph ? ' placeholder="' + esc(o.ph) + '"' : '') + (o.req ? ' required' : '') + ' maxlength="' + (o.max || 190) + '" value="' + esc(v ?? '') + '">') + '</div>'; };
    const dl = (id, arr) => '<datalist id="' + id + '">' + arr.map(a => '<option value="' + esc(a) + '">').join('') + '</datalist>';
    const m = modal('<div class="row"><h2 class="grow">' + (d ? 'Edit ' + FTYPE[d.doc_type].toLowerCase() + ' ' + esc(d.number) : 'New ' + (comm ? 'commercial' : FTYPE[type].toLowerCase())) + '</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="ff" style="margin-top:10px">' +
      (d ? '' : '<div class="grid2"><div class="field"><label>Document</label><select name="doc_type" id="fdt">' + opts([['commercial', 'Commercial (quotation)'], ['proforma', 'Proforma invoice'], ['invoice', 'Invoice'], ['requisition', 'Requisition (request for purchase or payment)']], type) + '</select></div><div class="field"><label>Link to RFQ task</label><input name="task_id" type="number" value="' + esc(pre.task_id || '') + '" placeholder="Task number (optional)"></div></div>') +
      (!d && tpls.length ? '<div class="field" style="max-width:420px"><label>Start from a saved template</label><select id="ftpl"><option value="">Blank ' + esc(FTYPE[type].toLowerCase()) + '</option>' + tpls.map(t => '<option value="' + t.id + '"' + (+pre.tpl === +t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select></div>' : '') +
      '<div class="muted small" style="margin:-4px 0 10px">' + (d ? 'The number ' + esc(d.number) + ' stays the same.' : 'The document number is given automatically when you create it.') + ' Start typing the client to pick a company (its details fill in by themselves, and you can still change them), or type a new one.' + '</div>' +
      (comm ? '<div class="grid2">' + F('client_name', 'Prepared for (client)', x.client_name || pre.client_name, { req: 1 }) + F('client_email', 'Client email', x.client_email, { type: 'email' }) + '</div>' + F('subject', 'Project title (heading above the table)', x.subject, { max: 300, ph: 'Commercials (Day Rate Per FPSO) for …' }) + F('intro', 'Note under the heading', x.intro, { ta: 2 }) +
        '<div class="grid2">' + F('rfq_ref', 'RFQ reference', x.rfq_ref || pre.rfq_ref, { max: 80 }) + '<div class="muted small" style="align-self:end;padding-bottom:10px">On a day rate, the quantity is the number of days. People working the same days side by side are not added up.</div></div><div class="grid2">' + F('issue_date', 'Date', x.issue_date || today(), { type: 'date' }) + F('valid_until', 'Valid until', x.valid_until || '', { type: 'date' }) + '</div>' +
        F('client_address', 'Client address (optional)', x.client_address, { ta: 2 })
        : req ? '<div class="grid2">' + F('client_name', 'Payee / vendor', x.client_name || pre.client_name, { req: 1, max: 190 }) + F('attention', 'Requested by', x.attention || (S.user ? S.user.name : ''), { max: 120 }) + '</div>' + F('subject', 'What is it for? (title)', x.subject, { max: 300, ph: 'e.g. Spare thruster for ROV 2' }) +
          '<div class="grid2">' + F('po_number', 'Department / cost centre', x.po_number || (S.user && S.user.department) || '', { max: 80 }) + F('due_date', 'Needed by', x.due_date || '', { type: 'date' }) + '</div><div class="grid2">' + F('issue_date', 'Date', x.issue_date || today(), { type: 'date' }) + F('payment_terms', 'Pay by / method', x.payment_terms, { max: 120, ph: 'Bank transfer, cash, card…' }) + '</div>' +
          F('client_address', 'Reason / justification', x.client_address, { ta: 3, max: 800 }) + F('client_email', 'Vendor email (optional)', x.client_email, { type: 'email' }) + F('ship_to', 'Deliver to / account details (optional)', x.ship_to, { ta: 2, max: 500 })
        : '<div class="grid2"><div class="field"><label>Bill to (client)</label><input name="client_name" required maxlength="190" value="' + esc(x.client_name || pre.client_name || '') + '"></div>' + F('attention', 'Attention', x.attention, { max: 120 }) + '</div><div class="grid2">' + F('client_email', 'Email', x.client_email, { type: 'email' }) + F('customer_id', 'Customer ID (company code)', x.customer_id, { max: 60, ph: 'N/A' }) + '</div>' + F('client_address', 'Bill to address', x.client_address, { ta: 2 }) +
        '<div class="grid2">' + F('ship_to', 'Ship to', x.ship_to, { ta: 2, max: 500 }) + '<div>' + F('terms_instr', 'Terms & instructions', x.terms_instr, { list: 'ftin', max: 120, ph: 'DDP' }) + F('shipping_method', 'Shipping method', x.shipping_method, { list: 'fshp', max: 80 }) + '</div></div>' +
        '<div class="grid2">' + F('rfq_ref', 'Customer RFQ number', x.rfq_ref || pre.rfq_ref, { max: 80 }) + F('po_number', 'PO number', x.po_number, { max: 80 }) + '</div><div class="grid2">' + F('payment_terms', 'Payment terms', x.payment_terms, { list: 'fpt', max: 120, ph: '60 DAYS' }) + F('sales_rep', 'Sales representative', x.sales_rep, { max: 80 }) + '</div><div class="grid2">' + F('sales_email', 'Sales rep email', x.sales_email, { type: 'email', max: 120 }) + F('sales_tel', 'Sales rep phone', x.sales_tel, { max: 60 }) + '</div>' +
        '<div class="grid2">' + (type === 'invoice' ? F('quote_ref', 'Proforma / quotation code', x.quote_ref || '', { max: 60, ph: 'e.g. PRO-2026-0007' }) + '</div><div class="grid2">' : '') + F('issue_date', 'Invoice date', x.issue_date || today(), { type: 'date' }) + F('due_date', 'Payment due date', x.due_date || '', { type: 'date' }) + (type === 'proforma' ? '</div><div class="grid2">' + F('valid_until', 'Quote expiration date', x.valid_until || '', { type: 'date' }) + F('delivery_leadtime', 'Delivery lead-time', x.delivery_leadtime, { max: 120, ph: 'e.g. 4 weeks after PO' }) + '</div>' : '</div>')) +
      dl('fcl', S.companies.map(c => c.name)) + dl('ftin', ['DDP', 'DAP', 'EXW', 'FOB', 'CIF', 'CFR']) + dl('fshp', ['TRUCK', 'AIR FREIGHT', 'SEA FREIGHT', 'COURIER', 'HAND DELIVERY']) + dl('fpt', ['IMMEDIATE', '14 DAYS', '30 DAYS', '45 DAYS', '60 DAYS', '90 DAYS']) + dl('fcats', ['Personnel', 'Project Deliverables', 'Project Management', 'Standby Rate', 'Optional Services']) +
      '<div class="field" style="max-width:360px"><label>This document is for</label><select id="fscope">' + opts([['service', 'Services'], ['product', 'Products'], ['both', 'Products and services together']], d ? scopeOf(d) : (pre.scope || 'service')) + '</select></div>' + '<h3 style="margin:12px 0 6px">Items <span class="muted small">' + (comm ? '(letters A, B, C… and totals are worked out for you)' : '(S/N is numbered automatically)') + '</span></h3><div id="fi" class="fin-items' + (comm ? ' comm' : '') + '"></div><div class="row" style="margin:6px 0"><button type="button" class="btn ghost sm" id="addi">+ Add line</button>' + (comm ? '<button type="button" class="btn ghost sm" id="adds">+ Standard categories</button>' : '') +
      (cat.length ? '<select id="fcat" style="width:auto"><option value="">+ From products &amp; services…</option>' + cat.map(c => '<option value="' + c.id + '">' + esc(c.name + (c.price != null ? ' — ' + c.price : '')) + '</option>').join('') + '</select>' : '') + '</div>' +
      '<div class="grid2"><div class="field"><label>Currency</label><select id="fcur">' + curOptions(cur) + '</select><div id="fcur2" class="grid2" style="margin-top:6px;display:none"><input id="fcc" maxlength="5" placeholder="Code, e.g. GHS" aria-label="Currency code"><input id="fcs" maxlength="6" placeholder="Sign, e.g. GH₵" aria-label="Currency sign"></div><input type="hidden" name="currency" value="' + esc(cur) + '"><input type="hidden" name="currency_symbol" value="' + esc(x.currency_symbol || '') + '"></div><div class="field"><label>Discount (amount)</label><input name="discount" type="number" min="0" step="0.01" value="' + (x.discount || 0) + '"></div></div><div class="grid2"><div class="field"><label>Tax name</label><input name="tax_label" value="' + esc(x.tax_label || st.tax_label || 'VAT') + '"></div><div class="field"><label>Tax rate (%)</label><input name="tax_rate" type="number" min="0" max="100" step="0.01" value="' + (x.tax_rate ?? (comm ? 0 : st.tax_rate) ?? 0) + '"></div></div>' +
      '<div id="ftot" class="small" style="text-align:right;margin:6px 0;font-weight:600"></div><div class="field"><label>Notes (shown on the document)</label><textarea name="notes" rows="2">' + esc(x.notes || '') + '</textarea></div><div class="field"><label>Terms and conditions</label><textarea name="terms" rows="3">' + esc(x.terms ?? st.terms ?? '') + '</textarea></div>' + (d && d.approval && d.approval.current && ['in_review', 'awaiting_sign', 'signed'].includes(d.approval.current.status) ? '<div class="banner" style="margin:10px 0;background:rgba(224,160,45,.14);color:var(--ink);border:1px solid rgba(224,160,45,.5)"><span><b>This document is in the approval chain.</b> Saving a change ' + (d.approval.current.steps.some(s => s.status === 'signed') ? 'clears the ' + d.approval.current.steps.filter(s => s.status === 'signed').length + ' signature(s) given so far, so the signees sign again. ' : 'is recorded in its history. ') + '</span></div><div class="field"><label>What did you change? (optional, sent to the others)</label><input name="edit_note" maxlength="500"></div>' + (d.approval.current.steps.some(s => s.kind === 'sign' && +s.user_id === +S.user.id) ? '<label class="small" style="display:block;margin:6px 0"><input type="checkbox" id="fnot" style="width:auto" checked> Tell the others about this change (your choice as a signee)</label>' : '<div class="small muted" style="margin:6px 0">The others in the chain will be told about this change.</div>') : '') +
      '<div class="field"><label>Attach documents <span class="muted small">(vendor quotes, scans, supporting files; up to 8 MB each)</span></label><div class="row" style="gap:8px;flex-wrap:wrap"><label class="btn ghost sm" style="cursor:pointer;margin:0">📎 Add documents<input type="file" id="ffl" multiple hidden></label><span class="small muted" id="ffn"></span></div></div>' + '<div class="err" id="er"></div><div class="row"><button class="btn">' + (d ? 'Save changes' : 'Create') + '</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
    m.querySelector('.modal').style.cssText += ';max-width:96vw;width:' + (comm ? 'min(1280px,96vw)' : '820px');
    const tp = $('#ftpl', m); if (tp) tp.onchange = async () => { if (!tp.value) { const f0 = Object.fromEntries(new FormData($('#ff', m))); m.close(); form(null, { ...pre, doc_type: type, client_name: f0.client_name, task_id: f0.task_id, fields: null, items: null, styles: null, tpl: 0 }); return; }
      try { const t = (await api('finance/templates/' + tp.value)).template, f0 = Object.fromEntries(new FormData($('#ff', m))); const ks = [...new Set((t.items || []).map(i => i.kind || 'service'))]; m.close(); form(null, { ...pre, doc_type: type, client_name: f0.client_name, task_id: f0.task_id, fields: t.fields, items: t.items, styles: t.styles, tpl: +tp.value, scope: ks.length > 1 ? 'both' : (ks[0] || 'service') }); } catch (er) { toast(er.message); } };
    const dt = $('#fdt', m); if (dt) dt.onchange = () => { const f = Object.fromEntries(new FormData($('#ff', m))); m.close(); form(null, { ...pre, ...f, doc_type: dt.value, client_name: f.client_name, task_id: f.task_id }); };
    const fi = $('#fi', m); const scopeSel = $('#fscope', m);
    const defKind = () => scopeSel.value === 'product' ? 'product' : 'service';
    const syncScope = () => { const ks = [...new Set($$('select[data-k=kind]', fi).map(n => n.value))]; scopeSel.value = ks.length > 1 ? 'both' : (ks[0] || scopeSel.value); };
    scopeSel.onchange = () => { if (scopeSel.value !== 'both') $$('select[data-k=kind]', fi).forEach(n => n.value = scopeSel.value); };
    const row = (i, after) => {
      const r = document.createElement('div'); r.className = 'frow' + (comm ? ' c' : '');
      r.innerHTML = (comm ? '<input data-k="category" placeholder="Category" list="fcats" value="' + esc(i.category || '') + '">' : '') + '<input data-k="description" placeholder="Description" value="' + esc(i.description || '') + '"><select data-k="kind" title="Is this line a product or a service?" aria-label="Product or service">' + opts([['service', 'Service'], ['product', 'Product']], i.kind || defKind()) + '</select><input data-k="unit" placeholder="' + (comm ? 'UOM' : 'UoM') + '" value="' + esc(i.unit || '') + '"><input data-k="qty" type="number" min="0" step="any" placeholder="Qty" value="' + num(i.qty ?? 1) + '">' +
        (comm ? '<input data-k="freq" placeholder="Frequency" list="ffq" value="' + esc(i.freq || '') + '"><input data-k="unit_price" type="number" min="0" step="any" placeholder="Unit rate" value="' + (i.unit_price ?? '') + '"><select data-k="amount_note" title="Shown instead of an amount, and not counted in the total">' + opts([['', 'Counted'], ...NOTES.map(n => [n, n])], i.amount_note || '') + '</select>'
          : '<input data-k="unit_price" type="number" min="0" step="any" placeholder="Unit price" value="' + (i.unit_price ?? '') + '">') + '<span class="fl" data-amt></span><button type="button" class="btn ghost sm fr-h" data-drag title="Hold and drag to move this line (or use the up and down arrow keys)" aria-label="Move line">⠿</button><button type="button" class="btn ghost sm" data-dup title="Duplicate this line" aria-label="Duplicate line">⧉</button><button type="button" class="btn ghost sm" data-r aria-label="Remove line">✕</button>';
      r.querySelector('[data-dup]').onclick = () => { const v = Object.fromEntries($$('[data-k]', r).map(n => [n.dataset.k, n.value])); row(v, r); tot(); };
      wireDrag(r.querySelector('[data-drag]'), r);
      r.querySelector('[data-r]').onclick = () => { r.remove(); tot(); }; if (X.suggest) X.suggest(r.querySelector('[data-k=description]'), 'product', o => { const set = (k, v) => { const el = r.querySelector('[data-k=' + k + ']'); if (el && v != null && v !== '') el.value = v; }; set('description', o.name + (o.description ? ' — ' + String(o.description).slice(0, 120) : '')); set('unit', o.unit); if (o.source === 'Service' || o.source === 'Product') { set('kind', o.source.toLowerCase()); syncScope(); } if (o.vendor && type === 'requisition') { const pe = m.querySelector('[name=client_name]'); if (pe && !pe.value.trim()) pe.value = o.vendor; const ve = m.querySelector('[name=client_email]'); if (ve && o.vendor_email && !ve.value.trim()) ve.value = o.vendor_email; } if (o.price != null) set('unit_price', o.price); tot(); }); r.querySelector('select[data-k=kind]').addEventListener('change', e => { if (e.target.value === 'product') { const fq = r.querySelector('[data-k=freq]'); if (fq && DAILY.includes(String(fq.value).trim().toLowerCase())) fq.value = 'Fixed'; } syncScope(); });
      $$('input,select', r).forEach(n => n.oninput = n.onchange = tot); if (after) after.after(r); else fi.appendChild(r); return r; };
    // hold the handle and drag a line up or down; the arrow keys do the same from the keyboard
    const wireDrag = (h, r) => {
      h.style.touchAction = 'none';
      h.addEventListener('keydown', e => { if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return; e.preventDefault(); const sib = e.key === 'ArrowUp' ? r.previousElementSibling : r.nextElementSibling; if (!sib || !sib.classList.contains('frow')) return; if (e.key === 'ArrowUp') fi.insertBefore(r, sib); else sib.after(r); h.focus(); });
      h.addEventListener('pointerdown', e => { e.preventDefault(); h.setPointerCapture(e.pointerId); const box = m.querySelector('.modal'), y0 = e.clientY; let target = null, before = true; r.classList.add('dragging');
        const clear = () => $$('.drop-before,.drop-after', fi).forEach(x => x.classList.remove('drop-before', 'drop-after'));
        const move = ev => { const mr = box.getBoundingClientRect(); if (ev.clientY < mr.top + 36) box.scrollBy(0, -12); else if (ev.clientY > mr.bottom - 36) box.scrollBy(0, 12);
          r.style.transform = 'translateY(' + (ev.clientY - y0) + 'px)'; clear(); target = null;   // the line follows the pointer; it is placed when you let go, so the pointer is never lost
          for (const o of $$('.frow', fi)) { if (o === r) continue; const bx = o.getBoundingClientRect(); if (ev.clientY < bx.top + bx.height / 2) { target = o; before = true; break; } }
          if (!target) { const rows = $$('.frow', fi).filter(x => x !== r); target = rows[rows.length - 1] || null; before = false; } if (target) target.classList.add(before ? 'drop-before' : 'drop-after'); };
        const up = () => { h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up); r.classList.remove('dragging'); r.style.transform = ''; clear();
          if (target && target !== r) { if (before) fi.insertBefore(r, target); else target.after(r); } tot(); };
        h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up); });
    };
    fi.insertAdjacentHTML('beforebegin', dl('ffq', FREQS));
    const items = () => $$('.frow', fi).map(r => Object.fromEntries($$('[data-k]', r).map(n => [n.dataset.k, n.value])));
    const tot = () => { const f = $('#ff', m); let sub = 0;
      $$('.frow', fi).forEach(r => { const it = Object.fromEntries($$('[data-k]', r).map(n => [n.dataset.k, n.value])); const [shown, counted] = lineOf(it, type); sub += counted; const a = r.querySelector('[data-amt]'); a.textContent = it.amount_note ? it.amount_note : fmoney(shown, f.currency.value, f.currency_symbol.value); });
      const disc = Math.min(+f.discount.value || 0, sub), tax = (sub - disc) * (+f.tax_rate.value || 0) / 100;
      $('#ftot', m).textContent = 'Subtotal ' + fmoney(sub, f.currency.value, f.currency_symbol.value) + ' · Tax ' + fmoney(tax, f.currency.value, f.currency_symbol.value) + ' · Total ' + fmoney(sub - disc + tax, f.currency.value, f.currency_symbol.value); };
    (d && d.items.length ? d.items : (pre.items && pre.items.length ? pre.items : [{}])).forEach(it => row(it)); tot(); $('#addi', m).onclick = () => { const last = items().pop() || {}; row(comm ? { category: last.category || '', qty: 1, unit: last.unit || '', freq: last.freq || '' } : {}); };
    const std = $('#adds', m); if (std) std.onclick = () => { [['Personnel – Daily Onsite (Operational)', 'Day rate'], ['Project Deliverables', 'Fixed'], ['Project Management', 'Fixed'], ['Standby Rate', 'Per person/day'], ['Optional Services', '']].forEach(([c, fq]) => row({ category: c, qty: 1, unit: 'EA', freq: fq, amount_note: c === 'Standby Rate' ? 'As incurred' : c === 'Optional Services' ? 'Optional' : '' })); tot(); };
    $$('[name=discount],[name=tax_rate]', m).forEach(n => n.oninput = tot);
    wireCurrency(m, tot);
    const fc = $('#fcat', m); if (fc) fc.onchange = () => { const c = cat.find(z => z.id == fc.value); if (c) { const rr = $$('.frow', fi); if (rr.length === 1 && !rr[0].querySelector('[data-k=description]').value) rr[0].remove(); row({ description: c.name + (c.description ? ' — ' + c.description.slice(0, 120) : ''), qty: 1, unit: c.unit, unit_price: c.price }); tot(); } fc.value = ''; };
    const att = []; const ffl = $('#ffl', m); if (ffl) ffl.onchange = async e => { for (const f of e.target.files) { if (att.length >= 10) break; if (f.size > 8e6) { $('#er', m).textContent = f.name + ' is larger than 8 MB'; continue; } try { att.push({ name: f.name, type: f.type || 'application/octet-stream', data: await X.fileB64(f) }); } catch (er) { $('#er', m).textContent = er.message; } } e.target.value = ''; $('#ffn', m).textContent = att.map(f => f.name).join(', '); };
    // as-you-type suggestions: they fill the boxes, every box stays editable
    const setv = (n, v, onlyEmpty) => { const el = m.querySelector('[name=' + n + ']'); if (!el || v == null || v === '') return; if (onlyEmpty && el.value.trim() !== '') return; el.value = v; };
    if (X.suggest) {
      X.suggest(m.querySelector('[name=client_name]'), 'client', o => { setv('client_name', o.name); setv('attention', o.attention); setv('client_email', o.email); setv('customer_id', o.customer_id || o.code); setv('client_address', o.address); setv('payment_terms', o.payment_terms, true); setv('ship_to', o.ship_to, true); setv('terms_instr', o.terms_instr, true); setv('shipping_method', o.shipping_method, true); setv('sales_rep', o.sales_rep, true); setv('sales_email', o.sales_email, true); setv('sales_tel', o.sales_tel, true); });
      X.suggest(m.querySelector('[name=attention]'), 'contact', o => { setv('attention', o.name); setv('client_email', o.email, true); });
      X.suggest(m.querySelector('[name=quote_ref]'), 'quote', o => { setv('quote_ref', o.name); setv('client_name', o.client_name, true); });
    }
    $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
    $('#ff', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.items = items().filter(i => i.description.trim() || String(i.category || '').trim()); if (f.task_id === '') delete f.task_id; if (d) delete f.doc_type; else f.doc_type = type; const nb = $('#fnot', m); if (nb) f.notify = nb.checked ? 1 : 0;
      try { const r = await api(d ? 'finance/doc/' + d.id : 'finance/doc', { method: d ? 'PATCH' : 'POST', data: f }); if (!d && pre.styles && Object.keys(pre.styles).length) { try { await api('finance/doc/' + r.id + '/styles', { method: 'PUT', data: { doc_styles: pre.styles } }); } catch {} }   // the template's look comes with it
      if (att.length && X.finUploadFiles) await X.finUploadFiles(d ? d.id : r.id, att); m.close(); toast(d ? (r.signatures_cleared ? 'Saved. ' + r.signatures_cleared + ' signature(s) cleared; it needs signing again' : 'Saved') + (r.notified ? ' · ' + r.notified + ' notified' : '') : r.number + ' created'); location.hash = '#/finance/' + (d ? d.id : r.id); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
  }
  const CUR_LIST = [['NGN', '₦', 'Naira'], ['USD', '$', 'US dollar'], ['GBP', '£', 'Pound sterling'], ['EUR', '€', 'Euro']];
  const curOptions = code => { const known = CUR_LIST.some(c => c[0] === String(code).toUpperCase()); return CUR_LIST.map(c => opt(c[0], c[1] + '  ' + c[0] + ' · ' + c[2], known ? String(code).toUpperCase() : '')).join('') + opt('OTHER', 'Other (type the code and sign)', known ? '' : 'OTHER'); };
  function wireCurrency(m, tot) {
    const sel = $('#fcur', m), f = $('#ff', m), box = $('#fcur2', m), cc = $('#fcc', m), cs = $('#fcs', m);
    if (sel.value === 'OTHER') { box.style.display = ''; cc.value = f.currency.value; cs.value = f.currency_symbol.value; }
    const sync = () => {
      if (sel.value === 'OTHER') { box.style.display = ''; f.currency.value = (cc.value || '').replace(/[^A-Za-z]/g, '').toUpperCase() || 'XXX'; f.currency_symbol.value = cs.value.trim(); }
      else { box.style.display = 'none'; f.currency.value = sel.value; f.currency_symbol.value = (CUR_LIST.find(c => c[0] === sel.value) || [])[1] || ''; }
      tot();
    };
    sel.onchange = sync; cc.oninput = sync; cs.oninput = sync; sync();
  }
  const opts = (arr, sel) => arr.map(([v, l]) => opt(v, l, sel)).join('');

  /* ---------- print layouts ---------- */
  /* The Invoice (sent after a purchase order) and the Proforma invoice / quotation share one page design: header art, details grid, items,
   * totals, amount in words, then the bank details followed by the two signatures. They differ in the top-right block and a few lines. */
  function invoiceHtml(d) {
    const isInv = d.doc_type === 'invoice', isPro = !isInv, s = d.seller || {}, t = d.totals, c = d.currency;
    const fm = n => fmoney(n, c, d.currency_symbol);
    const short = String(d.company_code || d.customer_id || '').trim() && String(d.company_code || d.customer_id).toUpperCase() !== 'N/A' ? String(d.company_code || d.customer_id).toUpperCase() : (String(d.client_name || '').split(/[\s,.]+/)[0] || '').toUpperCase();
    const rowHtml = (i, n) => '<tr><td class="c">' + (n + 1) + '.</td><td>' + nl(i.description) + '</td><td class="c">' + esc(i.unit || '') + '</td><td class="c">' + num(i.qty) + '</td><td class="r">' + fm(i.unit_price) + '</td><td class="r">' + fm(i.qty * i.unit_price) + '</td></tr>';
    const filler = '<tr class="fill"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
    const cost = i => Math.max(11, 5.2 * Math.max(1, String(i.description || '').split('\n').reduce((a, ln) => a + Math.max(1, Math.ceil(ln.length / 50)), 0)) + 4); // mm a row takes
    const ROOM1 = 138, ROOMN = 218, A_MM = isPro ? 68 : 40, B_MM = 92; // room for rows on page 1 / later pages; totals+words block; bank+signatures block
    const pages = []; let idx = 0;
    for (let pg = 0; ; pg++) {
      const rows = []; const room = pg === 0 ? ROOM1 : ROOMN; let used = 0;
      while (idx < d.items.length && (used + cost(d.items[idx]) <= room || !rows.length)) { used += cost(d.items[idx]); rows.push(idx++); }
      const left = room - used;
      if (idx < d.items.length) { pages.push({ rows, a: false, b: false }); continue; }
      if (left >= A_MM + B_MM) { pages.push({ rows, a: true, b: true }); break; }
      if (left >= A_MM) { pages.push({ rows, a: true, b: false }); pages.push({ rows: [], a: false, b: true }); break; }
      pages.push({ rows, a: false, b: false }); pages.push({ rows: [], a: true, b: true }); break;
    }
    const meta = '<div class="inv-meta">Invoice Number: <b>' + esc(d.number) + '</b>' + (isInv ? (d.quote_ref ? '<br>Proforma/Quotation: ' + esc(d.quote_ref) : '') + (d.po_number ? '<br>PO Number: ' + esc(d.po_number) : '') + (s.tax_id ? '<br>Tin: ' + esc(s.tax_id) : '') + (s.rc_number ? '<br>RC: ' + esc(s.rc_number) : '') : '') + '<br>Date: ' + esc(ordinalDate(d.issue_date)) + '<br>Covers: ' + SCOPE[scopeOf(d)] + '</div>';
    const rep = isPro ? '<b>Sales Rep ID – ' + esc(d.sales_email || d.sales_rep || '') + '</b>' : [d.sales_rep && '<b>Sales Representative – ' + esc(d.sales_rep) + '</b>', d.sales_email && 'Email: ' + esc(d.sales_email), d.sales_tel && 'Tel: ' + esc(d.sales_tel)].filter(Boolean).join('<br>') || '<b>Sales Representative</b>';
    const grid = '<table class="inv-grid"><colgroup><col style="width:36%"><col style="width:26%"><col style="width:38%"></colgroup><tr class="h"><td>Bill To:</td><td>Terms &amp; Instructions</td><td>Ship To:</td></tr>' +
      '<tr class="tall"><td><div class="bt"><div><b>' + esc(d.client_name) + '</b><br>' + nl(d.client_address) + (d.client_email ? '<div class="gap"></div>Email: ' + esc(d.client_email) : '') + '</div><div class="att"><b>Attention: ' + esc(d.attention || '') + '</b></div></div></td><td>' + esc(d.terms_instr || '') + '</td><td>' + nl(d.ship_to) + '</td></tr>' +
      '<tr class="h"><td>Customer ID' + (d.customer_id ? '' : ' - N/A') + '</td><td>Customer' + (short && short.length <= 14 ? ' (' + esc(short) + ')' : '') + ' RFQ Number</td><td>Payment Terms</td></tr><tr><td>' + esc(d.customer_id || '') + '</td><td>' + esc(d.rfq_ref || '') + (isPro && d.po_number ? '<br>PO ' + esc(d.po_number) : '') + '</td><td>' + esc(d.payment_terms || '') + '</td></tr>' +
      '<tr class="h tall2"><td>' + rep + '</td><td>Shipping Method</td><td></td></tr><tr><td>&nbsp;</td><td>' + esc(d.shipping_method || '') + '</td><td></td></tr></table>';
    const colg = '<colgroup><col style="width:6%"><col style="width:36%"><col style="width:8%"><col style="width:7%"><col style="width:20%"><col style="width:23%"></colgroup>';
    const thead = '<thead><tr class="h"><td class="c">S/N.</td><td>Description</td><td class="c">UoM</td><td class="c">Qty</td><td>Unit Price</td><td>Amount</td></tr></thead>';
    const tfoot = '<tfoot><tr class="tot"><td colspan="5">SUBTOTAL</td><td class="navy">' + fm(t.subtotal) + '</td></tr>' + (isPro ? '<tr class="tot"><td colspan="5">Discount</td><td class="navy">' + (t.discount ? '−' + fm(t.discount) : '') + '</td></tr><tr class="tot"><td colspan="5">SUBTOTAL</td><td class="navy">' + fm(t.subtotal - t.discount) + '</td></tr>' : t.discount ? '<tr class="tot"><td colspan="5">DISCOUNT</td><td class="navy">−' + fm(t.discount) + '</td></tr>' : '') + '<tr class="tot"><td colspan="5">' + esc((d.tax_label || 'VAT').toUpperCase()) + (+d.tax_rate ? ' (' + num(d.tax_rate) + '%)' : '') + '</td><td class="navy">' + fm(t.tax) + '</td></tr><tr class="tot"><td colspan="5">TOTAL</td><td class="navy">' + fm(t.total) + '</td></tr></tfoot>';
    const words = '<div class="inv-words' + (isInv ? ' strong' : '') + '"><b>AMOUNT IN WORDS:</b> ' + esc(amountWords(t.total, c)) + (isInv ? '.' : '') + '</div>';
    const tailA = words + (isPro ? ((d.valid_until || d.delivery_leadtime || d.due_date) ? '<div class="inv-lines">' + (d.valid_until ? '<div><b>Quote Expiration Date:</b> ' + esc(ordinalDate(d.valid_until)) + '</div>' : '') + (d.delivery_leadtime ? '<div><b>Leadtime:</b> ' + esc(d.delivery_leadtime) + '</div>' : '') + (d.due_date ? '<div><b>Payment due:</b> ' + esc(ordinalDate(d.due_date)) + '</div>' : '') + '</div>' : '') : (d.due_date ? '<div class="inv-lines"><div><b>Payment due:</b> ' + esc(ordinalDate(d.due_date)) + '</div></div>' : '')) + (d.notes ? '<div class="inv-notes"><b>Notes</b><br>' + nl(d.notes) + '</div>' : '');
    const bk = (k, v) => v ? '<tr><td class="k">' + k + '</td><td>' + esc(v) + '</td></tr>' : '', bankRows = bk('BANK NAME', s.bank_name) + bk('ACCOUNT NAME', s.account_name) + bk('ACCOUNT NUMBER', s.account_number) + bk('VAT REG', s.tax_id);
    const tailB = (bankRows ? '<table class="inv-bank"><tr class="h"><td colspan="2">PAYMENT SHOULD BE MADE TO</td></tr>' + bankRows + '</table>' : '') +
      (s.bank_details ? '<div class="inv-notes">' + nl(s.bank_details) + '</div>' : '') +
      '<div class="inv-sign"><div>FOR ' + esc((s.company_name || 'AEROSUB').toUpperCase()) + '<div class="line"></div></div><div>FOR CLIENT<div class="line"></div></div></div>' + (d.terms ? '<div class="inv-terms"><b>Terms</b><br>' + nl(d.terms) + '</div>' : '');
    return pages.map((pg, n) => {
      const body = pg.rows.map(i => rowHtml(d.items[i], i)).join('') + (n === 0 && pg.a ? filler.repeat(Math.max(0, 3 - d.items.length)) : '');
      const table = body || pg.a ? '<table class="inv-items">' + colg + thead + '<tbody>' + body + '</tbody>' + (pg.a ? tfoot : '') + '</table>' : '';
      return '<div class="fin-sheet inv"><div class="inv-bg' + (isPro ? ' pro' : '') + '"></div><div class="inv-body">' + (d.status === 'void' ? '<div class="fvoid" style="position:absolute;right:20mm;top:50mm">VOID</div>' : '') +
        (n === 0 ? meta + grid : '') + table + (pg.a ? tailA : '') + (pg.b ? tailB : '') + '</div></div>';
    }).join('');
  }

  function commercialHtml(d) {
    const s = d.seller || {}, c = d.currency, days = Math.max(1, +d.days || 1), K = c + ' ';
    const fmoney = n => X.fmoney(n, c, d.currency_symbol);
    // accounting layout: the currency sign sits at the left edge of the cell and the number at the right edge
    const acc = n => { const s = fmoney(n), m = /^(−?[^\d]*)(\d[\d,]*\.\d+)$/.exec(s); return m ? '<span class="mny"><i>' + esc(m[1]) + '</i><b>' + m[2] + '</b></span>' : esc(s); };
    const groups = []; d.items.forEach(i => { const name = i.category || 'General'; let g = groups.find(x => x.name === name); if (!g) groups.push(g = { name, items: [] }); g.items.push(i); });
    let grand = 0, body = '';
    groups.forEach((g, gi) => { g.L = String.fromCharCode(65 + gi); g.counted = g.items.filter(i => !String(i.amount_note || '').trim()).length; });
    const renderG = g => {
      let tot = 0, nd = 0;   // nd: days on a day rate. People work the same days side by side, so it is the longest line, not the sum.
      g.items.forEach(i => { if (!String(i.amount_note || '').trim()) { tot += lineOf(i, 'commercial')[1]; if (DAILY.includes(String(i.freq || '').trim().toLowerCase())) nd = Math.max(nd, +i.qty || 0); } });
      if (g.counted) grand += tot;
      const short = g.name.split(/\s[–-]\s/)[0]; const span = g.items.length + (g.counted ? 1 : 0);
      g.items.forEach((i, n) => { const note = String(i.amount_note || '').trim();
        body += '<tr>' + (n === 0 ? '<td class="c b" rowspan="' + span + '">' + g.L + '</td><td class="c b" rowspan="' + span + '">' + esc(g.name) + '</td>' : '') + '<td>' + nl(i.description) + '</td><td class="c">' + esc(i.unit || '') + '</td><td class="c">' + (+i.qty ? num(i.qty) : '') + '</td><td class="c">' + esc(i.freq || '') + '</td><td class="r">' + ((+i.unit_price) ? acc(i.unit_price) : '') + '</td><td class="' + (note ? 'c' : 'r') + '">' + (note ? esc(note) : acc(lineOf(i, 'commercial')[1])) + '</td></tr>'; });
      if (g.counted) body += '<tr class="sub"><td colspan="4" class="c b">Sub Total for ' + esc(short) + (nd ? ' (Estimated ' + num(nd) + (nd === 1 ? ' Day' : ' Days') + ')' : '') + '</td><td colspan="2" class="c b">' + acc(tot) + '</td></tr>';
    };
    const counted = groups.filter(g => g.counted);
    counted.forEach(renderG);
    if (counted.length > 1) body += '<tr class="grand"><td colspan="2" class="g"></td><td colspan="4" class="c b g">' + esc(counted.map(g => g.name.split(/\s[–-]\s/)[0]).join(' + ')) + '</td><td colspan="2" class="c b g">' + acc(grand) + '</td></tr>';   // what the total is made of, from the categories above
    groups.filter(g => !g.counted).forEach(renderG);   // standby and optional sections (shown, not counted) follow
    const disc = Math.min(+d.discount || 0, grand), tax = Math.round((grand - disc) * (+d.tax_rate || 0)) / 100, total = grand - disc + tax;
    if (disc) body += '<tr class="sub"><td colspan="7" class="c b">Discount</td><td class="r b">' + acc(-disc) + '</td></tr>';
    if (+d.tax_rate) body += '<tr class="sub"><td colspan="7" class="c b">' + esc(d.tax_label || 'VAT') + ' (' + num(d.tax_rate) + '%)</td><td class="r b">' + acc(tax) + '</td></tr>';
    body += '<tr class="total"><td colspan="7" class="c b">TOTAL (' + esc(c) + ')</td><td class="r b g">' + acc(total) + '</td></tr>';
    return '<div class="fin-sheet comm" data-page="comm"><section class="cover"><div class="cv-band"><img src="assets/img/aerosub-logo.png" alt="Aerosub" data-rm="1"></div><div class="cv-text"><div class="cv-k">COMMERCIAL PROPOSAL</div><div class="cv-sub">' + esc(d.subject || '') + '</div><div class="cv-ref">' + esc(d.number) + (d.rfq_ref ? ' · RFQ ' + esc(d.rfq_ref) : '') + '<br>' + esc(fmtDate(d.issue_date)) + '</div></div><div class="cv-for">Prepared For: <b>' + esc(d.client_name) + '</b></div></section>' +
      '<section class="tbl">' + (d.status === 'void' ? '<div class="fvoid">VOID</div>' : '') + '<h2 class="ct">' + esc(d.subject || 'Commercial offer') + '</h2><div class="ci" style="text-align:center;margin:-4px 0 6px"><b>' + SCOPE[scopeOf(d)] + '</b></div>' + (d.intro ? '<p class="ci">' + nl(d.intro) + '</p>' : '') +
      '<table class="comm-t"><thead><tr><th>S/N</th><th>Category</th><th>Items Description</th><th>UOM</th><th>QTY</th><th>Frequency/<br>No of Days</th><th>Unit Rate<br>(' + esc(c) + ')</th><th>Total<br>(' + esc(c) + ')</th></tr></thead><tbody>' + body + '</tbody></table>' +
      '<div class="cm-foot"><div><b>Amount in words:</b> ' + esc(amountWords(total, c)) + '.</div>' + (d.valid_until ? '<div><b>Valid until:</b> ' + esc(fmtDate(d.valid_until)) + '</div>' : '') + (d.notes ? '<div style="margin-top:8px"><b>Notes</b><br>' + nl(d.notes) + '</div>' : '') + (d.terms ? '<div style="margin-top:8px"><b>Terms</b><br>' + nl(d.terms) + '</div>' : '') + ([s.company_name, s.phone, s.email, s.website].some(Boolean) ? '<div class="cm-co">' + esc([s.company_name, s.phone, s.email, s.website].filter(Boolean).join(' · ')) + '</div>' : '') + '</div><div class="cm-web">aerosub.co</div></section></div>';
  }

  /* A requisition: a request for the company to buy something or pay someone. Same page style as the invoice, with a request and approval block. */
  /* A requisition: a request for the company to buy something or pay someone. Same page style as the invoice, with a request and approval block. */
  function requisitionHtml(d) {
    const s = d.seller || {}, t = d.totals, c = d.currency, fm = n => fmoney(n, c, d.currency_symbol);
    const rows = d.items.map((i, n) => '<tr><td class="c">' + (n + 1) + '</td><td>' + nl(i.description) + '</td><td class="c">' + esc(i.unit || '') + '</td><td class="c">' + num(i.qty) + '</td><td class="r">' + fm(i.unit_price) + '</td><td class="r">' + fm(i.qty * i.unit_price) + '</td></tr>').join('');
    const kv = (k, v) => v ? '<tr><td class="k">' + k + '</td><td>' + esc(v) + '</td></tr>' : '';
    const kvRows = kv('Title', d.subject) + kv('Payee / vendor', d.client_name) + kv('Requested by', d.attention) + kv('Department / cost centre', d.po_number) + kv('Needed by', d.due_date ? ordinalDate(d.due_date) : '') + kv('Pay by / method', d.payment_terms) + kv('Vendor email', d.client_email) + (d.ship_to ? '<tr><td class="k">Deliver to / account</td><td>' + nl(d.ship_to) + '</td></tr>' : '');
    return '<div class="fin-sheet req"><div class="rq-head"><img src="assets/img/aerosub-logo.png" alt="Aerosub" data-rm="1"><div class="rq-title">REQUISITION<div class="rq-code">' + esc(d.number) + '</div><div class="rq-code" style="color:#4a5a86">' + SCOPE[scopeOf(d)] + '</div></div></div><div class="rq-bar">' + esc([s.phone, s.email, s.website].filter(Boolean).join('   ·   ')) + '</div><div class="rq-body">' + (d.status === 'void' ? '<div class="fvoid" style="position:absolute;right:20mm;top:50mm">VOID</div>' : '') +
      '<div class="inv-meta">Requisition No: <b>' + esc(d.number) + '</b><br>Date: ' + esc(ordinalDate(d.issue_date)) + '</div>' +
      (kvRows ? '<table class="rq-kv"><tr class="h"><td colspan="2">REQUEST DETAILS</td></tr>' + kvRows + '</table>' : '') +
      (d.client_address ? '<div class="rq-why"><b>Reason / justification</b><br>' + nl(d.client_address) + '</div>' : '') +
      '<table class="inv-items"><colgroup><col style="width:6%"><col style="width:40%"><col style="width:8%"><col style="width:8%"><col style="width:18%"><col style="width:20%"></colgroup><thead><tr class="h"><td class="c">S/N</td><td>Description</td><td class="c">UoM</td><td class="c">Qty</td><td>Unit Price</td><td>Amount</td></tr></thead><tbody>' + rows + '</tbody><tfoot><tr class="tot"><td colspan="5">SUBTOTAL</td><td class="navy">' + fm(t.subtotal) + '</td></tr>' + (t.discount ? '<tr class="tot"><td colspan="5">DISCOUNT</td><td class="navy">−' + fm(t.discount) + '</td></tr>' : '') + (+d.tax_rate ? '<tr class="tot"><td colspan="5">' + esc((d.tax_label || 'VAT').toUpperCase()) + ' (' + num(d.tax_rate) + '%)</td><td class="navy">' + fm(t.tax) + '</td></tr>' : '') + '<tr class="tot"><td colspan="5">TOTAL</td><td class="navy">' + fm(t.total) + '</td></tr></tfoot></table>' +
      '<div class="inv-words strong"><b>AMOUNT IN WORDS:</b> ' + esc(amountWords(t.total, c)) + '.</div>' + (d.notes ? '<div class="inv-notes"><b>Notes</b><br>' + nl(d.notes) + '</div>' : '') +
      '<div class="inv-sign rq3"><div>REQUESTED BY<div class="line"></div></div><div>FINANCE HEAD<div class="line"></div></div><div>APPROVED BY (TOP HEAD)<div class="line"></div></div></div></div><div class="rq-foot"><div class="rq-addr"><b>16 Amadi Close,</b><br><b>Rumuomasi</b>, Port Harcourt.</div><div class="rq-tag">Better Data. Better Insights. <b>Better Decisions.</b></div></div></div>';
  }
  window.OfficeFin = { settings, form, amountWords, words, html: d => d.doc_type === 'commercial' ? commercialHtml(d) : d.doc_type === 'requisition' ? requisitionHtml(d) : invoiceHtml(d) };
})();
