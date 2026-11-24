/* =====================================================================
   RENT (users): choose a room after a viewing, the 5-day rent process, my tenancy,
   renewal prompts, early exit, and tenant problem reports
   ===================================================================== */
(function () {
  'use strict';
  const { $, $$, esc, api, fd, compressImage, toast, fail, sheet, confirmBox, busy, val, route, render, go, every, avatar, pill, ghs, fmtDate, fmtDateTime, fmtLeft, level, openPrivate } = window.Core;
  const R = window.Common;
  const STEP_LABEL = { started: 'Process started', room_check: 'Room confirmed', agreement: 'Agreement read', method: 'Payment method chosen', proof: 'Proof uploaded', verified: 'Payment verified', move_in: 'Move-in date set' };

  /* ---------- start (from a completed viewing) ---------- */
  route('/rent/start/:viewing', async (p) => {
    const v = (await api('GET', '/viewings/' + p.viewing)).viewing;
    if (v.room) return startWith(v.id, v.room.id);
    const r = await api('GET', `/buildings/${v.building.id}/available-rooms`);
    const el = render(`<h1>Choose a room</h1><p class="muted">${esc(v.building.name)}</p>${r.rooms.map((x) => `<div class="card row"><div class="grow"><b>${esc(x.label)}</b> ${x.room_type ? '· ' + esc(x.room_type) : ''}<div class="muted small">${x.durations.join(', ')} months</div></div><div>${ghs(x.monthly_rent)}/mo</div><button class="btn sm primary" data-r="${x.id}">Choose</button></div>`).join('') || '<div class="empty">No rooms available right now.</div>'}`);
    $$('[data-r]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Start the rent process for this room?', html: '<p>You will have <b>5 days</b> to pay and have the payment verified.</p>', confirmText: 'Start' })) startWith(v.id, +b.dataset.r); }));
  });
  async function startWith(viewingId, roomId) { try { const r = await api('POST', '/tenancy/start', { viewing_id: viewingId, room_id: roomId }); go('/rent/process/' + r.process.id); } catch (e) { fail(e); go('/rent'); } }

  /* ---------- list of my processes ---------- */
  route('/rent', async () => {
    const r = await api('GET', '/tenancy/processes');
    render(`<h1>Rent</h1>${r.processes.length ? r.processes.map((x) => `<a class="card row link" href="#/rent/process/${x.id}"><div class="grow"><b>${esc(x.terms.building.name)} · ${esc(x.terms.room.label)}</b><div class="muted small">${fmtDate(x.started_at)}</div></div>${pill(x.status, x.status === 'verified' ? 'ok' : ['expired', 'cancelled'].includes(x.status) ? 'bad' : '')}</a>`).join('') : '<div class="empty">Nothing yet. After a viewing is completed you can choose a room and start renting.</div>'}`);
  });

  /* ---------- one process ---------- */
  route('/rent/process/:id', async (p) => { await drawProcess(+p.id); });
  async function drawProcess(id) {
    const x = (await api('GET', '/tenancy/processes/' + id)).process; const t = x.terms; const open = ['started', 'agreement_read', 'method_chosen', 'proof_uploaded'].includes(x.status);
    const el = render(`<a class="btn sm ghost" href="#/rent">Back</a><h1>${esc(t.building.name)} · ${esc(t.room.label)}</h1>
      ${x.is_renewal ? pill('Renewal', 'info') : ''}
      ${x.countdown && open ? `<div class="timerbar ${x.countdown.level === 'normal' ? '' : x.countdown.level}"><b>${esc(x.countdown.label)}</b> <span id="cd" data-end="${esc(x.countdown.ends_at)}">${fmtLeft(x.countdown.seconds_left)}</span></div>` : ''}
      ${x.waiting_for ? `<div class="banner">${esc(String(x.waiting_for).replace(/_/g, ' '))}</div>` : ''}
      <div class="steps vertical">${x.steps.map((s) => `<div class="step ${s.done ? 'done' : ''}">${s.done ? '✓' : '○'} ${esc(STEP_LABEL[s.key] || s.key)}</div>`).join('')}</div>
      <div class="card"><div><b>${ghs(t.monthly_rent)}</b> / month</div>${t.duration_months ? `<div>${t.duration_months} months · total ${ghs(t.rent_total)}</div>` : ''}
        ${t.termination && t.termination.label ? `<div class="small muted">Early exit: ${esc(t.termination.label)}</div>` : ''}</div>
      <div id="stage"></div>
      ${x.chat_id ? `<a class="btn" href="#/chat/${x.chat_id}">Chat with the owner</a>` : ''}
      ${open ? '<button class="btn danger ghost mt" id="cancel">Cancel this process</button>' : ''}`);
    const st = $('#stage', el); const again = () => drawProcess(id);
    if (x.countdown && open) every('cd', 1000, () => { const e = $('#cd'); if (e) e.textContent = fmtLeft(Math.max(0, Math.floor((new Date(e.dataset.end.replace(' ', 'T') + 'Z') - Date.now()) / 1000))); });
    if ($('#cancel', el)) $('#cancel', el).onclick = async () => { if (await confirmBox({ title: 'Cancel the rent process?', danger: true, confirmText: 'Cancel process' })) { await api('POST', `/tenancy/processes/${id}/cancel`, {}).catch(fail); go('/rent'); } };
    if (!open) return stageDone(st, x);

    if (!x.room_confirmed) {
      st.innerHTML = `<div class="card"><h3>Confirm your room</h3><p>Is <b>${esc(t.room.label)}</b> the room you want?</p><div class="row wrap"><button class="btn primary" id="same">Yes, this room</button><button class="btn" id="other">Choose another</button></div></div>`;
      $('#same', st).onclick = () => busy($('#same', st), async () => { try { await api('POST', `/tenancy/processes/${id}/room`, { same_room: true }); again(); } catch (e) { fail(e); } });
      $('#other', st).onclick = async () => { const r = await api('GET', `/buildings/${t.building.id}/available-rooms`); const s = sheet(`<h3>Choose another room</h3>${r.rooms.filter((o) => o.id !== t.room.id).map((o) => `<button class="btn block mb" data-r="${o.id}">${esc(o.label)} · ${ghs(o.monthly_rent)}</button>`).join('') || '<p class="muted">No other rooms.</p>'}<button class="btn ghost block" id="x">Close</button>`); $('#x', s.el).onclick = () => s.close(); $$('[data-r]', s.el).forEach((b) => (b.onclick = async () => { try { await api('POST', `/tenancy/processes/${id}/room`, { same_room: false, room_id: +b.dataset.r }); s.close(); again(); } catch (e) { toast(e.message); } })); };
    } else if (!x.agreement_read) {
      st.innerHTML = `<div class="card"><h3>Tenancy agreement</h3><p>Open and read the agreement. You can only confirm after opening it.</p><div class="row wrap"><button class="btn" id="openA">Open agreement (PDF)</button><button class="btn primary" id="readA" ${x.agreement_opened ? '' : 'disabled'}>I have read it</button></div></div>`;
      $('#openA', st).onclick = async () => { await openPrivate(`/tenancy/processes/${id}/agreement`).catch(fail); $('#readA', st).disabled = false; };
      $('#readA', st).onclick = async () => { if (await confirmBox({ title: 'Confirm you have read the agreement?', confirmText: 'I have read it' })) { await api('POST', `/tenancy/processes/${id}/agreement-read`, { confirm: true }).catch(fail); again(); } };
    } else if (!x.payment_method) {
      const fp = t.fee_preview; const durs = t.durations || [];
      st.innerHTML = `<div class="card"><h3>How will you pay?</h3>
        <div class="field"><label for="dm">Rent period</label><select id="dm">${durs.map((d) => `<option value="${d}" ${d === t.duration_months ? 'selected' : ''}>${d} months</option>`).join('')}</select></div>
        <label class="card row"><input type="radio" name="pm" value="app"> <div><b>Pay through the app</b><div class="small muted">Payment goes to the platform and is passed to the owner after verification. A ${fp ? fp.app.fee_percent : 5}% fee applies.</div></div></label>
        <label class="card row"><input type="radio" name="pm" value="third_party"> <div><b>Pay the owner directly (third party)</b><div class="small muted">The owner's payment details are shown on the next step.</div></div></label>
        <div class="err" id="mE"></div><button class="btn primary block" id="mGo">Continue</button></div>`;
      $('#mGo', st).onclick = async () => { const m = $('input[name=pm]:checked', st); if (!m) return ($('#mE', st).textContent = 'Choose a payment method.'); if (!(await confirmBox({ title: 'Confirm payment method?', html: `<p>${m.value === 'app' ? 'Pay through the app' : 'Pay the owner directly'} for <b>${$('#dm', st).value} months</b>.</p>`, confirmText: 'Confirm' }))) return; try { await api('POST', `/tenancy/processes/${id}/method`, { method: m.value, duration_months: +$('#dm', st).value }); again(); } catch (e) { $('#mE', st).textContent = e.message; } };
    } else if (!x.payment || ['awaiting_proof', 'rejected'].includes(x.payment.status)) {
      const d = await api('GET', `/tenancy/processes/${id}/payment-details`);
      const det = d.details || {};
      st.innerHTML = `<div class="card"><h3>Make your payment</h3>
        <div class="big">${ghs(d.amount)}</div>
        ${d.kind === 'app' ? `<div class="small muted">Includes platform fee ${ghs(d.fee_amount)} (${d.fee_percent}%). Owner receives ${ghs(d.owner_amount)}.</div><div class="mt-s">Use this reference: <b>${esc(d.reference)}</b></div>` : `<div class="mt-s">Pay <b>${esc(d.owner ? d.owner.name : 'the owner')}</b></div>`}
        <div class="payd">${Object.keys(det).map((k) => `<div><span class="muted">${esc(k.replace(/_/g, ' '))}</span><br><b>${esc(det[k])}</b></div>`).join('')}</div>
        ${x.payment && x.payment.status === 'rejected' ? `<div class="err">Proof rejected: ${esc(x.payment.rejection_reason || '')}. Upload a corrected proof before ${fmtDateTime(x.payment.resubmit_by)}.</div>` : ''}
        <hr><div class="field"><label for="tx">Transaction ID (optional)</label><input class="input" id="tx" maxlength="80"></div>
        <label class="btn" for="pr">Upload payment proof</label><input class="sr" id="pr" type="file" accept="image/*"><div class="small" id="prn"></div>
        <div class="err" id="uE"></div><button class="btn primary block mt-s" id="uGo" disabled>Submit proof</button></div>`;
      let blob = null;
      $('#pr', st).onchange = async (e) => { const f = e.target.files[0]; if (!f) return; blob = await compressImage(f, 1600); $('#prn', st).textContent = f.name; $('#uGo', st).disabled = false; };
      $('#uGo', st).onclick = () => busy($('#uGo', st), async () => { try { await api('POST', `/tenancy/processes/${id}/proof`, fd({ proof: blob, transaction_id: val('tx', st) })); toast('Proof submitted'); again(); } catch (e) { $('#uE', st).textContent = e.message; } });
    } else if (x.payment.status === 'proof_uploaded') {
      st.innerHTML = `<div class="card"><h3>Waiting for verification</h3><p>Your proof is with ${x.payment.method === 'app' ? 'the platform' : 'the owner'} for checking. The 5-day clock has stopped. You will be notified.</p></div>`;
      every('poll', 15000, again);
    } else stageDone(st, x);
  }
  function stageDone(st, x) {
    const tn = x.tenancy;
    if (x.status === 'expired') st.innerHTML = '<div class="card"><h3>Process expired</h3><p>The 5 days ran out. You can start again from your completed viewing.</p></div>';
    else if (x.status === 'cancelled') st.innerHTML = '<div class="card"><h3>Cancelled</h3></div>';
    else if (tn) {
      st.innerHTML = `<div class="card"><h3>Payment verified</h3>${tn.move_in_date ? `<p>Move-in: <b>${fmtDate(tn.move_in_date)}</b></p>` : tn.can_pick_move_in ? `<p>Pick your move-in date within the window${tn.move_in_deadline ? ' (before ' + fmtDate(tn.move_in_deadline) + ')' : ''}.</p><input class="input" type="date" id="mi" min="${new Date().toISOString().slice(0, 10)}" ${tn.move_in_deadline ? 'max="' + tn.move_in_deadline + '"' : ''}><button class="btn primary mt-s" id="miGo">Set move-in date</button>` : '<p>Waiting for the owner to confirm the money.</p>'}<a class="btn mt-s" href="#/tenancy">Go to my tenancy</a></div>`;
      if ($('#miGo', st)) $('#miGo', st).onclick = async () => { try { await api('POST', `/tenancy/${tn.id}/move-in`, { date: val('mi', st) }); toast('Saved'); go('/rent/process/' + x.id); } catch (e) { toast(e.message); } };
    }
  }

  /* ---------- tenancy (after payment verified) ---------- */
  route('/tenancy', async () => {
    const r = await api('GET', '/tenancy/mine'); const pr = await api('GET', '/tenancy/renewal-prompt').catch(() => ({ prompts: [] }));
    const el = render(`<h1>My tenancy</h1>
      ${pr.prompts.map((q) => `<div class="card ${q.mode === 'pinned' ? 'pinned' : ''}"><b>Do you want to renew at ${esc(q.building)}?</b><div class="small muted">Lease ends ${fmtDate(q.end_date)} (${q.days_left} days left)</div><div class="row wrap mt-s"><button class="btn sm primary" data-q="${q.tenancy_id}" data-a="yes">Yes</button><button class="btn sm" data-q="${q.tenancy_id}" data-a="not_sure">Not sure</button><button class="btn sm ghost" data-q="${q.tenancy_id}" data-a="no">No</button></div></div>`).join('')}
      ${r.tenancies.map((t) => `<div class="card"><div class="row"><b>${esc(t.building.name)} · ${esc(t.room.label)}</b><span class="spacer"></span>${pill(t.status, t.status === 'active' ? 'ok' : '')}</div>
        <div>${ghs(t.rent_monthly)} / month · ${t.duration_months} months</div>${t.end_date ? `<div class="muted">Ends ${fmtDate(t.end_date)}</div>` : ''}
        <div class="row wrap mt-s"><button class="btn sm" data-ag="${t.id}">Agreement</button><a class="btn sm" href="#/tenancy/report/${t.id}">Report a problem</a><button class="btn sm" data-rn="${t.id}">Renew</button><button class="btn sm danger ghost" data-ex="${t.id}">Leave early</button></div></div>`).join('') || '<div class="empty">No active tenancy.</div>'}
      <a class="btn ghost" href="#/tenancy/reports">My reports</a>
      ${r.past.length ? `<h3>Past</h3>${r.past.map((t) => `<div class="card">${esc(t.building.name)} · ${esc(t.room.label)} ${pill(t.status)}</div>`).join('')}` : ''}`);
    $$('[data-q]', el).forEach((b) => (b.onclick = async () => { await api('POST', `/tenancy/${b.dataset.q}/renewal-answer`, { answer: b.dataset.a }).catch(fail); go('/tenancy'); }));
    $$('[data-ag]', el).forEach((b) => (b.onclick = () => openPrivate(`/tenancy/${b.dataset.ag}/agreement`).catch(fail)));
    $$('[data-rn]', el).forEach((b) => (b.onclick = async () => { if (!(await confirmBox({ title: 'Start a renewal?', html: '<p>The same 5-day rent process applies to renewals.</p>', confirmText: 'Start renewal' }))) return; try { const x = await api('POST', `/tenancy/${b.dataset.rn}/start-renewal`, {}); toast(x.note || 'Started'); go('/rent/process/' + x.process.id); } catch (e) { toast(e.message); } }));
    $$('[data-ex]', el).forEach((b) => (b.onclick = async () => {
      const q = (await api('GET', `/tenancy/${b.dataset.ex}/exit-quote`).catch(fail)); if (!q) return; const z = q.quote;
      if (await confirmBox({ title: 'Leave early?', danger: true, html: `<p>${esc(z.explanation || z.label || '')}</p><p>Estimated amount owed: <b>${ghs(z.amount_due != null ? z.amount_due : z.fee)}</b></p><p class="small muted">The owner must confirm your exit.</p>`, confirmText: 'Request exit', typeToConfirm: 'LEAVE' })) { await api('POST', `/tenancy/${b.dataset.ex}/request-exit`, {}).catch(fail); toast('Request sent to the owner'); }
    }));
  });

  /* ---------- tenant reports ---------- */
  route('/tenancy/report/:id', async (p) => {
    const o = await api('GET', '/tenant/reports/options'); let blobs = [];
    const el = render(`<a class="btn sm ghost" href="#/tenancy">Back</a><h1>Report a problem</h1><div class="card">
      <div class="field"><label for="rc">Category</label><select id="rc">${o.categories.map((c) => `<option value="${c}">${c}</option>`).join('')}</select></div>
      <div class="field hidden" id="amw"><label for="ra">Which amenity?</label><select id="ra">${(o.amenities || []).map((a) => `<option value="${a.id}">${esc(a.label)}</option>`).join('')}</select></div>
      <div class="field"><label for="rd">What is the problem?</label><textarea id="rd" maxlength="1000"></textarea></div>
      <label class="btn ghost" for="rf">Add photos</label><input class="sr" id="rf" type="file" accept="image/*" multiple><div class="small" id="rn"></div>
      <div class="err" id="rE"></div><button class="btn primary block mt-s" id="rg">Send to owner</button></div>`);
    $('#rc', el).onchange = (e) => $('#amw', el).classList.toggle('hidden', e.target.value !== 'amenity');
    $('#rf', el).onchange = async (e) => { blobs = []; for (const f of [...e.target.files].slice(0, 4)) blobs.push(await compressImage(f, 1200)); $('#rn', el).textContent = blobs.length + ' photo(s)'; };
    $('#rg', el).onclick = () => busy($('#rg', el), async () => { try { const body = { tenancy_id: +p.id, category: $('#rc', el).value, description: val('rd', el) }; if (body.category === 'amenity') body.amenity_id = +$('#ra', el).value; const f = fd(body); blobs.forEach((b) => f.append('photos[]', b, 'p.jpg')); await api('POST', '/tenant/reports', f); toast('Report sent'); go('/tenancy/reports'); } catch (e) { $('#rE', el).textContent = e.message; } });
  });
  route('/tenancy/reports', async () => {
    const r = await api('GET', '/tenant/reports');
    const el = render(`<a class="btn sm ghost" href="#/tenancy">Back</a><h1>My reports</h1>${r.reports.map((x) => `<div class="card"><div class="row"><b>${esc(x.category)}</b><span class="spacer"></span>${pill(x.status)}</div><p>${esc(x.description)}</p>${x.owner_note ? `<div class="small muted">Owner: ${esc(x.owner_note)}</div>` : ''}
      ${x.status !== 'resolved' ? `<div class="row wrap"><button class="btn sm primary" data-ok="${x.id}">It is fixed</button>${x.can_escalate ? `<button class="btn sm danger" data-esc="${x.id}">Tell admin</button>` : ''}</div>` : ''}</div>`).join('') || '<div class="empty">No reports.</div>'}`);
    $$('[data-ok]', el).forEach((b) => (b.onclick = async () => { await api('POST', `/tenant/reports/${b.dataset.ok}/confirm`, { resolved: true }).catch(fail); go('/tenancy/reports'); }));
    $$('[data-esc]', el).forEach((b) => (b.onclick = async () => { const why = await R.choose('Why escalate to admin?', [['owner_ignored', 'Owner is not responding'], ['conduct', 'Owner conduct'], ['safety', 'Safety issue']]); if (!why) return; await api('POST', `/tenant/reports/${b.dataset.esc}/escalate`, { reason: why }).catch(fail); toast('Admin notified'); go('/tenancy/reports'); }));
  });
})();
