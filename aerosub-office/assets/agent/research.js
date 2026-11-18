/* Agents area: the Research agent's own space. Header, status and tabs; each tab is its own file.
 * Routes: #/agents/research/chat  #/agents/research/inbox[/id]  #/agents/research/files[/id]  #/agents/research/settings
 * The space opens on Chat. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $ } = AA;
  AA.research = { tabs: {} };

  AA.renderResearch = async (v, rest) => {
    const tab = rest[0] || 'chat', id = rest[1] && /^\d+$/.test(rest[1]) ? +rest[1] : null;
    if (!AA.research.tabs[tab]) { location.hash = '#/agents/research/chat'; return; }
    const detail = id !== null;
    v.innerHTML = AA.header(detail ? ({ inbox: 'Opportunity', files: 'Document' }[tab] || 'Details') : 'Research agent', { back: detail ? '#/agents/research/' + tab : '#/agents', right: '<span id="agst"></span>' }) +
      (detail ? '' : '<div id="agtabs"></div>') + '<div id="agbody">' + AA.skeleton(3) + '</div>';
    AA.call('agent-owner/health').then(h => { const el = $('#agst'); if (el) el.innerHTML = AA.statusChip(h); AA.research.health = h; }).catch(() => {});
    if (!detail) {
      let review = 0; try { review = (await AA.call('agent-owner/items?tab=review')).counts.review; AA.setBadge(review); } catch {}
      const el = $('#agtabs'); if (el) el.innerHTML = AA.tabs([['chat', 'Chat', '#/agents/research/chat'], ['inbox', 'Inbox', '#/agents/research/inbox', review], ['files', 'Files', '#/agents/research/files'], ['settings', 'Settings', '#/agents/research/settings']], tab);
    }
    await AA.research.tabs[tab]($('#agbody'), id);
  };
})();
