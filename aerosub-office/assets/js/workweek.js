/* Aerosub Office: the work week (Admin page, Super Admin only): which day the week starts on, which day it ends on, and the working days.
 * Reports and analytics use it for "this week", counting weeks, and expected report days. No inline event attributes (CSP). */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, toast, esc, S, views } = X;
  const $ = (s, r = document) => r.querySelector(s);
  const NAMES = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' }, ORDER = [1, 2, 3, 4, 5, 6, 7];
  const orig = views.admin;
  views.admin = async () => {
    await orig(); const v = $('#view'); if (!v || S.user.role !== 'super_admin') return;
    let W; try { W = await api('workweek'); } catch { return; }
    const opt = (d, sel) => '<option value="' + d + '"' + (+d === +sel ? ' selected' : '') + '>' + NAMES[d] + '</option>';
    const html = '<div class="card" id="wwcard" style="margin:0 0 14px"><h2 style="margin-bottom:4px">Work week</h2><div class="muted small" style="margin-bottom:10px">Sets when a week starts and ends and which days are working days. It decides the weeks in the weekly report, "this week", how many weeks are counted in analytics, and which days people are expected to report.</div>' +
      '<div class="grid2"><div class="field"><label>Week starts on</label><select id="wws">' + ORDER.map(d => opt(d, W.week_start)).join('') + '</select></div><div class="field"><label>Week ends on</label><select id="wwe">' + ORDER.map(d => opt(d, W.week_end)).join('') + '</select></div></div>' +
      '<div class="field"><label>Working days</label><div class="row" style="flex-wrap:wrap;gap:14px">' + ORDER.map(d => '<label class="small" style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-d="' + d + '" style="width:auto"' + (W.work_days.includes(d) ? ' checked' : '') + '>' + NAMES[d] + '</label>').join('') + '</div></div>' +
      '<div class="small" id="wwn" style="margin:4px 0 10px"></div><div class="row"><button class="btn" id="wwsv">Save work week</button></div><div class="muted small" style="margin-top:8px">Reports already saved keep the week they were written for; the new week applies to everything from now on.</div><div class="err" id="wwer"></div></div>';
    const top = $('.top', v); if (top) top.insertAdjacentHTML('afterend', html); else v.insertAdjacentHTML('afterbegin', html);
    const card = $('#wwcard'); const read = () => ({ week_start: +$('#wws').value, week_end: +$('#wwe').value, work_days: [...card.querySelectorAll('[data-d]:checked')].map(i => +i.dataset.d) });
    const note = () => { const r = read(), len = ((r.week_end - r.week_start + 7) % 7) + 1; $('#wwn').innerHTML = 'A week runs <b>' + NAMES[r.week_start] + ' to ' + NAMES[r.week_end] + '</b> (' + len + ' day' + (len === 1 ? '' : 's') + '). Working days: <b>' + (r.work_days.length ? r.work_days.map(d => NAMES[d].slice(0, 3)).join(', ') : 'none selected') + '</b>.'; };
    card.querySelectorAll('select,input').forEach(e => e.onchange = note); note();
    $('#wwsv').onclick = async e => { const r = read(); $('#wwer').textContent = ''; if (!r.work_days.length) { $('#wwer').textContent = 'Choose at least one working day.'; return; } e.target.disabled = true;
      try { await api('workweek', { method: 'PATCH', data: r }); toast('Work week saved'); } catch (er) { $('#wwer').textContent = er.message; } e.target.disabled = false; };
  };
})();
