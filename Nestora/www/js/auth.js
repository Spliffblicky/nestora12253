/* =====================================================================
   AUTH: login, signup (role, name, unique username, email, password, terms), finish setup
   (email code + compulsory profile photo), forgot password, ban appeal, forced password change
   ===================================================================== */
(function () {
  'use strict';
  const { $, esc, api, fd, compressImage, toast, fail, confirmBox, busy, val, cfg } = window.Core;
  const host = () => $('#auth');
  const HOME = { owner: '/owner/buildings', agent: '/browse', user: '/browse', admin: '/admin' };

  function show(html) {
    $('#shell').classList.add('hidden'); host().classList.remove('hidden');
    host().innerHTML = `<div class="box"><div class="logo">${esc(cfg.APP_NAME)}</div>${html}</div>`;
    window.scrollTo(0, 0);
  }

  /* ---------- after any successful login / refresh ---------- */
  function enter(user) {
    S.user = Object.assign(user, { home: HOME[user.role] || '/browse' });
    if (user.status !== 'active') return showSetup();
    if (user.must_change_password) return showForcePassword();
    window.Boot.start();
  }

  /* ---------- login ---------- */
  function showLogin(msg) {
    show(`<p class="muted">Find, view and rent buildings in Ghana.</p>
      <div class="card"><h2>Log in</h2>
        <div class="field"><label for="lg">Username or email</label><input class="input" id="lg" autocomplete="username" autocapitalize="none"></div>
        <div class="field"><label for="pw">Password</label><input class="input" id="pw" type="password" autocomplete="current-password"></div>
        <div class="err" id="lgErr">${esc(msg || '')}</div>
        <button class="btn primary block" id="lgGo">Log in</button>
        <p class="center mt-s"><a href="#" id="lgForgot">Forgot password?</a></p>
      </div>
      <p class="center mt">New here? <a href="#" id="lgReg"><b>Create an account</b></a></p>`);
    const go = () => busy($('#lgGo'), async () => {
      try {
        const r = await api('POST', '/auth/login', { login: val('lg'), password: $('#pw').value, device: navigator.userAgent.slice(0, 90) });
        window.Core.setToken(r.token); enter(r.user);
      } catch (e) {
        if (e.code === 'account_banned') return showAppeal(val('lg'));
        $('#lgErr').textContent = e.message;
      }
    });
    $('#lgGo').onclick = go; $('#pw').onkeydown = (e) => { if (e.key === 'Enter') go(); };
    $('#lgForgot').onclick = (e) => { e.preventDefault(); showForgot(); };
    $('#lgReg').onclick = (e) => { e.preventDefault(); showRegister(); };
  }

  /* ---------- signup ---------- */
  function showRegister() {
    let role = null, checkT = null;
    show(`<div class="card"><h2>Create your account</h2>
      <div class="lbl mb">I am joining as</div>
      <div class="rolecards" id="roles">
        <button class="rolecard" data-r="user"><b>User</b><span class="small muted">Find a place, book viewings and rent.</span></button>
        <button class="rolecard" data-r="owner"><b>Owner</b><span class="small muted">List my buildings and rooms, manage tenants.</span></button>
        <button class="rolecard" data-r="agent"><b>Agent</b><span class="small muted">Show buildings I am approved for to renters.</span></button>
      </div>
      <div class="field"><label for="rn">Full name</label><input class="input" id="rn" autocomplete="name" maxlength="100"></div>
      <div class="field"><label for="ru">Username</label><input class="input" id="ru" autocapitalize="none" autocomplete="username" maxlength="20"><div class="hint" id="ruH">3 to 20 letters, numbers or underscores. It must be unique.</div></div>
      <div class="field"><label for="re">Email</label><input class="input" id="re" type="email" autocomplete="email"></div>
      <div class="field"><label for="rp">Password</label><input class="input" id="rp" type="password" autocomplete="new-password"><div class="hint">At least 8 characters.</div></div>
      <label class="check mb"><input type="checkbox" id="rt"><span>I have read and agree to the <a href="#" id="rtLink">Terms and Conditions</a>.</span></label>
      <div class="err" id="rErr"></div>
      <button class="btn primary block" id="rGo">Continue</button>
    </div><p class="center mt">Already have an account? <a href="#" id="rBack"><b>Log in</b></a></p>`);
    document.querySelectorAll('#roles .rolecard').forEach((b) => (b.onclick = () => { role = b.dataset.r; document.querySelectorAll('#roles .rolecard').forEach((x) => x.classList.toggle('on', x === b)); }));
    $('#rtLink').onclick = (e) => { e.preventDefault(); window.Common.showTerms(); };
    $('#rBack').onclick = (e) => { e.preventDefault(); showLogin(); };
    $('#ru').oninput = () => {                                  // live availability check
      const u = val('ru'), h = $('#ruH'); clearTimeout(checkT);
      if (!/^[A-Za-z0-9_]{3,20}$/.test(u)) { h.className = 'hint'; h.textContent = '3 to 20 letters, numbers or underscores. It must be unique.'; return; }
      h.className = 'hint'; h.textContent = 'Checking...';
      checkT = setTimeout(async () => { try { const r = await api('GET', '/auth/username-available', { username: u }); h.className = r.available ? 'okmsg' : 'err'; h.textContent = r.available ? 'Username available' : 'Taken or reserved'; } catch (e) { h.textContent = ''; } }, 350);
    };
    $('#rGo').onclick = async () => {
      const err = $('#rErr'); err.textContent = '';
      if (!role) return (err.textContent = 'Choose how you are joining.');
      if (!val('rn') || !val('ru') || !val('re') || $('#rp').value.length < 8) return (err.textContent = 'Fill in every field. Password needs 8+ characters.');
      if (!$('#rt').checked) return (err.textContent = 'Please accept the Terms and Conditions.');
      const ok = await confirmBox({ title: 'Create your account?', html: `<p>You are joining as <b>${esc(role)}</b> with username <b>@${esc(val('ru'))}</b>. Your username is used in payment references, so it cannot be changed later.</p>`, confirmText: 'Create account' });
      if (!ok) return;
      busy($('#rGo'), async () => {
        try {
          const r = await api('POST', '/auth/register', { role, name: val('rn'), username: val('ru'), email: val('re'), password: $('#rp').value, accept_terms: true, terms_version: '1.0', device: navigator.userAgent.slice(0, 90) });
          window.Core.setToken(r.token); enter(r.user);
        } catch (e) { err.textContent = e.message; }
      });
    };
  }

  /* ---------- finish setup: verify email, then the compulsory profile photo ---------- */
  function showSetup() {
    const u = S.user;
    if (!u.email_verified) return showVerifyEmail();
    if (!u.photo_url) return showPhoto();
    api('GET', '/auth/me').then((r) => enter(r.user)).catch(fail);
  }

  function showVerifyEmail() {
    const u = S.user; let wait = 0, tm = null;
    show(`<div class="steps"><i class="now"></i><i></i></div>
      <div class="card"><h2>Verify your email</h2>
        <p>We sent a 6-digit code to <b id="vEmail">${esc(u.email)}</b>. It expires in 15 minutes.</p>
        <div class="field"><label for="vc">Code</label><input class="input" id="vc" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div>
        <div class="err" id="vErr"></div>
        <button class="btn primary block" id="vGo">Verify</button>
        <div class="row mt wrap"><button class="btn sm ghost" id="vRe">Resend code</button><button class="btn sm ghost" id="vChange">Wrong email?</button><span class="spacer"></span><button class="btn sm ghost" id="vOut">Log out</button></div>
      </div>`);
    const tick = () => { $('#vRe').disabled = wait > 0; $('#vRe').textContent = wait > 0 ? `Resend in ${wait}s` : 'Resend code'; if (wait > 0) { wait--; tm = setTimeout(tick, 1000); } };
    wait = 30; tick();
    $('#vGo').onclick = () => busy($('#vGo'), async () => {
      try { const r = await api('POST', '/auth/verify-email', { code: val('vc') }); clearTimeout(tm); enter(Object.assign({}, S.user, r.user)); } catch (e) { $('#vErr').textContent = e.message; }
    });
    $('#vRe').onclick = () => busy($('#vRe'), async () => { try { await api('POST', '/auth/resend-email-code', {}); toast('Code sent'); wait = 60; tick(); } catch (e) { $('#vErr').textContent = e.message; } });
    $('#vChange').onclick = async () => {
      const s = window.Core.sheet(`<h3>Correct your email</h3><div class="field"><label for="ne">New email</label><input class="input" id="ne" type="email"></div><div class="actions"><button class="btn ghost" id="neNo">Cancel</button><button class="btn primary" id="neGo">Send code</button></div>`);
      $('#neNo', s.el).onclick = () => s.close();
      $('#neGo', s.el).onclick = () => busy($('#neGo', s.el), async () => { const r = await api('POST', '/auth/change-pending-email', { email: val('ne', s.el) }); S.user.email = r.email; $('#vEmail').textContent = r.email; s.close(); toast('Code sent to ' + r.email); });
    };
    $('#vOut').onclick = async () => { try { await api('POST', '/auth/logout', {}); } catch (e) {} window.Core.setToken(null); location.reload(); };
  }

  function showPhoto() {
    let blob = null;
    show(`<div class="steps"><i class="done"></i><i class="now"></i></div>
      <div class="card"><h2>Add your profile photo</h2>
        <p class="muted">A clear photo of your face is required for everyone: owners, agents and users.</p>
        <div class="photo-pick mb"><img id="pPrev" alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><div class="col gap-s">
          <label class="btn" for="pCam">Take a photo</label><input class="sr" id="pCam" type="file" accept="image/*" capture="user">
          <label class="btn ghost" for="pGal">Choose from gallery</label><input class="sr" id="pGal" type="file" accept="image/*"></div></div>
        <div class="err" id="pErr"></div>
        <button class="btn primary block" id="pGo" disabled>Finish</button>
      </div>`);
    const pick = async (e) => { const f = e.target.files[0]; if (!f) return; blob = await compressImage(f, 800); $('#pPrev').src = URL.createObjectURL(blob); $('#pGo').disabled = false; };
    $('#pCam').onchange = pick; $('#pGal').onchange = pick;
    $('#pGo').onclick = () => busy($('#pGo'), async () => {
      try { const r = await api('POST', '/auth/profile-photo', fd({ photo: blob })); enter(Object.assign({}, S.user, r.user)); } catch (e) { $('#pErr').textContent = e.message; }
    });
  }

  /* ---------- forgot password ---------- */
  function showForgot() {
    show(`<div class="card"><h2>Reset password</h2>
      <div id="fStep1"><div class="field"><label for="fe">Your email</label><input class="input" id="fe" type="email"></div><button class="btn primary block" id="fSend">Send code</button></div>
      <div id="fStep2" class="hidden"><p>If that email has an account, a code is on its way.</p>
        <div class="field"><label for="fc">Code</label><input class="input" id="fc" inputmode="numeric" maxlength="6"></div>
        <div class="field"><label for="fp">New password</label><input class="input" id="fp" type="password" autocomplete="new-password"></div>
        <div class="err" id="fErr"></div><button class="btn primary block" id="fGo">Set new password</button></div>
      <p class="center mt-s"><a href="#" id="fBack">Back to log in</a></p></div>`);
    $('#fSend').onclick = () => busy($('#fSend'), async () => { await api('POST', '/auth/forgot-password', { email: val('fe') }); $('#fStep1').classList.add('hidden'); $('#fStep2').classList.remove('hidden'); });
    $('#fGo').onclick = () => busy($('#fGo'), async () => { try { await api('POST', '/auth/reset-password', { email: val('fe'), code: val('fc'), new_password: $('#fp').value }); showLogin('Password changed. Log in with your new password.'); } catch (e) { $('#fErr').textContent = e.message; } });
    $('#fBack').onclick = (e) => { e.preventDefault(); showLogin(); };
  }

  /* ---------- banned: appeal (reviewed by a different admin than the one who banned) ---------- */
  function showAppeal(login) {
    show(`<div class="card"><h2>Account banned</h2><p>This account was banned. You can ask for a review. A different admin will look at it.</p>
      <div class="field"><label for="al">Username or email</label><input class="input" id="al" value="${esc(login || '')}"></div>
      <div class="field"><label for="ap">Password</label><input class="input" id="ap" type="password"></div>
      <div class="field"><label for="am">Your message</label><textarea id="am" maxlength="1000"></textarea></div>
      <div class="err" id="aErr"></div><button class="btn primary block" id="aGo">Send appeal</button><p class="center mt-s"><a href="#" id="aBack">Back</a></p></div>`);
    $('#aGo').onclick = () => busy($('#aGo'), async () => { try { const r = await api('POST', '/auth/appeal', { login: val('al'), password: $('#ap').value, message: val('am') }); showLogin('Appeal sent. Ban reason: ' + r.reason); } catch (e) { $('#aErr').textContent = e.message; } });
    $('#aBack').onclick = (e) => { e.preventDefault(); showLogin(); };
  }

  /* ---------- seeded admins must set their own password at first login ---------- */
  function showForcePassword() {
    show(`<div class="card"><h2>Set a new password</h2><p class="muted">For security, choose your own password before you continue.</p>
      <div class="field"><label for="cp">Current password</label><input class="input" id="cp" type="password"></div>
      <div class="field"><label for="np">New password</label><input class="input" id="np" type="password" autocomplete="new-password"></div>
      <div class="err" id="cErr"></div><button class="btn primary block" id="cGo">Save and continue</button></div>`);
    $('#cGo').onclick = () => busy($('#cGo'), async () => { try { await api('POST', '/auth/change-password', { current_password: $('#cp').value, new_password: $('#np').value }); S.user.must_change_password = false; enter(S.user); } catch (e) { $('#cErr').textContent = e.message; } });
  }

  window.addEventListener('signedout', () => showLogin('Please log in again.'));
  window.Auth = { showLogin, showRegister, enter, HOME };
})();
