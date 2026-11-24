/* =====================================================================
   CORE: state, API client, dialogs, helpers, router
   ===================================================================== */
(function () {
  'use strict';
  const cfg = window.APP_CONFIG;
  const S = (window.S = { token: null, user: null, counts: {}, pinned: [], lookups: null, timers: {} });
  try { S.token = localStorage.getItem('token'); } catch (e) {}

  /* ---------- tiny helpers ---------- */
  const $ = (sel, el) => (el || document).querySelector(sel);
  const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ghs = (n) => 'GH₵' + Number(n || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtDate = (d) => (d ? new Date(String(d).replace(' ', 'T') + (String(d).length <= 10 ? 'T00:00:00Z' : 'Z')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }) : '');
  const fmtDateTime = (d) => (d ? new Date(String(d).replace(' ', 'T') + 'Z').toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
  const fmtLeft = (sec) => {
    sec = Math.max(0, Math.floor(sec));
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + String(m).padStart(2, '0') + 'm';
    return m + 'm ' + String(s).padStart(2, '0') + 's';
  };
  const level = (sec) => (sec < 6 * 3600 ? 'red' : sec < 24 * 3600 ? 'amber' : '');
  const stars = (r) => (r && r.count ? `<span class="stars">★ ${Number(r.average).toFixed(1)} <small>(${r.count})</small></span>` : '<span class="muted small">No ratings yet</span>');
  const pill = (text, kind) => `<span class="pill ${kind || ''}">${esc(String(text).replace(/_/g, ' '))}</span>`;
  const avatar = (u, cls) => `<img class="avatar ${cls || ''}" src="${esc((u && u.photo_url) || '')}" alt="" onerror="this.style.visibility='hidden'">`;
  const WEEKDAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const icon = (n) => ({
    home: '<svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8M5 10v10h14V10"/></svg>',
    users: '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-4 3-6 6.500-6s5.900 2 6.500 6M16 4.500a3.500 3.500 0 010 7M18 14c2.200.6 3.500 2.600 3.800 6"/></svg>',
    chat: '<svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></svg>',
    bell: '<svg viewBox="0 0 24 24"><path d="M6 17V11a6 6 0 0112 0v6l2 2H4zM10 21h4"/></svg>',
    cal: '<svg viewBox="0 0 24 24"><rect x="3.500" y="5" width="17" height="15" rx="2"/><path d="M3.500 10h17M8 3v4M16 3v4"/></svg>',
    key: '<svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/></svg>',
    money: '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.500"/></svg>',
    shield: '<svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.500 8-8 9-4.500-1-8-4-8-9V6z"/></svg>',
    flag: '<svg viewBox="0 0 24 24"><path d="M5 21V4h12l-2 4 2 4H5"/></svg>',
    gear: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg>',
    list: '<svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.500 6h.01M3.500 12h.01M3.500 18h.01"/></svg>',
    user: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.500 4-6.500 8-6.500s7 2 8 6.500"/></svg>',
    more: '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.500"/><circle cx="12" cy="12" r="1.500"/><circle cx="19" cy="12" r="1.500"/></svg>',
    logout: '<svg viewBox="0 0 24 24"><path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/></svg>',
    doc: '<svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg>',
  }[n] || '');

  /* ---------- API ---------- */
  function deviceId() {
    try { let d = localStorage.getItem('device'); if (!d) { d = 'd' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('device', d); } return d; } catch (e) { return 'unknown'; }
  }
  async function api(method, path, data, opt) {
    opt = opt || {};
    const headers = { 'X-Device-Id': deviceId() };
    if (S.token) headers['Authorization'] = 'Bearer ' + S.token;
    let url = cfg.API_BASE + path, body;
    if (method === 'GET' && data) {
      const p = new URLSearchParams();
      Object.keys(data).forEach((k) => { const v = data[k]; if (v !== null && v !== undefined && v !== '') p.append(k, Array.isArray(v) ? v.join(',') : v); });
      const qs = p.toString(); if (qs) url += (url.indexOf('?') < 0 ? '?' : '&') + qs;
    } else if (data instanceof FormData) body = data;
    else if (data) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(data); }
    let res;
    try { res = await fetch(url, { method, headers, body }); }
    catch (e) { throw { message: 'Cannot reach the server. Check your connection.', code: 'network', status: 0 }; }
    if (opt.blob && res.ok) return await res.blob();
    let json = null;
    try { json = (res.headers.get('content-type') || '').indexOf('json') >= 0 ? await res.json() : null; } catch (e) {}
    if (!res.ok) {
      const err = Object.assign({}, json || {}, { status: res.status, message: (json && json.error) || 'Something went wrong. Please try again.' });
      if (res.status === 401 && !opt.keepSession) { setToken(null); S.user = null; window.dispatchEvent(new Event('signedout')); }
      throw err;
    }
    return json;
  }
  function setToken(t) { S.token = t; try { t ? localStorage.setItem('token', t) : localStorage.removeItem('token'); } catch (e) {} }
  function fd(obj) {
    const f = new FormData();
    Object.keys(obj).forEach((k) => {
      const v = obj[k];
      if (v === null || v === undefined) return;
      if (Array.isArray(v)) v.forEach((x) => f.append(k + '[]', x, x && x.name));
      else if (v instanceof Blob) f.append(k, v, v.name || 'photo.jpg');
      else f.append(k, v);
    });
    return f;
  }
  /** Resize and re-encode photos on the phone before upload (about 1200px wide). */
  function compressImage(file, maxW) {
    maxW = maxW || 1200;
    return new Promise((resolve) => {
      if (!file || !/^image\//.test(file.type)) return resolve(file);
      const img = new Image(), url = URL.createObjectURL(file);
      img.onload = () => {
        const sc = Math.min(1, maxW / Math.max(img.width, img.height));
        const c = document.createElement('canvas'); c.width = Math.round(img.width * sc); c.height = Math.round(img.height * sc);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob((b) => { URL.revokeObjectURL(url); if (b) { b.name = 'photo.jpg'; resolve(b); } else resolve(file); }, 'image/jpeg', 0.82);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  /** Open a private file (PDF or image) that needs the login token. */
  async function openPrivate(path) {
    const blob = await api('GET', path, null, { blob: true });
    const url = URL.createObjectURL(blob);
    if (window.cordova && window.cordova.InAppBrowser) window.cordova.InAppBrowser.open(url, '_blank', 'location=no'); else window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 120000);
  }
  async function privateImageUrl(path) { return URL.createObjectURL(await api('GET', path, null, { blob: true })); }

  /* ---------- overlays ---------- */
  function toast(msg, bad) {
    const t = document.createElement('div'); t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = msg; t.setAttribute('role', 'status');
    $('#layer').appendChild(t); setTimeout(() => t.remove(), bad ? 5200 : 3200);
  }
  const fail = (e) => toast((e && e.message) || 'Something went wrong', true);
  function sheet(html, opts) {
    opts = opts || {};
    const bg = document.createElement('div'); bg.className = 'modal-bg';
    bg.innerHTML = `<div class="modal ${opts.wide ? 'wide' : ''}" role="dialog" aria-modal="true">${html}</div>`;
    const close = (v) => { bg.remove(); if (opts.onClose) opts.onClose(v); };
    bg.addEventListener('mousedown', (e) => { if (e.target === bg && !opts.sticky) close(); });
    $('#layer').appendChild(bg);
    const f = $('input,select,textarea,button', bg); if (f && !opts.noFocus) f.focus();
    return { el: bg.firstElementChild, close };
  }
  /** Confirmation dialog: says what will happen. danger = red button. typeToConfirm = must type that text. */
  function confirmBox(o) {
    return new Promise((resolve) => {
      let done = false;
      const s = sheet(`<h3>${esc(o.title || 'Are you sure?')}</h3><div>${o.html || '<p>' + esc(o.message || '') + '</p>'}</div>
        ${o.typeToConfirm ? `<div class="field mt-s"><label>Type <b>${esc(o.typeToConfirm)}</b> to confirm</label><input class="input" id="cbType" autocomplete="off"></div>` : ''}
        <div class="actions"><button class="btn ghost" id="cbNo">${esc(o.cancelText || 'Cancel')}</button><button class="btn ${o.danger ? 'danger' : 'primary'}" id="cbYes" ${o.typeToConfirm ? 'disabled' : ''}>${esc(o.confirmText || 'Confirm')}</button></div>`,
        { sticky: true, noFocus: true, onClose: () => { if (!done) { done = true; resolve(false); } } });
      const yes = $('#cbYes', s.el);
      if (o.typeToConfirm) $('#cbType', s.el).addEventListener('input', (e) => { yes.disabled = e.target.value !== o.typeToConfirm; });
      $('#cbNo', s.el).onclick = () => s.close();
      yes.onclick = () => { done = true; s.close(); resolve(true); };
    });
  }
  /** Run an async action with a disabled button. */
  async function busy(btn, fn) {
    if (btn) btn.disabled = true;
    try { return await fn(); } catch (e) { fail(e); } finally { if (btn) btn.disabled = false; }
  }
  const val = (id, root) => { const e = $('#' + id, root); return e ? e.value.trim() : ''; };

  /* ---------- router ---------- */
  const routes = [];
  function route(pattern, handler, meta) {
    const keys = [];
    const re = new RegExp('^' + pattern.replace(/:[a-z_]+/gi, (m) => { keys.push(m.slice(1)); return '([^/]+)'; }) + '$');
    routes.push({ re, keys, handler, meta: meta || {} });
  }
  function clearTimers() { Object.keys(S.timers).forEach((k) => { clearInterval(S.timers[k]); delete S.timers[k]; }); }
  function every(name, ms, fn) { if (S.timers[name]) clearInterval(S.timers[name]); S.timers[name] = setInterval(fn, ms); }
  const content = () => $('#content');
  function render(html) { const c = content(); c.innerHTML = '<div class="page">' + html + '</div>'; c.scrollTop = 0; return c.firstElementChild; }
  function go(path) { if (location.hash === '#' + path) navigate(); else location.hash = path; }
  async function navigate() {
    if (!S.user) return;
    clearTimers();
    const path = location.hash.replace(/^#/, '') || S.user.home || '/';
    const query = {};
    const q = path.split('?')[1]; if (q) new URLSearchParams(q).forEach((v, k) => (query[k] = v));
    const clean = path.split('?')[0];
    for (const r of routes) {
      const m = clean.match(r.re);
      if (m) {
        const params = {}; r.keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
        window.dispatchEvent(new CustomEvent('routechange', { detail: { path: clean, meta: r.meta } }));
        try { await r.handler(params, query); } catch (e) { render(`<div class="empty">${esc(e.message || 'Something went wrong')}<br><br><button class="btn" onclick="location.reload()">Try again</button></div>`); }
        return;
      }
    }
    render('<div class="empty">Page not found.</div>');
  }
  window.addEventListener('hashchange', navigate);

  window.Core = { $, $$, esc, ghs, fmtDate, fmtDateTime, fmtLeft, level, stars, pill, avatar, icon, WEEKDAYS, api, setToken, fd, compressImage, openPrivate, privateImageUrl,
    toast, fail, sheet, confirmBox, busy, val, route, render, go, navigate, every, clearTimers, cfg, deviceId };
})();
