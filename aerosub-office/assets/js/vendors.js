/* Aerosub Office: vendors behind products and services. Adds a Vendor box to the product / service form, the vendor on each card, and a Vendors list
 * (Products & services page). The vendor also fills in the payee on a requisition when you pick its product. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, modal, toast, esc } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  let lastProd = 0, cache = null;
  document.addEventListener('click', e => { const c = e.target.closest && e.target.closest('.pcard[data-p]'); if (c) lastProd = +c.dataset.p; }, true);
  const items = async force => { if (!cache || force) { try { cache = (await api('catalog')).items || []; } catch { cache = []; } } return cache; };
  const vendors = async () => { try { return (await api('catalog/vendors')).vendors || []; } catch { return null; } };

  async function enhanceForm(form) {
    if (form.dataset.vendor) return; form.dataset.vendor = '1'; const list = await vendors(); if (list === null) return;
    const editing = /^Edit/.test((form.parentElement.querySelector('h2') || {}).textContent || ''); const cur = editing ? ((await items(true)).find(i => +i.id === lastProd) || {}) : {};
    const f = document.createElement('div'); f.className = 'field'; f.innerHTML = '<label>Vendor (who supplies it)</label><select name="vendor_id"><option value="">None</option>' + list.map(v => '<option value="' + v.id + '"' + (+cur.vendor_id === +v.id ? ' selected' : '') + '>' + esc(v.name) + '</option>').join('') + '<option value="__new">+ New vendor…</option></select><input name="vendor_name" maxlength="190" placeholder="New vendor name" style="display:none;margin-top:6px">';
    const anchor = form.querySelector('#er') || form.lastElementChild; form.insertBefore(f, anchor); const sel = $('select', f), nm = $('input', f);
    sel.onchange = () => { const isNew = sel.value === '__new'; nm.style.display = isNew ? '' : 'none'; if (isNew) nm.focus(); };
    form.addEventListener('submit', () => { if (sel.value === '__new') sel.value = ''; else nm.value = ''; }, true);   // the box is read when the form is sent
  }

  async function manager() {
    const m = modal('<div class="row"><h2 class="grow">Vendors</h2><button class="btn ghost sm" id="vx">✕</button></div><div class="muted small" style="margin:4px 0 8px">The suppliers behind your products and services. Pick a vendor on a product, and a requisition for it fills in the payee for you.</div><div id="vl"><div class="empty">Loading…</div></div><div class="row" style="margin-top:10px"><button class="btn" id="vn">+ New vendor</button></div>');
    $('#vx', m).onclick = () => m.close();
    const draw = async () => { const l = await vendors(); const box = $('#vl', m); if (l === null) { box.innerHTML = '<div class="empty">Only Finance, managers and admins can see vendors.</div>'; return; }
      box.innerHTML = l.length ? '<div class="list">' + l.map(v => '<div class="item"><div class="grow"><b>' + esc(v.name) + '</b><div class="muted small">' + esc([v.contact_name, v.email, v.phone].filter(Boolean).join(' · ')) + ' · ' + v.items + ' item' + (+v.items === 1 ? '' : 's') + '</div></div><button class="btn ghost sm" data-ve="' + v.id + '">Edit</button></div>').join('') + '</div>' : '<div class="empty">No vendors yet.</div>';
      $$('[data-ve]', m).forEach(b => b.onclick = () => form(l.find(v => v.id == b.dataset.ve), draw)); };
    $('#vn', m).onclick = () => form(null, draw); draw();
  }
  function form(v, done) {
    const x = v || {}; const m = modal('<h2>' + (v ? 'Edit' : 'New') + ' vendor</h2><form id="vf" style="margin-top:10px"><div class="field"><label>Name</label><input name="name" required maxlength="190" value="' + esc(x.name || '') + '"></div><div class="grid2"><div class="field"><label>Contact person</label><input name="contact_name" maxlength="160" value="' + esc(x.contact_name || '') + '"></div><div class="field"><label>Email</label><input name="email" type="email" maxlength="190" value="' + esc(x.email || '') + '"></div></div><div class="grid2"><div class="field"><label>Phone</label><input name="phone" maxlength="60" value="' + esc(x.phone || '') + '"></div><div class="field"><label>Address</label><input name="address" maxlength="500" value="' + esc(x.address || '') + '"></div></div><div class="field"><label>Notes</label><textarea name="notes" rows="3" maxlength="2000">' + esc(x.notes || '') + '</textarea></div><div class="err" id="er"></div><div class="row"><button class="btn">Save</button>' + (v ? '<button class="btn ghost" type="button" id="vd" style="color:var(--bad)">Delete</button>' : '') + '<button class="btn ghost" type="button" id="vc">Cancel</button></div></form>');
    $('#vc', m).onclick = () => m.close(); const d = $('#vd', m); if (d) d.onclick = async () => { if (!confirm('Delete this vendor? Its products stay, without a vendor.')) return; await api('catalog/vendors/' + v.id, { method: 'DELETE' }); m.close(); cache = null; done(); };
    $('#vf', m).onsubmit = async e => { e.preventDefault(); try { await api(v ? 'catalog/vendors/' + v.id : 'catalog/vendors', { method: v ? 'PATCH' : 'POST', data: Object.fromEntries(new FormData(e.target)) }); m.close(); cache = null; toast('Saved'); done(); } catch (er) { $('#er', m).textContent = er.message; } };
  }

  async function decorate() {
    const add = $('#npd'); if (add && !$('#vndb')) { const b = document.createElement('button'); b.className = 'btn ghost right'; b.id = 'vndb'; b.textContent = 'Vendors'; b.style.marginRight = '8px'; b.onclick = manager; add.before(b); }
    const cards = $$('#pl .pcard[data-p]:not([data-vd])'); if (cards.length) { const all = await items(false); cards.forEach(c => { c.dataset.vd = '1'; const it = all.find(i => +i.id === +c.dataset.p); if (it && it.vendor_name) { const h = $('h3', c); if (h) h.insertAdjacentHTML('afterend', '<div class="muted small">Vendor: ' + esc(it.vendor_name) + '</div>'); } }); }
    const pf = $('#pdf'); if (pf) enhanceForm(pf);
  }
  let busy = false; new MutationObserver(() => { if (busy) return; busy = true; setTimeout(async () => { try { await decorate(); } finally { busy = false; } }, 60); }).observe(document.body, { childList: true, subtree: true });
  X.vendors = { manager };
})();
