/* Aerosub Office: finance templates. "Save as template" keeps a document's lines, notes, terms, tax and (optionally) its look, without the client, number or dates.
 * New documents can start from a template (the "Start from a saved template" box in the New document form). Finance > Templates lists, renames and deletes them. No inline handlers (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc, FTYPE } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const when = t => t ? new Date(String(t).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';

  const prev = X.finDocHook;
  X.finDocHook = (d, v, id) => {
    if (prev) prev(d, v, id); if (!d.can_edit || d.doc_type === 'receipt') return; const anchor = $('#fcz', v) || $('#fpr', v); if (!anchor) return;
    const b = document.createElement('button'); b.className = 'btn ghost sm'; b.id = 'fts'; b.textContent = '📑 Save as template'; anchor.before(b);
    b.onclick = () => { const m = modal('<div class="row"><h2 class="grow">Save as template</h2><button class="btn ghost sm" id="tx">✕</button></div><div class="muted small" style="margin:4px 0 8px">Next time, start a new ' + esc((FTYPE[d.doc_type] || d.doc_type).toLowerCase()) + ' from it. It keeps the lines, categories, notes, terms, payment and tax settings. It does not keep the client, number or dates. If you use a name that already exists, that template is updated.</div><form id="tf"><div class="field"><label>Template name</label><input id="tn" required maxlength="120" value="' + esc(d.subject || ((FTYPE[d.doc_type] || '') + ' template')) + '"></div><label class="small" style="display:block;margin:6px 0 10px"><input type="checkbox" id="tl" style="width:auto" checked> Keep how it looks (fonts, colours, spacing)</label><div class="err" id="er"></div><div class="row"><button class="btn">Save template</button><button class="btn ghost" type="button" id="tc">Cancel</button></div></form>');
      $('#tx', m).onclick = $('#tc', m).onclick = () => m.close();
      $('#tf', m).onsubmit = async e => { e.preventDefault(); try { const r = await api('finance/templates', { method: 'POST', data: { name: $('#tn', m).value, from_doc: id, include_styles: $('#tl', m).checked ? 1 : 0 } }); m.close(); toast(r.updated ? 'Template updated' : 'Template saved. Start a new document from it any time.'); } catch (er) { $('#er', m).textContent = er.message; } }; };
  };

  async function manager() {
    const m = modal('<div class="row"><h2 class="grow">Templates</h2><button class="btn ghost sm" id="mx">✕</button></div><div class="muted small" style="margin:4px 0 8px">Open a document and press “Save as template” to add one. Start a new document from a template in the New document form.</div><div id="ml"><div class="empty">Loading…</div></div>');
    $('#mx', m).onclick = () => m.close();
    const draw = async () => { let l; try { l = (await api('finance/templates')).templates; } catch (er) { $('#ml', m).innerHTML = '<div class="empty">' + esc(er.message) + '</div>'; return; }
      $('#ml', m).innerHTML = l.length ? '<div class="list">' + l.map(t => '<div class="item" style="flex-wrap:wrap"><div class="grow"><b>' + esc(t.name) + '</b><div class="muted small">' + esc(FTYPE[t.doc_type] || t.doc_type) + ' · ' + esc(when(t.updated_at)) + (t.by ? ' · ' + esc(t.by) : '') + '</div></div><button class="btn sm" data-use="' + t.id + '" data-ty="' + t.doc_type + '">Use</button><button class="btn ghost sm" data-ren="' + t.id + '">Rename</button><button class="btn ghost sm" data-del="' + t.id + '" style="color:var(--bad)">Delete</button></div>').join('') + '</div>' : '<div class="empty">No templates yet.</div>';
      $$('[data-use]', m).forEach(b => b.onclick = async () => { try { const t = (await api('finance/templates/' + b.dataset.use)).template; const ks = [...new Set((t.items || []).map(i => i.kind || 'service'))]; m.close(); window.OfficeFin.form(null, { doc_type: t.doc_type, fields: t.fields, items: t.items, styles: t.styles, tpl: +t.id, scope: ks.length > 1 ? 'both' : (ks[0] || 'service') }); } catch (er) { toast(er.message); } });
      $$('[data-ren]', m).forEach(b => b.onclick = async () => { const t = l.find(x => x.id == b.dataset.ren), nn = prompt('New name for this template:', t.name); if (!nn || !nn.trim()) return; try { await api('finance/templates/' + t.id, { method: 'PATCH', data: { name: nn } }); draw(); } catch (er) { toast(er.message); } });
      $$('[data-del]', m).forEach(b => b.onclick = async () => { if (!confirm('Delete this template? Documents made from it are not affected.')) return; try { await api('finance/templates/' + b.dataset.del, { method: 'DELETE' }); draw(); } catch (er) { toast(er.message); } }); };
    draw();
  }
  // a Templates button next to "Company details" on the Finance page
  let busy = false; new MutationObserver(() => { if (busy) return; busy = true; setTimeout(() => { try { const st = $('#fset'); if (st && !$('#ftplm')) { const b = document.createElement('button'); b.className = 'btn ghost'; b.id = 'ftplm'; b.textContent = 'Templates'; b.onclick = manager; st.before(b); } } finally { busy = false; } }, 60); }).observe(document.body, { childList: true, subtree: true });
})();
