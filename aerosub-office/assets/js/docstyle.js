/* Aerosub Office: customize how a finance document looks. "Customize layout" lets Finance click any part of a document (heading, table, table header, rows,
 * totals, notes ...) and change its font, size, weight, colour, alignment and spacing. A look can be saved for one document or for every document of that type.
 * The looks are plain checked values (never free CSS) and are painted with a <style> inside the sheet, so printing and PDF show them too. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, toast, esc } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const FONTS = { calibri: ['Calibri', 'Calibri,Carlito,Arial,sans-serif'], poppins: ['Poppins', 'Poppins,Arial,sans-serif'], inter: ['Inter', 'Inter,Arial,sans-serif'], arial: ['Arial', 'Arial,Helvetica,sans-serif'], times: ['Times New Roman', "'Times New Roman',Times,serif"], georgia: ['Georgia', 'Georgia,serif'], verdana: ['Verdana', 'Verdana,Geneva,sans-serif'], courier: ['Courier New', "'Courier New',monospace"] };
  // [key, name shown, CSS selector inside the sheet, {border: the table lines can be changed}]
  const COMMON = [['words', 'Amount in words', '.inv-words'], ['notes', 'Notes', '.inv-notes'], ['sign', 'Signature blocks', '.inv-sign']];
  const EL = {
    commercial: [['coverTitle', 'Cover title', '.cv-k'], ['coverSub', 'Cover subtitle', '.cv-sub'], ['coverRef', 'Cover reference', '.cv-ref'], ['coverFor', 'Cover: prepared for', '.cv-for'],
      ['title', 'Heading above the table', '.tbl .ct'], ['scope', 'Products / services line', '.tbl div.ci'], ['intro', 'Note under the heading', '.tbl p.ci'],
      ['table', 'The whole table (lines)', '.comm-t', { border: 1 }], ['thead', 'Table header', '.comm-t th', { border: 1 }], ['cells', 'Table rows', '.comm-t td', { border: 1 }], ['cat', 'S/N and category cells', '.comm-t td[rowspan]', { border: 1 }],
      ['sub', 'Sub total rows', '.comm-t tr.sub td', { border: 1 }], ['total', 'TOTAL row', '.comm-t tr.total td', { border: 1 }], ['cmwords', 'Amount in words', '.cm-foot > div:first-child'], ['extra', 'Notes and terms', '.cm-foot > div:not(:first-child):not(.cm-co)'], ['company', 'Company line', '.cm-co']],
    invoice: [['meta', 'Number and date', '.inv-meta'], ['grid', 'Details grid', '.inv-grid td', { border: 1 }], ['gridh', 'Details grid headings', '.inv-grid tr.h td', { border: 1 }], ['thead', 'Items header', '.inv-items thead td', { border: 1 }], ['rows', 'Items rows', '.inv-items tbody td', { border: 1 }], ['totals', 'Totals', '.inv-items tfoot td', { border: 1 }], ['lines', 'Expiry and lead time', '.inv-lines'], ['bank', 'Payment details', '.inv-bank td', { border: 1 }], ['terms', 'Terms', '.inv-terms']].concat(COMMON),
    requisition: [['title', 'Title', '.rq-title'], ['code', 'Requisition code', '.rq-code'], ['bar', 'Contact bar', '.rq-bar'], ['meta', 'Number and date', '.inv-meta'], ['kvh', 'Request details heading', '.rq-kv tr.h td', { border: 1 }], ['kv', 'Request details', '.rq-kv td', { border: 1 }], ['why', 'Reason', '.rq-why'],
      ['thead', 'Items header', '.inv-items thead td', { border: 1 }], ['rows', 'Items rows', '.inv-items tbody td', { border: 1 }], ['totals', 'Totals', '.inv-items tfoot td', { border: 1 }], ['foot', 'Page footer', '.rq-foot']].concat(COMMON),
  };
  EL.proforma = EL.invoice;
  const PROPS = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'color', 'background', 'textAlign', 'lineHeight', 'letterSpacing', 'paddingY', 'paddingX', 'marginTop', 'marginBottom', 'borderColor', 'borderWidth'];
  const decl = p => { const o = []; const f = (k, v) => o.push(k + ':' + v + ' !important');
    if (p.fontFamily && FONTS[p.fontFamily]) f('font-family', FONTS[p.fontFamily][1]); if (p.fontSize) f('font-size', p.fontSize + 'px'); if (p.fontWeight) f('font-weight', p.fontWeight); if (p.fontStyle) f('font-style', p.fontStyle);
    if (p.color) f('color', p.color); if (p.background) f('background-color', p.background === 'none' ? 'transparent' : p.background); if (p.textAlign) f('text-align', p.textAlign); if (p.lineHeight) f('line-height', p.lineHeight); if (p.letterSpacing !== undefined) f('letter-spacing', p.letterSpacing + 'px');
    if (p.paddingY !== undefined) { f('padding-top', p.paddingY + 'px'); f('padding-bottom', p.paddingY + 'px'); } if (p.paddingX !== undefined) { f('padding-left', p.paddingX + 'px'); f('padding-right', p.paddingX + 'px'); }
    if (p.marginTop !== undefined) f('margin-top', p.marginTop + 'px'); if (p.marginBottom !== undefined) f('margin-bottom', p.marginBottom + 'px');
    if (p.borderColor) f('border-color', p.borderColor); if (p.borderWidth !== undefined) { f('border-width', p.borderWidth + 'px'); if (p.borderWidth > 0) f('border-style', 'solid'); } return o.join(';'); };
  const merge = (a, b) => { const o = {}; for (const k of new Set([...Object.keys(a || {}), ...Object.keys(b || {})])) o[k] = { ...(a || {})[k], ...(b || {})[k] }; return o; };
  const cssFor = (type, styles) => (EL[type] || []).map(([k, , sel]) => styles[k] && Object.keys(styles[k]).length ? '.fin-sheet ' + sel + '{' + decl(styles[k]) + '}' : '').join('\n');
  const paint = (v, type, styles) => { $$('.fin-sheet', v).forEach(sh => { let t = sh.querySelector(':scope > style.fs-style'); if (!t) { t = document.createElement('style'); t.className = 'fs-style'; sh.prepend(t); } t.textContent = cssFor(type, styles); }); };
  const rgb2hex = c => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(c || ''); if (!m || (m[4] !== undefined && +m[4] === 0)) return '#ffffff'; return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join(''); };

  const prev = X.finDocHook;
  X.finDocHook = (d, v, id) => {
    if (prev) prev(d, v, id); if (d.doc_type === 'receipt' || !EL[d.doc_type]) return;
    const type = d.doc_type, S = d.styles || { type: {}, doc: {} }; let draft = { type: JSON.parse(JSON.stringify(S.type || {})), doc: JSON.parse(JSON.stringify(S.doc || {})) };
    const effective = () => merge(draft.type, draft.doc); const apply = () => paint(v, type, effective()); apply();
    if (!d.can_edit) return;
    const bar = $('#fpr', v) && $('#fpr', v).parentElement; if (!bar) return; const btn = document.createElement('button'); btn.className = 'btn ghost sm'; btn.id = 'fcz'; btn.textContent = '🎨 Customize layout'; $('#fpr', v).before(btn);
    btn.onclick = () => start();
    function start() {
      if ($('#fzp')) return; let scope = Object.keys(draft.doc).length ? 'doc' : 'doc', key = null, picking = true;
      const sheet = $('.fin-sheet', v); const panel = document.createElement('div'); panel.id = 'fzp'; panel.className = 'fz-panel noprint'; document.body.appendChild(panel); v.classList.add('fz-on');
      const name = k => ((EL[type].find(e => e[0] === k) || [])[1]) || k;
      const field = (label, inner) => '<label class="fz-f"><span>' + label + '</span>' + inner + '</label>';
      const draw = () => { const cur = (draft[scope][key] || {}), el = EL[type].find(e => e[0] === key), live = key ? (() => { const t = $('.fin-sheet ' + el[2], v); return t ? getComputedStyle(t) : null; })() : null; const val = (p, fb) => cur[p] !== undefined ? cur[p] : fb;
        panel.innerHTML = '<div class="row" style="gap:6px"><b class="grow">Customize layout</b><button class="btn ghost sm" id="fzx" aria-label="Close">✕</button></div><div class="muted small" style="margin:4px 0 8px">Click any part of the document, or pick it here. Changes show straight away.</div>' +
          '<label class="fz-f"><span>Part of the document</span><select id="fzk"><option value="">Choose…</option>' + EL[type].map(([k, l]) => '<option value="' + k + '"' + (k === key ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></label>' +
          '<div class="row small" style="gap:12px;margin:4px 0 8px"><label><input type="radio" name="fzs" value="doc" ' + (scope === 'doc' ? 'checked' : '') + ' style="width:auto"> This document</label><label><input type="radio" name="fzs" value="type" ' + (scope === 'type' ? 'checked' : '') + ' style="width:auto"> All ' + esc((window.OfficeExt.FTYPE || {})[type] || type).toLowerCase() + 's</label></div>' +
          (key && live ? '<div class="fz-grid">' + field('Font', '<select data-p="fontFamily"><option value="">As it is</option>' + Object.entries(FONTS).map(([k, f]) => '<option value="' + k + '"' + (val('fontFamily') === k ? ' selected' : '') + '>' + f[0] + '</option>').join('') + '</select>') +
            field('Size (px)', '<input data-p="fontSize" type="number" min="6" max="60" step="0.5" value="' + val('fontSize', Math.round(parseFloat(live.fontSize) * 2) / 2) + '">') +
            field('Weight', '<select data-p="fontWeight">' + [['', 'As it is'], ['300', 'Light'], ['400', 'Normal'], ['500', 'Medium'], ['600', 'Semi-bold'], ['700', 'Bold'], ['800', 'Extra bold']].map(([a, b]) => '<option value="' + a + '"' + (String(val('fontWeight', '')) === a ? ' selected' : '') + '>' + b + '</option>').join('') + '</select>') +
            field('Style', '<select data-p="fontStyle">' + [['', 'As it is'], ['normal', 'Normal'], ['italic', 'Italic']].map(([a, b]) => '<option value="' + a + '"' + (val('fontStyle', '') === a ? ' selected' : '') + '>' + b + '</option>').join('') + '</select>') +
            field('Text colour', '<input data-p="color" type="color" value="' + val('color', rgb2hex(live.color)) + '">') +
            field('Background', '<span class="row" style="gap:6px"><input data-p="background" type="color" value="' + (val('background', rgb2hex(live.backgroundColor)) === 'none' ? '#ffffff' : val('background', rgb2hex(live.backgroundColor))) + '"><button class="btn ghost sm" type="button" data-nobg>None</button></span>') +
            field('Alignment', '<select data-p="textAlign">' + [['', 'As it is'], ['left', 'Left'], ['center', 'Centre'], ['right', 'Right'], ['justify', 'Justify']].map(([a, b]) => '<option value="' + a + '"' + (val('textAlign', '') === a ? ' selected' : '') + '>' + b + '</option>').join('') + '</select>') +
            field('Line height', '<input data-p="lineHeight" type="number" min="0.8" max="3" step="0.1" value="' + val('lineHeight', '') + '" placeholder="e.g. 1.4">') +
            field('Letter spacing (px)', '<input data-p="letterSpacing" type="number" min="-2" max="10" step="0.1" value="' + val('letterSpacing', '') + '" placeholder="0">') +
            field('Space inside, up and down (px)', '<input data-p="paddingY" type="number" min="0" max="60" step="1" value="' + val('paddingY', '') + '" placeholder="' + Math.round(parseFloat(live.paddingTop)) + '">') +
            field('Space inside, left and right (px)', '<input data-p="paddingX" type="number" min="0" max="60" step="1" value="' + val('paddingX', '') + '" placeholder="' + Math.round(parseFloat(live.paddingLeft)) + '">') +
            field('Space above (px)', '<input data-p="marginTop" type="number" min="0" max="80" step="1" value="' + val('marginTop', '') + '" placeholder="' + Math.round(parseFloat(live.marginTop)) + '">') +
            field('Space below (px)', '<input data-p="marginBottom" type="number" min="0" max="80" step="1" value="' + val('marginBottom', '') + '" placeholder="' + Math.round(parseFloat(live.marginBottom)) + '">') +
            (el[3] && el[3].border ? field('Line colour', '<input data-p="borderColor" type="color" value="' + val('borderColor', '#111111') + '">') + field('Line thickness (px)', '<input data-p="borderWidth" type="number" min="0" max="6" step="0.5" value="' + val('borderWidth', '') + '" placeholder="1">') : '') + '</div>' +
            '<div class="row" style="gap:6px;margin-top:8px"><button class="btn ghost sm" id="fzr">Reset this part</button></div>' : '<div class="muted small">Choose a part of the document to begin.</div>') +
          '<div class="muted small" style="margin-top:8px">Bigger text or spacing can push content onto another page, mostly on invoices.</div><div class="row" style="gap:6px;margin-top:10px;flex-wrap:wrap"><button class="btn" id="fzs">Save</button><button class="btn ghost" id="fza">Reset all (' + (scope === 'doc' ? 'this document' : 'all ' + esc(type) + 's') + ')</button></div>';
        $('#fzx', panel).onclick = stop; $('#fzk', panel).onchange = e => { key = e.target.value || null; mark(); draw(); };
        $$('input[name=fzs]', panel).forEach(r => r.onchange = () => { scope = r.value; draw(); });
        $$('[data-p]', panel).forEach(n => { const set = () => { const p = n.dataset.p; draft[scope][key] = draft[scope][key] || {}; let val = n.value; if (val === '') delete draft[scope][key][p]; else draft[scope][key][p] = ['fontSize', 'lineHeight', 'letterSpacing', 'paddingY', 'paddingX', 'marginTop', 'marginBottom', 'borderWidth'].includes(p) ? +val : val; if (!Object.keys(draft[scope][key]).length) delete draft[scope][key]; apply(); }; n.oninput = n.onchange = set; });
        const nb = $('[data-nobg]', panel); if (nb) nb.onclick = () => { draft[scope][key] = draft[scope][key] || {}; draft[scope][key].background = 'none'; apply(); draw(); };
        const rs = $('#fzr', panel); if (rs) rs.onclick = () => { delete draft[scope][key]; apply(); draw(); };
        $('#fza', panel).onclick = () => { if (!confirm('Reset every change made for ' + (scope === 'doc' ? 'this document' : 'all ' + type + 's') + '?')) return; draft[scope] = {}; apply(); draw(); };
        $('#fzs', panel).onclick = async () => { try { const r = await api('finance/doc/' + id + '/styles', { method: 'PUT', data: { type_styles: draft.type, doc_styles: draft.doc } }); draft = { type: r.styles.type, doc: r.styles.doc }; apply(); toast('Saved'); } catch (er) { toast(er.message); } };
      };
      const mark = () => { $$('.fz-sel', v).forEach(x => x.classList.remove('fz-sel')); if (key) { const el = EL[type].find(e => e[0] === key); $$('.fin-sheet ' + el[2], v).forEach(x => x.classList.add('fz-sel')); } };
      const pick = e => { if (!panel.isConnected || e.target.closest('#fzp')) return; const hit = e.target.closest('.fin-sheet'); if (!hit) return; e.preventDefault(); e.stopPropagation();
        let best = null; for (const [k, , sel] of EL[type]) { const m = e.target.closest(sel); if (m && hit.contains(m) && (!best || best.el.contains(m))) best = { k, el: m }; } if (best) { key = best.k; mark(); draw(); } };
      const stop = () => { v.removeEventListener('click', pick, true); panel.remove(); v.classList.remove('fz-on'); $$('.fz-sel', v).forEach(x => x.classList.remove('fz-sel')); };
      v.addEventListener('click', pick, true); draw();
      if (sheet) sheet.scrollIntoView({ block: 'nearest' });
    }
  };
})();
