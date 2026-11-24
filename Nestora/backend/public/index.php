<?php
declare(strict_types=1);

/**
 * Front controller: every API request lands here (see .htaccess).
 * Routes are registered by the files in src/routes/ and matched by dispatch().
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rules.php';
require_once __DIR__ . '/../src/services/mailer.php';
require_once __DIR__ . '/../src/services/sms.php';
require_once __DIR__ . '/../src/services/notify.php';
require_once __DIR__ . '/../src/services/uploads.php';

/* ---------- CORS (the Cordova app calls this API from another origin) ---------- */
$origins = array_map('trim', explode(',', (string)cfg('CORS_ORIGINS', '*')));
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $origins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- errors ---------- */
set_exception_handler(function (Throwable $e): void {
    if ($e instanceof PDOException) {
        $state = (string)$e->getCode();
        $msg = $e->getMessage();
        if ($state === '45000') {                                // our triggers (photo limits etc.)
            fail(422, preg_replace('/^SQLSTATE\[45000\]:.*?:\s*\d+\s*/', '', $msg) ?: 'Limit reached', 'limit');
        }
        if ($state === '23000' && stripos($msg, 'Duplicate') !== false) {
            fail(409, 'That already exists.', 'duplicate');
        }
    }
    plain_log('error.log', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $local = cfg('APP_ENV', 'local') === 'local';
    fail(500, $local ? $e->getMessage() : 'Something went wrong. Please try again.', 'server_error');
});

/* ---------- routes ---------- */
foreach (['auth', 'lookups', 'owner', 'browse', 'agent', 'viewings', 'chat', 'reviews', 'tenancy', 'tenant_reports', 'admin', 'notifications'] as $f) {
    require __DIR__ . "/../src/routes/$f.php";
}

/* ---------- dispatch ---------- */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = preg_replace('#^/index\.php#', '', $path) ?: '/';
$path = '/' . trim($path, '/');

dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
