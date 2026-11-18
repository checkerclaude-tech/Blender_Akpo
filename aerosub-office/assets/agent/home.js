/* Agents area: Agents home (mockup M1). One card per agent showing only what needs the owner. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;

  AA.renderHome = async v => {
    v.innerHTML = AA.header('Agents', { right: '<span id="agst"></span>' }) +
      '<p class="muted small">Each agent keeps its own information. Nothing is shared between them.</p><div id="aghome">' + AA.skeleton(2) + '</div>';
    let health, inbox, personal = null;
    try { [health, inbox] = await Promise.all([AA.call('agent-owner/health'), AA.call('agent-owner/items?tab=review')]); }
    catch (e) { if (e.step === 'code') throw e; $('#aghome').innerHTML = AA.errorBox('your agents', 'agretry'); $('#agretry').onclick = () => AA.renderHome(v); return; }
    try { personal = await AA.call('agent-owner/personal/status'); } catch {}      // counts only; never any content
    let paired = false, pairing = false; try { paired = !!(AA.pstore && await AA.pstore.key()); pairing = !paired && !!(AA.pstore && await AA.pstore.pending()); } catch {}
    $('#agst').innerHTML = AA.statusChip(health);
    AA.setBadge(inbox.counts.review);
    const waiting = health.documents_waiting;
    $('#aghome').innerHTML =
      '<a class="card ag-agent" href="#/agents/research/chat"><span class="ag-ico">' + AA.icon.search + '</span><span class="grow"><b>Research agent</b>' +
      '<span class="muted small ag-block">Checks research documents and watches for opportunities</span>' +
      '<span class="ag-row">' + (inbox.counts.review ? AA.tag('warn', inbox.counts.review + ' to review') : AA.tag('', 'Nothing to review')) +
      (waiting ? AA.tag('info', AA.plural(waiting, 'file') + ' checking') : '') + '</span></span></a>' +
      '<a class="card ag-agent ag-personal" href="#/agents/personal/' + (paired || pairing ? 'chat' : 'pair') + '"><span class="ag-ico">' + AA.icon.lock + '</span><span class="grow"><b>Personal agent</b>' +
      '<span class="muted small ag-block">Your career guidance</span><span class="ag-row">' + AA.tag('priv', 'Only you') +
      (pairing ? AA.tag('warn', 'Finishing pairing') : !paired ? AA.tag('warn', 'Pair your phone') : personal && personal.online ? AA.tag('ok', 'Laptop online') : AA.tag('', 'Laptop offline')) + '</span></span></a>';
  };
})();
