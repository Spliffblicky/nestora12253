/* ADMIN: overview, accounts, bans, reports, appeals, tenant reports, payments, disputes, admins, settings, audit */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fail, sheet, confirmBox, busy, val, route, render, go, pill, ghs, fmtDateTime, openPrivate } = window.Core;
  const seeded = () => S.user.admin_tier === 'root' || S.user.admin_tier === 'seeded';
  const ask = (title, label, okText, danger) => new Promise((res) => {
    const s = sheet(`<h3>${esc(title)}</h3><div class="field"><label for="ak">${esc(label)}</label><textarea id="ak" maxlength="500"></textarea></div><div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn ${danger ? 'danger' : 'primary'}" id="g">${esc(okText)}</button></div>`, { onClose: () => res(null) });
    $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = () => { const v = val('ak', s.el); if (!v) return toast('A reason is required'); s.close(); res(v); };
  });
  const tbl = (head, rows) => `<div class="tablewrap"><table><thead><tr>${head.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('') || `<tr><td colspan="${head.length}" class="muted">Nothing here.</td></tr>`}</tbody></table></div>`;

  route('/admin', async () => {
    const r = await api('GET', '/admin/overview'); const o = r.overview;
    render(`<h1>Overview</h1><div class="grid">${Object.keys(o).map((k) => `<div class="card stat"><div class="big">${esc(o[k])}</div><div class="muted small">${esc(k.replace(/_/g, ' '))}</div></div>`).join('')}</div>`);
  });

  route('/admin/accounts', async () => {
    const el = render(`<h1>Accounts</h1><div class="row wrap"><input class="input grow" id="q" placeholder="Search name, username or email"><select id="rl" class="input w-auto"><option value="">All roles</option><option>owner</option><option>agent</option><option>user</option><option>admin</option></select><button class="btn primary" id="go">Search</button></div><div id="list"></div>`);
    const load = async () => {
      const r = await api('GET', '/admin/users', { q: val('q', el), role: $('#rl', el).value });
      $('#list', el).innerHTML = tbl(['Name', 'Role', 'Status', ''], r.users.map((u) => `<tr><td>${esc(u.name)}<br><span class="muted small">@${esc(u.username)}</span></td><td>${esc(u.role)}${u.admin_tier ? ' (' + esc(u.admin_tier) + ')' : ''}</td><td>${pill(u.status, u.status === 'banned' ? 'bad' : '')}</td><td>${u.status === 'banned' ? `<button class="btn sm" data-lift="${u.id}">Lift ban</button>` : u.role !== 'admin' ? `<button class="btn sm danger" data-ban="${u.id}">Ban</button>` : ''}${seeded() && u.role === 'user' ? ` <button class="btn sm" data-pr="${u.id}">Make admin</button>` : ''}</td></tr>`));
      $$('[data-ban]', el).forEach((b) => (b.onclick = async () => { const why = await ask('Ban this account?', 'Reason (shown to the person)', 'Ban', true); if (why) { await api('POST', '/admin/bans', { target_type: 'user', target_id: +b.dataset.ban, reason: why }).catch(fail); load(); } }));
      $$('[data-lift]', el).forEach((b) => (b.onclick = async () => { const why = await ask('Lift ban?', 'Reason', 'Lift ban'); if (why) { await api('POST', '/admin/bans/lift', { target_type: 'user', target_id: +b.dataset.lift, reason: why }).catch(fail); load(); } }));
      $$('[data-pr]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Promote to admin?', html: '<p>They get admin tools but less authority than seeded admins.</p>', confirmText: 'Promote' })) { await api('POST', `/admin/users/${b.dataset.pr}/promote`, {}).catch(fail); load(); } }));
    };
    $('#go', el).onclick = load; load();
  });

  route('/admin/reports', async () => {
    const r = await api('GET', '/admin/reports');
    const el = render(`<h1>Reports</h1>${r.reports.map((x) => `<div class="card"><div class="row"><b>${esc(x.target_type)} #${esc(x.target_id)}</b><span class="spacer"></span>${pill(x.status)}</div><p>${esc(x.reason)}</p><div class="small muted">${fmtDateTime(x.created_at)}</div><div class="row wrap">${x.target_type === 'chat' ? `<button class="btn sm" data-ch="${x.target_id}">Read chat</button>` : ''}<button class="btn sm primary" data-r="${x.id}" data-s="resolved">Resolve</button><button class="btn sm" data-r="${x.id}" data-s="dismissed">Dismiss</button></div></div>`).join('') || '<div class="empty">No reports.</div>'}`);
    $$('[data-r]', el).forEach((b) => (b.onclick = async () => { const o = await ask('Outcome', 'What was decided?', 'Save'); if (o) { await api('POST', `/admin/reports/${b.dataset.r}/resolve`, { status: b.dataset.s, outcome: o }).catch(fail); go('/admin/reports'); } }));
    $$('[data-ch]', el).forEach((b) => (b.onclick = async () => { const m = await api('GET', `/admin/chats/${b.dataset.ch}/messages`); const s = sheet(`<h3>Chat (read only, logged)</h3><div class="chat">${(m.messages || []).map((x) => `<div class="msg theirs"><b>${esc(x.sender_name || '')}</b> ${esc(x.body)}</div>`).join('')}</div><div class="actions"><button class="btn" id="x">Close</button></div>`, { wide: true }); $('#x', s.el).onclick = () => s.close(); }));
  });

  route('/admin/appeals', async () => {
    const r = await api('GET', '/admin/appeals');
    const el = render(`<h1>Ban appeals</h1>${r.appeals.map((a) => `<div class="card"><b>${esc(a.name || a.user_name || '')}</b><p>${esc(a.message)}</p><div class="small muted">Ban reason: ${esc(a.ban_reason || '')}</div><div class="row wrap"><button class="btn sm primary" data-a="${a.id}" data-d="overturn">Overturn ban</button><button class="btn sm danger" data-a="${a.id}" data-d="uphold">Uphold</button></div></div>`).join('') || '<div class="empty">No appeals.</div>'}`);
    $$('[data-a]', el).forEach((b) => (b.onclick = async () => { const why = await ask('Decision reason', 'Reason', 'Submit'); if (why) { try { await api('POST', `/admin/appeals/${b.dataset.a}/decide`, { decision: b.dataset.d, reason: why }); go('/admin/appeals'); } catch (e) { fail(e); } } }));
  });

  route('/admin/tenant-reports', async () => {
    const r = await api('GET', '/admin/tenant-reports');
    const el = render(`<h1>Escalated tenant reports</h1>${r.reports.map((x) => `<div class="card"><b>${esc(x.category)}</b> ${pill(x.escalation_reason || '')}<p>${esc(x.description)}</p><button class="btn sm primary" data-r="${x.id}">Resolve</button></div>`).join('') || '<div class="empty">None.</div>'}`);
    $$('[data-r]', el).forEach((b) => (b.onclick = async () => { const o = await ask('Outcome', 'What was done?', 'Resolve'); if (o) { await api('POST', `/admin/tenant-reports/${b.dataset.r}/resolve`, { outcome: o }).catch(fail); go('/admin/tenant-reports'); } }));
  });

  /* payments: only seeded admins */
  route('/admin/payments', async () => {
    if (!seeded()) return render('<div class="empty">Only seeded admins can handle app payments.</div>');
    const r = await api('GET', '/admin/payments');
    const el = render(`<h1>App payments</h1>${r.payments.map((p) => `<div class="card"><div class="row"><b>${ghs(p.amount)}</b><span class="spacer"></span>${pill(p.status)}</div><div class="small muted">${esc(p.user_name || '')} → ${esc(p.owner_name || '')}</div><div class="row wrap mt-s"><button class="btn sm" data-pf="${p.id}">Proof</button>${p.status === 'proof_uploaded' ? `<button class="btn sm primary" data-v="${p.id}">Approve</button><button class="btn sm danger" data-x="${p.id}">Reject</button>` : ''}${p.status === 'verified' ? `<button class="btn sm primary" data-po="${p.id}">Mark paid to owner</button><button class="btn sm danger ghost" data-rf="${p.id}">Refund</button>` : ''}</div></div>`).join('') || '<div class="empty">None.</div>'}
      <div class="card"><h3>App payment details</h3><button class="btn" id="apd">View / edit</button></div>`);
    const act = (sel, path, body, title, danger) => $$(sel, el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title, danger, confirmText: 'Confirm' })) { await api('POST', `/admin/payments/${b.dataset[sel.slice(6, -1)]}${path}`, body).catch(fail); go('/admin/payments'); } }));
    $$('[data-pf]', el).forEach((b) => (b.onclick = () => openPrivate(`/tenancy/payments/${b.dataset.pf}/proof`).catch(fail)));
    $$('[data-v]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Approve payment proof?', confirmText: 'Approve' })) { await api('POST', `/admin/payments/${b.dataset.v}/verify`, { action: 'approve' }).catch(fail); go('/admin/payments'); } }));
    $$('[data-x]', el).forEach((b) => (b.onclick = async () => { const why = await ask('Reject proof', 'Reason', 'Reject', true); if (why) { await api('POST', `/admin/payments/${b.dataset.x}/verify`, { action: 'reject', reason: why }).catch(fail); go('/admin/payments'); } }));
    $$('[data-po]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Mark as paid to owner?', confirmText: 'Paid' })) { await api('POST', `/admin/payments/${b.dataset.po}/paid-to-owner`, {}).catch(fail); go('/admin/payments'); } }));
    $$('[data-rf]', el).forEach((b) => (b.onclick = async () => { const why = await ask('Refund payment', 'Reason', 'Refund', true); if (why) { await api('POST', `/admin/payments/${b.dataset.rf}/refund`, { reason: why }).catch(fail); go('/admin/payments'); } }));
    $('#apd', el).onclick = async () => {
      const d = (await api('GET', '/admin/app-payment-details')); const p = d.details || d; const f = ['bank_name', 'account_name', 'account_number', 'momo_network', 'momo_number', 'momo_name'];
      const s = sheet(`<h3>App payment details</h3>${f.map((k) => `<div class="field"><label for="f_${k}">${k.replace(/_/g, ' ')}</label><input class="input" id="f_${k}" value="${esc(p[k] || '')}"></div>`).join('')}<div class="actions"><button class="btn ghost" id="x">Cancel</button><button class="btn primary" id="g">Save</button></div>`);
      $('#x', s.el).onclick = () => s.close(); $('#g', s.el).onclick = async () => { const b = {}; f.forEach((k) => (b[k] = val('f_' + k, s.el))); try { await api('PUT', '/admin/app-payment-details', b); s.close(); toast('Saved'); } catch (e) { toast(e.message); } };
    };
  });

  route('/admin/disputes', async () => {
    if (!seeded()) return render('<div class="empty">Only seeded admins can resolve disputes.</div>');
    const r = await api('GET', '/admin/disputes');
    const el = render(`<h1>Payment disputes</h1>${r.disputes.map((d) => `<div class="card"><b>Payment #${esc(d.payment_id)}</b><p>${esc(d.reason)}</p><button class="btn sm primary" data-d="${d.id}">Resolve</button></div>`).join('') || '<div class="empty">None.</div>'}`);
    $$('[data-d]', el).forEach((b) => (b.onclick = async () => { const o = await ask('Outcome', 'Decision', 'Resolve'); if (o) { await api('POST', `/admin/disputes/${b.dataset.d}/resolve`, { outcome: o }).catch(fail); go('/admin/disputes'); } }));
  });

  route('/admin/admins', async () => {
    const r = await api('GET', '/admin/admins');
    const el = render(`<h1>Admins</h1>${r.admins.map((a) => `<div class="card row"><div class="grow"><b>${esc(a.name)}</b> <span class="muted">@${esc(a.username)}</span></div>${pill(a.admin_tier)}${seeded() && a.admin_tier === 'promoted' ? `<button class="btn sm danger" data-dm="${a.id}">Demote</button>` : ''}</div>`).join('')}`);
    $$('[data-dm]', el).forEach((b) => (b.onclick = async () => { if (await confirmBox({ title: 'Demote this admin?', danger: true, confirmText: 'Demote' })) { await api('POST', `/admin/admins/${b.dataset.dm}/demote`, {}).catch(fail); go('/admin/admins'); } }));
  });

  route('/admin/settings', async () => {
    const r = await api('GET', '/admin/settings');
    const el = render(`<h1>Settings</h1><div class="card">${r.settings.map((s) => `<div class="field"><label for="s_${esc(s.key)}">${esc(s.key.replace(/_/g, ' '))}</label><input class="input" id="s_${esc(s.key)}" data-k="${esc(s.key)}" value="${esc(s.value)}"><div class="hint">${esc(s.description || '')}</div></div>`).join('')}<button class="btn primary block" id="ss">Save settings</button></div>`);
    $('#ss', el).onclick = async () => { if (!(await confirmBox({ title: 'Save settings?', html: '<p>Changes apply to new activity straight away.</p>', confirmText: 'Save' }))) return; const o = {}; $$('[data-k]', el).forEach((i) => (o[i.dataset.k] = i.value)); try { await api('PUT', '/admin/settings', { settings: o }); toast('Saved'); } catch (e) { fail(e); } };
  });

  route('/admin/audit', async () => {
    const r = await api('GET', '/admin/audit');
    render(`<h1>Audit log</h1>${tbl(['When', 'Who', 'Action', 'Target'], r.entries.map((e) => `<tr><td>${fmtDateTime(e.created_at)}</td><td>${esc(e.actor_name || '')}</td><td>${esc(e.action)}</td><td>${esc(e.target_type || '')} ${esc(e.target_id || '')}</td></tr>`))}`);
  });
})();
