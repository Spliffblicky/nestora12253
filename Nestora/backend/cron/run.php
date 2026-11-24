<?php
declare(strict_types=1);

/**
 * SERVER-SIDE TIMERS. Run every minute (see README):
 *   Windows (XAMPP):  Task Scheduler -> C:\xampp\php\php.exe C:\xampp\htdocs\realestate-app\backend\cron\run.php
 *   Linux:            * * * * * php /path/to/backend/cron/run.php
 * Everything here happens on the server, so it works even when every phone is closed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/lifecycle.php';
require_once __DIR__ . '/../src/services/mailer.php';

$lock = fopen(APP_ROOT . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another run is still going.\n");
}

function job(string $name, callable $fn): void
{
    try {
        $n = $fn();
        if ($n) {
            echo "[$name] $n\n";
        }
    } catch (Throwable $e) {
        plain_log('cron.log', "$name failed: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo "[$name] FAILED: " . $e->getMessage() . "\n";
    }
}

/* ---------- accounts ---------- */

job('unverified accounts', function () {
    $days = (int)setting('unverified_delete_days', '7');
    $n = 0;
    foreach (rows("SELECT id FROM users WHERE status = 'unverified' AND role <> 'admin' AND created_at < ?", [plus("-$days days")]) as $u) {
        try {
            q('DELETE FROM users WHERE id = ?', [$u['id']]);
            $n++;
        } catch (Throwable $e) {
            // has linked rows: leave it
        }
    }
    return $n;
});

job('cleanup', function () {
    q('DELETE FROM api_tokens WHERE expires_at < ?', [now()]);
    q('DELETE FROM verification_codes WHERE expires_at < ?', [plus('-1 day')]);
    q('DELETE FROM password_resets WHERE expires_at < ?', [plus('-1 day')]);
    q('DELETE FROM rate_limits WHERE window_start < ?', [plus('-1 day')]);
    q('DELETE FROM sms_log WHERE sent_at < ?', [plus('-30 days')]);
    return 0;
});

/* ---------- viewings ---------- */

job('viewing requests: unanswered', function () {
    $n = 0;
    foreach (rows("SELECT * FROM viewings WHERE status = 'pending' AND request_expires_at IS NOT NULL") as $v) {
        $left = ts($v['request_expires_at']) - time();
        $chat = viewing_chat_id_cron((int)$v['id']);
        if ($left <= 0) {
            q("UPDATE viewings SET status = 'expired', request_expires_at = NULL WHERE id = ?", [$v['id']]);
            if ($chat) {
                chat_system($chat, 'The agent did not answer in time. This request expired.');
                chat_close($chat, 'closed');
            }
            notify((int)$v['user_id'], 'viewing_expired', 'Viewing request expired', 'The agent did not respond. Try another agent.', ['viewing_id' => (int)$v['id']]);
            notify((int)$v['agent_id'], 'viewing_expired', 'Viewing request expired', 'You did not answer a viewing request in time.', ['viewing_id' => (int)$v['id']]);
            $n++;
        } elseif ($left < 24 * 3600 && (int)$v['reminder_stage'] < 1) {
            q('UPDATE viewings SET reminder_stage = 1 WHERE id = ?', [$v['id']]);
            notify((int)$v['agent_id'], 'viewing_reminder', 'Viewing request waiting', 'A user is waiting for your answer. It expires soon.', ['viewing_id' => (int)$v['id']]);
        }
    }
    return $n;
});

function viewing_chat_id_cron(int $viewing_id): ?int
{
    $c = val('SELECT id FROM chats WHERE viewing_id = ? ORDER BY id LIMIT 1', [$viewing_id]);
    return $c ? (int)$c : null;
}

job('viewing 2-day timer', function () {
    $n = 0;
    foreach (rows("SELECT * FROM viewings WHERE status = 'date_change_requested' AND timer_ends_at IS NOT NULL") as $v) {
        $left = ts($v['timer_ends_at']) - time();
        $chat = viewing_chat_id_cron((int)$v['id']);
        if ($left <= 0) {                                                // no new date picked: viewing cancelled, chat closes
            q("UPDATE viewings SET status = 'cancelled', timer_ends_at = NULL WHERE id = ?", [$v['id']]);
            if ($chat) {
                chat_system($chat, 'No new date was selected in time. The viewing is cancelled and this chat is closed.');
                chat_close($chat, 'closed');
            }
            notify((int)$v['user_id'], 'viewing_cancelled', 'Viewing cancelled', 'You did not pick a new date in time.', ['viewing_id' => (int)$v['id']]);
            notify((int)$v['agent_id'], 'viewing_cancelled', 'Viewing cancelled', 'The user did not pick a new date in time.', ['viewing_id' => (int)$v['id']]);
            $n++;
        } elseif ($left < 6 * 3600 && (int)$v['reminder_stage'] < 2) {
            q('UPDATE viewings SET reminder_stage = 2 WHERE id = ?', [$v['id']]);
            notify((int)$v['user_id'], 'viewing_timer', 'Pick a new date now', 'Less than 6 hours left to pick a new viewing date.', ['viewing_id' => (int)$v['id']]);
        } elseif ($left < 24 * 3600 && (int)$v['reminder_stage'] < 1) {
            q('UPDATE viewings SET reminder_stage = 1 WHERE id = ?', [$v['id']]);
            notify((int)$v['user_id'], 'viewing_timer', 'Pick a new viewing date', 'Less than 24 hours left to pick a new date.', ['viewing_id' => (int)$v['id']]);
        }
    }
    return $n;
});

