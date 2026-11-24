/* =====================================================================
   BROWSE: search, building page with slideshow, room page, reviews, viewing request,
   agent and owner public profiles. Used by users and agents (agents also apply per building).
   ===================================================================== */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fail, sheet, confirmBox, busy, val, route, render, go, avatar, pill, stars, ghs, fmtDate, fmtDateTime } = window.Core;
  const R = window.Common;
  let lookups = null;
  const getLookups = async () => lookups || (lookups = await api('GET', '/lookups'));

  /* ---------- slideshow (swipe, arrows, dots, caption) ---------- */
  function slideshow(slides) {
    if (!slides.length) return '<div class="slides empty">No photos yet</div>';
    const id = 'ss' + Math.random().toString(36).slice(2, 7);
    setTimeout(() => {
      const root = document.getElementById(id); if (!root) return; let i = 0, x0 = null;
      const img = $('img', root), cap = $('.cap', root), dots = $$('.dot', root);
      const show = (n) => { i = (n + slides.length) % slides.length; img.src = slides[i].url; cap.textContent = slides[i].caption || ''; dots.forEach((d, k) => d.classList.toggle('on', k === i)); };
      $('.prev', root).onclick = () => show(i - 1); $('.next', root).onclick = () => show(i + 1);
      root.addEventListener('touchstart', (e) => (x0 = e.touches[0].clientX), { passive: true });
      root.addEventListener('touchend', (e) => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1)); x0 = null; });
      root.tabIndex = 0; root.onkeydown = (e) => { if (e.key === 'ArrowLeft') show(i - 1); if (e.key === 'ArrowRight') show(i + 1); };
      show(0);
    }, 0);
    return `<div class="slides" id="${id}"><img alt=""><button class="prev" aria-label="Previous">‹</button><button class="next" aria-label="Next">›</button><div class="cap"></div><div class="dots">${slides.map(() => '<i class="dot"></i>').join('')}</div></div>`;
  }

  /* ---------- amenities ---------- */
  function amenityList(list, custom) {
    const rows = (list || []).map((a) => `<li>${a.answer === 'yes' || a.answer === true ? '✓' : '✗'} ${esc(a.label || a.name)}${a.note ? ` <span class="muted small">(${esc(a.note)})</span>` : ''}${a.detail ? ` <span class="muted small">${esc(a.detail)}</span>` : ''}</li>`);
    (custom || []).forEach((a) => rows.push(`<li>${a.answer === 'yes' ? '✓' : '✗'} ${esc(a.label)}${a.note ? ` <span class="muted small">(${esc(a.note)})</span>` : ''}</li>`));
    return rows.length ? `<ul class="amen">${rows.join('')}</ul>` : '<p class="muted">No details given.</p>';
  }

  /* ---------- search ---------- */
  route('/browse', async (p, q) => {
    const L = await getLookups();
    const st = { category: q.category || 'name', q: q.q || '', region_id: q.region_id || '', city_id: q.city_id || '', page: 1 };
    const el = render(`<h1>Find a place</h1>
      <div class="card"><div class="row wrap"><select id="sc" class="input w-auto"><option value="name">Building</option><option value="location">Location</option><option value="room_type">Room type</option><option value="owner">Owner</option><option value="agent">Agent</option></select><input class="input grow" id="sq" placeholder="Search" value="${esc(st.q)}"><button class="btn primary" id="sg">Search</button></div>
      <div class="row wrap mt-s"><select id="sr" class="input w-auto"><option value="">All regions</option>${L.regions.map((r) => `<option value="${r.id}">${esc(r.name)}</option>`).join('')}</select>
      <select id="scity" class="input w-auto"><option value="">All cities</option></select>
      <input class="input w-auto" id="smin" inputmode="numeric" placeholder="Min rent"><input class="input w-auto" id="smax" inputmode="numeric" placeholder="Max rent"></div>
      <details class="mt-s"><summary>Amenities</summary><div class="chips" id="sam">${L.building_amenities.concat(L.room_amenities).map((a) => `<label class="chip"><input type="checkbox" value="${esc(a.code)}"> ${esc(a.label)}</label>`).join('')}</div></details></div>
      <div id="res"></div><div class="center"><button class="btn ghost hidden" id="more">Load more</button></div>`);
    $('#sc', el).value = st.category; $('#sr', el).value = st.region_id;
    const fillCities = () => { const rid = $('#sr', el).value; $('#scity', el).innerHTML = '<option value="">All cities</option>' + L.cities.filter((c) => !rid || String(c.region_id) === rid).map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join(''); $('#scity', el).value = st.city_id; };
    fillCities(); $('#sr', el).onchange = () => { st.city_id = ''; fillCities(); };
    const run = async (append) => {
      if (!append) { st.page = 1; $('#res', el).innerHTML = ''; }
      const r = await api('GET', '/search', { category: $('#sc', el).value, q: val('sq', el), region_id: $('#sr', el).value, city_id: $('#scity', el).value, min_rent: val('smin', el), max_rent: val('smax', el), amenities: $$('#sam input:checked', el).map((c) => c.value), page: st.page }).catch(fail);
      if (!r) return;
      if (!append && !r.buildings.length) $('#res', el).innerHTML = '<div class="empty">No buildings match. Try fewer filters.</div>';
      r.buildings.forEach((b) => { $('#res', el).insertAdjacentHTML('beforeend', `<a class="card bcard" href="#/building/${b.id}"><img class="cover" src="${esc(b.cover_url || '')}" alt="" onerror="this.style.visibility='hidden'"><div><b>${esc(b.name)}</b><div class="muted">${esc([b.area, b.city, b.region].filter(Boolean).join(', '))}</div><div>${b.min_rent != null ? ghs(b.min_rent) + (b.max_rent !== b.min_rent ? ' – ' + ghs(b.max_rent) : '') + ' / month' : ''}</div><div class="small">${b.rooms_available} room(s) available · ${stars(b.rating.building || b.rating)}</div></div></a>`); });
      $('#more', el).classList.toggle('hidden', !r.has_more);
    };
    $('#sg', el).onclick = () => run(false); $('#sq', el).onkeydown = (e) => { if (e.key === 'Enter') run(false); };
    $('#more', el).onclick = () => { st.page++; run(true); };
    run(false);
  });

  /* ---------- building ---------- */
  route('/building/:id', async (p) => {
    const d = await api('GET', '/buildings/' + p.id); const b = d.building; const me = S.user;
    const el = render(`<div class="row"><a class="btn sm ghost" href="#/browse">Back</a><span class="spacer"></span><button class="btn sm ghost" id="rep">Report</button></div>
      ${slideshow(d.slides)}
      <h1>${esc(b.name)}</h1><div class="muted">${esc([b.area, b.city, b.region].filter(Boolean).join(', '))}${b.landmark ? ' · near ' + esc(b.landmark) : ''}</div>
      <div class="row wrap mt-s">${stars(d.rating.building || d.rating)}${b.directions_url ? `<a class="btn sm" target="_blank" rel="noopener" href="${esc(b.directions_url)}">Get directions</a>` : ''}<a class="btn sm ghost" href="#/owner-profile/${d.owner.id}">Owner: ${esc(d.owner.name)}</a></div>
      <p>${esc(b.description || '')}</p>
      <div class="card"><h3>Amenities</h3>${amenityList(d.amenities, d.custom_amenities)}</div>
      ${b.termination_policy && b.termination_policy.label ? `<div class="card"><h3>Early termination</h3><p>${esc(b.termination_policy.label)}</p></div>` : ''}
      <div class="card"><h3>Rooms</h3><div id="rooms">${d.rooms.map((r) => `<a class="card row link" href="#/room/${r.id}"><div class="grow"><b>${esc(r.label)}</b> ${r.room_type ? '· ' + esc(r.room_type) : ''}<div class="muted small">${r.durations.join(', ')} months</div></div><div><b>${ghs(r.monthly_rent)}</b>/mo<br>${pill(r.status, r.status === 'available' ? 'ok' : '')}</div></a>`).join('') || '<div class="empty">No rooms listed.</div>'}</div></div>
      <div class="card"><h3>Approved agents</h3>${d.agents.length ? d.agents.map((a) => `<a class="row link" href="#/agent-profile/${a.id}">${avatar(a)}<div><b>${esc(a.name)}</b><div class="small">${stars(a.rating)}</div></div></a>`).join('') : '<p class="muted">No approved agents yet.</p>'}</div>
      <div class="row wrap" id="cta"></div>
      <h2>Reviews</h2><div id="revs"></div>`);
    $('#rep', el).onclick = () => R.report('building', +p.id);
    const cta = $('#cta', el);
    if (me.role === 'user' && d.agents.length) cta.innerHTML = '<button class="btn primary" id="reqV">Request a viewing</button>';
    if (me.role === 'agent') {
      const a = d.my_application;
      cta.innerHTML = a ? `<div>${pill('Application ' + a.status, a.status === 'approved' ? 'ok' : '')}</div>` : '<button class="btn primary" id="apply">Apply as agent for this building</button>';
    }
    if ($('#reqV', el)) $('#reqV', el).onclick = () => viewingRequest(b.id, null);
    if ($('#apply', el)) $('#apply', el).onclick = async () => { if (!(await confirmBox({ title: 'Apply as agent?', html: `<p>The owner of <b>${esc(b.name)}</b> will review your application.</p>`, confirmText: 'Apply' }))) return; await api('POST', '/agent/applications', { building_id: b.id }).catch(fail); toast('Application sent'); go('/building/' + b.id); };
    reviewsBlock($('#revs', el), 'building', b.id);
  });

  /* ---------- room ---------- */
  route('/room/:id', async (p) => {
    const d = await api('GET', '/rooms/' + p.id); const r = d.room;
    const el = render(`<a class="btn sm ghost" href="#/building/${r.building_id}">Back to building</a>
      ${slideshow(r.photos.map((u) => ({ url: u, caption: r.label })))}
      <h1>${esc(r.label)}</h1><div>${r.room_type ? esc(r.room_type) + ' · ' : ''}<b>${ghs(r.monthly_rent)}</b> / month · ${stars(r.rating.room || r.rating)}</div>
      <div class="muted">Rent for: ${r.durations.join(', ')} months</div>
      <div class="card"><h3>Amenities</h3>${amenityList(r.amenities, r.custom_amenities)}</div>
      ${S.user.role === 'user' && r.status === 'available' ? '<button class="btn primary" id="reqV">Request a viewing</button>' : ''}
      <h2>Reviews</h2><div id="revs"></div>`);
    if ($('#reqV', el)) $('#reqV', el).onclick = () => viewingRequest(r.building_id, r.id);
    reviewsBlock($('#revs', el), 'room', r.id);
  });

  /* ---------- request a viewing ---------- */
  async function viewingRequest(buildingId, roomId) {
    const r = await api('GET', '/viewings/agents', { building_id: buildingId }).catch(fail); if (!r) return;
    if (r.none_available) return toast('No approved agents are available for this building yet.');
    const names = window.Core.WEEKDAYS; let agent = null;
    const s = sheet(`<h3>Request a viewing</h3><div class="field"><label>Agent</label><div class="col gap-s" id="vag">${r.agents.map((a) => `<label class="card row"><input type="radio" name="ag" value="${a.id}"> ${avatar(a)}<div><b>${esc(a.name)}</b><div class="small muted">Works: ${(a.weekdays || []).map((d) => names[d]).join(', ') || 'not set'}</div></div></label>`).join('')}</div></div>
      <div class="field"><label for="vd">Date</label><input class="input" id="vd" type="date" min="${new Date().toISOString().slice(0, 10)}"></div>
      <div class="field"><label for="vs">Time</label><select id="vs"><option value="morning">Morning</option><option value="afternoon">Afternoon</option><option value="evening">Evening</option></select></div>
      <div class="err" id="vE"></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Send request</button></div>`);
    $('#x', s.el).onclick = () => s.close();
    $('#g', s.el).onclick = () => { const sel = $('input[name=ag]:checked', s.el); if (!sel) return ($('#vE', s.el).textContent = 'Choose an agent.');
      busy($('#g', s.el), async () => { try { const x = await api('POST', '/viewings', { building_id: buildingId, room_id: roomId, agent_id: +sel.value, date: val('vd', s.el), time_slot: $('#vs', s.el).value }); s.close(); toast('Request sent'); go('/viewing/' + x.viewing.id); } catch (e) { $('#vE', s.el).textContent = e.message; } }); };
  }

  /* ---------- reviews (user and agent reviews shown separately for buildings) ---------- */
  async function reviewsBlock(host, type, id) {
    const draw = async () => {
      const r = await api('GET', '/reviews', { target_type: type, target_id: id }).catch(() => null); if (!r) return;
      const one = (rv) => `<div class="card"><div class="row">${avatar(rv.author)}<div class="grow"><b>${esc(rv.author.name)}</b> ${rv.reviewer_role === 'agent' ? pill('Agent', 'info') : ''}<div class="small muted">${fmtDate(rv.created_at)}${rv.edited ? ' · edited' : ''}</div></div>${rv.reviewer_role === 'agent' && type === 'building' ? '' : `<span class="stars">${'★'.repeat(rv.rating)}${'☆'.repeat(5 - rv.rating)}</span>`}</div>
        <p>${esc(rv.comment || '')}</p>${rv.comments.map((c) => `<div class="small reply">↳ ${esc(c.body)}${c.after_reapproval ? ' <i>(after re-approval)</i>' : ''}</div>`).join('')}
        <div class="row wrap">${rv.mine && !rv.locked ? `<button class="btn sm" data-e="${rv.id}" data-r="${rv.rating}" data-c="${esc(rv.comment || '')}">Edit</button>` : ''}${rv.mine ? `<button class="btn sm ghost" data-c2="${rv.id}">Add comment</button>` : ''}${!rv.mine ? `<button class="btn sm ghost" data-rp="${rv.id}">Report</button>` : ''}</div></div>`;
      host.innerHTML = `<div class="row"><span>${stars(r.summary.user || r.summary)}</span><span class="spacer"></span>${r.can_review ? '<button class="btn sm primary" id="wr">Write a review</button>' : r.my_review_id ? '' : `<span class="muted small">${esc(r.cannot_reason || '')}</span>`}</div>${r.reviews.map(one).join('') || '<div class="empty">No reviews yet.</div>'}`;
      if ($('#wr', host)) $('#wr', host).onclick = () => form(null);
      $$('[data-e]', host).forEach((b) => (b.onclick = () => form({ id: b.dataset.e, rating: +b.dataset.r, comment: b.dataset.c })));
      $$('[data-c2]', host).forEach((b) => (b.onclick = () => comment(b.dataset.c2)));
      $$('[data-rp]', host).forEach((b) => (b.onclick = () => R.report('review', +b.dataset.rp)));
    };
    const form = (ex) => {
      let rating = ex ? ex.rating : 0;
      const s = sheet(`<h3>${ex ? 'Edit' : 'Write'} review</h3><div class="stars-in" id="st">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-n="${n}" aria-label="${n} stars">☆</button>`).join('')}</div><div class="field"><label for="rc">Comment</label><textarea id="rc" maxlength="1000">${esc(ex ? ex.comment : '')}</textarea></div><div class="err" id="rE"></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Save</button></div>`);
      const paint = () => $$('#st button', s.el).forEach((b) => (b.textContent = +b.dataset.n <= rating ? '★' : '☆')); paint();
      $$('#st button', s.el).forEach((b) => (b.onclick = () => { rating = +b.dataset.n; paint(); }));
      $('#x', s.el).onclick = () => s.close();
      $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { if (ex) await api('PUT', '/reviews/' + ex.id, { rating, comment: val('rc', s.el) }); else await api('POST', '/reviews', { target_type: type, target_id: id, rating, comment: val('rc', s.el) }); s.close(); draw(); } catch (e) { $('#rE', s.el).textContent = e.message; } });
    };
    const comment = (rid) => { const s = sheet(`<h3>Add comment</h3><div class="field"><textarea id="cm" maxlength="500"></textarea></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Post</button></div>`);
      $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { await api('POST', `/reviews/${rid}/comments`, { body: val('cm', s.el) }); s.close(); draw(); } catch (e) { toast(e.message); } }); };
    draw();
  }

  /* ---------- public profiles ---------- */
  route('/agent-profile/:id', async (p) => {
    const d = await api('GET', '/agents/' + p.id); const a = d.agent; const names = window.Core.WEEKDAYS;
    const el = render(`<a class="btn sm ghost" href="javascript:history.back()">Back</a><div class="card center">${avatar(a, 'xl')}<h2>${esc(a.name)}</h2><div>${stars(d.rating.user || d.rating)}</div><div class="muted">Works: ${d.weekdays.map((x) => names[x]).join(', ') || 'not set'}</div></div>
      <div class="card"><h3>Approved buildings</h3>${d.buildings.map((b) => `<a class="row link" href="#/building/${b.id}">${esc(b.name)}</a>`).join('') || '<p class="muted">None</p>'}</div><h2>Reviews</h2><div id="revs"></div>`);
    reviewsBlock($('#revs', el), 'agent', a.id);
  });
  route('/owner-profile/:id', async (p) => {
    const d = await api('GET', '/owners/' + p.id);
    render(`<a class="btn sm ghost" href="javascript:history.back()">Back</a><div class="card center">${avatar(d.owner, 'xl')}<h2>${esc(d.owner.name)}</h2></div><div class="card"><h3>Buildings</h3>${d.buildings.map((b) => `<a class="row link" href="#/building/${b.id}">${esc(b.name)}</a>`).join('') || '<p class="muted">None</p>'}</div>`);
  });

  window.Browse = { slideshow, amenityList, getLookups, reviewsBlock };
})();
