<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/* ---------- time ---------- */

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** plus('+5 days') / plus('-1 hour', $from) */
function plus(string $modifier, ?string $from = null): string
{
    return (new DateTimeImmutable($from ?? 'now', new DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d H:i:s');
}

function ts(?string $dt): int
{
    return $dt ? (int)strtotime($dt . ' UTC') : 0;
}

/* ---------- HTTP in / out ---------- */

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Stop with an error. $code_name is a stable machine-readable code the app can react to. */
function fail(int $http, string $message, string $code_name = 'error', array $extra = []): never
{
    json_out(['error' => $message, 'code' => $code_name] + $extra, $http);
}

function input(): array
{
    static $in = null;
    if ($in !== null) {
        return $in;
    }
    $raw = file_get_contents('php://input');
    $json = null;
    if ($raw !== '' && $raw !== false && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false) {
        $json = json_decode($raw, true);
    }
    $in = is_array($json) ? array_merge($_GET, $json) : array_merge($_GET, $_POST);
    return $in;
}

function in_str(string $key, int $max = 255, bool $required = false): ?string
{
    $v = input()[$key] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) {
            fail(422, "$key is required", 'validation', ['field' => $key]);
        }
        return null;
    }
    if (!is_scalar($v)) {
        fail(422, "$key is invalid", 'validation', ['field' => $key]);
    }
    $v = trim((string)$v);
    if (mb_strlen($v) > $max) {
        fail(422, "$key is too long (max $max characters)", 'validation', ['field' => $key]);
    }
    return $v;
}

function in_int(string $key, bool $required = false): ?int
{
    $v = input()[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            fail(422, "$key is required", 'validation', ['field' => $key]);
        }
        return null;
    }
    if (!is_numeric($v) || (string)(int)$v !== (string)(int)$v) {
        fail(422, "$key must be a number", 'validation', ['field' => $key]);
    }
    return (int)$v;
}

function in_bool(string $key): bool
{
    $v = input()[$key] ?? false;
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
}

function in_enum(string $key, array $allowed, bool $required = true): ?string
{
    $v = in_str($key, 60, $required);
    if ($v !== null && !in_array($v, $allowed, true)) {
        fail(422, "$key must be one of: " . implode(', ', $allowed), 'validation', ['field' => $key]);
    }
    return $v;
}

function in_date(string $key, bool $required = true): ?string
{
    $v = in_str($key, 10, $required);
    if ($v === null) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $v) {
        fail(422, "$key must be a date (YYYY-MM-DD)", 'validation', ['field' => $key]);
    }
    return $v;
}

function today(): string
{
    return gmdate('Y-m-d');
}

function must_find(?array $r, string $what = 'Item'): array
{
    if (!$r) {
        fail(404, "$what not found", 'not_found');
    }
    return $r;
}

/* ---------- settings ---------- */

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT `key`, value FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache[$key] ?? $default;
}

/* ---------- validation ---------- */

/** Phone / link / email / long number detection: keeps contact details out of free text and chat. */
function contains_contact_info(string $s): bool
{
    if (preg_match('/(\+?\s?233|\b0)[\s.\-]?[25]\d[\s.\-]?\d{3}[\s.\-]?\d{4}\b/u', $s)) {
        return true;
    }
    if (preg_match('~https?://|www\.|\b[a-z0-9\-]+\.(com|net|org|gh|io|me|app|co|info|biz)\b~i', $s)) {
        return true;
    }
    if (preg_match('/\S+@\S+\.\S+/', $s)) {
        return true;
    }
    if (preg_match('/\d[\d\s.\-]{7,}\d/', $s)) {   // 9+ digits in a row: account / phone numbers
        return true;
    }
    return false;
}

function reject_contact_info(?string $text, string $field): void
{
    if ($text !== null && contains_contact_info($text)) {
        fail(422, 'Phone numbers, account numbers, emails and links are not allowed here.', 'contact_info_blocked', ['field' => $field]);
    }
}

function word_count(string $s): int
{
    return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

/** Ghana numbers in, "+233XXXXXXXXX" out. */
function normalize_phone(string $p): ?string
{
    $p = preg_replace('/[\s\-().]/', '', $p) ?? '';
    if (preg_match('/^0([25]\d{8})$/', $p, $m)) {
        return '+233' . $m[1];
    }
    if (preg_match('/^233([25]\d{8})$/', $p, $m)) {
        return '+233' . $m[1];
    }
    if (preg_match('/^\+233([25]\d{8})$/', $p, $m)) {
        return '+233' . $m[1];
    }
    return null;
}

function mask_account(?string $n): ?string
{
    if (!$n) {
        return null;
    }
    return str_repeat('*', max(0, strlen($n) - 4)) . substr($n, -4);
}

/* ---------- misc ---------- */

function photo_url(?string $rel): ?string
{
    if (!$rel) {
        return null;
    }
    return rtrim((string)cfg('PUBLIC_BASE_URL', ''), '/') . '/uploads/' . ltrim($rel, '/');
}

function money(float $v): float
{
    return round($v, 2);
}

function plain_log(string $file, string $line): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . $file, '[' . now() . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function rate_limit(string $key, int $max, int $window_seconds): void
{
    $r = row('SELECT hits, window_start FROM rate_limits WHERE key_name = ?', [$key]);
    if (!$r || ts($r['window_start']) + $window_seconds < time()) {
        q('REPLACE INTO rate_limits (key_name, hits, window_start) VALUES (?, 1, ?)', [$key, now()]);
        return;
    }
    if ((int)$r['hits'] >= $max) {
        fail(429, 'Too many attempts. Please wait a little and try again.', 'rate_limited');
    }
    q('UPDATE rate_limits SET hits = hits + 1 WHERE key_name = ?', [$key]);
}

function audit(?array $actor, string $action, ?string $target_type = null, ?int $target_id = null, ?string $reason = null, $old = null, $new = null): void
{
    q(
        'INSERT INTO audit_log (actor_id, actor_tier, action, target_type, target_id, reason, old_values, new_values) VALUES (?,?,?,?,?,?,?,?)',
        [
            $actor['id'] ?? null,
            $actor['admin_tier'] ?? 'system',
            $action,
            $target_type,
            $target_id,
            $reason,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
        ]
    );
}

/* ---------- routing ---------- */

function route(string $method, string $pattern, callable $handler): void
{
    $GLOBALS['__routes'][] = [$method, $pattern, $handler];
}

function dispatch(string $method, string $path): void
{
    foreach ($GLOBALS['__routes'] ?? [] as [$m, $pattern, $handler]) {
        if ($m !== $method) {
            continue;
        }
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (preg_match($regex, $path, $mm)) {
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($params);
            return;
        }
    }
    fail(404, 'Route not found', 'not_found');
}

function pid(array $p, string $key = 'id'): int
{
    return (int)($p[$key] ?? 0);
}
