<?php
declare(strict_types=1);

/* =====================================================================
   Signup, email verification, login, profile photo, password, phone (payments only)
   ===================================================================== */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function check_name(string $name): void
{
    if (!preg_match('/\p{L}/u', $name) || contains_contact_info($name)) {
        fail(422, 'Please enter your real name (no links, numbers or emoji-only names).', 'validation', ['field' => 'name']);
    }
}

function check_username(string $u): void
{
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $u)) {
        fail(422, 'Username: 3 to 20 letters, numbers or underscores.', 'validation', ['field' => 'username']);
    }
    if (val('SELECT 1 FROM reserved_usernames WHERE username = ?', [$u])) {
        fail(422, 'That username is reserved.', 'username_taken', ['field' => 'username']);
    }
}

function check_email(string $e): void
{
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Enter a valid email address.', 'validation', ['field' => 'email']);
    }
    $domain = strtolower(substr(strrchr($e, '@'), 1));
    if (val('SELECT 1 FROM blocked_email_domains WHERE domain = ?', [$domain])) {
        fail(422, 'Please use a permanent email address.', 'validation', ['field' => 'email']);
    }
}

function check_password(string $p): void
{
    if (strlen($p) < 8) {
        fail(422, 'Password must be at least 8 characters.', 'validation', ['field' => 'password']);
    }
}

/** Sends a code to $email (defaults to the account email). 60s gap and a daily cap. */
function send_email_code(array $u, ?string $email = null, string $target = ''): void
{
    $email = $email ?? $u['email'];
    $target = $target !== '' ? $target : $email;
    $last = val("SELECT MAX(created_at) FROM verification_codes WHERE user_id = ? AND channel = 'email'", [$u['id']]);
    if ($last && ts($last) > time() - 60) {
        fail(429, 'Please wait 60 seconds before asking for another code.', 'rate_limited');
    }
    if ((int)val("SELECT COUNT(*) FROM verification_codes WHERE user_id = ? AND channel = 'email' AND created_at > ?", [$u['id'], plus('-1 day')]) >= 10) {
        fail(429, 'Daily code limit reached. Try again tomorrow.', 'rate_limited');
    }
    $code = (string)random_int(100000, 999999);
    q("UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND channel = 'email' AND used_at IS NULL", [now(), $u['id']]);
    q(
        "INSERT INTO verification_codes (user_id, channel, target, code_hash, expires_at) VALUES (?, 'email', ?, ?, ?)",
        [$u['id'], $target, hash('sha256', $code), plus('+' . (int)setting('verification_code_minutes', '15') . ' minutes')]
    );
    [$subject, $body] = email_code_message($code);
    send_mail($email, $subject, $body);
}

/** Checks a code; returns the code row. Locks after 5 wrong tries. */
function consume_code(int $user_id, string $channel, string $code, ?string $target = null): array
{
    $c = row(
        'SELECT * FROM verification_codes WHERE user_id = ? AND channel = ? AND used_at IS NULL AND expires_at > ? ' . ($target !== null ? 'AND target = ? ' : '') . 'ORDER BY id DESC LIMIT 1',
        $target !== null ? [$user_id, $channel, now(), $target] : [$user_id, $channel, now()]
    );
    if (!$c) {
        fail(422, 'That code has expired. Ask for a new one.', 'code_expired');
    }
    if ((int)$c['attempts'] >= 5) {
        fail(429, 'Too many wrong tries. Ask for a new code.', 'code_locked');
    }
    if (!hash_equals($c['code_hash'], hash('sha256', trim($code)))) {
        q('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [$c['id']]);
        fail(422, 'That code is not right.', 'code_wrong');
    }
    q('UPDATE verification_codes SET used_at = ? WHERE id = ?', [now(), $c['id']]);
    return $c;
}

route('GET', '/auth/username-available', function () {
    rate_limit('uname:' . client_ip(), 60, 60);
    $u = in_str('username', 20, true);
    $ok = preg_match('/^[A-Za-z0-9_]{3,20}$/', $u)
        && !val('SELECT 1 FROM reserved_usernames WHERE username = ?', [$u])
        && !val('SELECT 1 FROM users WHERE username = ?', [$u]);
    json_out(['available' => (bool)$ok]);
});

route('POST', '/auth/register', function () {
    rate_limit('register:' . client_ip(), 10, 3600);
    $role     = in_enum('role', ['owner', 'agent', 'user']);          // admins are seeded, never chosen
    $name     = in_str('name', 100, true);
    $username = in_str('username', 20, true);
    $email    = strtolower((string)in_str('email', 190, true));
    $password = (string)(input()['password'] ?? '');
    if (!in_bool('accept_terms')) {
        fail(422, 'You must accept the Terms and Conditions.', 'terms_required');
    }
    check_name($name);
    check_username($username);
    check_email($email);
    check_password($password);
    if (val('SELECT 1 FROM users WHERE username = ?', [$username])) {
        fail(409, 'That username is taken.', 'username_taken', ['field' => 'username']);
    }
    if (val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        fail(409, 'An account with that email already exists.', 'email_taken', ['field' => 'email']);
    }
    q(
        "INSERT INTO users (role, name, username, email, password_hash, status, terms_version, terms_accepted_at) VALUES (?,?,?,?,?, 'unverified', ?, ?)",
        [$role, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT), in_str('terms_version', 20) ?? '1.0', now()]
    );
    $id = insert_id();
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    send_email_code($u);
    json_out(['token' => issue_token($id, in_str('device', 100)), 'user' => me_payload($u)], 201);
});