job('viewing day passed', function () {                                  // push the evening after: "did it happen?"
    $n = 0;
    foreach (rows("SELECT * FROM viewings WHERE status = 'accepted' AND requested_date < ? AND reminder_stage < 5", [today()]) as $v) {
        q('UPDATE viewings SET reminder_stage = 5 WHERE id = ?', [$v['id']]);
        $agent = (string)val('SELECT name FROM users WHERE id = ?', [$v['agent_id']]);
        notify((int)$v['user_id'], 'viewing_confirm_prompt', 'Did you have your viewing?', "Did you have your viewing with $agent? Open the app to confirm.", ['viewing_id' => (int)$v['id']]);
        notify((int)$v['agent_id'], 'viewing_confirm_prompt', 'Mark the viewing complete', 'Tap "Viewing complete" if the viewing took place.', ['viewing_id' => (int)$v['id']]);
        $n++;
    }
    return $n;
});

/* ---------- chats: day-5 warning, then deletion one week after closing ---------- */

job('chat deletion warning', function () {
    $n = 0;
    foreach (rows("SELECT * FROM chats WHERE delete_after IS NOT NULL AND hold_for_review = 0 AND warning_sent = 0 AND delete_after < ? AND delete_after > ?", [plus('+2 days'), now()]) as $c) {
        q('UPDATE chats SET warning_sent = 1 WHERE id = ?', [$c['id']]);
        foreach (chat_member_ids((int)$c['id']) as $uid) {
            notify($uid, 'chat_deletion', 'Chat will be deleted soon', 'A chat will be deleted on ' . substr($c['delete_after'], 0, 10) . '. Copy anything you need.', ['chat_id' => (int)$c['id']]);
        }
        $n++;
    }
    return $n;
});

job('chat deletion', function () {
    $n = 0;
    foreach (rows('SELECT id FROM chats WHERE delete_after IS NOT NULL AND hold_for_review = 0 AND delete_after < ?', [now()]) as $c) {
        q('DELETE FROM chats WHERE id = ?', [$c['id']]);               // messages and members cascade
        $n++;
    }
    return $n;
});

/* ---------- tenant processes: 5 days to upload proof ---------- */

job('tenant process expiry and reminders', function () {
    $n = 0;
    foreach (rows("SELECT * FROM tenancy_processes WHERE status IN ('started','agreement_read','method_chosen')") as $tp) {
        $pay = row('SELECT * FROM payments WHERE process_id = ? ORDER BY id DESC LIMIT 1', [$tp['id']]);
        $rejected = $pay && $pay['status'] === 'rejected' && $pay['resubmit_by'];
        $deadline = $rejected ? $pay['resubmit_by'] : $tp['expires_at'];
        $left = ts($deadline) - time();
        if ($left <= 0) {
            end_process($tp, 'expired', $rejected ? 'Corrected payment proof was not uploaded in time' : 'Payment proof was not uploaded within ' . (int)setting('tenant_process_days', '5') . ' days');
            $n++;
            continue;
        }
        $age = time() - ts($tp['started_at']);
        $stage = (int)$tp['reminder_stage'];
        $new = $stage;
        if ($left < 24 * 3600 && $stage < 3) {
            $new = 3;
        } elseif ($age >= 3 * 86400 && $stage < 2) {
            $new = 2;
        } elseif ($age >= 86400 && $stage < 1) {
            $new = 1;
        }
        if ($new !== $stage) {
            q('UPDATE tenancy_processes SET reminder_stage = ? WHERE id = ?', [$new, $tp['id']]);
            $msg = $new === 3 ? 'Less than 24 hours left to upload your payment proof.' : 'Your tenant process is open. Upload your payment proof before it ends.';
            notify((int)$tp['user_id'], 'process_reminder', 'Finish renting', $msg, ['process_id' => (int)$tp['id']]);
        }
    }
    return $n;
});

/* ---------- third-party payments waiting for the owner: reminders only. Nothing expires; it waits for the owner's next open. ---------- */

