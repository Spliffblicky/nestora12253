<?php
declare(strict_types=1);

/* =====================================================================
   CHAT
   Allowed pairs only (created by other flows):
     user <-> agent   through a viewing request
     agent <-> owner  for buildings the agent is approved for
     user <-> owner   after the user confirms tenant interest and has read the agreement
   Titles are the other person's NAME. Subtitle = building / room. Username under the name.
   ===================================================================== */

function chat_for_member(int $chat_id, array $u): array
{
    $c = must_find(row('SELECT c.* FROM chats c JOIN chat_members m ON m.chat_id = c.id WHERE c.id = ? AND m.user_id = ?', [$chat_id, $u['id']]), 'Chat');
    return $c;
}

function chat_other(array $chat, int $me): ?array
{
    $id = val('SELECT user_id FROM chat_members WHERE chat_id = ? AND user_id <> ? LIMIT 1', [$chat['id'], $me]);
    return $id ? user_card_by_id((int)$id) : null;
}

function is_blocked_between(int $a, int $b): bool
{
    return (bool)val('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$a, $b, $b, $a]);
}

/** Is this chat still allowed to receive messages? Returns a reason string if not. */
function chat_blocked_reason(array $chat, int $me): ?string
{
    if ($chat['status'] !== 'open') {
        return 'This chat is closed.';
    }
    $other = chat_other($chat, $me);
    if (!$other) {
        return 'The other person is no longer in this chat.';
    }
    if (val("SELECT 1 FROM users WHERE id = ? AND status = 'banned'", [$other['id']])) {
        return 'The other account is unavailable.';
    }
    if (is_blocked_between($me, (int)$other['id'])) {
        return 'You cannot message this person.';
    }
    switch ($chat['type']) {
        case 'viewing':
            $v = row('SELECT agent_id, status FROM viewings WHERE id = ?', [$chat['viewing_id']]);
            if ($v && !approved_agent((int)$v['agent_id'], (int)$chat['building_id'])) {
                return 'The agent is no longer approved for this building.';
            }
            break;
        case 'agent_owner':
            $agent = (int)val("SELECT user_id FROM chat_members m JOIN users u ON u.id = m.user_id WHERE m.chat_id = ? AND u.role = 'agent' LIMIT 1", [$chat['id']]);
            if (!approved_agent($agent, (int)$chat['building_id'])) {
                return 'This agency has ended.';
            }
            break;
        case 'tenant_owner':
            $tp = row('SELECT status FROM tenancy_processes WHERE id = ?', [$chat['process_id']]);
            if (!$tp || in_array($tp['status'], ['expired', 'cancelled'], true)) {
                return 'The tenant process has ended.';
            }
            break;
    }
    return null;
}

route('GET', '/chats', function () {
    $u = require_user();
    $out = [];
    foreach (rows(
        "SELECT c.*, m.muted, m.last_read_message_id,
                (SELECT COUNT(*) FROM messages x WHERE x.chat_id = c.id AND x.id > COALESCE(m.last_read_message_id, 0) AND (x.sender_id IS NULL OR x.sender_id <> ?)) AS unread,
                (SELECT body FROM messages x WHERE x.chat_id = c.id ORDER BY x.id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM messages x WHERE x.chat_id = c.id ORDER BY x.id DESC LIMIT 1) AS last_at
         FROM chats c JOIN chat_members m ON m.chat_id = c.id AND m.user_id = ?
         WHERE c.status <> 'deleted' ORDER BY COALESCE((SELECT MAX(created_at) FROM messages x WHERE x.chat_id = c.id), c.created_at) DESC LIMIT 200",
        [$u['id'], $u['id']]) as $c) {
        $other = chat_other($c, (int)$u['id']);
        $out[] = [
            'id' => (int)$c['id'], 'type' => $c['type'], 'status' => $c['status'],
            'title' => $other['name'] ?? 'Chat', 'username' => $other['username'] ?? null, 'photo_url' => $other['photo_url'] ?? null,
            'subtitle' => building_title((int)$c['building_id'], $c['room_id'] ? (int)$c['room_id'] : null),
            'unread' => (int)$c['unread'], 'muted' => (bool)$c['muted'],
            'last_message' => $c['last_body'] !== null ? mb_substr($c['last_body'], 0, 80) : null, 'last_at' => $c['last_at'],
            'viewing_id' => $c['viewing_id'] ? (int)$c['viewing_id'] : null, 'process_id' => $c['process_id'] ? (int)$c['process_id'] : null,
        ];
    }
    json_out(['chats' => $out]);
});

route('GET', '/chats/{id}', function ($p) {
    $u = require_user();
    $c = chat_for_member(pid($p), $u);
    $other = chat_other($c, (int)$u['id']);
    $out = [
        'id' => (int)$c['id'], 'type' => $c['type'], 'status' => $c['status'],
        'title' => $other['name'] ?? 'Chat', 'username' => $other['username'] ?? null, 'photo_url' => $other['photo_url'] ?? null, 'other_user_id' => $other['id'] ?? null,
        'subtitle' => building_title((int)$c['building_id'], $c['room_id'] ? (int)$c['room_id'] : null),
        'blocked_reason' => chat_blocked_reason($c, (int)$u['id']),
        'delete_after' => $c['delete_after'],
        'muted' => (bool)val('SELECT muted FROM chat_members WHERE chat_id = ? AND user_id = ?', [$c['id'], $u['id']]),
        'i_blocked' => $other ? (bool)val('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$u['id'], $other['id']]) : false,
    ];
    if ($c['viewing_id']) {
        $v = row('SELECT * FROM viewings WHERE id = ?', [$c['viewing_id']]);
        if ($v) {
            require_once __DIR__ . '/viewings.php';
            $out['viewing'] = viewing_view($v, $u);                     // pinned status card and timer bar
        }
    }
    json_out(['chat' => $out]);
});

route('GET', '/chats/{id}/messages', function ($p) {
    $u = require_user();
    $c = chat_for_member(pid($p), $u);
    $after = in_int('after') ?? 0;
    $msgs = rows(
        'SELECT m.id, m.sender_id, m.type, m.body, m.created_at FROM messages m WHERE m.chat_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200',
        [$c['id'], $after]
    );
    if ($msgs) {
        $last = (int)end($msgs)['id'];
        q('UPDATE chat_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?) WHERE chat_id = ? AND user_id = ?', [$last, $c['id'], $u['id']]);
    }
    json_out(['messages' => array_map(fn($m) => ['id' => (int)$m['id'], 'mine' => (int)$m['sender_id'] === (int)$u['id'], 'system' => $m['type'] === 'system', 'sender_id' => $m['sender_id'] ? (int)$m['sender_id'] : null, 'body' => $m['body'], 'created_at' => $m['created_at']], $msgs)]);
});

route('POST', '/chats/{id}/messages', function ($p) {
    $u = require_user();
    $c = chat_for_member(pid($p), $u);
    $why = chat_blocked_reason($c, (int)$u['id']);
    if ($why) {
        fail(403, $why, 'chat_closed');
    }
    rate_limit('msg:' . $u['id'], 30, 60);
    $body = in_str('body', 1000, true);
    reject_contact_info($body, 'body');                                 // keeps phone numbers, account numbers and links out of chat
    q("INSERT INTO messages (chat_id, sender_id, type, body) VALUES (?,?, 'text', ?)", [$c['id'], $u['id'], $body]);
    $mid = insert_id();
    foreach (rows('SELECT user_id, muted FROM chat_members WHERE chat_id = ? AND user_id <> ?', [$c['id'], $u['id']]) as $m) {
        if (!(int)$m['muted']) {
            // group alerts: only notify if there is no unread chat alert already waiting for this chat
            $dup = val("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'chat_message' AND read_at IS NULL AND JSON_EXTRACT(data, '$.chat_id') = ? LIMIT 1", [$m['user_id'], (int)$c['id']]);
            if (!$dup) {
                notify((int)$m['user_id'], 'chat_message', 'New message from ' . $u['name'], mb_substr($body, 0, 80), ['chat_id' => (int)$c['id']]);
            }
        }
    }
    json_out(['id' => $mid], 201);
});

route('POST', '/chats/{id}/mute', function ($p) {
    $u = require_user();
    $c = chat_for_member(pid($p), $u);
    q('UPDATE chat_members SET muted = ? WHERE chat_id = ? AND user_id = ?', [in_bool('muted') ? 1 : 0, $c['id'], $u['id']]);
    json_out(['ok' => true]);
});

route('POST', '/users/{id}/block', function ($p) {
    $u = require_user();
    $target = pid($p);
    if ($target === (int)$u['id'] || !val('SELECT 1 FROM users WHERE id = ?', [$target])) {
        fail(422, 'You cannot block this account.', 'validation');
    }
    if (in_bool('unblock')) {
        q('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$u['id'], $target]);
    } else {
        q('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?,?)', [$u['id'], $target]);
    }
    json_out(['ok' => true]);
});

/** Report a chat, message, review, person, building or photo. Reported chats are kept for admin review. */
route('POST', '/reports', function () {
    $u = require_user();
    $type = in_enum('target_type', ['user', 'building', 'review', 'comment', 'chat', 'message', 'photo']);
    $tid = in_int('target_id', true);
    $reason = in_str('reason', 500, true);
    if ($type === 'chat' || $type === 'message') {
        $cid = $type === 'chat' ? $tid : (int)val('SELECT chat_id FROM messages WHERE id = ?', [$tid]);
        chat_for_member($cid, $u);
        q('UPDATE chats SET hold_for_review = 1, delete_after = NULL WHERE id = ?', [$cid]);
    }
    if ((int)val('SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at > ?', [$u['id'], plus('-1 day')]) >= 10) {
        fail(429, 'Too many reports today.', 'rate_limited');
    }
    q('INSERT INTO reports (reporter_id, target_type, target_id, reason) VALUES (?,?,?,?)', [$u['id'], $type, $tid, $reason]);
    notify_admins('report', 'New report', "$type #$tid: " . mb_substr($reason, 0, 80));
    json_out(['ok' => true], 201);
});