route('POST', '/auth/resend-email-code', function () {
    $u = require_user(false);
    if ($u['email_verified_at']) {
        fail(409, 'Your email is already verified.', 'already_verified');
    }
    send_email_code($u);
    json_out(['sent' => true]);
});

/** While unverified, the user may correct a mistyped email address. */
route('POST', '/auth/change-pending-email', function () {
    $u = require_user(false);
    if ($u['email_verified_at']) {
        fail(409, 'Your email is already verified.', 'already_verified');
    }
    $email = strtolower((string)in_str('email', 190, true));
    check_email($email);
    if (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
        fail(409, 'An account with that email already exists.', 'email_taken');
    }
    q('UPDATE users SET email = ? WHERE id = ?', [$email, $u['id']]);
    $u['email'] = $email;
    send_email_code($u);
    json_out(['sent' => true, 'email' => $email]);
});

route('POST', '/auth/verify-email', function () {
    $u = require_user(false);
    $c = consume_code((int)$u['id'], 'email', (string)in_str('code', 10, true));
    if ($c['target'] !== $u['email']) {                                   // verified a changed address
        if (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$c['target'], $u['id']])) {
            fail(409, 'That email is now used by another account.', 'email_taken');
        }
        q('UPDATE users SET email = ? WHERE id = ?', [$c['target'], $u['id']]);
    }
    q('UPDATE users SET email_verified_at = ? WHERE id = ?', [now(), $u['id']]);
    try_activate((int)$u['id']);
    json_out(['user' => me_payload(row('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
});

route('POST', '/auth/profile-photo', function () {
    $u = require_user(false);
    $f = uploaded_files('photo')[0] ?? null;
    if (!$f) {
        fail(422, 'Choose a photo.', 'validation', ['field' => 'photo']);
    }
    $rel = save_image($f, 'profiles', false, 800);
    delete_file($u['profile_photo']);
    q('UPDATE users SET profile_photo = ? WHERE id = ?', [$rel, $u['id']]);
    try_activate((int)$u['id']);
    json_out(['user' => me_payload(row('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
});

route('POST', '/auth/login', function () {
    $login = in_str('login', 190, true);
    $pass  = (string)(input()['password'] ?? '');
    rate_limit('login:' . client_ip() . ':' . strtolower($login), 10, 900);
    $u = row('SELECT * FROM users WHERE username = ? OR email = ?', [$login, strtolower($login)]);
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        fail(401, 'Wrong username/email or password.', 'bad_credentials');
    }
    if ($u['status'] === 'banned') {
        fail(403, 'This account has been banned. You can appeal the ban.', 'account_banned', ['can_appeal' => true]);
    }
    json_out(['token' => issue_token((int)$u['id'], in_str('device', 100)), 'user' => me_payload($u)]);
});

route('POST', '/auth/logout', function () {
    $t = bearer_token();
    if ($t) {
        q('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $t)]);
    }
    json_out(['ok' => true]);
});

route('GET', '/auth/me', function () {
    $u = require_user(false);
    json_out(['user' => me_payload($u)]);
});

route('PUT', '/auth/me', function () {                               // name change, at most once every 30 days
    $u = require_user();
    $name = in_str('name', 100, true);
    check_name($name);
    if ($name !== $u['name']) {
        if ($u['name_changed_at'] && ts($u['name_changed_at']) > time() - 30 * 86400) {
            fail(429, 'You can change your name once every 30 days.', 'name_change_limit');
        }
        q('UPDATE users SET name = ?, name_changed_at = ? WHERE id = ?', [$name, now(), $u['id']]);
    }
    json_out(['user' => me_payload(row('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
});

route('POST', '/auth/change-password', function () {
    $u = require_user(false);
    $cur = (string)(input()['current_password'] ?? '');
    $new = (string)(input()['new_password'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        fail(403, 'Your current password is wrong.', 'bad_credentials');
    }
    check_password($new);
    q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    $keep = hash('sha256', (string)bearer_token());
    q('DELETE FROM api_tokens WHERE user_id = ? AND token_hash <> ?', [$u['id'], $keep]);
    json_out(['ok' => true]);
});

route('POST', '/auth/forgot-password', function () {
    rate_limit('forgot:' . client_ip(), 5, 3600);
    $email = strtolower((string)in_str('email', 190, true));
    $u = row("SELECT * FROM users WHERE email = ? AND email_verified_at IS NOT NULL AND status <> 'banned'", [$email]);
    if ($u) {
        try {
            send_email_code($u, $u['email'], 'reset');
        } catch (Throwable $e) {
            // never reveal whether the email exists
        }
    }
    json_out(['sent' => true]);
});

route('POST', '/auth/reset-password', function () {
    rate_limit('reset:' . client_ip(), 10, 3600);
    $email = strtolower((string)in_str('email', 190, true));
    $u = must_find(row('SELECT * FROM users WHERE email = ?', [$email]), 'Account');
    consume_code((int)$u['id'], 'email', (string)in_str('code', 10, true), 'reset');
    $new = (string)(input()['new_password'] ?? '');
    check_password($new);
    q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    q('DELETE FROM api_tokens WHERE user_id = ?', [$u['id']]);
    json_out(['ok' => true]);
});

/** Banned users can appeal with their credentials. A different admin reviews it. */
route('POST', '/auth/appeal', function () {
    rate_limit('appeal:' . client_ip(), 5, 3600);
    $u = row('SELECT * FROM users WHERE username = ? OR email = ?', [in_str('login', 190, true), strtolower((string)in_str('login', 190))]);
    if (!$u || !password_verify((string)(input()['password'] ?? ''), $u['password_hash']) || $u['status'] !== 'banned') {
        fail(401, 'Could not verify this banned account.', 'bad_credentials');
    }
    $ban = row("SELECT * FROM bans WHERE target_type = 'user' AND target_id = ? AND lifted_at IS NULL ORDER BY id DESC LIMIT 1", [$u['id']]);
    if (!$ban) {
        fail(404, 'No active ban found.', 'not_found');
    }
    if (val("SELECT 1 FROM ban_appeals WHERE ban_id = ? AND status = 'open'", [$ban['id']])) {
        fail(409, 'You already have an appeal waiting.', 'duplicate');
    }
    q('INSERT INTO ban_appeals (ban_id, message) VALUES (?,?)', [$ban['id'], in_str('message', 1000, true)]);
    notify_admins('ban_appeal', 'New ban appeal', $u['name'] . ' (@' . $u['username'] . ')');
    json_out(['ok' => true, 'reason' => $ban['reason']], 201);
});

/* ---------- phone verification: only needed at payment steps ---------- */

route('POST', '/phone/send-code', function () {
    $u = require_user();
    $phone = normalize_phone((string)in_str('phone', 20, true));
    if (!$phone) {
        fail(422, 'Enter a valid Ghana mobile number.', 'validation', ['field' => 'phone']);
    }
    if (val('SELECT 1 FROM users WHERE phone = ? AND phone_verified_at IS NOT NULL AND id <> ?', [$phone, $u['id']])) {
        fail(409, 'That number is already used by another account.', 'phone_taken');
    }
    if ($u['phone_verified_at'] && $u['phone'] !== $phone) {            // changing a verified number
        $pw = (string)(input()['password'] ?? '');
        if (!password_verify($pw, $u['password_hash'])) {
            fail(403, 'Enter your password to change your number.', 'bad_credentials');
        }
        if ((int)val("SELECT COUNT(*) FROM tenancy_processes WHERE user_id = ? AND status IN ('started','agreement_read','method_chosen','proof_uploaded')", [$u['id']])) {
            fail(409, 'You cannot change your number while a payment is in progress.', 'payment_pending');
        }
    }
    if ((input()['via'] ?? 'sms') === 'email') {                          // fallback so people are never locked out
        $code = (string)random_int(100000, 999999);
        q("UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND channel = 'phone' AND used_at IS NULL", [now(), $u['id']]);
        q("INSERT INTO verification_codes (user_id, channel, target, code_hash, expires_at) VALUES (?, 'phone', ?, ?, ?)",
            [$u['id'], $phone, hash('sha256', $code), plus('+10 minutes')]);
        send_mail($u['email'], 'Your phone verification code', "Your phone verification code is $code. It expires in 10 minutes.");
    } else {
        send_phone_code($u, $phone);
    }
    json_out(['sent' => true, 'phone' => $phone]);
});

route('POST', '/phone/verify', function () {
    $u = require_user();
    $c = consume_code((int)$u['id'], 'phone', (string)in_str('code', 10, true));
    if (val('SELECT 1 FROM users WHERE phone = ? AND phone_verified_at IS NOT NULL AND id <> ?', [$c['target'], $u['id']])) {
        fail(409, 'That number is already used by another account.', 'phone_taken');
    }
    q('UPDATE users SET phone = ?, phone_verified_at = ? WHERE id = ?', [$c['target'], now(), $u['id']]);
    json_out(['user' => me_payload(row('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
});

route('POST', '/devices', function () {
    $u = require_user();
    q(
        'INSERT INTO device_tokens (user_id, token, platform) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
        [$u['id'], in_str('token', 255, true), in_enum('platform', ['android', 'ios', 'web'])]
    );
    json_out(['ok' => true]);
});