job('owner verification reminders', function () {
    $n = 0;
    foreach (rows("SELECT py.*, tp.building_id, tp.user_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.method = 'third_party' AND py.status = 'proof_uploaded' AND py.verify_by IS NOT NULL") as $py) {
        $stage = (int)$py['reminder_stage'];
        $now = time();
        $new = $stage;
        if ($now >= ts($py['verify_by']) && $stage < 2) {
            $new = 2;
        } elseif ($now >= ts($py['verify_by']) - 24 * 3600 && $stage < 1) {
            $new = 1;
        }
        if ($new !== $stage) {
            q('UPDATE payments SET reminder_stage = ? WHERE id = ?', [$new, $py['id']]);
            notify(building_owner_id((int)$py['building_id']), 'verify_payment', 'A tenant is waiting', 'Please check your account and confirm a tenant\'s payment.', ['payment_id' => (int)$py['id']]);
            if ($new === 2) {
                notify((int)$py['user_id'], 'owner_not_opened', 'Waiting for the owner', 'The owner has not confirmed yet. It will be checked as soon as they open the app.', ['payment_id' => (int)$py['id']]);
            }
            $n++;
        }
    }
    return $n;
});

/* ---------- tenancies ---------- */

job('tenancies: renewals start, leases end', function () {
    $n = 0;
    // a renewal term starts the day the old one ends
    foreach (rows("SELECT t.*, tp.renewal_of_tenancy_id FROM tenancies t JOIN tenancy_processes tp ON tp.id = t.process_id WHERE t.status = 'reserved' AND tp.renewal_of_tenancy_id IS NOT NULL AND t.move_in_date <= ?", [today()]) as $t) {
        tx(function () use ($t) {
            q("UPDATE tenancies SET status = 'ended' WHERE id = ? AND status = 'active'", [$t['renewal_of_tenancy_id']]);
            q("UPDATE tenancies SET status = 'active', signed_at = COALESCE(signed_at, ?) WHERE id = ?", [now(), $t['id']]);
        });
        notify((int)$t['user_id'], 'tenancy_renewed', 'Lease renewed', 'Your new term has started.', ['tenancy_id' => (int)$t['id']]);
        $n++;
    }
    foreach (rows("SELECT * FROM tenancies WHERE status = 'active' AND end_date < ?", [today()]) as $t) {
        tx(function () use ($t) {
            q("UPDATE tenancies SET status = 'ended' WHERE id = ?", [$t['id']]);
            q("UPDATE rooms SET status = 'available' WHERE id = ? AND status = 'occupied'", [$t['room_id']]);
        });
        $left = $t['renewal_answer'] ?? 'no_response';                 // the owner sees "no response" and decides
        notify((int)$t['owner_id'], 'lease_ended', 'Lease ended', 'A lease ended (' . building_title((int)$t['building_id'], (int)$t['room_id']) . "). Renewal answer: $left.", ['tenancy_id' => (int)$t['id']]);
        notify((int)$t['user_id'], 'lease_ended', 'Lease ended', 'Your lease has ended.', ['tenancy_id' => (int)$t['id']]);
        $n++;
    }
    return $n;
});

job('renewal reminders', function () {
    $n = 0;
    $start = (int)setting('renewal_prompt_start_days', '60');
    $end = (int)setting('renewal_prompt_end_days', '45');
    foreach (rows("SELECT * FROM tenancies WHERE status = 'active' AND end_date IS NOT NULL AND (renewal_answer IS NULL OR renewal_answer = 'not_sure')") as $t) {
        $left = days_between(today(), $t['end_date']);
        if ($left > $start || $left < $end) {
            continue;                                                  // pushes only in the 60-to-45-day window; below that the pinned bar takes over
        }
        if ($t['last_renewal_notice_at'] && ts($t['last_renewal_notice_at']) > time() - 3 * 86400) {
            continue;                                                  // every 3 days, so people do not mute the app
        }
        q('UPDATE tenancies SET last_renewal_notice_at = ? WHERE id = ?', [now(), $t['id']]);
        q("INSERT INTO renewal_prompts (tenancy_id, kind) VALUES (?, 'push')", [$t['id']]);
        notify((int)$t['user_id'], 'renewal_prompt', 'Do you intend to renew?', "Your lease ends in $left days. Open the app and tell us.", ['tenancy_id' => (int)$t['id']]);
        $n++;
    }
    return $n;
});

job('move-in window missed', function () {
    $n = 0;
    foreach (rows("SELECT t.* FROM tenancies t JOIN tenancy_processes tp ON tp.id = t.process_id WHERE t.status = 'reserved' AND t.move_in_date IS NULL AND t.move_in_deadline < ? AND tp.renewal_of_tenancy_id IS NULL", [today()]) as $t) {
        if (val("SELECT 1 FROM notifications WHERE type = 'move_in_missed' AND JSON_EXTRACT(data, '$.tenancy_id') = ? LIMIT 1", [(int)$t['id']])) {
            continue;
        }
        $msg = 'A paid tenant did not choose a move-in date in time. A seeded admin decides on a refund or extension.';
        notify_admins('move_in_missed', 'Move-in date missed', $msg, true, ['tenancy_id' => (int)$t['id']]);
        notify((int)$t['owner_id'], 'move_in_missed', 'Move-in date missed', $msg, ['tenancy_id' => (int)$t['id']]);
        notify((int)$t['user_id'], 'move_in_missed', 'Choose your move-in date', 'The move-in window has passed. Contact support.', ['tenancy_id' => (int)$t['id']]);
        $n++;
    }
    return $n;
});

flock($lock, LOCK_UN);
