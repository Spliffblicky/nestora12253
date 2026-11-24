<?php
declare(strict_types=1);

/* In-app notification list, unread counts for tab badges, and the pinned bars the app shows. */

route('GET', '/notifications', function () {
    $u = require_user();
    $list = rows('SELECT id, type, title, body, data, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
    foreach ($list as &$n) {
        $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
        $n['read'] = $n['read_at'] !== null;
    }
    json_out(['notifications' => $list]);
});

route('POST', '/notifications/read', function () {
    $u = require_user();
    $id = in_int('id');
    if ($id) {
        q('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ?', [now(), $id, $u['id']]);
    } else {
        q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $u['id']]);
    }
    json_out(['ok' => true]);
});

/**
 * One call the app polls (about every 20 s, and when it opens): badges for every tab plus the
 * pinned bars: viewing timers, tenant-process countdown, renewal reminder, owner verification waiting.
 */
route('GET', '/notifications/counts', function () {
    $u = require_user();
    $id = (int)$u['id'];
    $c = [
        'notifications' => (int)val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$id]),
        'chats' => (int)val(
            "SELECT COUNT(*) FROM messages x JOIN chat_members m ON m.chat_id = x.chat_id AND m.user_id = ?
             WHERE x.id > COALESCE(m.last_read_message_id, 0) AND (x.sender_id IS NULL OR x.sender_id <> ?)", [$id, $id]),
    ];
    $pinned = [];
    switch ($u['role']) {
        case 'owner':
            $c['applications'] = (int)val("SELECT COUNT(*) FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE b.owner_id = ? AND aa.status = 'pending'", [$id]);
            $c['payments_to_verify'] = (int)val("SELECT COUNT(*) FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id JOIN buildings b ON b.id = tp.building_id WHERE b.owner_id = ? AND ((py.method = 'third_party' AND py.status = 'proof_uploaded') OR (py.method = 'app' AND py.status = 'paid_to_owner'))", [$id]);
            $c['tenant_reports'] = (int)val("SELECT COUNT(*) FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE t.owner_id = ? AND r.status IN ('sent','reopened')", [$id]);
            $c['surveys'] = (int)val('SELECT COUNT(*) FROM revocation_surveys WHERE respondent_id = ? AND submitted_at IS NULL', [$id]);
            if ($c['payments_to_verify'] > 0) {
                $pinned[] = ['type' => 'verify_payment', 'count' => $c['payments_to_verify']];
            }
            break;
        case 'agent':
            $c['viewing_requests'] = (int)val("SELECT COUNT(*) FROM viewings WHERE agent_id = ? AND status = 'pending'", [$id]);
            $c['surveys'] = (int)val('SELECT COUNT(*) FROM revocation_surveys WHERE respondent_id = ? AND submitted_at IS NULL', [$id]);
            break;
        case 'user':
            foreach (rows("SELECT id, timer_ends_at FROM viewings WHERE user_id = ? AND status = 'date_change_requested'", [$id]) as $v) {
                $left = max(0, ts($v['timer_ends_at']) - time());
                $pinned[] = ['type' => 'viewing_timer', 'viewing_id' => (int)$v['id'], 'seconds_left' => $left, 'level' => $left < 6 * 3600 ? 'red' : ($left < 24 * 3600 ? 'amber' : 'normal')];
            }
            foreach (rows("SELECT * FROM tenancy_processes WHERE user_id = ? AND status IN ('started','agreement_read','method_chosen')", [$id]) as $tp) {
                $left = max(0, ts($tp['expires_at']) - time());
                $pinned[] = ['type' => 'process_countdown', 'process_id' => (int)$tp['id'], 'seconds_left' => $left, 'level' => $left < 6 * 3600 ? 'red' : ($left < 24 * 3600 ? 'amber' : 'normal')];
            }
            $c['viewing_prompts'] = (int)val("SELECT COUNT(*) FROM viewings WHERE user_id = ? AND status = 'accepted' AND requested_date < ?", [$id, today()]);
            $c['has_tenancy'] = (bool)val("SELECT 1 FROM tenancies WHERE user_id = ? AND status IN ('reserved','active')", [$id]);
            break;
        case 'admin':
            $c['reports'] = (int)val("SELECT COUNT(*) FROM reports WHERE status = 'open'");
            $c['appeals'] = (int)val("SELECT COUNT(*) FROM ban_appeals WHERE status = 'open'");
            $c['tenant_reports'] = (int)val('SELECT COUNT(*) FROM tenant_reports WHERE escalated_to_admin = 1 AND status <> \'resolved\'');
            if (is_seeded_admin($u)) {
                $c['payments'] = (int)val("SELECT COUNT(*) FROM payments WHERE method = 'app' AND status IN ('proof_uploaded','verified')");
                $c['disputes'] = (int)val("SELECT COUNT(*) FROM payment_disputes WHERE status = 'open'");
            }
            break;
    }
    json_out(['counts' => $c, 'pinned' => $pinned, 'server_time' => now()]);
});
