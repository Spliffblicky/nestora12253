<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * Creates an in-app notification (shown in the Alerts list with badges) and hands it to push.
 * $data carries ids so the app can open the right screen, e.g. ['viewing_id' => 5, 'chat_id' => 9].
 */
function notify(int $user_id, string $type, string $title, ?string $body = null, array $data = []): void
{
    q(
        'INSERT INTO notifications (user_id, type, title, body, data) VALUES (?,?,?,?,?)',
        [$user_id, $type, mb_substr($title, 0, 120), $body !== null ? mb_substr($body, 0, 300) : null, $data ? json_encode($data) : null]
    );
    push_send($user_id, $title, (string)$body, $data + ['type' => $type]);
}

function notify_many(array $user_ids, string $type, string $title, ?string $body = null, array $data = []): void
{
    foreach (array_unique($user_ids) as $id) {
        notify((int)$id, $type, $title, $body, $data);
    }
}

function notify_admins(string $type, string $title, ?string $body = null, bool $seeded_only = false, array $data = []): void
{
    $sql = "SELECT id FROM users WHERE role = 'admin' AND status = 'active'" . ($seeded_only ? " AND admin_tier IN ('root','seeded')" : '');
    foreach (rows($sql) as $a) {
        notify((int)$a['id'], $type, $title, $body, $data);
    }
}

/**
 * PUSH HOOK. Notifications already reach users inside the app (the app polls /notifications/counts).
 * To get real phone pushes when the app is closed, send to the tokens in `device_tokens`
 * through Firebase Cloud Messaging (HTTP v1) here. Until then this only writes a log line.
 */
function push_send(int $user_id, string $title, string $body, array $data): void
{
    $tokens = rows('SELECT token FROM device_tokens WHERE user_id = ?', [$user_id]);
    if ($tokens) {
        plain_log('push.log', "user $user_id (" . count($tokens) . " device(s)): $title | $body");
    }
}
