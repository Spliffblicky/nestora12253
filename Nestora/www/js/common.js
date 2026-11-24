/* =====================================================================
   COMMON screens for every role: terms (placeholder), profile, settings, help, alerts,
   chats (list + room), viewings (list + detail, with the date-change timer bar)
   ===================================================================== */
(function () {
  'use strict';
  const { $, $$, esc, api, fd, compressImage, toast, fail, sheet, confirmBox, busy, val, route, render, go, every, avatar, pill, fmtDate, fmtDateTime, fmtLeft, level, icon, cfg } = window.Core;

  /* ---------- terms: placeholder until the real text is supplied ---------- */
  function showTerms(which) {
    const s = sheet(`<h3>${which === 'privacy' ? 'Privacy Policy' : 'Terms and Conditions'}</h3>
      <iframe class="terms-frame" src="terms.html#${which === 'privacy' ? 'privacy' : 'terms'}" title="Terms"></iframe>
      <div class="actions"><button class="btn primary" id="tmOk">Close</button></div>`, { wide: true });
    $('#tmOk', s.el).onclick = () => s.close();
  }

  /* ---------- profile ---------- */
  route('/profile', async () => {
    const r = await api('GET', '/auth/me'); const u = r.user; S.user = Object.assign(S.user, u);
    const el = render(`<h1>My profile</h1><div class="card"><div class="row">${avatar(u, 'xl')}<div><b>${esc(u.name)}</b><div class="muted">@${esc(u.username)} · ${esc(u.role)}${u.admin_tier ? ' (' + esc(u.admin_tier) + ')' : ''}</div></div></div>
      <div class="field mt"><label for="pn">Name</label><input class="input" id="pn" value="${esc(u.name)}"></div>
      <div class="field"><label>Email</label><div>${esc(u.email)} ${u.email_verified ? pill('verified', 'ok') : pill('not verified', 'warn')}</div></div>
      <div class="field"><label>Phone</label><div>${u.phone ? esc(u.phone) : '<span class="muted">Not added</span>'} ${u.phone_verified ? pill('verified', 'ok') : ''}</div>
        <div class="hint">A verified phone is needed before you can pay or receive payment through the app.</div>
        <button class="btn sm mt-s" id="phBtn">${u.phone_verified ? 'Change phone' : 'Verify phone'}</button></div>
      <div class="row wrap"><label class="btn ghost" for="pf">Change photo</label><input class="sr" id="pf" type="file" accept="image/*" capture="user"><span class="spacer"></span><button class="btn primary" id="pSave">Save</button></div></div>
      <div class="card"><h3>Password</h3><button class="btn" id="pwBtn">Change password</button></div>`);
    $('#pSave', el).onclick = () => busy($('#pSave', el), async () => { await api('PUT', '/auth/me', { name: val('pn') }); S.user.name = val('pn'); toast('Saved'); });
    $('#pf', el).onchange = async (e) => { const f = e.target.files[0]; if (!f) return; try { const b = await compressImage(f, 800); const x = await api('POST', '/auth/profile-photo', fd({ photo: b })); S.user.photo_url = x.user.photo_url; toast('Photo updated'); go('/profile'); } catch (er) { fail(er); } };
    $('#phBtn', el).onclick = phoneFlow;
    $('#pwBtn', el).onclick = () => {
      const s = sheet(`<h3>Change password</h3><div class="field"><label for="o">Current</label><input class="input" id="o" type="password"></div><div class="field"><label for="n">New (8+ characters)</label><input class="input" id="n" type="password"></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Change</button></div>`);
      $('#x', s.el).onclick = () => s.close();
      $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { await api('POST', '/auth/change-password', { current_password: $('#o', s.el).value, new_password: $('#n', s.el).value }); s.close(); toast('Password changed'); } catch (e) { toast(e.message); } });
    };
  });

  function phoneFlow() {
    const s = sheet(`<h3>Verify your phone</h3><div id="p1"><div class="field"><label for="ph">Phone number</label><input class="input" id="ph" inputmode="tel" placeholder="024 000 0000"></div><button class="btn primary block" id="pS">Send code</button></div>
      <div id="p2" class="hidden"><div class="field"><label for="pc">Code from SMS</label><input class="input" id="pc" inputmode="numeric" maxlength="6"></div><button class="btn primary block" id="pV">Verify</button></div><div class="err" id="pE"></div>`);
    $('#pS', s.el).onclick = () => busy($('#pS', s.el), async () => { try { await api('POST', '/phone/send-code', { phone: val('ph', s.el) }); $('#p1', s.el).classList.add('hidden'); $('#p2', s.el).classList.remove('hidden'); } catch (e) { $('#pE', s.el).textContent = e.message; } });
    $('#pV', s.el).onclick = () => busy($('#pV', s.el), async () => { try { await api('POST', '/phone/verify', { code: val('pc', s.el) }); s.close(); toast('Phone verified'); go('/profile'); } catch (e) { $('#pE', s.el).textContent = e.message; } });
  }

  /* ---------- settings / help ---------- */
  route('/settings', () => {
    const dark = document.documentElement.dataset.theme;
    const el = render(`<h1>Settings</h1><div class="card"><div class="field"><label for="th">Appearance</label><select id="th"><option value="">Match my device</option><option value="light">Light</option><option value="dark">Dark</option></select></div>
      <button class="btn danger" id="lo">Log out</button></div><p class="muted small center">${esc(cfg.APP_NAME)}</p>`);
    $('#th', el).value = dark || ''; $('#th', el).onchange = (e) => { const v = e.target.value; if (v) document.documentElement.dataset.theme = v; else delete document.documentElement.dataset.theme; try { localStorage.setItem('theme', v); } catch (x) {} };
    $('#lo', el).onclick = async () => { if (!(await confirmBox({ title: 'Log out?', confirmText: 'Log out' }))) return; try { await api('POST', '/auth/logout', {}); } catch (e) {} window.Core.setToken(null); location.hash = ''; location.reload(); };
  });
  route('/help', () => render(`<h1>Help</h1>
    <div class="card"><h3>Viewings</h3><p>Pick an approved agent, a day they work and a time slot. The agent can accept, reject, or ask for another date. A new date must be chosen within 2 days or the request ends.</p></div>
    <div class="card"><h3>Renting</h3><p>After a viewing, choose a room, read the agreement, pick how to pay, and upload proof. The whole process must be paid and verified within 5 days.</p></div>
    <div class="card"><h3>Payments</h3><p>Payments made through the app carry a platform fee. Payment details are shown only at the payment step.</p></div>
    <div class="card"><h3>Safety</h3><p>Never share phone numbers or pay outside the steps shown here. Use Report on any chat, review or listing that looks wrong.</p></div>`));

  /* ---------- alerts ---------- */
  route('/alerts', async () => {
    const r = await api('GET', '/notifications');
    const el = render(`<div class="row"><h1>Alerts</h1><span class="spacer"></span><button class="btn sm ghost" id="allRead">Mark all read</button></div>
      ${r.notifications.length ? r.notifications.map((n) => `<div class="card tap ${n.read_at ? '' : 'unread'}" data-id="${n.id}" data-link="${esc(n.link || '')}"><b>${esc(n.title)}</b><div>${esc(n.body || '')}</div><div class="muted small">${fmtDateTime(n.created_at)}</div></div>`).join('') : '<div class="empty">Nothing yet.</div>'}`);
    $('#allRead', el).onclick = async () => { await api('POST', '/notifications/read', {}); window.Layout.refreshCounts(); go('/alerts'); };
    $$('.tap', el).forEach((c) => (c.onclick = async () => { await api('POST', '/notifications/read', { id: +c.dataset.id }); window.Layout.refreshCounts(); if (c.dataset.link) location.hash = c.dataset.link; else c.classList.remove('unread'); }));
  });

  /* ---------- chats ---------- */
  route('/chats', async () => {
    const r = await api('GET', '/chats');
    render(`<h1>Chats</h1>${r.chats.length ? r.chats.map((c) => `<a class="card row link" href="#/chat/${c.id}">${avatar(c.other || c, 'lg')}<div class="grow"><b>${esc(c.title || (c.other && c.other.name) || 'Chat')}</b><div class="muted small">${esc(c.subtitle || '')}</div><div class="small">${esc(c.last_message || '')}</div></div>${c.unread ? `<span class="badge">${c.unread}</span>` : ''}</a>`).join('') : '<div class="empty">No chats yet. Chats open when a viewing is requested or a tenancy begins.</div>'}`);
  });

  route('/chat/:id', async (p) => {
    const first = await api('GET', '/chats/' + p.id);
    const c = first.chat; let last = 0;
    const el = render(`<div class="row"><a href="#/chats" class="btn sm ghost">Back</a><div class="grow"><b>${esc(c.title)}</b><div class="muted small">${esc(c.subtitle || '')}</div></div><button class="btn sm ghost" id="cMore">More</button></div>
      ${c.viewing ? `<div id="vcard"></div>` : ''}
      ${c.delete_after ? `<div class="banner">This chat will be deleted on ${fmtDate(c.delete_after)}.</div>` : ''}
      <div class="chat" id="msgs"></div>
      ${c.blocked_reason ? `<div class="banner">${esc(c.blocked_reason)}</div>` : `<div class="composer"><textarea id="mt" rows="1" maxlength="1000" placeholder="Message"></textarea><button class="btn primary" id="ms">Send</button></div><div class="hint">Phone numbers and contact details cannot be shared in chat.</div>`}`);
    if (c.viewing) viewingCard($('#vcard', el), c.viewing);
    const box = $('#msgs', el);
    const add = (m) => { last = Math.max(last, m.id); const d = document.createElement('div'); d.className = m.system ? 'msg sys' : 'msg ' + (m.mine ? 'mine' : 'theirs'); d.innerHTML = `<div>${esc(m.body)}</div><small>${fmtDateTime(m.created_at)}</small>`; box.appendChild(d); };
    const poll = async () => { const r = await api('GET', `/chats/${p.id}/messages`, { after: last }); if (r.messages.length) { const atEnd = box.scrollTop + box.clientHeight >= box.scrollHeight - 40; r.messages.forEach(add); if (atEnd) box.scrollTop = box.scrollHeight; } };
    await poll(); box.scrollTop = box.scrollHeight; every('chat', 4000, () => poll().catch(() => {}));
    const send = () => { const t = $('#mt', el); const body = t.value.trim(); if (!body) return; busy($('#ms', el), async () => { try { await api('POST', `/chats/${p.id}/messages`, { body }); t.value = ''; await poll(); box.scrollTop = box.scrollHeight; } catch (e) { toast(e.message); } }); };
    if ($('#ms', el)) $('#ms', el).onclick = send;
    $('#cMore', el).onclick = () => {
      const s = sheet(`<h3>Chat options</h3><div class="col gap-s"><button class="btn" id="mu">${c.muted ? 'Unmute' : 'Mute'} notifications</button>${c.other ? `<button class="btn" id="bl">${c.i_blocked ? 'Unblock' : 'Block'} this person</button>` : ''}<button class="btn danger" id="rp">Report this chat</button><button class="btn ghost" id="cl">Close</button></div>`);
      $('#cl', s.el).onclick = () => s.close();
      $('#mu', s.el).onclick = async () => { await api('POST', `/chats/${p.id}/mute`, { mute: !c.muted }); s.close(); toast('Updated'); };
      if ($('#bl', s.el)) $('#bl', s.el).onclick = async () => { await api('POST', `/users/${c.other.id}/block`, { unblock: !!c.i_blocked }); s.close(); toast('Updated'); };
      $('#rp', s.el).onclick = () => { s.close(); report('chat', +p.id); };
    };
  });

  function report(type, id) {
    const s = sheet(`<h3>Report</h3><div class="field"><label for="rr">What is wrong?</label><textarea id="rr" maxlength="500"></textarea></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn danger" id="g">Send report</button></div>`);
    $('#x', s.el).onclick = () => s.close();
    $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { await api('POST', '/reports', { target_type: type, target_id: id, reason: val('rr', s.el) }); s.close(); toast('Report sent to admins'); } catch (e) { toast(e.message); } });
  }

  /* ---------- viewings ---------- */
  const SLOTS = { morning: 'Morning', afternoon: 'Afternoon', evening: 'Evening' };
  function viewingCard(host, v) {
    const me = S.user.id, isUser = v.user && v.user.id === me, isAgent = v.agent && v.agent.id === me;
    const other = isUser ? v.agent : v.user;
    const bar = v.bar;
    host.innerHTML = `<div class="card"><div class="row"><b>${esc(v.building.name)}${v.room ? ' · ' + esc(v.room.label) : ''}</b><span class="spacer"></span>${pill(v.status, v.status === 'accepted' ? 'ok' : v.status === 'rejected' || v.status === 'expired' ? 'bad' : '')}</div>
      <div class="muted">${isUser ? 'Agent' : 'User'}: ${esc(other ? other.name : '')}</div>
      <div>${fmtDate(v.requested_date)} · ${esc(SLOTS[v.time_slot] || v.time_slot || '')}</div>
      ${v.reject_reason ? `<div class="err">Reason: ${esc(v.reject_reason.replace(/_/g, ' '))}</div>` : ''}
      ${bar ? `<div class="timerbar ${bar.seconds_left != null ? level(bar.seconds_left) : ''}"><b>${esc(bar.question)}</b> ${bar.seconds_left != null ? `<span data-end="${esc(bar.ends_at)}">${fmtLeft(bar.seconds_left)}</span>` : ''}</div>` : ''}
      <div class="row wrap mt-s" id="vAct"></div></div>`;
    const act = $('#vAct', host); const btn = (t, cls, fn) => { const b = document.createElement('button'); b.className = 'btn sm ' + cls; b.textContent = t; b.onclick = fn; act.appendChild(b); };
    const reload = () => window.Core.navigate();
    if (v.chat_id && !location.hash.startsWith('#/chat/')) btn('Open chat', '', () => go('/chat/' + v.chat_id));
    if (isAgent && v.status === 'pending') {
      btn('Accept', 'primary', async () => { if (await confirmBox({ title: 'Accept this viewing?', confirmText: 'Accept' })) { await api('POST', `/viewings/${v.id}/respond`, { action: 'accept' }).catch(fail); reload(); } });
      btn('Ask another date', '', async () => { if (await confirmBox({ title: 'Ask the user for another date?', html: `<p>They have 2 days to choose a new one.</p>`, confirmText: 'Ask' })) { await api('POST', `/viewings/${v.id}/respond`, { action: 'ask_date' }).catch(fail); reload(); } });
      btn('Reject', 'danger', () => rejectSheet(v, reload));
    }
    if (isUser && bar && bar.action === 'pick_new_date') btn('Choose new date', 'primary', () => datePicker(v, reload));
    if ((isUser || isAgent) && ['pending', 'accepted', 'date_change_requested'].includes(v.status)) btn('Cancel', 'ghost', async () => { if (await confirmBox({ title: 'Cancel this viewing?', danger: true, confirmText: 'Cancel viewing' })) { await api('POST', `/viewings/${v.id}/cancel`, {}).catch(fail); reload(); } });
    if (isAgent && v.status === 'accepted') btn('Mark completed', 'primary', async () => { if (await confirmBox({ title: 'Mark viewing as completed?', confirmText: 'Completed' })) { await api('POST', `/viewings/${v.id}/complete`, {}).catch(fail); reload(); } });
  }

  function rejectSheet(v, done) {
    const s = sheet(`<h3>Reject viewing</h3><div class="field"><label for="rj">Reason</label><select id="rj"><option value="not_available">I am not available</option><option value="building_unavailable">Building unavailable</option><option value="room_taken">Room taken</option><option value="other">Other</option></select></div><div class="actions"><button class="btn ghost" id="x">Back</button><button class="btn danger" id="g">Reject</button></div>`);
    $('#x', s.el).onclick = () => s.close();
    $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { await api('POST', `/viewings/${v.id}/respond`, { action: 'reject', reason: $('#rj', s.el).value }); s.close(); done(); } catch (e) { toast(e.message); } });
  }

  function datePicker(v, done) {
    const days = v.agent_weekdays || []; const names = window.Core.WEEKDAYS;
    const s = sheet(`<h3>Pick a new date</h3><p class="muted small">This agent works: ${days.map((d) => names[d]).join(', ') || 'no days set'}.</p><div class="field"><input class="input" id="nd" type="date" min="${new Date().toISOString().slice(0, 10)}"></div><div class="err" id="nE"></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Send date</button></div>`);
    $('#x', s.el).onclick = () => s.close();
    $('#g', s.el).onclick = () => busy($('#g', s.el), async () => { try { await api('POST', `/viewings/${v.id}/new-date`, { date: val('nd', s.el) }); s.close(); done(); } catch (e) { $('#nE', s.el).textContent = e.message; } });
  }

  route('/viewings', async () => {
    const r = await api('GET', '/viewings'); const el = render(`<h1>Viewings</h1><div id="vl"></div>`);
    if (!r.viewings.length) $('#vl', el).innerHTML = '<div class="empty">No viewings yet.</div>';
    r.viewings.forEach((v) => { const d = document.createElement('div'); $('#vl', el).appendChild(d); viewingCard(d, v); });
    tickTimers(el); await promptsFor(el);
  });
  route('/agent/viewings', () => go('/viewings'));
  route('/viewing/:id', async (p) => { const r = await api('GET', '/viewings/' + p.id); const el = render('<div id="one"></div>'); viewingCard($('#one', el), r.viewing); tickTimers(el); });

  function tickTimers(root) { every('vt', 1000, () => $$('[data-end]', root).forEach((s) => { const sec = Math.max(0, Math.floor((new Date(s.dataset.end.replace(' ', 'T') + 'Z') - Date.now()) / 1000)); s.textContent = fmtLeft(sec); })); }

  /* "Did the viewing take place?" and "Do you want to rent?" prompts */
  async function promptsFor(el) {
    if (S.user.role === 'owner') return;
    const c = await api('GET', '/viewings/prompts/confirm').catch(() => ({ prompts: [] }));
    c.prompts.forEach((v) => {
      const d = document.createElement('div'); d.className = 'card'; d.innerHTML = `<b>Did your viewing of ${esc(v.building.name)} on ${fmtDate(v.requested_date)} take place?</b><div class="row mt-s"><button class="btn sm primary" data-a="yes">Yes</button><button class="btn sm" data-a="no">No</button></div>`;
      el.prepend(d);
      $$('button', d).forEach((b) => (b.onclick = async () => {
        if (b.dataset.a === 'no') { const fu = await choose('What happened?', [['reschedule', 'Reschedule'], ['report_no_show', 'Report agent no-show']]); if (!fu) return; await api('POST', `/viewings/${v.id}/confirm`, { answer: 'no', follow_up: fu }).catch(fail); }
        else await api('POST', `/viewings/${v.id}/confirm`, { answer: 'yes' }).catch(fail);
        window.Core.navigate();
      }));
    });
    const r = await api('GET', '/viewings/prompts/rent').catch(() => ({ prompts: [] }));
    r.prompts.forEach((v) => {
      const d = document.createElement('div'); d.className = 'card'; d.innerHTML = `<b>Would you like to rent at ${esc(v.building.name)}?</b><div class="row wrap mt-s"><button class="btn sm primary" data-a="yes">Yes, choose a room</button><button class="btn sm" data-a="not_now">Not now</button><button class="btn sm ghost" data-a="not_interested">Not interested</button></div>`;
      el.prepend(d);
      $$('button', d).forEach((b) => (b.onclick = async () => { if (b.dataset.a === 'yes') return go('/rent/start/' + v.id); await api('POST', `/viewings/${v.id}/rent-decision`, { decision: b.dataset.a }).catch(fail); window.Core.navigate(); }));
    });
  }
  function choose(title, options) {
    return new Promise((res) => {
      const s = sheet(`<h3>${esc(title)}</h3><div class="col gap-s">${options.map((o) => `<button class="btn" data-v="${o[0]}">${esc(o[1])}</button>`).join('')}<button class="btn ghost" data-v="">Cancel</button></div>`, { onClose: () => res(null) });
      $$('button', s.el).forEach((b) => (b.onclick = () => { s.close(); res(b.dataset.v || null); }));
    });
  }

  window.Common = { showTerms, report, viewingCard, choose, phoneFlow, SLOTS };
})();
