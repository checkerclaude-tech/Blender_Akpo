/* Agents area: Research agent > Settings. Scoring weights (must total 100), public sources, how often it runs. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;

  AA.research.tabs.settings = async box => {
    let s;
    try { s = await AA.call('agent-owner/settings'); }
    catch (e) { if (e.step === 'code') return AA.go(); box.innerHTML = AA.errorBox('settings', 'agretry'); $('#agretry').onclick = () => AA.research.tabs.settings(box); return; }
    const weights = s.weights || {}, sources = (s.sources || []).slice(), schedules = s.schedules || {};
    const draw = () => {
      const total = () => Object.keys(weights).reduce((n, k) => n + (+weights[k] || 0), 0);
      box.innerHTML =
        '<section class="card ag-sec"><h2>How opportunities are scored</h2>' + (Object.keys(weights).length
          ? Object.keys(weights).map(k => '<div class="field"><label for="w_' + esc(k) + '">' + esc(AA.humanise(k)) + '</label><input id="w_' + esc(k) + '" data-w="' + esc(k) + '" type="number" min="0" max="100" inputmode="numeric" value="' + (+weights[k]) + '"></div>').join('') +
            '<p id="agsum" class="small" role="status"></p>'
          : '<p class="muted">The agent is using its built-in weights.</p>') + '</section>' +
        '<section class="card ag-sec"><h2>Public sources it reads</h2><div id="agsrc">' + (sources.length
          ? sources.map((x, i) => '<div class="ag-src"><b>' + esc(x.name) + '</b><div class="muted small ag-break">' + esc(x.url) + '</div><button class="btn ghost sm" data-rm="' + i + '">Remove</button></div>').join('')
          : '<p class="muted">No public sources set. Add one to start.</p>') + '</div>' +
        '<div class="ag-two"><div><label for="agsn">Name</label><input id="agsn" maxlength="120"></div><div><label for="agsu">Web address</label><input id="agsu" type="url" inputmode="url" placeholder="https://"></div></div>' +
        '<button class="btn ghost sm" id="agadd" type="button">Add source</button></section>' +
        (Object.keys(schedules).length ? '<section class="card ag-sec"><h2>How often it runs</h2>' + Object.keys(schedules).map(k =>
          '<div class="field"><label for="s_' + esc(k) + '">' + esc(AA.humanise(k)) + ' (every … minutes)</label><input id="s_' + esc(k) + '" data-s="' + esc(k) + '" type="number" min="5" max="10080" inputmode="numeric" value="' + (+schedules[k]) + '"></div>').join('') + '</section>' : '') +
        '<div id="agerr" class="ag-err" role="alert"></div><button class="btn block" id="agsave">Save settings</button>';
      const sum = () => { const el = $('#agsum'); if (!el) return; const t = total(); el.textContent = t === 100 ? 'Weights add up to 100.' : 'Weights add up to ' + t + '. Adjust them to total 100.'; el.className = 'small ' + (t === 100 ? 'ag-ok' : 'ag-bad'); };
      box.querySelectorAll('[data-w]').forEach(i => i.oninput = () => { weights[i.dataset.w] = i.value === '' ? 0 : +i.value; sum(); }); sum();
      box.querySelectorAll('[data-s]').forEach(i => i.oninput = () => { schedules[i.dataset.s] = +i.value; });
      box.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { sources.splice(+b.dataset.rm, 1); draw(); });
      $('#agadd').onclick = () => {
        const url = $('#agsu').value.trim(), name = $('#agsn').value.trim();
        if (!/^https?:\/\/[^\s]{4,}$/i.test(url)) { $('#agerr').textContent = 'Each source needs a web address starting with http:// or https://'; return; }
        sources.push({ name: name || url, url, kind: /rss|feed|\.xml$/i.test(url) ? 'rss' : 'site' }); draw();
      };
      $('#agsave').onclick = async () => {
        const btn = $('#agsave'), err = $('#agerr'); err.textContent = ''; btn.disabled = true; btn.textContent = 'Working…';
        const data = { sources }; if (Object.keys(weights).length) data.weights = weights; if (Object.keys(schedules).length) data.schedules = schedules;
        try { await AA.call('agent-owner/settings', { method: 'PUT', data }); AA.X.toast('Saved. Applies from the next run.'); }
        catch (e) { if (e.step === 'code') return AA.go(); err.textContent = e.message; }
        btn.disabled = false; btn.textContent = 'Save settings';
      };
    };
    draw();
  };
})();
