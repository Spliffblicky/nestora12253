/* =====================================================================
   OWNER: buildings, rooms, photos, amenities, agreements, publish, agents, tenants + payment checks, payout
   ===================================================================== */
(function () {
  'use strict';
  const { $, $$, esc, api, fd, compressImage, toast, fail, sheet, confirmBox, busy, val, route, render, go, avatar, pill, ghs, fmtDate, fmtLeft, openPrivate } = window.Core;
  const R = window.Common, B = () => window.Browse;

  /* ---------- fee acknowledgement gate: owners see the fee before uploading anything ---------- */
  async function feeGate() {
    const st = await api('GET', '/owner/status');
    if (st.fee_acknowledged) return st;
    const ok = await confirmBox({ title: 'Platform fee', html: `<p>Payments made through the app carry a <b>${st.fee_percent}%</b> fee, paid by the <b>${esc(st.fee_payer)}</b>. Please confirm you understand before listing buildings.</p>`, confirmText: 'I understand', cancelText: 'Not now' });
    if (!ok) { go('/owner/payout'); throw { message: 'Please acknowledge the platform fee to continue.' }; }
    await api('POST', '/owner/fee-ack', { confirm: true }); st.fee_acknowledged = true; return st;
  }

  /* ---------- buildings list ---------- */
  route('/owner/buildings', async () => {
    await feeGate(); const r = await api('GET', '/owner/buildings');
    const el = render(`<div class="row"><h1>My buildings</h1><span class="spacer"></span><button class="btn primary" id="add">Add building</button></div>
      ${(r.buildings || []).map((b) => `<a class="card row link" href="#/owner/building/${b.id}"><div class="grow"><b>${esc(b.name)}</b><div class="muted small">${esc([b.city_name, b.region_name].filter(Boolean).join(', '))} · ${b.rooms_count != null ? b.rooms_count + ' rooms' : ''}</div></div>${pill(b.status, b.status === 'live' ? 'ok' : b.status === 'banned' ? 'bad' : '')}</a>`).join('') || '<div class="empty">No buildings yet.</div>'}`);
    $('#add', el).onclick = () => go('/owner/building/new');
  });

  /* ---------- create / edit building details ---------- */
  async function detailsForm(host, b, L, onSaved) {
    const regionOf = (cid) => { const c = L.cities.find((x) => x.id === +cid); return c ? c.region_id : ''; };
    const rid = b ? regionOf(b.city_id) : '';
    host.innerHTML = `<div class="card"><h3>${b ? 'Details' : 'New building'}</h3>
      <div class="field"><label for="bn">Name</label><input class="input" id="bn" maxlength="120" value="${esc(b ? b.name : '')}"></div>
      <div class="field"><label for="br">Region</label><select id="br"><option value="">Choose</option>${L.regions.map((r) => `<option value="${r.id}" ${r.id === rid ? 'selected' : ''}>${esc(r.name)}</option>`).join('')}</select></div>
      <div class="field"><label for="bc">City</label><select id="bc"></select></div>
      <div class="field"><label for="ba">Area / neighbourhood</label><select id="ba"><option value="">Choose city first</option></select></div>
      <div class="field hidden" id="aow"><label for="bo">Area name</label><input class="input" id="bo" maxlength="80" value="${esc(b ? b.area_other || '' : '')}"><div class="hint">Admins review new areas.</div></div>
      <div class="field"><label for="bg">Ghana Post GPS address</label><input class="input" id="bg" maxlength="20" placeholder="GA-123-4567" value="${esc(b ? b.gps_address || '' : '')}"></div>
      <div class="field"><label for="bl">Landmark</label><input class="input" id="bl" maxlength="150" value="${esc(b ? b.landmark || '' : '')}"></div>
      <div class="field"><label for="bd">Description</label><textarea id="bd" maxlength="3000">${esc(b ? b.description || '' : '')}</textarea></div>
      <div class="field"><label for="bp">Early termination policy</label><select id="bp"><option value="">Choose</option>${L.termination_policies.map((p) => `<option value="${p.id}">${esc(p.label)}</option>`).join('')}</select></div>
      <div class="field"><label for="bs">Agreements</label><select id="bs"><option value="general">One general agreement</option><option value="by_floor">One per floor</option><option value="by_room_type">One per room type</option></select></div>
      <div class="field"><label for="bf">Number of floors</label><input class="input" id="bf" inputmode="numeric" value="${esc(b && b.floors_count ? b.floors_count : '')}"></div>
      <div class="err" id="bE"></div><button class="btn primary block" id="bSave">Save</button></div>`;
    const cities = async () => { const r = $('#br', host).value; $('#bc', host).innerHTML = '<option value="">Choose</option>' + L.cities.filter((c) => String(c.region_id) === r).map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join(''); if (b && b.city_id) $('#bc', host).value = b.city_id; await areas(); };
    const areas = async () => { const c = $('#bc', host).value; if (!c) return; const a = (await api('GET', '/areas', { city_id: c })).areas; $('#ba', host).innerHTML = '<option value="">Choose</option>' + a.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('') + '<option value="other">Other (not listed)</option>'; if (b && b.area_id) $('#ba', host).value = b.area_id; else if (b && b.area_other) $('#ba', host).value = 'other'; $('#aow', host).classList.toggle('hidden', $('#ba', host).value !== 'other'); };
    $('#br', host).onchange = cities; $('#bc', host).onchange = areas; $('#ba', host).onchange = () => $('#aow', host).classList.toggle('hidden', $('#ba', host).value !== 'other');
    if (b) { $('#bp', host).value = b.termination_policy_id || ''; $('#bs', host).value = b.agreement_scope || 'general'; }
    await cities();
    $('#bSave', host).onclick = () => busy($('#bSave', host), async () => {
      const a = $('#ba', host).value; const body = { name: val('bn', host), city_id: +$('#bc', host).value || null, area_id: a && a !== 'other' ? +a : null, area_other: a === 'other' ? val('bo', host) : null, gps_address: val('bg', host), landmark: val('bl', host), description: val('bd', host), termination_policy_id: +$('#bp', host).value || null, agreement_scope: $('#bs', host).value, floors_count: +val('bf', host) || null };
      try { const r = b ? await api('PUT', '/owner/buildings/' + b.id, body) : await api('POST', '/owner/buildings', body); toast('Saved'); onSaved(r); } catch (e) { $('#bE', host).textContent = e.message; }
    });
  }

  route('/owner/building/new', async () => { await feeGate(); const L = await B().getLookups(); const el = render('<a class="btn sm ghost" href="#/owner/buildings">Back</a><div id="f"></div>'); await detailsForm($('#f', el), null, L, (r) => go('/owner/building/' + (r.building ? r.building.id : r.id))); });

  /* ---------- building editor ---------- */
  route('/owner/building/:id', async (p) => {
    const L = await B().getLookups(); const d = await api('GET', '/owner/buildings/' + p.id); const b = d.building; const id = +p.id;
    const el = render(`<a class="btn sm ghost" href="#/owner/buildings">Back</a><div class="row"><h1>${esc(b.name)}</h1><span class="spacer"></span>${pill(b.status, b.status === 'live' ? 'ok' : '')}</div>
      ${d.missing && d.missing.length ? `<div class="banner"><b>Before you can publish:</b><ul>${d.missing.map((m) => `<li>${esc(m.message || m)}</li>`).join('')}</ul></div>` : ''}
      <div class="tabs" id="tb"><button data-t="d" class="on">Details</button><button data-t="p">Photos</button><button data-t="a">Amenities</button><button data-t="g">Agreements</button><button data-t="r">Rooms</button></div>
      <div id="tab"></div><div class="row wrap mt"><button class="btn primary" id="pub">Publish building</button><button class="btn danger ghost" id="del">Delete</button></div>`);
    const tab = $('#tab', el); const reload = () => window.Core.navigate();
    const draw = {
      d: () => detailsForm(tab, b, L, reload),
      p: () => photoBlock(tab, d.photos, L.limits.building_photos, `/owner/buildings/${id}/photos`, reload),
      a: () => amenityForm(tab, L.building_amenities, d.amenities, d.custom, `/owner/buildings/${id}/amenities`, reload),
      g: () => agreements(tab, b, d, id, reload),
      r: () => rooms(tab, d, id, L, reload),
    };
    let cur = sessionStorage.getItem('otab') || 'd'; const sel = (t) => { cur = t; try { sessionStorage.setItem('otab', t); } catch (e) {} $$('#tb button', el).forEach((x) => x.classList.toggle('on', x.dataset.t === t)); draw[t](); };
    $$('#tb button', el).forEach((x) => (x.onclick = () => sel(x.dataset.t))); sel(cur);
    $('#pub', el).onclick = async () => { if (!(await confirmBox({ title: 'Publish this building?', html: '<p>It becomes visible to everyone.</p>', confirmText: 'Publish' }))) return; try { const r = await api('POST', `/owner/buildings/${id}/publish`, {}); toast(r.unpublished && r.unpublished.length ? 'Published. Some rooms were incomplete.' : 'Published'); reload(); } catch (e) { fail(e); } };
    $('#del', el).onclick = async () => { const ok = await confirmBox({ title: 'Delete building?', danger: true, html: '<p>This cannot be undone.</p>', typeToConfirm: b.name, confirmText: 'Delete' }); if (!ok) return; try { await api('DELETE', '/owner/buildings/' + id, { confirm_name: b.name }); go('/owner/buildings'); } catch (e) { fail(e); } };
  });

  /* ---------- photos with limits ---------- */
  function photoBlock(host, photos, max, base, reload) {
    host.innerHTML = `<div class="card"><h3>Photos (${photos.length}/${max})</h3><div class="photogrid">${photos.map((ph) => `<div class="ph"><img src="${esc(ph.url)}" alt=""><button class="x" data-d="${ph.id}" aria-label="Remove">×</button></div>`).join('')}</div>
      ${photos.length < max ? `<label class="btn" for="pf">Add photos</label><input class="sr" id="pf" type="file" accept="image/*" multiple>` : '<p class="muted">Maximum reached.</p>'}</div>`;
    if ($('#pf', host)) $('#pf', host).onchange = async (e) => { const room = max - photos.length; const f = fd({}); for (const file of [...e.target.files].slice(0, room)) f.append('photos[]', await compressImage(file, 1600), 'p.jpg'); try { await api('POST', base, f); reload(); } catch (er) { fail(er); } };
    $$('[data-d]', host).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Remove photo?', danger: true, confirmText: 'Remove' })) { await api('DELETE', `${base}/${b.dataset.d}`).catch(fail); reload(); } }));
  }

  /* ---------- amenities: every question must be answered ---------- */
  function amenityForm(host, defs, answers, custom, url, reload) {
    const byId = {}; (answers || []).forEach((a) => (byId[a.amenity_id || a.id] = a));
    host.innerHTML = `<div class="card"><h3>Amenities</h3><p class="muted small">Answer every item. Notes are limited to 10 words.</p>${defs.map((d) => {
      const a = byId[d.id] || {};
      return `<div class="field am" data-id="${d.id}" data-kind="${d.kind}"><label>${esc(d.label)}</label>${d.kind === 'yes_no'
        ? `<div class="row"><label class="check"><input type="radio" name="a${d.id}" value="yes" ${a.answer === 'yes' ? 'checked' : ''}> Yes</label><label class="check"><input type="radio" name="a${d.id}" value="no" ${a.answer === 'no' ? 'checked' : ''}> No</label></div>`
        : `<select class="ch"><option value="">Choose</option>${d.options.map((o) => `<option value="${esc(o.value)}" data-p="${o.needs_payer}" ${a.choice_value === o.value ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select><select class="pay hidden"><option value="">Who pays?</option>${['tenant', 'owner', 'split', 'included_in_rent'].map((p) => `<option value="${p}" ${a.payer === p ? 'selected' : ''}>${p.replace(/_/g, ' ')}</option>`).join('')}</select>`}
        <input class="input note" placeholder="Short note (max 10 words)" value="${esc(a.note || '')}"></div>`;
    }).join('')}
      <h4>Your own extras (up to 5)</h4><div id="cx">${(custom || []).map((c) => cx(c)).join('')}</div><button class="btn sm" id="addcx">Add extra</button>
      <div class="err" id="aE"></div><button class="btn primary block mt" id="aSave">Save amenities</button></div>`;
    function cx(c) { c = c || {}; return `<div class="row mb cxr"><input class="input" placeholder="Name" value="${esc(c.label || '')}"><select><option value="yes" ${c.answer !== 'no' ? 'selected' : ''}>Yes</option><option value="no" ${c.answer === 'no' ? 'selected' : ''}>No</option></select><input class="input" placeholder="Note" value="${esc(c.note || '')}"><button class="btn sm ghost" type="button">×</button></div>`; }
    const wire = () => $$('.cxr .btn', host).forEach((b) => (b.onclick = () => b.parentNode.remove()));
    const payVis = () => $$('.am[data-kind=choice]', host).forEach((f) => { const o = $('.ch', f).selectedOptions[0]; $('.pay', f).classList.toggle('hidden', !(o && o.dataset.p === '1')); });
    $$('.ch', host).forEach((s) => (s.onchange = payVis)); payVis(); wire();
    $('#addcx', host).onclick = () => { if ($$('.cxr', host).length >= 5) return toast('Maximum 5 extras'); $('#cx', host).insertAdjacentHTML('beforeend', cx()); wire(); };
    $('#aSave', host).onclick = () => busy($('#aSave', host), async () => {
      const ans = $$('.am', host).map((f) => { const o = { amenity_id: +f.dataset.id, note: $('.note', f).value.trim() }; if (f.dataset.kind === 'yes_no') { const c = $('input:checked', f); o.answer = c ? c.value : null; } else { o.choice_value = $('.ch', f).value || null; const pv = $('.pay', f); if (!pv.classList.contains('hidden')) o.payer = pv.value || null; } return o; });
      const cus = $$('.cxr', host).map((r) => { const i = $$('input', r), s = $('select', r); return { label: i[0].value.trim(), answer: s.value, note: i[1].value.trim() }; }).filter((c) => c.label);
      try { await api('PUT', url, { answers: ans, custom: cus }); toast('Saved'); reload(); } catch (e) { $('#aE', host).textContent = e.message; }
    });
  }

  /* ---------- agreements ---------- */
  function agreements(host, b, d, id, reload) {
    const scope = b.agreement_scope || 'general';
    host.innerHTML = `<div class="card"><h3>Tenancy agreements (PDF)</h3><p class="muted small">Scope: ${esc(scope.replace(/_/g, ' '))}. Each agreement needs a clear witness clause.</p>
      ${d.agreements.map((a) => `<div class="row mb"><div class="grow">${esc(a.scope)}${a.floor_number != null ? ' · floor ' + a.floor_number : ''}${a.room_type_id ? ' · ' + esc((d.room_types.find((t) => t.id === a.room_type_id) || {}).name || '') : ''}<div class="small muted">v${esc(a.version)} · ${fmtDate(a.uploaded_at)}</div></div><button class="btn sm" data-o="${a.id}">Open</button></div>`).join('') || '<p class="muted">None uploaded.</p>'}
      <hr><h4>Upload new</h4>
      <div class="field"><select id="as"><option value="general">General</option><option value="floor">For a floor</option><option value="room_type">For a room type</option></select></div>
      <div class="field hidden" id="afw"><input class="input" id="af" inputmode="numeric" placeholder="Floor number"></div>
      <div class="field hidden" id="atw"><select id="at">${d.room_types.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></div>
      <input class="input" type="file" id="apdf" accept="application/pdf"><div class="err" id="gE"></div><button class="btn primary mt-s" id="gUp">Upload</button>
      <hr><h4>Room types</h4><div class="row wrap">${d.room_types.map((t) => pill(t.name)).join('')}<button class="btn sm" id="addrt">Add type</button></div></div>`;
    $('#as', host).onchange = (e) => { $('#afw', host).classList.toggle('hidden', e.target.value !== 'floor'); $('#atw', host).classList.toggle('hidden', e.target.value !== 'room_type'); };
    $$('[data-o]', host).forEach((x) => (x.onclick = () => openPrivate(`/owner/agreements/${x.dataset.o}/file`).catch(fail)));
    $('#addrt', host).onclick = () => { const s = sheet('<h3>New room type</h3><input class="input" id="rtn" maxlength="80" placeholder="e.g. Chamber and hall"><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Add</button></div>'); $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = async () => { try { await api('POST', `/owner/buildings/${id}/room-types`, { name: val('rtn', s.el) }); s.close(); reload(); } catch (e) { toast(e.message); } }; };
    $('#gUp', host).onclick = () => busy($('#gUp', host), async () => { const f = $('#apdf', host).files[0]; if (!f) return ($('#gE', host).textContent = 'Choose a PDF.'); const sc = $('#as', host).value; const body = { scope: sc }; if (sc === 'floor') body.floor_number = +val('af', host); if (sc === 'room_type') body.room_type_id = +$('#at', host).value; const x = fd(body); x.append('pdf', f); try { await api('POST', `/owner/buildings/${id}/agreements`, x); toast('Uploaded'); reload(); } catch (e) { $('#gE', host).textContent = e.message; } });
  }

  /* ---------- rooms ---------- */
  function rooms(host, d, id, L, reload) {
    host.innerHTML = `<div class="row"><h3>Rooms</h3><span class="spacer"></span><button class="btn sm primary" id="nr">Add room</button></div>${d.rooms.map((r) => `<div class="card"><div class="row"><b>${esc(r.label)}</b><span class="spacer"></span>${pill(r.status, r.status === 'available' ? 'ok' : '')}</div><div>${r.monthly_rent != null ? ghs(r.monthly_rent) : 'No rent'} · ${(r.durations || []).join(', ') || 'no durations'} months</div>${r.missing && r.missing.length ? `<div class="small err">Missing: ${r.missing.map((m) => esc(m.message || m)).join('; ')}</div>` : ''}<div class="row wrap mt-s"><button class="btn sm" data-e="${r.id}">Edit</button>${r.status === 'draft' || r.status === 'hidden' ? `<button class="btn sm primary" data-pb="${r.id}">Publish</button>` : r.status === 'available' ? `<button class="btn sm" data-up="${r.id}">Hide</button>` : ''}<button class="btn sm danger ghost" data-rm="${r.id}">Delete</button></div></div>`).join('') || '<div class="empty">No rooms yet.</div>'}`;
    $('#nr', host).onclick = () => roomEditor(null, d, id, L, reload);
    $$('[data-e]', host).forEach((b) => (b.onclick = () => roomEditor(d.rooms.find((r) => r.id === +b.dataset.e), d, id, L, reload)));
    $$('[data-pb]', host).forEach((b) => (b.onclick = async () => { try { await api('POST', `/owner/rooms/${b.dataset.pb}/publish`, {}); reload(); } catch (e) { fail(e); } }));
    $$('[data-up]', host).forEach((b) => (b.onclick = async () => { await api('POST', `/owner/rooms/${b.dataset.up}/unpublish`, {}).catch(fail); reload(); }));
    $$('[data-rm]', host).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Delete room?', danger: true, confirmText: 'Delete' })) { await api('DELETE', '/owner/rooms/' + b.dataset.rm).catch(fail); reload(); } }));
  }

  function roomEditor(r, d, bid, L, reload) {
    const s = sheet(`<h3>${r ? 'Edit' : 'New'} room</h3><div class="tabs" id="rt"><button data-t="d" class="on">Details</button>${r ? '<button data-t="p">Photos</button><button data-t="a">Amenities</button>' : ''}</div><div id="rb"></div><div class="actions"><button class="btn ghost" id="x">Close</button></div>`, { wide: true, sticky: true });
    $('#x', s.el).onclick = () => { s.close(); reload(); };
    const rb = $('#rb', s.el);
    const details = () => {
      rb.innerHTML = `<div class="field"><label for="rl">Label</label><input class="input" id="rl" maxlength="80" value="${esc(r ? r.label : '')}"></div>
        <div class="field"><label for="rty">Room type</label><select id="rty"><option value="">None</option>${d.room_types.map((t) => `<option value="${t.id}" ${r && r.room_type_id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
        <div class="field"><label for="rfl">Floor</label><input class="input" id="rfl" inputmode="numeric" value="${r && r.floor_number != null ? r.floor_number : ''}"></div>
        <div class="field"><label for="rr">Monthly rent (GHS)</label><input class="input" id="rr" inputmode="decimal" value="${r && r.monthly_rent != null ? r.monthly_rent : ''}"></div>
        <div class="field"><label>Rent periods offered</label><div class="row">${L.rent_durations.map((m) => `<label class="check"><input type="checkbox" value="${m}" ${r && (r.durations || []).includes(m) ? 'checked' : ''}> ${m} months</label>`).join('')}</div></div>
        <div class="field"><label for="rd">Description</label><textarea id="rd" maxlength="2000">${esc(r ? r.description || '' : '')}</textarea></div>
        <div class="err" id="rE"></div><button class="btn primary block" id="rs">Save room</button>`;
      $('#rs', rb).onclick = () => busy($('#rs', rb), async () => { const body = { label: val('rl', rb), room_type_id: +$('#rty', rb).value || null, floor_number: val('rfl', rb) === '' ? null : +val('rfl', rb), monthly_rent: val('rr', rb), durations: $$('input:checked', rb).map((c) => +c.value), description: val('rd', rb) }; try { if (r) await api('PUT', '/owner/rooms/' + r.id, body); else await api('POST', `/owner/buildings/${bid}/rooms`, body); toast('Saved'); s.close(); reload(); } catch (e) { $('#rE', rb).textContent = e.message; } });
    };
    details();
    $$('#rt button', s.el).forEach((t) => (t.onclick = () => { $$('#rt button', s.el).forEach((x) => x.classList.toggle('on', x === t)); if (t.dataset.t === 'd') details(); if (t.dataset.t === 'p') photoBlock(rb, r.photos, L.limits.room_photos, `/owner/rooms/${r.id}/photos`, () => { s.close(); reload(); }); if (t.dataset.t === 'a') amenityForm(rb, L.room_amenities, r.amenities, r.custom, `/owner/rooms/${r.id}/amenities`, () => { s.close(); reload(); }); }));
  }

  /* ---------- agents (applications, revoke with survey) ---------- */
  route('/owner/agents', async () => {
    const r = await api('GET', '/owner/applications'); const sv = await api('GET', '/surveys/mine').catch(() => ({ surveys: [] }));
    const el = render(`<h1>Agents</h1>${(sv.surveys || []).map((x) => `<div class="card pinned"><b>Tell us why you removed an agent</b><button class="btn sm primary" data-sv="${x.id}">Answer</button></div>`).join('')}
      ${r.applications.map((a) => `<div class="card"><div class="row">${avatar(a.agent)}<div class="grow"><b>${esc(a.agent.name)}</b><div class="small muted">${esc(a.building ? a.building.name : a.building_name || '')}</div></div>${pill(a.status, a.status === 'approved' ? 'ok' : '')}</div>
        <div class="row wrap mt-s">${a.status === 'pending' ? `<button class="btn sm primary" data-ap="${a.id}">Approve</button><button class="btn sm danger" data-dc="${a.id}">Decline</button>` : ''}${a.status === 'approved' ? `<button class="btn sm danger ghost" data-rv="${a.id}">Remove agent</button>` : ''}<a class="btn sm ghost" href="#/agent-profile/${a.agent.id}">Profile</a></div></div>`).join('') || '<div class="empty">No applications.</div>'}`);
    const decide = (id, decision, title) => async () => { if (await confirmBox({ title, confirmText: decision === 'approve' ? 'Approve' : 'Decline', danger: decision !== 'approve' })) { await api('POST', `/owner/applications/${id}/decide`, { decision }).catch(fail); go('/owner/agents'); } };
    $$('[data-ap]', el).forEach((b) => (b.onclick = decide(b.dataset.ap, 'approve', 'Approve this agent?')));
    $$('[data-dc]', el).forEach((b) => (b.onclick = decide(b.dataset.dc, 'decline', 'Decline this agent?')));
    $$('[data-rv]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Remove this agent?', danger: true, html: '<p>You will be asked to give a reason. The agent\'s earlier reviews stay locked.</p>', confirmText: 'Remove' })) { await api('POST', `/owner/applications/${b.dataset.rv}/revoke`, {}).catch(fail); go('/owner/agents'); } }));
    $$('[data-sv]', el).forEach((b) => (b.onclick = async () => { const why = await R.choose('Reason', [['unresponsive', 'Unresponsive'], ['poor_service', 'Poor service'], ['conduct', 'Conduct'], ['no_longer_needed', 'No longer needed'], ['building_sold', 'Building sold'], ['other', 'Other']]); if (!why) return; await api('POST', `/surveys/${b.dataset.sv}/submit`, { reason_code: why }).catch(fail); go('/owner/agents'); }));
  });

  /* ---------- tenants: pending payment checks (48h) and current tenants ---------- */
  route('/owner/tenants', async () => {
    const pv = await api('GET', '/owner/pending-verifications'); const tn = await api('GET', '/owner/tenants').catch(() => ({ tenants: [] }));
    const el = render(`<h1>Tenants</h1><h3>Needs your attention</h3>${pv.pending.map((x) => x.kind === 'confirm_received' ? `<div class="card"><b>Confirm you received ${ghs(x.amount)}</b> from ${esc(x.user.name)}<div class="small muted">${esc(x.room)}</div><button class="btn sm primary mt-s" data-rc="${x.payment_id}">Money received</button></div>` : `<div class="card ${x.past_48h ? 'pinned' : ''}"><b>${esc(x.user.name)}</b> paid ${ghs(x.amount)} for ${esc(x.room)}<div class="small muted">${x.transaction_id ? 'Txn: ' + esc(x.transaction_id) : ''}</div><div class="row wrap mt-s"><button class="btn sm" data-pf="${x.payment_id}">View proof</button><button class="btn sm primary" data-ok="${x.payment_id}">Approve</button><button class="btn sm danger" data-no="${x.payment_id}">Reject</button></div></div>`).join('') || '<div class="empty">Nothing waiting.</div>'}
      <h3>Tenants</h3>${(tn.tenants || []).map((t) => `<div class="card"><div class="row">${avatar(t.user)}<div class="grow"><b>${esc(t.user.name)}</b><div class="small muted">${esc(t.building ? t.building.name : '')} · ${esc(t.room ? t.room.label : '')}</div></div>${pill(t.status)}</div><div class="row wrap mt-s">${t.status === 'reserved' && !t.owner_signed ? `<button class="btn sm primary" data-sg="${t.id}">Sign agreement</button>` : ''}${t.exit_requested ? `<button class="btn sm danger" data-ex="${t.id}">Confirm exit</button>` : ''}${t.chat_id ? `<a class="btn sm" href="#/chat/${t.chat_id}">Chat</a>` : ''}</div></div>`).join('') || '<div class="empty">No tenants.</div>'}`);
    $$('[data-pf]', el).forEach((b) => (b.onclick = () => openPrivate(`/tenancy/payments/${b.dataset.pf}/proof`).catch(fail)));
    $$('[data-ok]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Approve this payment?', confirmText: 'Approve' })) { await api('POST', `/owner/payments/${b.dataset.ok}/verify`, { action: 'approve' }).catch(fail); go('/owner/tenants'); } }));
    $$('[data-no]', el).forEach((b) => (b.onclick = () => { const s = sheet('<h3>Reject proof</h3><div class="field"><label for="rj">Reason (the user gets 24h to resubmit)</label><input class="input" id="rj" maxlength="200"></div><div class="actions"><button class="btn ghost" id="x">Back</button><button class="btn danger" id="g">Reject</button></div>'); $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = async () => { try { await api('POST', `/owner/payments/${b.dataset.no}/verify`, { action: 'reject', reason: val('rj', s.el) }); s.close(); go('/owner/tenants'); } catch (e) { toast(e.message); } }; }));
    $$('[data-rc]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Confirm money received?', confirmText: 'Received' })) { await api('POST', `/owner/payments/${b.dataset.rc}/received`, {}).catch(fail); go('/owner/tenants'); } }));
    $$('[data-sg]', el).forEach((b) => (b.onclick = () => { const s = sheet('<h3>Sign agreement</h3><div class="field"><label for="wn">Witness full name</label><input class="input" id="wn" maxlength="100"></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Sign</button></div>'); $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = async () => { try { await api('POST', `/owner/tenancies/${b.dataset.sg}/sign`, { witness_name: val('wn', s.el) }); s.close(); go('/owner/tenants'); } catch (e) { toast(e.message); } }; }));
    $$('[data-ex]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Confirm early exit?', danger: true, confirmText: 'Confirm exit' })) { await api('POST', `/owner/tenancies/${b.dataset.ex}/confirm-exit`, {}).catch(fail); go('/owner/tenants'); } }));
  });

  /* ---------- tenant problem reports ---------- */
  route('/owner/reports', async () => {
    const r = await api('GET', '/owner/tenant-reports');
    const el = render(`<h1>Tenant reports</h1>${r.reports.map((x) => `<div class="card"><div class="row"><b>${esc(x.category)}</b><span class="spacer"></span>${pill(x.status)}</div><div class="small muted">${esc(x.user ? x.user.name : '')} · ${esc(x.room || '')}</div><p>${esc(x.description)}</p><div class="row wrap">${['seen', 'in_progress', 'resolved'].map((s) => `<button class="btn sm ${x.status === s ? 'primary' : ''}" data-r="${x.id}" data-s="${s}">${s.replace('_', ' ')}</button>`).join('')}</div></div>`).join('') || '<div class="empty">No reports.</div>'}`);
    $$('[data-r]', el).forEach((b) => (b.onclick = async () => { await api('POST', `/owner/tenant-reports/${b.dataset.r}/status`, { status: b.dataset.s }).catch(fail); go('/owner/reports'); }));
  });

  /* ---------- payout details (phone must be verified) ---------- */
  route('/owner/payout', async () => {
    const st = await api('GET', '/owner/status'); const d = await api('GET', '/owner/payout-details').catch(() => ({}));
    const p = d.details || d;
    const el = render(`<h1>Payout details</h1>${!st.phone_verified ? '<div class="banner">Verify your phone in Profile before payouts can be set.</div>' : ''}
      <div class="card"><div class="field"><label for="mn">Mobile money network</label><input class="input" id="mn" value="${esc(p.momo_network || '')}" placeholder="MTN, Telecel, AirtelTigo"></div>
      <div class="field"><label for="mo">Mobile money number</label><input class="input" id="mo" inputmode="tel" value="${esc(p.momo_number || '')}"></div>
      <div class="field"><label for="mm">Name on mobile money</label><input class="input" id="mm" value="${esc(p.momo_name || '')}"></div>
      <div class="field"><label for="bk">Bank name</label><input class="input" id="bk" value="${esc(p.bank_name || '')}"></div>
      <div class="field"><label for="an">Account name</label><input class="input" id="an" value="${esc(p.account_name || '')}"></div>
      <div class="field"><label for="ac">Account number</label><input class="input" id="ac" inputmode="numeric" value="${esc(p.account_number || '')}"></div>
      <div class="hint">Shown to a tenant only when they reach the payment step.</div><div class="err" id="oE"></div><button class="btn primary block" id="oS">Save</button></div>`);
    $('#oS', el).onclick = () => busy($('#oS', el), async () => {
      const body = { momo_network: val('mn'), momo_number: val('mo'), momo_name: val('mm'), bank_name: val('bk'), account_name: val('an'), account_number: val('ac') };
      try { await api('PUT', '/owner/payout-details', body); toast('Saved'); }
      catch (e) { if (e.code === 'confirm_different_number' || /different/i.test(e.message)) { if (await confirmBox({ title: 'Different number', html: `<p>${esc(e.message)}</p>`, confirmText: 'Yes, use it' })) { await api('PUT', '/owner/payout-details', Object.assign(body, { confirm_different_number: true })).catch(fail); toast('Saved'); } } else $('#oE', el).textContent = e.message; }
    });
  });
})();
