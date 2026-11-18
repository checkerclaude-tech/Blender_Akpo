/* Aerosub Office: theme skins (Christmas, Monday office, Cookie, Cyber Punk, Broken PC, Tetris).
 * A skin can be tried in the Games view, or accepted as the theme of the whole app (kept in this browser). The look itself lives in assets/css/skins.css. */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { esc, views, toast } = X;
  const SKINS = [
    { id: '', name: 'Aerosub', emoji: '🖥️', blurb: 'The standard look' },
    { id: 'xmas', name: 'Christmas', emoji: '🎄', blurb: 'Candy stripes, snow and festive green' },
    { id: 'monday', name: 'Monday office', emoji: '☕', blurb: 'Grey desks, sticky notes, coffee' },
    { id: 'cookie', name: 'Cookie', emoji: '🍪', blurb: 'Warm chocolate-chip browns' },
    { id: 'cyber', name: 'Cyber Punk', emoji: '🌆', blurb: 'Neon on midnight' },
    { id: 'brokenpc', name: 'Broken PC', emoji: '💥', blurb: 'Blue screen, glitches and a cracked display' },
    { id: 'tetris', name: 'Tetris', emoji: '🧱', blurb: 'Falling blocks on a dark grid' },
  ];
  const store = { get: () => { try { return localStorage.getItem('ac:skin') || ''; } catch { return ''; } }, set: v => { try { v ? localStorage.setItem('ac:skin', v) : localStorage.removeItem('ac:skin'); } catch {} } };
  const known = id => SKINS.some(s => s.id === id);
  const current = () => { const v = document.documentElement.dataset.skin || ''; return known(v) ? v : ''; };
  function applyApp(id) { if (!known(id)) id = ''; if (id) document.documentElement.dataset.skin = id; else delete document.documentElement.dataset.skin; store.set(id); }
  // restore the saved skin as early as possible
  const saved = store.get(); if (saved && known(saved)) document.documentElement.dataset.skin = saved;
  const swatch = id => '<span class="sk-sw sk-' + (id || 'default') + '" aria-hidden="true"></span>';
  const chips = (sel, attr) => SKINS.map(s => '<button type="button" class="sk-chip' + (s.id === sel ? ' on' : '') + '" ' + attr + '="' + s.id + '" title="' + esc(s.blurb) + '">' + swatch(s.id) + '<span>' + s.emoji + ' ' + esc(s.name) + '</span></button>').join('');

  // profile page: choose (or undo) the app theme
  const baseMe = views.me;
  if (baseMe) views.me = async () => {
    await baseMe(); const v = document.querySelector('#view'); if (!v) return;
    const card = document.createElement('div'); card.className = 'card'; card.style.marginTop = '18px';
    card.innerHTML = '<h3 style="margin-bottom:6px">App theme</h3><div class="muted small" style="margin-bottom:10px">Pick a look for the whole app on this device. You can also try themes in the Games tab.</div><div class="sk-chips" id="skc">' + chips(current(), 'data-sk') + '</div>';
    v.appendChild(card);
    card.querySelectorAll('[data-sk]').forEach(b => b.onclick = () => { applyApp(b.dataset.sk); card.querySelectorAll('.sk-chip').forEach(c => c.classList.toggle('on', c === b)); toast(b.dataset.sk ? 'Theme applied' : 'Back to the standard look'); });
  };
  window.OfficeSkins = { list: SKINS, current, apply: applyApp, chips };
})();
