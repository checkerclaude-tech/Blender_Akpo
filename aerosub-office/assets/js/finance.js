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
  const FREQS = ['Fixed', 'Day rate', 'Per person/day', 'Flat rate/day'];
  const NOTES = ['As incurred', 'Optional', 'As applicable'];

  /* ---------- helpers ---------- */
  const lineOf = (i, type, days) => { // [shown, counted]: same rules as the server
    const base = Math.round((+i.qty || 0) * (+i.unit_price || 0) * 100) / 100;
    if (type !== 'commercial') return [base, base];
    if (String(i.amount_note || '').trim()) return [0, 0];
    return [base, DAILY.includes(String(i.freq || '').trim().toLowerCase()) ? Math.round(base * Math.max(1, +days || 1) * 100) / 100 : base];
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
    const type = d ? d.doc_type : (pre.doc_type || 'commercial'); const x = d || {}, cur = x.currency || st.currency || 'USD', comm = type === 'commercial';
    const F = (n, l, v, o) => { o = o || {}; return '<div class="field"' + (o.id ? ' id="' + o.id + '"' : '') + '><label>' + l + '</label>' + (o.ta ? '<textarea name="' + n + '" rows="' + o.ta + '">' + esc(v || '') + '</textarea>' : '<input name="' + n + '"' + (o.type ? ' type="' + o.type + '"' : '') + (o.list ? ' list="' + o.list + '"' : '') + (o.ph ? ' placeholder="' + esc(o.ph) + '"' : '') + (o.req ? ' required' : '') + ' maxlength="' + (o.max || 190) + '" value="' + esc(v ?? '') + '">') + '</div>'; };
    const dl = (id, arr) => '<datalist id="' + id + '">' + arr.map(a => '<option value="' + esc(a) + '">').join('') + '</datalist>';
    const m = modal('<div class="row"><h2 class="grow">' + (d ? 'Edit ' + FTYPE[d.doc_type].toLowerCase() + ' ' + esc(d.number) : 'New ' + (comm ? 'commercial' : FTYPE[type].toLowerCase())) + '</h2><button class="btn ghost sm" id="cx">✕</button></div><form id="ff" style="margin-top:10px">' +
      (d ? '' : '<div class="grid2"><div class="field"><label>Document</label><select name="doc_type" id="fdt">' + opts([['commercial', 'Commercial (quotation)'], ['proforma', 'Proforma invoice'], ['invoice', 'Invoice']], type) + '</select></div><div class="field"><label>Link to RFQ task</label><input name="task_id" type="number" value="' + esc(pre.task_id || '') + '" placeholder="Task number (optional)"></div></div>') +
      '<div class="muted small" style="margin:-4px 0 10px">' + (d ? 'The number ' + esc(d.number) + ' stays the same.' : 'The document number is given automatically when you create it.') + '</div>' +
      (comm ? '<div class="grid2">' + F('client_name', 'Prepared for (client)', x.client_name || pre.client_name, { req: 1, list: 'fcl' }) + F('client_email', 'Client email', x.client_email, { type: 'email' }) + '</div>' + F('subject', 'Project title (heading above the table)', x.subject, { max: 300, ph: 'Commercials (Day Rate Per FPSO) for …' }) + F('intro', 'Note under the heading', x.intro, { ta: 2 }) +
        '<div class="grid2">' + F('rfq_ref', 'RFQ reference', x.rfq_ref || pre.rfq_ref, { max: 80 }) + F('days', 'Number of days in the scope window', x.days || 10, { type: 'number' }) + '</div><div class="grid2">' + F('issue_date', 'Date', x.issue_date || today(), { type: 'date' }) + F('valid_until', 'Valid until', x.valid_until || '', { type: 'date' }) + '</div>' +
        F('client_address', 'Client address (optional)', x.client_address, { ta: 2 })
        : '<div class="grid2"><div class="field"><label>Bill to (client)</label><input name="client_name" required maxlength="190" list="fcl" value="' + esc(x.client_name || pre.client_name || '') + '"></div>' + F('attention', 'Attention', x.attention, { max: 120 }) + '</div><div class="grid2">' + F('client_email', 'Email', x.client_email, { type: 'email' }) + F('customer_id', 'Customer ID', x.customer_id, { max: 60, ph: 'N/A' }) + '</div>' + F('client_address', 'Bill to address', x.client_address, { ta: 2 }) +
        '<div class="grid2">' + F('ship_to', 'Ship to', x.ship_to, { ta: 2, max: 500 }) + '<div>' + F('terms_instr', 'Terms & instructions', x.terms_instr, { list: 'ftin', max: 120, ph: 'DDP' }) + F('shipping_method', 'Shipping method', x.shipping_method, { list: 'fshp', max: 80 }) + '</div></div>' +
        '<div class="grid2">' + F('rfq_ref', 'Customer RFQ number', x.rfq_ref || pre.rfq_ref, { max: 80 }) + F('po_number', 'PO number', x.po_number, { max: 80 }) + '</div><div class="grid2">' + F('payment_terms', 'Payment terms', x.payment_terms, { list: 'fpt', max: 120, ph: '60 DAYS' }) + F('sales_rep', 'Sales representative', x.sales_rep, { max: 80 }) + '</div><div class="grid2">' + F('sales_email', 'Sales rep email', x.sales_email, { type: 'email', max: 120 }) + F('sales_tel', 'Sales rep phone', x.sales_tel, { max: 60 }) + '</div>' +
        '<div class="grid2">' + F('issue_date', 'Invoice date', x.issue_date || today(), { type: 'date' }) + F('due_date', 'Payment due date', x.due_date || '', { type: 'date' }) + (type === 'proforma' ? '</div><div class="grid2">' + F('valid_until', 'Quote expiration date', x.valid_until || '', { type: 'date' }) + F('delivery_leadtime', 'Delivery lead-time', x.delivery_leadtime, { max: 120, ph: 'e.g. 4 weeks after PO' }) + '</div>' : '</div>')) +
      dl('fcl', S.companies.map(c => c.name)) + dl('ftin', ['DDP', 'DAP', 'EXW', 'FOB', 'CIF', 'CFR']) + dl('fshp', ['TRUCK', 'AIR FREIGHT', 'SEA FREIGHT', 'COURIER', 'HAND DELIVERY']) + dl('fpt', ['IMMEDIATE', '14 DAYS', '30 DAYS', '45 DAYS', '60 DAYS', '90 DAYS']) + dl('fcats', ['Personnel', 'Project Deliverables', 'Project Management', 'Standby Rate', 'Optional Services']) +
      '<h3 style="margin:12px 0 6px">Items <span class="muted small">' + (comm ? '(letters A, B, C… and totals are worked out for you)' : '(S/N is numbered automatically)') + '</span></h3><div id="fi" class="fin-items' + (comm ? ' comm' : '') + '"></div><div class="row" style="margin:6px 0"><button type="button" class="btn ghost sm" id="addi">+ Add line</button>' + (comm ? '<button type="button" class="btn ghost sm" id="adds">+ Standard categories</button>' : '') +
      (cat.length ? '<select id="fcat" style="width:auto"><option value="">+ From products &amp; services…</option>' + cat.map(c => '<option value="' + c.id + '">' + esc(c.name + (c.price != null ? ' — ' + c.price : '')) + '</option>').join('') + '</select>' : '') + '</div>' +
      '<div class="grid2"><div class="field"><label>Currency</label><select id="fcur">' + curOptions(cur) + '</select><div id="fcur2" class="grid2" style="margin-top:6px;display:none"><input id="fcc" maxlength="5" placeholder="Code, e.g. GHS" aria-label="Currency code"><input id="fcs" maxlength="6" placeholder="Sign, e.g. GH₵" aria-label="Currency sign"></div><input type="hidden" name="currency" value="' + esc(cur) + '"><input type="hidden" name="currency_symbol" value="' + esc(x.currency_symbol || '') + '"></div><div class="field"><label>Discount (amount)</label><input name="discount" type="number" min="0" step="0.01" value="' + (x.discount || 0) + '"></div></div><div class="grid2"><div class="field"><label>Tax name</label><input name="tax_label" value="' + esc(x.tax_label || st.tax_label || 'VAT') + '"></div><div class="field"><label>Tax rate (%)</label><input name="tax_rate" type="number" min="0" max="100" step="0.01" value="' + (x.tax_rate ?? (comm ? 0 : st.tax_rate) ?? 0) + '"></div></div>' +
      '<div id="ftot" class="small" style="text-align:right;margin:6px 0;font-weight:600"></div><div class="field"><label>Notes (shown on the document)</label><textarea name="notes" rows="2">' + esc(x.notes || '') + '</textarea></div><div class="field"><label>Terms and conditions</label><textarea name="terms" rows="3">' + esc(x.terms ?? st.terms ?? '') + '</textarea></div><div class="err" id="er"></div><div class="row"><button class="btn">' + (d ? 'Save changes' : 'Create') + '</button><button class="btn ghost" type="button" id="cx2">Cancel</button></div></form>');
    m.querySelector('.modal').style.cssText += ';max-width:96vw;width:' + (comm ? '1040px' : '820px');
    const dt = $('#fdt', m); if (dt) dt.onchange = () => { const f = Object.fromEntries(new FormData($('#ff', m))); m.close(); form(null, { ...pre, ...f, doc_type: dt.value, client_name: f.client_name, task_id: f.task_id }); };
    const fi = $('#fi', m);
    const row = i => {
      const r = document.createElement('div'); r.className = 'frow' + (comm ? ' c' : '');
      r.innerHTML = (comm ? '<input data-k="category" placeholder="Category" list="fcats" value="' + esc(i.category || '') + '">' : '') + '<input data-k="description" placeholder="Description" value="' + esc(i.description || '') + '"><input data-k="unit" placeholder="' + (comm ? 'UOM' : 'UoM') + '" value="' + esc(i.unit || '') + '"><input data-k="qty" type="number" min="0" step="any" placeholder="Qty" value="' + (i.qty ?? 1) + '">' +
        (comm ? '<input data-k="freq" placeholder="Frequency" list="ffq" value="' + esc(i.freq || '') + '"><input data-k="unit_price" type="number" min="0" step="any" placeholder="Unit rate" value="' + (i.unit_price ?? '') + '"><select data-k="amount_note" title="Shown instead of an amount, and not counted in the total">' + opts([['', 'Counted'], ...NOTES.map(n => [n, n])], i.amount_note || '') + '</select>'
          : '<input data-k="unit_price" type="number" min="0" step="any" placeholder="Unit price" value="' + (i.unit_price ?? '') + '">') + '<span class="fl" data-amt></span><button type="button" class="btn ghost sm" data-r aria-label="Remove line">✕</button>';
      r.querySelector('[data-r]').onclick = () => { r.remove(); tot(); }; $$('input,select', r).forEach(n => n.oninput = n.onchange = tot); fi.appendChild(r); };
    fi.insertAdjacentHTML('beforebegin', dl('ffq', FREQS));
    const items = () => $$('.frow', fi).map(r => Object.fromEntries($$('[data-k]', r).map(n => [n.dataset.k, n.value])));
    const tot = () => { const f = $('#ff', m), days = comm ? +f.days.value || 1 : 1; let sub = 0;
      $$('.frow', fi).forEach(r => { const it = Object.fromEntries($$('[data-k]', r).map(n => [n.dataset.k, n.value])); const [shown, counted] = lineOf(it, type, days); sub += counted; const a = r.querySelector('[data-amt]'); a.textContent = it.amount_note ? it.amount_note : fmoney(shown, f.currency.value, f.currency_symbol.value); });
      const disc = Math.min(+f.discount.value || 0, sub), tax = (sub - disc) * (+f.tax_rate.value || 0) / 100;
      $('#ftot', m).textContent = (comm ? 'Estimated ' + days + '-day total ' : 'Subtotal ') + fmoney(sub, f.currency.value, f.currency_symbol.value) + ' · Tax ' + fmoney(tax, f.currency.value, f.currency_symbol.value) + ' · Total ' + fmoney(sub - disc + tax, f.currency.value, f.currency_symbol.value); };
    (d && d.items.length ? d.items : [{}]).forEach(row); tot(); $('#addi', m).onclick = () => { const last = items().pop() || {}; row(comm ? { category: last.category || '', qty: 1, unit: last.unit || '', freq: last.freq || '' } : {}); };
    const std = $('#adds', m); if (std) std.onclick = () => { [['Personnel – Daily Onsite (Operational)', 'Day rate'], ['Project Deliverables', 'Fixed'], ['Project Management', 'Fixed'], ['Standby Rate', 'Per person/day'], ['Optional Services', '']].forEach(([c, fq]) => row({ category: c, qty: 1, unit: 'EA', freq: fq, amount_note: c === 'Standby Rate' ? 'As incurred' : c === 'Optional Services' ? 'Optional' : '' })); tot(); };
    $$('[name=discount],[name=tax_rate],[name=days]', m).forEach(n => n.oninput = tot);
    wireCurrency(m, tot);
    const fc = $('#fcat', m); if (fc) fc.onchange = () => { const c = cat.find(z => z.id == fc.value); if (c) { const rr = $$('.frow', fi); if (rr.length === 1 && !rr[0].querySelector('[data-k=description]').value) rr[0].remove(); row({ description: c.name + (c.description ? ' — ' + c.description.slice(0, 120) : ''), qty: 1, unit: c.unit, unit_price: c.price }); tot(); } fc.value = ''; };
    $('#cx', m).onclick = $('#cx2', m).onclick = () => m.close();
    $('#ff', m).onsubmit = async e => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.target)); f.items = items().filter(i => i.description.trim()); if (f.task_id === '') delete f.task_id; if (d) delete f.doc_type; else f.doc_type = type;
      try { const r = await api(d ? 'finance/doc/' + d.id : 'finance/doc', { method: d ? 'PATCH' : 'POST', data: f }); m.close(); toast(d ? 'Saved' : r.number + ' created'); location.hash = '#/finance/' + (d ? d.id : r.id); X.render(); } catch (er) { $('#er', m).textContent = er.message; } };
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
    const short = (String(d.client_name || '').split(/[\s,.]+/)[0] || '').toUpperCase();
    const rowHtml = (i, n) => '<tr><td class="c">' + (n + 1) + '.</td><td>' + nl(i.description) + '</td><td class="c">' + esc(i.unit || '') + '</td><td class="c">' + num(i.qty) + '</td><td class="r">' + fm(i.unit_price) + '</td><td class="r">' + fm(i.qty * i.unit_price) + '</td></tr>';
    const filler = '<tr class="fill"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
    const cost = i => Math.max(11, 5.2 * Math.max(1, String(i.description || '').split('\n').reduce((a, ln) => a + Math.max(1, Math.ceil(ln.length / 50)), 0)) + 4); // mm a row takes
    const ROOM1 = 138, ROOMN = 218, A_MM = isPro ? 62 : 40, B_MM = 92; // room for rows on page 1 / later pages; totals+words block; bank+signatures block
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
    const meta = '<div class="inv-meta">Invoice Number: <b>' + esc(d.number) + '</b>' + (isInv ? (d.po_number ? '<br>PO Number: ' + esc(d.po_number) : '') + (s.tax_id ? '<br>Tin: ' + esc(s.tax_id) : '') + (s.rc_number ? '<br>RC: ' + esc(s.rc_number) : '') : '') + '<br>Date: ' + esc(ordinalDate(d.issue_date)) + '</div>';
    const rep = [d.sales_rep && '<b>Sales Representative – ' + esc(d.sales_rep) + '</b>', d.sales_email && 'Email: ' + esc(d.sales_email), d.sales_tel && 'Tel: ' + esc(d.sales_tel)].filter(Boolean).join('<br>') || '<b>Sales Representative</b>';
    const grid = '<table class="inv-grid"><colgroup><col style="width:36%"><col style="width:26%"><col style="width:38%"></colgroup><tr class="h"><td>Bill To:</td><td>Terms &amp; Instructions</td><td>Ship To:</td></tr>' +
      '<tr class="tall"><td><div class="bt"><div><b>' + esc(d.client_name) + '</b><br>' + nl(d.client_address) + (d.client_email ? '<div class="gap"></div>Email: ' + esc(d.client_email) : '') + '</div><div class="att"><b>Attention: ' + esc(d.attention || '') + '</b></div></div></td><td>' + esc(d.terms_instr || '') + '</td><td>' + nl(d.ship_to) + '</td></tr>' +
      '<tr class="h"><td>Customer ID' + (d.customer_id ? '' : ' - N/A') + '</td><td>Customer' + (short && short.length <= 14 ? ' (' + esc(short) + ')' : '') + ' RFQ Number</td><td>Payment Terms</td></tr><tr><td>' + esc(d.customer_id || '') + '</td><td>' + esc(d.rfq_ref || '') + (isPro && d.po_number ? '<br>PO ' + esc(d.po_number) : '') + '</td><td>' + esc(d.payment_terms || '') + '</td></tr>' +
      '<tr class="h tall2"><td>' + rep + '</td><td>Shipping Method</td><td></td></tr><tr><td>&nbsp;</td><td>' + esc(d.shipping_method || '') + '</td><td></td></tr></table>';
    const colg = '<colgroup><col style="width:6%"><col style="width:36%"><col style="width:8%"><col style="width:7%"><col style="width:20%"><col style="width:23%"></colgroup>';
    const thead = '<thead><tr class="h"><td class="c">S/N.</td><td>Description</td><td class="c">UoM</td><td class="c">Qty</td><td>Unit Price</td><td>Amount</td></tr></thead>';
    const tfoot = '<tfoot><tr class="tot"><td colspan="5">SUBTOTAL</td><td class="navy">' + fm(t.subtotal) + '</td></tr>' + (t.discount ? '<tr class="tot"><td colspan="5">DISCOUNT</td><td class="navy">−' + fm(t.discount) + '</td></tr>' : '') + '<tr class="tot"><td colspan="5">' + esc((d.tax_label || 'VAT').toUpperCase()) + (+d.tax_rate ? ' (' + num(d.tax_rate) + '%)' : '') + '</td><td class="navy">' + fm(t.tax) + '</td></tr><tr class="tot"><td colspan="5">TOTAL</td><td class="navy">' + fm(t.total) + '</td></tr></tfoot>';
    const words = '<div class="inv-words' + (isInv ? ' strong' : '') + '"><b>AMOUNT IN WORDS:</b> ' + esc(amountWords(t.total, c)) + (isInv ? '.' : '') + '</div>';
    const tailA = words + (isPro ? '<div class="inv-lines"><div><b>Quote Expiration Date:</b> ' + esc(d.valid_until ? ordinalDate(d.valid_until) : '') + '</div><div><b>Delivery Leadtime:</b> ' + esc(d.delivery_leadtime || '') + '</div>' + (d.due_date ? '<div><b>Payment due:</b> ' + esc(ordinalDate(d.due_date)) + '</div>' : '') + '</div>' : (d.due_date ? '<div class="inv-lines"><div><b>Payment due:</b> ' + esc(ordinalDate(d.due_date)) + '</div></div>' : '')) + (d.notes ? '<div class="inv-notes"><b>Notes</b><br>' + nl(d.notes) + '</div>' : '');
    const tailB = '<table class="inv-bank"><tr class="h"><td colspan="2">PAYMENT SHOULD BE MADE TO</td></tr><tr><td class="k">BANK NAME</td><td>' + esc(s.bank_name || '') + '</td></tr><tr><td class="k">ACCOUNT NAME</td><td>' + esc(s.account_name || '') + '</td></tr><tr><td class="k">ACCOUNT NUMBER</td><td>' + esc(s.account_number || '') + '</td></tr><tr><td class="k">VAT REG</td><td>' + esc(s.tax_id || '') + '</td></tr></table>' +
      (s.bank_details ? '<div class="inv-notes">' + nl(s.bank_details) + '</div>' : '') +
      '<div class="inv-sign"><div>FOR ' + esc((s.company_name || 'AEROSUB').toUpperCase()) + '<div class="line"></div></div><div>FOR CLIENT<div class="line"></div></div></div>' + (d.terms ? '<div class="inv-terms"><b>Terms</b><br>' + nl(d.terms) + '</div>' : '');
    return pages.map((pg, n) => {
      const body = pg.rows.map(i => rowHtml(d.items[i], i)).join('') + (n === 0 && pg.a ? filler.repeat(Math.max(0, 3 - d.items.length)) : '');
      const table = body || pg.a ? '<table class="inv-items">' + colg + thead + '<tbody>' + body + '</tbody>' + (pg.a ? tfoot : '') + '</table>' : '';
      return '<div class="fin-sheet inv"><div class="inv-bg"></div>' + (isPro ? '<div class="inv-title-cover">PROFORMA<br>INVOICE</div>' : '') + '<div class="inv-body">' + (d.status === 'void' ? '<div class="fvoid" style="position:absolute;right:20mm;top:50mm">VOID</div>' : '') +
        (n === 0 ? meta + grid : '') + table + (pg.a ? tailA : '') + (pg.b ? tailB : '') + '</div></div>';
    }).join('');
  }

  function commercialHtml(d) {
    const s = d.seller || {}, c = d.currency, days = Math.max(1, +d.days || 1), K = c + ' ';
    const fmoney = n => X.fmoney(n, c, d.currency_symbol);
    const groups = []; d.items.forEach(i => { const name = i.category || 'General'; let g = groups.find(x => x.name === name); if (!g) groups.push(g = { name, items: [] }); g.items.push(i); });
    let anyDaily = false, grand = 0, body = '', incl = [];
    groups.forEach((g, gi) => {
      const L = String.fromCharCode(65 + gi); let sub = 0, est = 0, counted = 0, daily = false;
      g.items.forEach(i => { const [shown, cnt] = lineOf(i, 'commercial', days); if (!String(i.amount_note || '').trim()) { counted++; sub += shown; est += cnt; if (DAILY.includes(String(i.freq || '').trim().toLowerCase())) daily = true; } });
      if (daily) anyDaily = true; if (counted) { grand += est; incl.push(g); }
      const short = g.name.split(/\s[–-]\s/)[0]; const span = g.items.length + (counted ? 1 : 0);
      g.items.forEach((i, n) => { const [shown] = lineOf(i, 'commercial', days), note = String(i.amount_note || '').trim();
        body += '<tr>' + (n === 0 ? '<td class="c b" rowspan="' + span + '">' + L + '</td><td class="c b" rowspan="' + span + '">' + esc(g.name) + '</td>' : '') + '<td>' + esc(i.description) + '</td><td class="c">' + esc(i.unit || '') + '</td><td class="c">' + (+i.qty ? num(i.qty) : '') + '</td><td class="c">' + esc(i.freq || '') + '</td><td class="r">' + ((+i.unit_price) ? fmoney(i.unit_price, c) : '') + '</td><td class="r' + (note ? ' c' : '') + '">' + (note ? esc(note) : fmoney(shown, c)) + '</td></tr>'; });
      if (counted) body += '<tr class="sub"><td colspan="5" class="c b">Sub Total for ' + esc(short) + '</td><td class="r b">' + fmoney(sub, c) + '</td></tr>';
      if (daily) body += '<tr class="est"><td class="blank"></td><td class="blank"></td><td colspan="4" class="c b big">Estimated ' + days + '-Day Total (' + esc(short) + ')</td><td colspan="2" class="c b big">' + fmoney(est, c) + '</td></tr>';
    });
    if (anyDaily && incl.length > 1) body += '<tr class="est grand"><td class="blank g"></td><td class="blank g"></td><td colspan="4" class="c b big">Estimated ' + days + '-Day Total (' + esc(incl.map(g => g.name.split(/\s[–-]\s/)[0]).join(' + ')) + ')</td><td colspan="2" class="c b big">' + fmoney(grand, c) + '</td></tr>';
    const disc = Math.min(+d.discount || 0, grand), tax = Math.round((grand - disc) * (+d.tax_rate || 0)) / 100, total = grand - disc + tax;
    if (disc) body += '<tr class="sub"><td colspan="7" class="c b">Discount</td><td class="r b">−' + fmoney(disc, c) + '</td></tr>';
    if (+d.tax_rate) body += '<tr class="sub"><td colspan="7" class="c b">' + esc(d.tax_label || 'VAT') + ' (' + num(d.tax_rate) + '%)</td><td class="r b">' + fmoney(tax, c) + '</td></tr>';
    body += '<tr class="total"><td colspan="7" class="c b">TOTAL (' + esc(c) + ')</td><td class="r b g">' + fmoney(total, c) + '</td></tr>';
    return '<div class="fin-sheet comm" data-page="comm"><section class="cover"><div class="cv-band"><img src="assets/img/aerosub-logo.png" alt="Aerosub" data-rm="1"></div><div class="cv-text"><div class="cv-k">COMMERCIAL PROPOSAL</div><div class="cv-sub">' + esc(d.subject || '') + '</div><div class="cv-ref">' + esc(d.number) + (d.rfq_ref ? ' · RFQ ' + esc(d.rfq_ref) : '') + '<br>' + esc(fmtDate(d.issue_date)) + '</div></div><div class="cv-for">Prepared For: <b>' + esc(d.client_name) + '</b></div></section>' +
      '<section class="tbl"><table class="pgwrap"><thead><tr><td><div class="sp"></div></td></tr></thead><tfoot><tr><td><div class="sp f">aerosub.co</div></td></tr></tfoot><tbody><tr><td>' + (d.status === 'void' ? '<div class="fvoid">VOID</div>' : '') + '<h2 class="ct">' + esc(d.subject || 'Commercial offer') + '</h2>' + (d.intro ? '<p class="ci">' + nl(d.intro) + '</p>' : '') +
      '<table class="comm-t"><thead><tr><th>S/N</th><th>Category</th><th>Items Description</th><th>UOM</th><th>QTY</th><th>Frequency/<br>No of Days</th><th>Unit Rate<br>(' + esc(c) + ')</th><th>Total<br>(' + esc(c) + ')</th></tr></thead><tbody>' + body + '</tbody></table>' +
      '<div class="cm-foot"><div><b>Amount in words:</b> ' + esc(amountWords(total, c)) + '.</div>' + (d.valid_until ? '<div><b>Valid until:</b> ' + esc(fmtDate(d.valid_until)) + '</div>' : '') + (d.notes ? '<div style="margin-top:8px"><b>Notes</b><br>' + nl(d.notes) + '</div>' : '') + (d.terms ? '<div style="margin-top:8px"><b>Terms</b><br>' + nl(d.terms) + '</div>' : '') + '<div class="cm-co">' + esc([s.company_name, s.phone, s.email, s.website].filter(Boolean).join(' · ')) + '</div></div></td></tr></tbody></table></section></div>';
  }

  window.OfficeFin = { settings, form, amountWords, words, html: d => d.doc_type === 'commercial' ? commercialHtml(d) : invoiceHtml(d) };
})();
