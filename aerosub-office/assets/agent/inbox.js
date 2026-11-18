/* Agents area: Research agent > Inbox list (mockup M3). Opens an item in item.js. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $, esc } = AA;

  const SUB = [['review', 'To review'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['stale', 'Stale']];
  const EMPTY = {
    review: () => 'No new opportunities. The agent was last online ' + ((AA.research.health && AA.research.health.last_seen_at) ? AA.rel(AA.research.health.last_seen_at) : 'never') + '.',
    approved: () => 'Nothing approved yet.', rejected: () => 'Nothing rejected.', stale: () => 'Nothing waiting for more than 14 days.',
  };
  const RISK = { receivership: 'In receivership', litigation: 'Open dispute', common_control: 'Under common control' };

  const row = it => {
    const sub = [it.company_name, it.service].filter(Boolean).join(' · ') || AA.KIND[it.kind] || '';
    const tags = [];
    if (it.risk) tags.push(AA.chip('bad', '⚑', RISK[it.risk] || 'Risk flagged'));
    if (it.timing) tags.push(AA.tag('', 'Timing: ' + it.timing));
    if (it.status === 'approved') tags.push(it.sync_state === 'syncing' ? AA.chip('warn', '↻', 'Approved — syncing') : AA.chip('ok', '✓', 'Approved ' + AA.date(it.decided_at)));
    if (it.status === 'rejected') tags.push(AA.tag('', 'Rejected ' + AA.date(it.decided_at)));
    if (it.status === 'pending' && !it.risk && it.kind !== 'signal') tags.push(AA.tag('info', AA.KIND[it.kind] || ''));
    return '<a class="card ag-sig" href="#/agents/research/inbox/' + (+it.id) + '">' + AA.score(it.score) +
      '<span class="grow"><b>' + esc(it.headline) + '</b><span class="muted small ag-block">' + esc(sub) + '</span>' + (tags.length ? '<span class="ag-row">' + tags.join('') + '</span>' : '') + '</span></a>';
  };

  AA.research.inboxTab = AA.research.inboxTab || 'review';
  AA.research.tabs.inbox = async (box, id) => {
    if (id !== null) return AA.item(box, id);
    const draw = async () => {
      box.innerHTML = '<div class="ag-subtabs" role="tablist">' + SUB.map(([k, l]) => '<button class="chip' + (k === AA.research.inboxTab ? ' on' : '') + '" data-sub="' + k + '" role="tab" aria-selected="' + (k === AA.research.inboxTab) + '">' + l + '</button>').join('') + '</div><div id="aglist">' + AA.skeleton(3) + '</div>';
      box.querySelectorAll('[data-sub]').forEach(b => b.onclick = () => { AA.research.inboxTab = b.dataset.sub; draw(); });
      const list = $('#aglist');
      try {
        const r = await AA.call('agent-owner/items?tab=' + AA.research.inboxTab);
        if (AA.research.inboxTab === 'review') AA.setBadge(r.counts.review);
        list.innerHTML = r.items.length
          ? (AA.research.inboxTab === 'stale' ? '<p class="muted small">Not reviewed for 14 days. Still here if you need it.</p>' : '') + '<div class="ag-stack">' + r.items.map(row).join('') + '</div>'
          : AA.empty(EMPTY[AA.research.inboxTab]());
      } catch (e) { if (e.step === 'code') return AA.go(); list.innerHTML = AA.errorBox('the inbox', 'agretry'); $('#agretry').onclick = draw; }
    };
    await draw();
  };
})();
