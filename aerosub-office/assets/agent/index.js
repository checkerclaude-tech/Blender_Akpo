/* Agents area: entry point. Registers the #/agents view and the menu hooks. Loaded last.
 * Only the owner ever sees the Agents tab. Anyone else gets the normal app, unchanged.
 * There is deliberately no background polling of agent routes: every agent request counts as use and would keep the
 * 15-minute idle timeout from ever expiring. The count on the tab is refreshed whenever an agent screen loads. */
(() => {
  'use strict';
  const AA = window.AA; if (!AA) return;
  const { X, $ } = AA;

  AA.icon.agentsNav = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 3v4M9 13h.01M15 13h.01"/></svg>';

  /* ---------- who is this? (asked once per signed-in person) ---------- */
  let askedFor = 0;
  const ensureOwnerKnown = () => {
    const u = X.S.user; if (!u || u.role !== 'super_admin') { AA.state.owner = false; return; }
    if (askedFor === u.id) return;
    askedFor = u.id; AA.state.owner = undefined;
    AA.call('agent-auth/status').then(s => { AA.state.owner = !!s.owner; AA.state.codeRequired = !!s.code_required; AA.state.enrolled = !!s.enrolled; AA.state.unlocked = !!s.unlocked; if (AA.state.owner) X.render(); }).catch(() => { AA.state.owner = false; });
  };

  /* ---------- menu hooks (see CHANGES.md: hooks 4 to 6 in app.js) ---------- */
  const prevNav = X.nav;
  X.nav = (items, icons) => {
    if (prevNav) prevNav(items, icons);
    ensureOwnerKnown();
    if (!AA.state.owner) return;
    const at = items.findIndex(x => x[0] === 'pipeline');
    items.splice(at < 0 ? items.length : at + 1, 0, ['agents', 'Agents', AA.icon.agentsNav]);
  };
  X.tabs = tabs => { if (!AA.state.owner) return; const i = tabs.findIndex(t => t && t[0] === 'mail'); if (i >= 0) tabs[i] = ['agents', 'Agents', AA.icon.agentsNav]; };
  X.more = items => { if (AA.state.owner) items.unshift(['mail', 'Mail', null]); };
  const baseSetBadge = AA.setBadge;
  AA.setBadge = n => { X.S.badges = Object.assign({}, X.S.badges, { agents: n || 0 }); baseSetBadge(n); };

  /* ---------- the view ---------- */
  X.views.agents = async () => {
    const v = $('#view'); v.innerHTML = AA.skeleton(3);
    await AA.refreshStatus();
    if (!AA.state.owner) return AA.renderPrivate(v);
    // The authenticator step is optional (server setting agent_require_code, off by default). When it is off none of this appears.
    if (AA.state.codeRequired && !AA.state.enrolled) return AA.renderSetup(v);
    if (AA.state.codeRequired && !AA.state.unlocked) return AA.renderCode(v);
    const parts = location.hash.split('/'); // ['#', 'agents', 'research', 'inbox', '12']
    try {
      if (!parts[2]) return await AA.renderHome(v);
      if (parts[2] === 'research') return await AA.renderResearch(v, parts.slice(3));
      if (parts[2] === 'personal') return await AA.renderPersonal(v, parts.slice(3));
      location.hash = '#/agents';
    } catch (e) {
      if (!v.isConnected) return;       // the person already moved to another screen: a failure of the one they left must not be painted over the one they are on now
      if (AA.state.codeRequired && (e.step === 'code' || e.status === 401)) { AA.state.unlocked = false; return AA.renderCode(v); }
      v.innerHTML = AA.errorBox('this screen', 'agretry'); $('#agretry').onclick = () => X.views.agents();
    }
  };

  // On a fast load the office app can draw its first screen before this file has registered the Agents view (and its menu hooks).
  // If the address is already an Agents one (a reload, or the pairing link from the QR code), draw it again now that the view exists.
  if (location.hash.startsWith('#/agents') && X.S.user && X.render) setTimeout(() => X.render(), 0);
})();
