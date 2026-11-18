/* Agents area: the Personal agent's own space (violet, "Only you"). Header, privacy note and tabs; each tab is its own file.
 * Routes: #/agents/personal/chat  #/agents/personal/pair[/code]
 * Everything here is end-to-end encrypted between this phone and the office laptop (pcrypto.js). The server cannot read it. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { $ } = AA;
  // timing.historyWaitMs: how long a newly paired browser waits for the laptop to send its earlier conversations before it says it could not get them
  AA.personal = { tabs: {}, order: [['chat', 'Chat'], ['brief', 'Brief'], ['scan', 'Scan'], ['progress', 'Progress'], ['profile', 'Profile']], timing: { historyWaitMs: 60000 } };

  AA.personal.privacy = () => AA.banner('priv', AA.icon.lock, '<b>Only you.</b> Everything here is scrambled between this browser and your office laptop. The office server cannot read it.');

  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  AA.personal.util = {
    /** "2026-11-13" as "Fri Nov 13" (read as a calendar day, never shifted by the time zone). */
    day(iso, withWeekday) { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); if (!m) return ''; const d = new Date(+m[1], +m[2] - 1, +m[3]); return (withWeekday ? DAYS[d.getDay()] + ' ' : '') + MONTHS[d.getMonth()] + ' ' + d.getDate(); },
    /** A line that says how fresh what is on screen is, because it comes from the laptop and the laptop may be off. */
    saved(snap) { return snap && snap.savedAt ? '<p class="muted small">Last updated from your laptop ' + AA.esc(AA.rel(new Date(snap.savedAt).toISOString().replace('T', ' ').slice(0, 19) + 'Z')) + '.</p>' : ''; },
    offline(status) { return status && status.has_token && !status.online ? AA.banner('warn', AA.icon.clock, 'Your personal agent needs the office laptop, which is off. What you change is saved here and sent when it starts.') : ''; },
  };

  AA.renderPersonal = async (v, rest) => {
    const tab = rest[0] || 'chat';
    if (tab === 'pair') return AA.personal.renderPair(v, rest[1]);
    if (!AA.personal.tabs[tab]) { location.hash = '#/agents/personal/chat'; return; }
    if (!AA.pcrypto.supported()) {
      v.innerHTML = AA.header('Personal agent', { back: '#/agents' }) + AA.empty('Your personal agent needs a secure connection (https). Open the office app from its normal https address.');
      return;
    }
    let key; try { key = await AA.pstore.key(); } catch (e) { v.innerHTML = AA.header('Personal agent', { back: '#/agents' }) + AA.empty(e.message); return; }
    if (!key) {
      if (await AA.pstore.pending()) return AA.personal.renderPending(v);          // a pairing code was used and the laptop has not answered yet
      if (await AA.pstore.removed()) return AA.personal.renderRemoved(v);          // the owner removed this browser on the laptop
      location.hash = '#/agents/personal/pair'; return;
    }
    v.innerHTML = AA.header('Personal agent', { back: '#/agents', right: AA.tag('priv', 'Only you') }) +
      (AA.personal.order.length > 1 ? AA.tabs(AA.personal.order.map(([k, l]) => [k, l, '#/agents/personal/' + k]), tab) : '') +
      AA.personal.privacy() + '<div id="agbody">' + AA.skeleton(2) + '</div>';
    AA.pstore.purge().catch(() => {});
    await AA.personal.tabs[tab]($('#agbody'));
  };
})();
