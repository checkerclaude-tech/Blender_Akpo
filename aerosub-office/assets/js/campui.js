/* Aerosub Office: Campaigns > Start here. A guided overview: what needs attention now, the quickest next step, and the way the tabs fit together. */
(() => {
  'use strict';
  const X = window.OfficeExt; if (!X) return;
  const { api, esc } = X;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const safe = p => api(p).catch(() => null);

  X.campOverview = async () => {
    const box = $('#cpb'); box.innerHTML = '<div class="empty">Loading…</div>';
    const [pr, ia, camps, lists, mt, st] = await Promise.all([safe('prospecting/overview?status=new'), safe('inbox-auto/overview'), safe('campaigns/campaigns'), safe('campaigns/lists'), safe('mail-test/overview'), safe('campaigns/settings')]);
    const newSig = pr ? +(pr.counts || {}).new || 0 : 0, closing = pr ? pr.closing_soon || 0 : 0, awaiting = ia ? +(ia.counts || {}).awaiting || 0 : 0, newMail = ia ? +(ia.counts || {}).new || 0 : 0;
    const drafts = (camps || []).filter(c => c.status === 'draft').length, running = (camps || []).filter(c => ['sending', 'scheduled'].includes(c.status)).length, subs = (lists || []).reduce((a, l) => a + (+l.subscribed || 0), 0);
    const sendReady = !!(st && st.smtp_host && st.from_email), lastTest = mt && mt.tests && mt.tests.find(t => t.score !== null);
    const todo = [];
    if (!sendReady) todo.push(['settings', '⚙️', 'Set up your sending account', 'Emails cannot go out until this is done.', 'Set up', true]);
    if (pr && !pr.has_key) todo.push(['prospect', '🔎', 'Add your Exa key to start prospecting', 'Free key from exa.ai. It finds tenders, events and news.', 'Add key', false]);
    if (closing) todo.push(['prospect', '⏰', closing + ' tender' + (closing === 1 ? '' : 's') + ' closing within 2 weeks', 'Open Prospecting to see contacts and deadlines.', 'View', true]);
    if (awaiting) todo.push(['inbox', '✋', awaiting + ' repl' + (awaiting === 1 ? 'y' : 'ies') + ' waiting for your approval', 'From emails received in info@.', 'Review', true]);
    if (newSig) todo.push(['prospect', '🆕', newSig + ' new signal' + (newSig === 1 ? '' : 's') + ' to look at', 'Companies showing buying signals.', 'Look', false]);
    if (drafts) todo.push(['campaigns', '📝', drafts + ' draft campaign' + (drafts === 1 ? '' : 's'), 'Finish designing and send.', 'Open', false]);
    if (sendReady && !lastTest) todo.push(['mailtest', '✅', 'Test your emails before the next send', 'Get a score out of 10 so they do not land in spam.', 'Test', false]);
    const stat = (n, l, tab) => '<a href="#" class="card stat" data-go="' + tab + '" style="color:inherit"><span class="muted small">' + l + '</span><b>' + n + '</b></a>';
    box.innerHTML = '<div class="card"><h2 style="margin:0 0 4px">Welcome to Campaigns</h2><div class="muted small">Work from left to right: <b>find</b> people, <b>prepare</b> a list and an email, <b>test</b> it, then <b>send</b>. Replies and new business come back through Inbox automation.</div>' +
      '<div class="cards" style="margin-top:12px">' + stat(newSig, 'New prospecting signals', 'prospect') + stat(closing, 'Tenders closing soon', 'prospect') + stat(awaiting + newMail, 'Replies to handle', 'inbox') + stat(subs, 'People you can email', 'lists') + stat(drafts + running, 'Campaigns in progress', 'campaigns') + stat(lastTest ? lastTest.score.toFixed(1) + '/10' : '—', 'Last email test score', 'mailtest') + '</div></div>' +
      '<div class="card" style="margin-top:14px"><h3 style="margin:0 0 8px">Do next</h3>' + (todo.length ? todo.map(([tab, ic, t, d, b, hot]) => '<div class="row" style="padding:8px 0;border-top:1px solid var(--line);gap:10px"><span style="font-size:20px">' + ic + '</span><div class="grow"><b>' + esc(t) + '</b><div class="muted small">' + esc(d) + '</div></div><button class="btn ' + (hot ? '' : 'ghost') + ' sm" data-go="' + tab + '">' + b + '</button></div>').join('') : '<div class="muted small">Nothing urgent. Start with a prospecting search, or create a campaign.</div>') + '</div>' +
      '<div class="card" style="margin-top:14px"><h3 style="margin:0 0 8px">How it fits together</h3><div class="cards">' + [['prospect', '🔎', 'Prospecting', 'Search tenders, events, news and hires. Dig deeper for emails, phones and closing dates, then add as lead.'], ['inbox', '📥', 'Inbox automation', 'Scans info@, turns business emails into contacts, tasks and draft replies you approve.'], ['lists', '👥', 'Lists & contacts', 'Group people to email. Import a CSV, add from Pipeline and verify addresses.'], ['templates', '🎨', 'Templates', 'Reusable email designs.'], ['campaigns', '✉️', 'Campaigns', 'Design, schedule and send, then see opens and clicks.'], ['autos', '↩️', 'Auto-responders', 'Automatic replies and follow-ups.'], ['mailtest', '✅', 'Test your emails', 'A spam score and DNS check using mail-tester.com.']].map(([tab, ic, t, d]) => '<a href="#" data-go="' + tab + '" class="card" style="color:inherit;display:block"><div style="font-size:22px">' + ic + '</div><b>' + t + '</b><div class="muted small" style="margin-top:4px">' + d + '</div></a>').join('') + '</div></div>';
    $$('[data-go]', box).forEach(a => a.onclick = e => { e.preventDefault(); X.campGo(a.dataset.go); });
  };
})();
