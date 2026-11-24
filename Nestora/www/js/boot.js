/* Start-up: restore theme and session, then show the right screen. */
(function () {
  'use strict';
  const { api, setToken, cfg } = window.Core;
  try { const t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}

  function start() {
    window.Layout.mount();
    if (!location.hash || location.hash === '#') location.hash = S.user.home;
    else window.Core.navigate();
    window.Hooks.open.forEach((f) => f());
  }
  window.Boot = { start };

  async function init() {
    document.title = cfg.APP_NAME;
    const saved = (function () { try { return localStorage.getItem('token'); } catch (e) { return null; } })();
    if (!saved) return window.Auth.showLogin();
    setToken(saved);
    try { const r = await api('GET', '/auth/me'); window.Auth.enter(r.user); }
    catch (e) { if (e.status !== 401) { document.getElementById('auth').innerHTML = '<div class="box"><p class="err">' + (e.message || 'Cannot connect') + '</p><button class="btn primary block" onclick="location.reload()">Try again</button></div>'; document.getElementById('auth').classList.remove('hidden'); } }
  }
  if (window.cordova) document.addEventListener('deviceready', init, false); else document.addEventListener('DOMContentLoaded', init);
})();
