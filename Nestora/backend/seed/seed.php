<?php
declare(strict_types=1);

/**
 * Creates the seeded admins and the app's payment details from seed/seed.config.php.
 * Run on the server only:   php seed/seed.php        (add --reset-root to reset the root password)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/services/uploads.php';

$file = __DIR__ . '/seed.config.php';
if (!is_file($file)) {
    exit("Missing seed/seed.config.php. Copy seed.config.example.php to seed.config.php and fill it in.\n");
}
$conf = require $file;
$reset_root = in_array('--reset-root', $argv ?? [], true);

/** Copies the seed photo into public/uploads/profiles (no upload form: this runs from the command line). */
function seed_photo(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }
    $dir = APP_ROOT . '/public/uploads/profiles';
    ensure_dir($dir);
    $rel = 'profiles/' . bin2hex(random_bytes(12)) . '.jpg';
    if (function_exists('imagecreatefromjpeg') && ($src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($path), IMAGETYPE_PNG => @imagecreatefrompng($path), IMAGETYPE_WEBP => @imagecreatefromwebp($path), default => false })) {
        $scale = min(1, 800 / max(imagesx($src), imagesy($src)));
        $dst = imagecreatetruecolor(max(1, (int)(imagesx($src) * $scale)), max(1, (int)(imagesy($src) * $scale)));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), imagesx($src), imagesy($src));
        imagejpeg($dst, APP_ROOT . "/public/uploads/$rel", 82);
    } else {
        copy($path, APP_ROOT . "/public/uploads/$rel");
    }
    return $rel;
}

$made = 0;
foreach ($conf['admins'] ?? [] as $i => $a) {
    $tier = $i === 0 ? 'root' : 'seeded';                           // the first entry is always root
    foreach (['name', 'username', 'email', 'password_env'] as $k) {
        if (empty($a[$k])) {
            exit("Admin #" . ($i + 1) . ": '$k' is required.\n");
        }
    }
    $password = (string)cfg($a['password_env'], '');
    $existing = row('SELECT * FROM users WHERE username = ? OR email = ?', [$a['username'], strtolower($a['email'])]);
    if ($existing) {
        if ($tier === 'root' && $reset_root && $existing['admin_tier'] === 'root') {
            if (strlen($password) < 8) {
                exit('Set SEED_ROOT_PASSWORD (8+ characters) in .env first.' . "\n");
            }
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
            q('DELETE FROM api_tokens WHERE user_id = ?', [$existing['id']]);
            echo "Root password reset (must change at next login).\n";
        } else {
            echo "Skipped {$a['username']}: already exists.\n";
        }
        continue;
    }
    if (strlen($password) < 8) {
        exit("Set {$a['password_env']} (8+ characters) in .env first. Passwords are never typed into the seed file.\n");
    }
    if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9_]{3,20}$/', $a['username'])) {
        exit("Admin '{$a['username']}': check the email and username (3-20 letters, numbers, underscores).\n");
    }
    $phone = !empty($a['phone']) ? normalize_phone((string)$a['phone']) : null;
    $photo = !empty($a['photo']) ? seed_photo((string)$a['photo']) : null;
    if (!$photo) {
        echo "WARNING: no photo found for {$a['username']}. A profile photo is required for everyone: upload one after logging in.\n";
    }
    q(
        "INSERT INTO users (role, admin_tier, name, username, email, email_verified_at, phone, phone_verified_at, password_hash, must_change_password, profile_photo, status, terms_accepted_at, terms_version)
         VALUES ('admin', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'active', ?, '1.0')",
        [$tier, $a['name'], $a['username'], strtolower($a['email']), now(), $phone, $phone ? now() : null, password_hash($password, PASSWORD_DEFAULT), $photo, now()]
    );
    audit(null, 'seed_admin', 'user', insert_id(), "tier=$tier");
    echo "Created $tier admin: {$a['username']}\n";
    $made++;
}

// App payment details: only written when none exist yet (after that, only the root admin changes them in the app)
$d = $conf['app_payment_details'] ?? null;
$have = row('SELECT account_number, momo_number FROM app_payment_details WHERE id = 1');
if ($d && (!$have || (!$have['account_number'] && !$have['momo_number']))) {
    $root = row("SELECT id FROM users WHERE admin_tier = 'root' LIMIT 1");
    $momo = !empty($d['momo_number']) ? normalize_phone((string)$d['momo_number']) : null;
    q('UPDATE app_payment_details SET bank_name=?, account_name=?, account_number=?, momo_network=?, momo_number=?, momo_name=?, updated_by=? WHERE id = 1',
        [$d['bank_name'] ?? null, $d['account_name'] ?? null, $d['account_number'] ?? null, $d['momo_network'] ?? null, $momo, $d['momo_name'] ?? null, $root['id'] ?? null]);
    echo "App payment details saved.\n";
} else {
    echo "App payment details already set (only the root admin can change them in the app).\n";
}
echo $made ? "Done.\n" : "Nothing new to create.\n";
