/* Agents area: Personal agent > "This browser was removed". Shown instead of the chat when the owner removed this browser on the laptop.
 * By the time this appears the stored chat, key and tag are already gone (pstore.markRemoved); this screen only says so and offers to pair again. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA || !AA.personal) return;

  AA.personal.renderRemoved = v => {
    v.innerHTML = AA.header('Personal agent', { back: '#/agents' }) +
      '<div id="agremoved">' +
      AA.empty('This browser was removed from your Personal agent. Your chat is no longer kept here. To use it again, pair it from your laptop.') +
      '<div class="ag-actions"><a class="btn" id="agpairagain" href="#/agents/personal/pair">Pair again</a></div></div>';
  };
})();
