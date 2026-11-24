<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/* ---------- tokens ---------- */

function issue_token(int $user_id, ?string $device = null): string
{
    $token = bin2hex(random_bytes(32));
    q(
        'INSERT INTO api_tokens (user_id, token_hash, device, expires_at) VALUES (?,?,?,?)',
        [$user_id, hash('sha256', $token), $device, plus('+30 days')]
    );
    return $token;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!$h && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    if ($h && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
        return $m[1];
    }
    return null;
}

/* ---------- current user ---------- */

function current_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $t = bearer_token();
    if (!$t) {
        return null;
    }
    $user = row(
        'SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ?',
        [hash('sha256', $t), now()]
    );
    if ($user) {
        q('UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?', [now(), hash('sha256', $t)]);
        q('UPDATE users SET last_seen_at = ? WHERE id = ?', [now(), $user['id']]);
    }
    return $user;
}

/**
 * $must_be_active = false is used by signup-completion endpoints
 * (verify email, upload profile photo, resend code) that unverified accounts need.
 */
function require_user(bool $must_be_active = true): array
{
    $u = current_user();
    if (!$u) {
        fail(401, 'Please log in.', 'unauthenticated');
    }
    if ($u['status'] === 'banned') {
        fail(403, 'This account has been banned.', 'account_banned');
    }
    if ($must_be_active && $u['status'] !== 'active') {
        fail(403, 'Finish signup first: verify your email and add a profile photo.', 'account_inactive');
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_user();
    if (!in_array($u['role'], $roles, true)) {
        fail(403, 'Your account type cannot do this.', 'forbidden_role');
    }
    return $u;
}

function require_admin(): array
{
    return require_role('admin');
}

function is_seeded_admin(array $u): bool
{
    return $u['role'] === 'admin' && in_array($u['admin_tier'], ['root', 'seeded'], true);
}

/** Money and system settings: root and seeded admins only. */
function require_seeded(): array
{
    $u = require_admin();
    if (!is_seeded_admin($u)) {
        fail(403, 'Only seeded admins can do this.', 'forbidden_tier');
    }
    return $u;
}

function require_root(): array
{
    $u = require_admin();
    if ($u['admin_tier'] !== 'root') {
        fail(403, 'Only the root admin can do this.', 'forbidden_tier');
    }
    return $u;
}

/* ---------- shaping user data for the API ---------- */

function user_card(?array $u): ?array
{
    if (!$u) {
        return null;
    }
    return [
        'id'        => (int)$u['id'],
        'name'      => $u['name'],
        'username'  => $u['username'],
        'role'      => $u['role'],
        'photo_url' => photo_url($u['profile_photo'] ?? null),
    ];
}

function user_card_by_id(?int $id): ?array
{
    return $id ? user_card(row('SELECT id, name, username, role, profile_photo FROM users WHERE id = ?', [$id])) : null;
}

function me_payload(array $u): array
{
    $tenant = (int)val(
        "SELECT COUNT(*) FROM tenancies WHERE user_id = ? AND status IN ('reserved','active')",
        [$u['id']]
    ) > 0;
    return [
        'id'                => (int)$u['id'],
        'role'              => $u['role'],
        'admin_tier'        => $u['admin_tier'],
        'name'              => $u['name'],
        'username'          => $u['username'],
        'email'             => $u['email'],
        'email_verified'    => (bool)$u['email_verified_at'],
        'phone_verified'    => (bool)$u['phone_verified_at'],
        'phone'             => $u['phone'] ? mask_account($u['phone']) : null,
        'photo_url'         => photo_url($u['profile_photo']),
        'status'            => $u['status'],
        'must_change_password' => (bool)$u['must_change_password'],
        'is_tenant'         => $tenant,
    ];
}

/** An account becomes active only when: email verified + profile photo + terms accepted. */
function try_activate(int $user_id): void
{
    $u = row('SELECT * FROM users WHERE id = ?', [$user_id]);
    if ($u && $u['status'] === 'unverified' && $u['email_verified_at'] && $u['profile_photo'] && $u['terms_accepted_at']) {
        q("UPDATE users SET status = 'active' WHERE id = ?", [$user_id]);
    }
}
