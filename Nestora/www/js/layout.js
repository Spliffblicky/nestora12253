/* =====================================================================
   LAYOUT
   Under 768px : profile photo + name (top left) -> profile, three-dot dropdown (top right), pinned footer tabs with "More".
   768px+      : three-dash button (top left) opens a side nav that is hidden by default and holds sections + profile + dropdown items.
   Same sections, same order in both. Badges show unread counts. Pinned bars sit above the content on every tab.
   ===================================================================== */
(function () {
  'use strict';
  const { $, $$, esc, icon, api, go, confirmBox, sheet, toast, cfg, fmtLeft, level } = window.Core;
  const mq = window.matchMedia('(min-width: ' + cfg.WIDE_BREAKPOINT + 'px)');
  window.Hooks = { open: [] };            // modules add functions that run each time the app opens

  /* ---------- sections per role (same list feeds the footer and the side nav) ---------- */
  function sections(u) {
    const c = S.counts || {};
    const tenant = !!u.is_tenant;
    if (u.role === 'owner') return [
      { key: 'buildings', label: 'Buildings', icon: 'home', path: '/owner/buildings', foot: true },
      { key: 'agents', label: 'Agents', icon: 'users', path: '/owner/agents', foot: true, badge: c.applications },
      { key: 'tenants', label: 'Tenants', icon: 'key', path: '/owner/tenants', foot: true, badge: c.payments_to_verify },
      { key: 'chats', label: 'Chats', icon: 'chat', path: '/chats', foot: true, badge: c.chats },
      { key: 'treports', label: 'Problem reports', icon: 'flag', path: '/owner/reports', badge: c.tenant_reports },
      { key: 'payout', label: 'Payout details', icon: 'money', path: '/owner/payout' },
      { key: 'alerts', label: 'Alerts', icon: 'bell', path: '/alerts', badge: c.notifications },
    ];
    if (u.role === 'agent') return [
      { key: 'browse', label: 'Browse', icon: 'search', path: '/browse', foot: true },
      { key: 'mybuildings', label: 'My buildings', icon: 'home', path: '/agent/buildings', foot: true },
      { key: 'viewings', label: 'Viewings', icon: 'cal', path: '/agent/viewings', foot: true, badge: c.viewing_requests },
      { key: 'chats', label: 'Chats', icon: 'chat', path: '/chats', foot: true, badge: c.chats },
      { key: 'mypage', label: 'My page', icon: 'user', path: '/agent/me' },
      { key: 'apps', label: 'Applications', icon: 'doc', path: '/agent/applications', badge: c.surveys },
      { key: 'alerts', label: 'Alerts', icon: 'bell', path: '/alerts', badge: c.notifications },
    ];
    if (u.role === 'admin') {
      const seeded = u.admin_tier === 'root' || u.admin_tier === 'seeded';
      const a = [{ key: 'overview', label: 'Overview', icon: 'home', path: '/admin', foot: true }];
      if (seeded) a.push({ key: 'payments', label: 'Payments', icon: 'money', path: '/admin/payments', foot: true, badge: c.payments });
      a.push({ key: 'reports', label: 'Reports', icon: 'flag', path: '/admin/reports', foot: true, badge: c.reports });
      a.push({ key: 'accounts', label: 'Accounts', icon: 'users', path: '/admin/accounts', foot: !seeded });
      if (seeded) a.push({ key: 'disputes', label: 'Disputes', icon: 'shield', path: '/admin/disputes', badge: c.disputes });
      a.push({ key: 'tr', label: 'Tenant reports', icon: 'list', path: '/admin/tenant-reports', badge: c.tenant_reports });
      a.push({ key: 'appeals', label: 'Appeals & surveys', icon: 'doc', path: '/admin/appeals', badge: c.appeals });
      a.push({ key: 'admins', label: 'Admins', icon: 'shield', path: '/admin/admins' });
      if (seeded) a.push({ key: 'asettings', label: 'Settings', icon: 'gear', path: '/admin/settings' });
      a.push({ key: 'audit', label: 'Audit log', icon: 'list', path: '/admin/audit' });
      a.push({ key: 'alerts', label: 'Alerts', icon: 'bell', path: '/alerts', badge: c.notifications });
      return a;
    }
    return [
      { key: 'browse', label: 'Browse', icon: 'search', path: '/browse', foot: true },
      { key: 'viewings', label: 'Viewings', icon: 'cal', path: '/viewings', foot: true, badge: c.viewing_prompts },
      { key: 'chats', label: 'Chats', icon: 'chat', path: '/chats', foot: true, badge: c.chats },
      tenant ? { key: 'tenancy', label: 'Tenancy', icon: 'key', path: '/tenancy', foot: true } : { key: 'rent', label: 'Rent', icon: 'key', path: '/rent', foot: true },
      tenant ? { key: 'rent', label: 'Rent a room', icon: 'key', path: '/rent' } : null,
      { key: 'alerts', label: 'Alerts', icon: 'bell', path: '/alerts', badge: c.notifications },
    ].filter(Boolean);
  }

  const accountItems = [
    { key: 'profile', label: 'Profile', icon: 'user', act: () => go('/profile') },
    { key: 'settings', label: 'Settings', icon: 'gear', act: () => go('/settings') },
    { key: 'terms', label: 'Terms and Conditions', icon: 'doc', act: () => window.Common.showTerms() },
    { key: 'privacy', label: 'Privacy Policy', icon: 'shield', act: () => window.Common.showTerms('privacy') },
    { key: 'help', label: 'Help and support', icon: 'chat', act: () => go('/help') },
    { key: 'logout', label: 'Log out', icon: 'logout', danger: true, act: logout },
  ];

  async function logout() {
    if (!(await confirmBox({ title: 'Log out?', message: 'You will need to sign in again.', confirmText: 'Log out', danger: true }))) return;
    try { await api('POST', '/auth/logout', {}, { keepSession: true }); } catch (e) {}
    window.Core.setToken(null); location.hash = ''; location.reload();
  }

  /* ---------- drawing ---------- */
  const isWide = () => mq.matches;
  const activeKey = () => {
    const p = location.hash.replace(/^#/, '').split('?')[0] || '/';
    const list = sections(S.user).slice().sort((a, b) => b.path.length - a.path.length);
    const hit = list.find((s) => (s.path === '/' ? p === '/' : p === s.path || p.indexOf(s.path + '/') === 0));
    return hit ? hit.key : null;
  };
  const badgeHtml = (n) => (n ? `<i class="badge">${n > 99 ? '99+' : n}</i>` : '');

  function drawChip() {
    $('#chipPhoto').src = S.user.photo_url || ''; $('#chipName').textContent = S.user.name;
    $('#profileChip').onclick = () => go('/profile');
  }
  function drawFooter() {
    const list = sections(S.user), foot = list.filter((s) => s.foot).slice(0, 4), rest = list.filter((s) => foot.indexOf(s) < 0);
    const key = activeKey(), moreCount = rest.reduce((n, s) => n + (s.badge || 0), 0);
    $('#footer').innerHTML = foot.map((s) => `<button data-p="${s.path}" class="${s.key === key ? 'on' : ''}">${icon(s.icon)}<span>${esc(s.label)}</span>${badgeHtml(s.badge)}</button>`).join('') +
      `<button id="fMore" class="${foot.every((s) => s.key !== key) && key ? 'on' : ''}">${icon('more')}<span>More</span>${badgeHtml(moreCount)}</button>`;
    $$('#footer button[data-p]').forEach((b) => (b.onclick = () => go(b.dataset.p)));
    $('#fMore').onclick = () => {
      const s = sheet(`<h3>More</h3><div class="col gap-s">${rest.map((x) => `<button class="sn-item" data-p="${x.path}">${icon(x.icon)}<span>${esc(x.label)}</span>${badgeHtml(x.badge)}</button>`).join('')}
        <hr style="border:0;border-top:1px solid var(--line);width:100%">${accountItems.map((a) => `<button class="sn-item ${a.danger ? 'danger-item' : ''}" data-a="${a.key}">${icon(a.icon)}<span>${esc(a.label)}</span></button>`).join('')}</div>`);
      $$('[data-p]', s.el).forEach((b) => (b.onclick = () => { s.close(); go(b.dataset.p); }));
      $$('[data-a]', s.el).forEach((b) => (b.onclick = () => { s.close(); accountItems.find((a) => a.key === b.dataset.a).act(); }));
    };
  }
  function drawDots() {
    $('#dotsMenu').innerHTML = accountItems.map((a, i) => (a.danger ? '<hr>' : '') + `<button data-a="${a.key}" role="menuitem" class="${a.danger ? 'danger-item' : ''}">${esc(a.label)}</button>`).join('');
    $$('#dotsMenu button').forEach((b) => (b.onclick = () => { $('#dotsMenu').classList.add('hidden'); accountItems.find((a) => a.key === b.dataset.a).act(); }));
  }
  function drawSide() {
    const list = sections(S.user), key = activeKey();
    $('#sidenav').innerHTML = `<div class="sn-head" id="snHead"><img src="${esc(S.user.photo_url || '')}" alt=""><div><div class="nm">${esc(S.user.name)}</div><div class="rl">${esc(S.user.role === 'admin' ? (S.user.admin_tier + ' admin') : S.user.role)}${S.user.is_tenant ? ' · tenant' : ''}</div></div></div>
      <div class="sn-group"><h5>Sections</h5>${list.map((s) => `<button class="sn-item ${s.key === key ? 'on' : ''}" data-p="${s.path}">${icon(s.icon)}<span>${esc(s.label)}</span>${badgeHtml(s.badge)}</button>`).join('')}</div>
      <div class="sn-group"><h5>Account</h5>${accountItems.map((a) => `<button class="sn-item ${a.danger ? 'danger-item' : ''}" data-a="${a.key}">${icon(a.icon)}<span>${esc(a.label)}</span></button>`).join('')}</div>
      <label class="sn-pin"><input type="checkbox" id="snPin" ${document.body.classList.contains('nav-pinned') ? 'checked' : ''}> Keep this menu open</label>`;
    $('#snHead').onclick = () => { closeNav(); go('/profile'); };
    $$('#sidenav [data-p]').forEach((b) => (b.onclick = () => { closeNav(); go(b.dataset.p); }));
    $$('#sidenav [data-a]').forEach((b) => (b.onclick = () => { closeNav(); accountItems.find((a) => a.key === b.dataset.a).act(); }));
    $('#snPin').onchange = (e) => { document.body.classList.toggle('nav-pinned', e.target.checked && isWide()); try { localStorage.setItem('navPinned', e.target.checked ? '1' : ''); } catch (x) {} if (e.target.checked) closeNav(true); };
  }
  function openNav() { document.body.classList.add('nav-open'); $('#scrim').classList.remove('hidden'); }
  function closeNav(keepPinned) { document.body.classList.remove('nav-open'); $('#scrim').classList.add('hidden'); }

  function drawAll() {
    if (!S.user) return;
    drawChip(); drawFooter(); drawDots(); drawSide();
    const total = sections(S.user).reduce((n, s) => n + (s.badge || 0), 0);
    $('#menuDot').classList.toggle('hidden', !total);        // dot on the three-dash button when something is unread
    const s = sections(S.user).find((x) => x.key === activeKey());
    $('#pageTitle').textContent = s ? s.label : window.Core.cfg.APP_NAME;
  }

  /* ---------- pinned bars ---------- */
  const extra = {};          // bars added by other modules (e.g. renewal reminder)
  let barData = [], barAt = 0;
  function drawBars() {
    const parts = [];
    const age = (Date.now() - barAt) / 1000;
    barData.forEach((b) => {
      const left = Math.max(0, (b.seconds_left || 0) - age);
      if (b.type === 'viewing_timer') parts.push(`<div class="pbar ${level(left)}"><span>⏳ Pick a new viewing date: <b>${fmtLeft(left)}</b> left</span><button class="btn sm primary" data-p="/viewing/${b.viewing_id}">Pick date</button></div>`);
      else if (b.type === 'process_countdown') parts.push(`<div class="pbar ${level(left)}"><span>🏠 Finish renting: upload your payment proof in <b>${fmtLeft(left)}</b></span><button class="btn sm primary" data-p="/rent/process/${b.process_id}">Continue</button></div>`);
      else if (b.type === 'verify_payment') parts.push(`<div class="pbar amber"><span>💳 ${b.count} payment${b.count > 1 ? 's' : ''} waiting for you to confirm</span><button class="btn sm primary" data-p="/owner/tenants">Review</button></div>`);
    });
    Object.keys(extra).forEach((k) => parts.push(extra[k]));
    const host = $('#pinned'); const html = parts.join('');
    if (host.dataset.sig !== html) { host.innerHTML = html; host.dataset.sig = html; $$('#pinned [data-p]').forEach((b) => (b.onclick = () => go(b.dataset.p))); $$('#pinned [data-x]').forEach((b) => (b.onclick = () => extraActions[b.dataset.x] && extraActions[b.dataset.x]())); }
  }
  const extraActions = {};
  function setPinned(key, html, action) { if (html) extra[key] = html; else delete extra[key]; if (action) extraActions[key] = action; drawBars(); }

  async function refreshCounts() {
    if (!S.user) return;
    try {
      const r = await api('GET', '/notifications/counts', null, { keepSession: true });
      S.counts = r.counts || {}; barData = r.pinned || []; barAt = Date.now();
      if (S.counts.has_tenancy !== undefined && S.counts.has_tenancy !== !!S.user.is_tenant) { S.user.is_tenant = !!S.counts.has_tenancy; }
      drawAll(); drawBars();
    } catch (e) { if (e.status === 401) return; }
  }

  /* ---------- mount ---------- */
  function mount() {
    $('#auth').classList.add('hidden'); $('#shell').classList.remove('hidden');
    try { if (localStorage.getItem('navPinned') && isWide()) document.body.classList.add('nav-pinned'); } catch (e) {}
    drawAll(); refreshCounts();
    window.Core.every('counts', cfg.POLL_SECONDS * 1000, refreshCounts);
    window.Core.every('bars', 1000, drawBars);
  }
  $('#btnMenu').onclick = () => (document.body.classList.contains('nav-open') ? closeNav() : openNav());
  $('#scrim').onclick = () => closeNav();
  $('#btnDots').onclick = (e) => { e.stopPropagation(); $('#dotsMenu').classList.toggle('hidden'); };
  document.addEventListener('click', (e) => { if (!e.target.closest('#dotsMenu') && !e.target.closest('#btnDots')) $('#dotsMenu').classList.add('hidden'); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeNav(); $('#dotsMenu').classList.add('hidden'); } });
  window.addEventListener('routechange', () => { if (S.user) { drawAll(); closeNav(); refreshCounts(); } });
  // crossing the breakpoint (rotation, window resize) switches layouts without losing the place
  mq.addEventListener ? mq.addEventListener('change', onBreak) : mq.addListener(onBreak);
  function onBreak() { document.body.classList.remove('nav-open'); $('#scrim').classList.add('hidden'); if (!isWide()) document.body.classList.remove('nav-pinned'); else { try { if (localStorage.getItem('navPinned')) document.body.classList.add('nav-pinned'); } catch (e) {} } if (S.user) drawAll(); }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && S.user) { refreshCounts(); window.Hooks.open.forEach((f) => f()); } });

  window.Layout = { mount, drawAll, refreshCounts, setPinned, sections };
})();
