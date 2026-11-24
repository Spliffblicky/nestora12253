/* AGENT: my profile + working weekdays, buildings I am approved for, my applications */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fail, confirmBox, busy, route, render, go, avatar, pill, stars, WEEKDAYS } = window.Core;

  route('/agent/me', async () => {
    const me = await api('GET', '/agent/me'); const av = await api('GET', '/agent/availability');
    const days = av.weekdays || [];
    const el = render(`<h1>My agent profile</h1><div class="card center">${avatar(me.agent, 'xl')}<h2>${esc(me.agent.name)}</h2>${stars(me.rating.user || me.rating)}
      <div class="small muted">${me.pending_viewings} viewing request(s) waiting</div></div>
      <div class="card"><h3>Days I work</h3><p class="muted small">Users can only request viewings on these days.</p>
        <div class="chips">${WEEKDAYS.map((n, i) => `<label class="chip"><input type="checkbox" value="${i}" ${days.includes(i) ? 'checked' : ''}> ${n}</label>`).join('')}</div>
        <button class="btn primary mt-s" id="sv">Save days</button></div>
      <div class="card"><h3>Reviews</h3><div id="revs"></div></div>`);
    $('#sv', el).onclick = () => busy($('#sv', el), async () => {
      const w = $$('.chips input:checked', el).map((c) => +c.value);
      try { await api('PUT', '/agent/availability', { weekdays: w }); toast('Saved'); }
      catch (e) { if (await confirmBox({ title: 'Change days?', html: `<p>${esc(e.message)}</p>`, confirmText: 'Save anyway' })) { await api('PUT', '/agent/availability', { weekdays: w, confirm_conflicts: true }).catch(fail); toast('Saved'); } }
    });
    window.Browse.reviewsBlock($('#revs', el), 'agent', me.agent.id);
  });

  route('/agent/buildings', async () => {
    const r = await api('GET', '/agent/buildings');
    render(`<h1>My buildings</h1>${r.buildings.map((b) => `<a class="card row link" href="#/building/${b.id}"><div class="grow"><b>${esc(b.name)}</b><div class="muted small">${esc([b.city, b.region].filter(Boolean).join(', '))}</div></div>${pill('approved', 'ok')}</a>`).join('') || '<div class="empty">You are not approved for any building yet. Browse buildings and apply.</div>'}<a class="btn" href="#/browse">Browse buildings</a>`);
  });

  route('/agent/applications', async () => {
    const r = await api('GET', '/agent/applications');
    render(`<h1>My applications</h1>${r.applications.map((a) => `<a class="card row link" href="#/building/${a.building_id}"><div class="grow"><b>${esc(a.building_name || a.name || 'Building')}</b></div>${pill(a.status, a.status === 'approved' ? 'ok' : a.status === 'declined' || a.status === 'revoked' ? 'bad' : '')}</a>`).join('') || '<div class="empty">No applications.</div>'}`);
  });
})();
