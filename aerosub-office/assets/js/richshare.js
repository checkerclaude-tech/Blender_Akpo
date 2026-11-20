/* Aerosub Office: rich messages when sharing a report.
 * X.rich.composer(el)  -> a formatted-text box (bold, italic, underline, lists, link, pictures inline, paste a picture),
 *                         attach documents, add links; .payload() gives what the share routes expect.
 * X.rich.messages(el, kind, id) -> shows the messages sent about a report, with pictures, files and links.
 * No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, toast, esc, S } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const fsize = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  const readB64 = f => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = () => no(new Error('Could not read ' + f.name)); r.readAsDataURL(f); });
  const picture = file => new Promise((ok, no) => {      // scale a picture down so it is quick to send and to open
    if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) return no(new Error('Pictures must be PNG, JPG, GIF or WEBP'));
    if (file.type === 'image/gif' && file.size < 1.5e6) { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.readAsDataURL(file); return; }
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = () => { const k = Math.min(1, 1400 / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); ok(c.toDataURL(file.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.86)); };
    img.onerror = () => { URL.revokeObjectURL(url); no(new Error('That picture could not be read')); }; img.src = url;
  });

  function composer(host, opts) {
    opts = opts || {}; const files = [], links = [];
    host.innerHTML = '<div class="rs-box"><div class="rs-bar" role="toolbar" aria-label="Formatting">' +
      '<button type="button" class="rs-b" data-c="bold" title="Bold" aria-label="Bold"><b>B</b></button><button type="button" class="rs-b" data-c="italic" title="Italic" aria-label="Italic"><i>I</i></button><button type="button" class="rs-b" data-c="underline" title="Underline" aria-label="Underline"><u>U</u></button>' +
      '<span class="rs-sep"></span><button type="button" class="rs-b" data-c="insertUnorderedList" title="Bullet list" aria-label="Bullet list">• List</button><button type="button" class="rs-b" data-c="insertOrderedList" title="Numbered list" aria-label="Numbered list">1. List</button><button type="button" class="rs-b" data-c="formatBlock" data-v="h4" title="Heading" aria-label="Heading">H</button>' +
      '<span class="rs-sep"></span><button type="button" class="rs-b" data-link title="Add a link" aria-label="Add a link">🔗</button><label class="rs-b" title="Insert a picture" style="margin:0;cursor:pointer">🖼 Picture<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden data-pic></label></div>' +
      '<div class="rs-ed" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message" data-ph="' + esc(opts.placeholder || 'Write your message. Format it, add pictures, attach documents…') + '"></div></div>' +
      '<div class="row" style="gap:8px;flex-wrap:wrap;margin:8px 0 4px"><label class="btn ghost sm" style="cursor:pointer;margin:0">📎 Attach documents<input type="file" multiple hidden data-doc accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.png,.jpg,.jpeg,.gif,.webp"></label>' +
      '<input data-lnk placeholder="Paste a link and press Add" style="flex:1;min-width:170px"><button class="btn ghost sm" type="button" data-addl>Add link</button></div><div data-list style="display:grid;gap:4px;margin-bottom:4px"></div><div class="err small" data-err></div>';
    const ed = $('.rs-ed', host), err = m => { $('[data-err]', host).textContent = m || ''; };
    const exec = (c, v) => { ed.focus(); document.execCommand(c, false, v || null); };
    $$('[data-c]', host).forEach(b => { b.onmousedown = e => e.preventDefault(); b.onclick = () => exec(b.dataset.c, b.dataset.v); });
    $('[data-link]', host).onmousedown = e => e.preventDefault();
    $('[data-link]', host).onclick = () => { const sel = window.getSelection(); if (!sel.rangeCount || sel.isCollapsed) return err('Select the words to turn into a link first.'); err(''); let u = prompt('Link address (https://…)'); if (!u) return; if (!/^(https?:\/\/|mailto:)/i.test(u)) u = 'https://' + u; exec('createLink', u); };
    const addPic = async f => { try { const url = await picture(f); ed.focus(); document.execCommand('insertHTML', false, '<img src="' + url + '" alt="' + esc(f.name.replace(/\.[^.]+$/, '').slice(0, 60)) + '" style="max-width:100%;height:auto"><br>'); err(''); } catch (e) { err(e.message); } };
    $('[data-pic]', host).onchange = async e => { for (const f of e.target.files) { if ($$('img', ed).length >= 10) { err('Up to 10 pictures in one message'); break; } await addPic(f); } e.target.value = ''; };
    ed.addEventListener('paste', async e => {      // a picture on the clipboard goes in as a picture; anything else as clean text
      const items = [...(e.clipboardData && e.clipboardData.items || [])], pic = items.find(i => i.kind === 'file' && /^image\//.test(i.type));
      if (pic) { e.preventDefault(); if ($$('img', ed).length < 10) await addPic(pic.getAsFile()); else err('Up to 10 pictures in one message'); return; }
      const html = e.clipboardData && e.clipboardData.getData('text/html'); if (!html) return; e.preventDefault();
      const t = e.clipboardData.getData('text/plain'); document.execCommand('insertText', false, t);
    });
    ed.addEventListener('drop', async e => { const f = [...(e.dataTransfer && e.dataTransfer.files || [])].find(x => /^image\//.test(x.type)); if (f) { e.preventDefault(); await addPic(f); } });
    const paint = () => { $('[data-list]', host).innerHTML = files.map((f, i) => '<div class="item" style="padding:6px 10px"><span style="margin-right:8px">📄</span><span class="grow">' + esc(f.name) + ' <span class="muted small">' + fsize(f.size) + '</span></span><button class="btn ghost sm" type="button" data-rf="' + i + '" aria-label="Remove ' + esc(f.name) + '">✕</button></div>').join('') +
      links.map((l, i) => '<div class="item" style="padding:6px 10px"><span style="margin-right:8px">🔗</span><span class="grow" style="overflow-wrap:anywhere">' + esc(l) + '</span><button class="btn ghost sm" type="button" data-rl="' + i + '" aria-label="Remove link">✕</button></div>').join('');
      $$('[data-rf]', host).forEach(b => b.onclick = () => { files.splice(+b.dataset.rf, 1); paint(); }); $$('[data-rl]', host).forEach(b => b.onclick = () => { links.splice(+b.dataset.rl, 1); paint(); }); };
    $('[data-doc]', host).onchange = async e => { for (const f of e.target.files) { try { if (files.length >= 8) throw new Error('You can attach up to 8 documents'); if (f.size > 8e6) throw new Error(f.name + ' is larger than 8 MB'); if (/\.(php|phtml|exe|bat|cmd|com|scr|js|vbs|sh|msi|jar|html?|svg)$/i.test(f.name)) throw new Error('That kind of file cannot be attached: ' + f.name);
        files.push({ name: f.name, type: f.type || 'application/octet-stream', size: f.size, data: await readB64(f) }); err(''); } catch (er) { err(er.message); } } e.target.value = ''; paint(); };
    const addLink = () => { const i = $('[data-lnk]', host), v = i.value.trim(); if (!v) return; if (links.length >= 10) return err('Up to 10 links'); links.push(/^https?:\/\//i.test(v) ? v : 'https://' + v); i.value = ''; err(''); paint(); };
    $('[data-addl]', host).onclick = addLink; $('[data-lnk]', host).onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); addLink(); } };
    return {
      focus: () => ed.focus(),
      payload() {      // pictures leave the text as separate uploads, so the message stays small
        addLink(); const clone = ed.cloneNode(true), images = [];
        $$('img', clone).forEach(im => { const m = /^data:(image\/[a-z]+);base64,(.+)$/.exec(im.getAttribute('src') || ''); if (!m || images.length >= 10) { im.remove(); return; } images.push({ type: m[1], data: m[2], name: (im.getAttribute('alt') || 'picture') + (m[1] === 'image/png' ? '.png' : m[1] === 'image/gif' ? '.gif' : '.jpg') }); ['src', 'style'].forEach(a => im.removeAttribute(a)); im.setAttribute('data-i', String(images.length - 1)); });
        const html = clone.innerHTML.trim(); return { html: /^(<br>|\s)*$/.test(html) ? '' : html, images, files: files.map(f => ({ name: f.name, type: f.type, data: f.data })), links: links.slice() };
      },
    };
  }

  const when = t => { const d = new Date(String(t).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  async function messages(host, kind, id) {
    host.innerHTML = ''; let r; try { r = await api('share/messages?kind=' + kind + '&id=' + id); } catch { return; } const list = (r && r.messages) || []; if (!list.length) return;
    host.innerHTML = '<h3 style="margin-top:16px">Messages</h3>' + list.map(m => '<div class="rs-msg" data-m="' + m.id + '"><div class="row" style="gap:8px"><b>' + esc(m.sender || '') + '</b><span class="muted small grow">' + esc(when(m.created_at)) + (m.to.length ? ' · to ' + esc(m.to.join(', ')) : '') + '</span>' + (+m.sender_id === +S.user.id || (S.user.role === 'super_admin') ? '<a href="#" class="small" data-delm="' + m.id + '" style="color:var(--bad)">remove</a>' : '') + '</div>' +
      '<div class="rs-body">' + m.html + '</div>' + (m.links.length ? '<div class="small" style="margin-top:6px">' + m.links.map(l => '<a href="' + esc(l) + '" target="_blank" rel="noopener noreferrer nofollow">🔗 ' + esc(l.replace(/^https?:\/\//, '').slice(0, 70)) + '</a>').join('<br>') + '</div>' : '') +
      (m.files.length ? '<div class="row" style="gap:6px;flex-wrap:wrap;margin-top:8px">' + m.files.map(f => '<a class="chip" href="api/share/file/' + f.id + '" download>📎 ' + esc(f.name) + ' <span class="muted small">' + fsize(f.size) + '</span></a>').join('') + '</div>' : '') + '</div>').join('');
    $$('.rs-body img', host).forEach(im => { im.style.cursor = 'zoom-in'; im.onclick = () => window.open(im.src, '_blank', 'noopener'); });
    $$('[data-delm]', host).forEach(a => a.onclick = async e => { e.preventDefault(); if (!confirm('Remove this message and its attachments?')) return; try { await api('share/messages/' + a.dataset.delm, { method: 'DELETE' }); messages(host, kind, id); } catch (er) { toast(er.message); } });
  }
  X.rich = { composer, messages };
})();
