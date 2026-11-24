<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Public images (profile, building, room photos) go to public/uploads/<dir>/ and are served by Apache.
 * Private files (tenancy agreements, payment proofs, report photos) go to storage/private/<dir>/
 * and are only served through authenticated endpoints.
 */

function uploaded_files(string $key): array
{
    if (empty($_FILES[$key])) {
        return [];
    }
    $f = $_FILES[$key];
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function check_upload(array $f, int $max_bytes): void
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        fail(422, 'The upload failed. Please try again.', 'upload_failed');
    }
    if ($f['size'] > $max_bytes) {
        fail(422, 'That file is too large (max ' . round($max_bytes / 1048576) . ' MB).', 'file_too_large');
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        fail(422, 'Invalid upload.', 'upload_failed');
    }
}

function ensure_dir(string $d): void
{
    if (!is_dir($d) && !@mkdir($d, 0775, true)) {
        fail(500, 'Cannot create upload folder.', 'server_error');
    }
}

/** Validates it is a real image, resizes (max 1600px) and re-encodes as JPEG. Returns relative path. */
function save_image(array $f, string $dir, bool $private = false, int $max_px = 1600): string
{
    check_upload($f, 10 * 1048576);
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        fail(422, 'Please upload a JPG, PNG or WebP image.', 'bad_image');
    }
    $base = $private ? APP_ROOT . '/storage/private' : APP_ROOT . '/public/uploads';
    ensure_dir("$base/$dir");
    $rel  = "$dir/" . bin2hex(random_bytes(12)) . '.jpg';
    $dest = "$base/$rel";

    if (function_exists('imagecreatefromjpeg')) {
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($f['tmp_name']),
            default        => @imagecreatefromwebp($f['tmp_name']),
        };
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $scale = min(1, $max_px / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($dst, $dest, 82);
            imagedestroy($src);
            imagedestroy($dst);
            return $rel;
        }
    }
    // No GD: keep original bytes but still with a random .jpg name (type was validated above)
    move_uploaded_file($f['tmp_name'], $dest);
    return $rel;
}

/** PDF only (checks the file header), max 15 MB. Stored privately. */
function save_pdf(array $f, string $dir): string
{
    check_upload($f, 15 * 1048576);
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($head !== '%PDF-') {
        fail(422, 'Only PDF files are accepted.', 'bad_pdf');
    }
    ensure_dir(APP_ROOT . "/storage/private/$dir");
    $rel = "$dir/" . bin2hex(random_bytes(12)) . '.pdf';
    move_uploaded_file($f['tmp_name'], APP_ROOT . "/storage/private/$rel");
    return $rel;
}

function delete_file(?string $rel, bool $private = false): void
{
    if (!$rel || str_contains($rel, '..')) {
        return;
    }
    $p = ($private ? APP_ROOT . '/storage/private/' : APP_ROOT . '/public/uploads/') . $rel;
    if (is_file($p)) {
        @unlink($p);
    }
}

function serve_private(string $rel, string $mime, ?string $download_name = null): never
{
    $p = APP_ROOT . '/storage/private/' . $rel;
    if (str_contains($rel, '..') || !is_file($p)) {
        fail(404, 'File not found', 'not_found');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    if ($download_name) {
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $download_name) . '"');
    }
    readfile($p);
    exit;
}
