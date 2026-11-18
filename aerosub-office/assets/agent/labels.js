/* Agents area: every word people see for an internal value (design spec section 8). One place, so nothing leaks as snake_case. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;

  /** The eight verification labels. Only "Supported" uses the success colour; each has an icon and words. */
  AA.VERIFY = {
    supported: ['Supported', '✓', 'ok', 'We opened the source and found a passage that backs this claim.'],
    partly_supported: ['Partly supported', '◐', 'warn', 'The source backs most of it, but a number, date or name differs.'],
    not_supported: ['Not supported', '✕', 'bad', 'We opened the source and found nothing that backs this claim.'],
    cannot_check: ["Couldn't check", '⏸', 'info', "We couldn't open the source. This is not a pass."],
    cited_not_online: ['Cited, not online', '⌕', 'info', "The source is named, but we couldn't find it online."],
    source_suggested: ['Source suggested', '↗', 'info', 'The document gave no source. We found a possible one — please review it.'],
    no_source: ['No source', '–', '', 'No source is given and none was found.'],
    author_flagged: ['Author unsure', '⚑', 'warn', 'The author marked this as unconfirmed or conflicting.'],
  };
  const SHORT = { dead: 'link dead', blocked: 'site blocked us', paywalled: 'paywalled', login: 'login needed' };
  const LONG = { dead: 'the link is dead', blocked: 'the site blocked us', paywalled: 'it is behind a paywall', login: 'it needs a login' };
  AA.verifyChip = (label, reason) => {
    const l = AA.VERIFY[label] || [AA.humanise(label), '•', '', ''];
    const cc = label === 'cannot_check' && reason;
    return AA.chip(l[2], l[1], cc ? "Couldn't check — " + (SHORT[reason] || reason) : l[0], cc ? "We couldn't open the source: " + (LONG[reason] || reason) + '. This is not a pass.' : l[3]);
  };

  AA.TIER = { primary: 'Primary source', news: 'News', weak: 'Weak source (forum, job board, aggregator)' };
  AA.KIND = { signal: 'Opportunity', company: 'New company', contact: 'Contact', task: 'Task', research: 'Research note' };
  AA.SIGNAL = {
    restart_shutdown: 'Restart or shutdown', fid_first_oil: 'Investment decision or first oil', divestment_acquisition: 'Sale or acquisition',
    incident: 'Incident', tender: 'Tender or call for interest', regulatory: 'Regulatory change',
  };
  AA.DOC_STATUS = { queued: 'Waiting for agent', checking: 'Checking', ready: 'Ready', failed: "Couldn't check this file" };
  AA.DOC_TONE = { queued: 'warn', checking: 'info', ready: 'ok', failed: 'bad' };
  AA.CONTACT_CHECK = { verified: 'Verified', likely: 'Likely', unverified: 'Unverified' };
  AA.REJECT = [['wrong_service', 'Wrong service'], ['wrong_country', 'Wrong country'], ['too_late', 'Too late'], ['already_known', 'Already known'], ['bad_source', 'Bad source'], ['low_value', 'Low value']];
  AA.REJECT_LABEL = c => (AA.REJECT.find(r => r[0] === c) || [0, AA.humanise(c)])[1];
  AA.FIELD = { name: 'Name', sector: 'Sector', location: 'Location', website: 'Website', notes: 'Notes', position: 'Position', title: 'Title', body: 'Notes', description: 'Details', due_date: 'Due date', company_name: 'Company', email: 'Email', phone: 'Phone', linkedin: 'Profile link' };
  AA.HIDDEN = new Set(['sources', 'tasks', 'risk_flags', 'flags_cleared', '_assignments', 'email_evidence', 'phone_evidence', 'lawful_basis', 'retention_months', 'source_note', 'company_id']);

  AA.riskText = (flag, company) => {
    const c = company || 'This company';
    switch (flag.type) {
      case 'receivership': return c + ' is in receivership. Outreach is blocked until you clear this flag.';
      case 'litigation': return c + ' has an open dispute. Outreach is blocked until you clear this flag.';
      case 'common_control': return c + ' is under common control with ' + (flag.related_company || 'another company') + '. Outreach is blocked until you clear this flag.';
      default: return c + ': ' + (flag.note || AA.humanise(flag.type)) + '. Outreach is blocked until you clear this flag.';
    }
  };
})();
