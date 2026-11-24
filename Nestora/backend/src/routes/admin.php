<?php
declare(strict_types=1);

require_once __DIR__ . '/../lifecycle.php';

/* =====================================================================
   ADMIN
   Tiers:  root (first seeded admin)  >  seeded  >  promoted (a normal user an admin turned into an admin)
   Both tiers: reports, bans (accounts and buildings), appeals, review/chat moderation, surveys,
               tenant reports sent to admin, area approvals.
   Seeded + root only: MONEY (verify app payments, pay owners, payment disputes, refunds), settings, promoting/demoting admins.
   Root only: the app's own bank / MoMo details.
   Every action is written to the append-only audit log. Promoted admins only see their own entries.
   ===================================================================== */

/** May $actor act on $target (ban, demote)? Nobody touches themselves or the root; promoted admins never touch other admins. */
function admin_may_touch(array $actor, array $target): bool
{
    if ((int)$actor['id'] === (int)$target['id']) {
        return false;
    }
    if ($target['role'] !== 'admin') {
        return true;
    }
    if ($target['admin_tier'] === 'root') {
        return false;
    }
    if ($target['admin_tier'] === 'seeded') {
        return $actor['admin_tier'] === 'root';
    }
    return is_seeded_admin($actor);                                    // target is promoted
}

function admin_user_row(int $id): array
{
    return must_find(row('SELECT * FROM users WHERE id = ?', [$id]), 'User');
}

/* ---------- overview ---------- */

route('GET', '/admin/overview', function () {
    $a = require_admin();
    $o = [
        'users' => (int)val("SELECT COUNT(*) FROM users WHERE role = 'user'"), 'owners' => (int)val("SELECT COUNT(*) FROM users WHERE role = 'owner'"),
        'agents' => (int)val("SELECT COUNT(*) FROM users WHERE role = 'agent'"), 'buildings' => (int)val("SELECT COUNT(*) FROM buildings WHERE status = 'live'"),
        'open_reports' => (int)val("SELECT COUNT(*) FROM reports WHERE status = 'open'"), 'open_appeals' => (int)val("SELECT COUNT(*) FROM ban_appeals WHERE status = 'open'"),
        'escalated_tenant_reports' => (int)val("SELECT COUNT(*) FROM tenant_reports WHERE escalated_to_admin = 1 AND status <> 'resolved'"),
        'tier' => $a['admin_tier'],
    ];
    if (is_seeded_admin($a)) {
        $o['payments_to_verify'] = (int)val("SELECT COUNT(*) FROM payments WHERE method = 'app' AND status = 'proof_uploaded'");
        $o['payouts_due'] = (int)val("SELECT COUNT(*) FROM payments WHERE method = 'app' AND status = 'verified'");
        $o['open_disputes'] = (int)val("SELECT COUNT(*) FROM payment_disputes WHERE status = 'open'");
    }
    json_out(['overview' => $o]);
});

/* ---------- users / buildings ---------- */

route('GET', '/admin/users', function () {
    require_admin();
    $q = in_str('q', 100);
    $role = in_enum('role', ['owner', 'agent', 'user', 'admin'], false);
    $where = ['1=1'];
    $args = [];
    if ($q) {
        $where[] = '(name LIKE ? OR username LIKE ? OR email LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    if ($role) {
        $where[] = 'role = ?';
        $args[] = $role;
    }
    json_out(['users' => rows('SELECT id, role, admin_tier, name, username, email, status, phone_verified_at, created_at FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100', $args)]);
});

route('GET', '/admin/buildings', function () {
    require_admin();
    $status = in_enum('status', ['draft', 'live', 'banned'], false);
    $q = in_str('q', 100);
    $where = ['1=1'];
    $args = [];
    if ($status) {
        $where[] = 'b.status = ?';
        $args[] = $status;
    }
    if ($q) {
        $where[] = 'b.name LIKE ?';
        $args[] = "%$q%";
    }
    json_out(['buildings' => rows('SELECT b.id, b.name, b.status, b.owner_id, u.name AS owner_name FROM buildings b JOIN users u ON u.id = b.owner_id WHERE ' . implode(' AND ', $where) . ' ORDER BY b.id DESC LIMIT 100', $args)]);
});

/* ---------- bans ---------- */

route('POST', '/admin/bans', function () {
    $a = require_admin();
    $type = in_enum('target_type', ['user', 'building']);
    $id = in_int('target_id', true);
    $reason = in_str('reason', 500, true);
    if ($type === 'user') {
        $t = admin_user_row($id);
        if (!admin_may_touch($a, $t)) {
            fail(403, 'You cannot ban this account.', 'forbidden_tier');
        }
        if ($t['status'] === 'banned') {
            fail(409, 'Already banned.', 'duplicate');
        }
        tx(function () use ($t, $a, $reason) {
            q("UPDATE users SET status = 'banned' WHERE id = ?", [$t['id']]);
            q('DELETE FROM api_tokens WHERE user_id = ?', [$t['id']]);
            q("UPDATE chats SET status = 'read_only' WHERE status = 'open' AND id IN (SELECT chat_id FROM chat_members WHERE user_id = ?)", [$t['id']]);
            q('INSERT INTO bans (target_type, target_id, reason, banned_by) VALUES (?,?,?,?)', ['user', $t['id'], $reason, $a['id']]);
            audit($a, 'ban_user', 'user', (int)$t['id'], $reason);
        });
        // payments, tenancies and disputes stay on record; a seeded admin settles anything money-related separately
        send_mail($t['email'], 'Your account has been banned', "Your account was banned. Reason: $reason\n\nYou can appeal from the login screen.");
        json_out(['banned' => true]);
    }
    $b = must_find(row('SELECT * FROM buildings WHERE id = ?', [$id]), 'Building');
    if ($b['status'] === 'banned') {
        fail(409, 'Already banned.', 'duplicate');
    }
    tx(function () use ($b, $a, $reason) {
        q("UPDATE buildings SET status = 'banned' WHERE id = ?", [$b['id']]);
        q("UPDATE rooms SET status = 'banned' WHERE building_id = ? AND status IN ('draft','available')", [$b['id']]);     // occupied/reserved rooms keep their tenants
        foreach (rows("SELECT id, user_id FROM viewings WHERE building_id = ? AND status IN ('pending','accepted','date_change_requested')", [$b['id']]) as $v) {
            q("UPDATE viewings SET status = 'cancelled' WHERE id = ?", [$v['id']]);
            foreach (rows('SELECT id FROM chats WHERE viewing_id = ?', [$v['id']]) as $c) {
                chat_system((int)$c['id'], 'This viewing was cancelled because the building is unavailable.');
                chat_close((int)$c['id'], 'closed');
            }
            notify((int)$v['user_id'], 'viewing_cancelled', 'Viewing cancelled', 'The building is no longer available.', ['viewing_id' => (int)$v['id']]);
        }
        q('INSERT INTO bans (target_type, target_id, reason, banned_by) VALUES (?,?,?,?)', ['building', $b['id'], $reason, $a['id']]);
        audit($a, 'ban_building', 'building', (int)$b['id'], $reason);
    });
    notify((int)$b['owner_id'], 'building_banned', 'Building banned', $b['name'] . ': ' . $reason, ['building_id' => (int)$b['id']]);
    foreach (rows("SELECT agent_id FROM agency_applications WHERE building_id = ? AND status = 'approved'", [$b['id']]) as $ag) {
        notify((int)$ag['agent_id'], 'building_banned', 'Building banned', $b['name'] . ' was banned by an admin.', ['building_id' => (int)$b['id']]);
    }
    json_out(['banned' => true]);
});

route('POST', '/admin/bans/lift', function () {
    $a = require_admin();
    $type = in_enum('target_type', ['user', 'building']);
    $id = in_int('target_id', true);
    $reason = in_str('reason', 500, true);
    $ban = must_find(row('SELECT * FROM bans WHERE target_type = ? AND target_id = ? AND lifted_at IS NULL ORDER BY id DESC LIMIT 1', [$type, $id]), 'Active ban');
    lift_ban($ban, $a, $reason);
    json_out(['lifted' => true]);
});

function lift_ban(array $ban, array $a, string $reason): void
{
    tx(function () use ($ban, $a, $reason) {
        q('UPDATE bans SET lifted_by = ?, lifted_at = ? WHERE id = ?', [$a['id'], now(), $ban['id']]);
        if ($ban['target_type'] === 'user') {
            q("UPDATE users SET status = 'active' WHERE id = ? AND status = 'banned'", [$ban['target_id']]);
        } else {
            q("UPDATE buildings SET status = 'draft' WHERE id = ? AND status = 'banned'", [$ban['target_id']]);      // owner re-posts after checking details
            q("UPDATE rooms SET status = 'draft' WHERE building_id = ? AND status = 'banned'", [$ban['target_id']]);
        }
        audit($a, 'lift_ban', $ban['target_type'], (int)$ban['target_id'], $reason);
    });
    if ($ban['target_type'] === 'user') {
        $u = row('SELECT email FROM users WHERE id = ?', [$ban['target_id']]);
        $u && send_mail($u['email'], 'Your account is active again', 'Your ban has been lifted. You can log in again.');
    }
}

route('GET', '/admin/appeals', function () {
    require_admin();
    json_out(['appeals' => rows(
        "SELECT ba.*, b.target_type, b.target_id, b.reason AS ban_reason, b.banned_by, u.name AS target_name FROM ban_appeals ba JOIN bans b ON b.id = ba.ban_id
         LEFT JOIN users u ON b.target_type = 'user' AND u.id = b.target_id WHERE ba.status = 'open' ORDER BY ba.id"
    )]);
});

/** Reviewed by a different admin than the one who banned. */
route('POST', '/admin/appeals/{id}/decide', function ($p) {
    $a = require_admin();
    $ap = must_find(row("SELECT ba.*, b.banned_by, b.target_type, b.target_id FROM ban_appeals ba JOIN bans b ON b.id = ba.ban_id WHERE ba.id = ? AND ba.status = 'open'", [pid($p)]), 'Appeal');
    if ((int)$ap['banned_by'] === (int)$a['id']) {
        fail(403, 'A different admin must review this appeal.', 'conflict_of_interest');
    }
    $decision = in_enum('decision', ['uphold', 'overturn']);
    $reason = in_str('reason', 500, true);
    q('UPDATE ban_appeals SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$decision === 'uphold' ? 'upheld' : 'overturned', $a['id'], now(), $ap['id']]);
    if ($decision === 'overturn') {
        lift_ban(row('SELECT * FROM bans WHERE id = ?', [$ap['ban_id']]), $a, "Appeal overturned: $reason");
    }
    audit($a, 'appeal_' . $decision, 'ban_appeal', (int)$ap['id'], $reason);
    json_out(['ok' => true]);
});

/* ---------- reports and moderation ---------- */

route('GET', '/admin/reports', function () {
    require_admin();
    $status = in_enum('status', ['open', 'reviewing', 'resolved', 'dismissed'], false) ?? 'open';
    $list = rows('SELECT r.*, u.name AS reporter_name FROM reports r JOIN users u ON u.id = r.reporter_id WHERE r.status = ? ORDER BY r.id DESC LIMIT 100', [$status]);
    json_out(['reports' => $list]);
});

route('POST', '/admin/reports/{id}/resolve', function ($p) {
    $a = require_admin();
    $r = must_find(row('SELECT * FROM reports WHERE id = ?', [pid($p)]), 'Report');
    $status = in_enum('status', ['reviewing', 'resolved', 'dismissed']);
    $outcome = in_str('outcome', 500, $status !== 'reviewing');
    q('UPDATE reports SET status = ?, handled_by = ?, outcome = ?, resolved_at = ? WHERE id = ?', [$status, $a['id'], $outcome, $status === 'reviewing' ? null : now(), $r['id']]);
    if ($status !== 'reviewing' && in_array($r['target_type'], ['chat', 'message'], true)) {
        $cid = $r['target_type'] === 'chat' ? (int)$r['target_id'] : (int)val('SELECT chat_id FROM messages WHERE id = ?', [$r['target_id']]);
        q('UPDATE chats SET hold_for_review = 0, delete_after = COALESCE(delete_after, ?) WHERE id = ?', [plus('+7 days'), $cid]);
    }
    audit($a, 'report_' . $status, 'report', (int)$r['id'], $outcome);
    notify((int)$r['reporter_id'], 'report_update', 'Your report was reviewed', $outcome ?? 'Thank you for reporting.', []);
    json_out(['ok' => true]);
});

/** Admins can read a reported chat. Every read is logged. */
route('GET', '/admin/chats/{id}/messages', function ($p) {
    $a = require_admin();
    $c = must_find(row('SELECT * FROM chats WHERE id = ? AND hold_for_review = 1', [pid($p)]), 'Reported chat');
    audit($a, 'read_reported_chat', 'chat', (int)$c['id']);
    json_out(['messages' => rows('SELECT m.id, m.sender_id, u.name AS sender_name, m.type, m.body, m.created_at FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.chat_id = ? ORDER BY m.id', [$c['id']])]);
});

route('DELETE', '/admin/reviews/{id}', function ($p) {
    $a = require_admin();
    $r = must_find(row('SELECT * FROM reviews WHERE id = ?', [pid($p)]), 'Review');
    $reason = in_str('reason', 500, true);
    q('DELETE FROM reviews WHERE id = ?', [$r['id']]);
    audit($a, 'remove_review', 'review', (int)$r['id'], $reason, $r);
    notify((int)$r['reviewer_id'], 'review_removed', 'Your review was removed', $reason, []);
    json_out(['ok' => true]);
});

route('GET', '/admin/surveys', function () {
    require_admin();
    json_out(['surveys' => rows(
        'SELECT s.*, b.name AS building_name, u.name AS respondent_name FROM revocation_surveys s JOIN agency_applications aa ON aa.id = s.application_id JOIN buildings b ON b.id = aa.building_id JOIN users u ON u.id = s.respondent_id ORDER BY s.id DESC LIMIT 100'
    )]);
});

route('GET', '/admin/tenant-reports', function () {
    require_admin();
    $list = [];
    foreach (rows("SELECT r.*, t.user_id, t.owner_id, t.room_id, t.building_id FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE r.escalated_to_admin = 1 ORDER BY r.status = 'resolved', r.id DESC LIMIT 100") as $r) {
        $v = report_view($r);
        $v['tenant'] = user_card_by_id((int)$r['user_id']);
        $v['owner'] = user_card_by_id((int)$r['owner_id']);
        $v['room'] = building_title((int)$r['building_id'], (int)$r['room_id']);
        $list[] = $v;
    }
    json_out(['reports' => $list]);
});

route('POST', '/admin/tenant-reports/{id}/resolve', function ($p) {
    $a = require_admin();
    $r = must_find(row('SELECT r.*, t.user_id, t.owner_id FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE r.id = ? AND r.escalated_to_admin = 1', [pid($p)]), 'Report');
    $outcome = in_str('outcome', 500, true);
    q("UPDATE tenant_reports SET status = 'resolved', resolved_at = ?, admin_handler_id = ?, admin_outcome = ? WHERE id = ?", [now(), $a['id'], $outcome, $r['id']]);
    q('INSERT INTO tenant_report_updates (report_id, author_id, status, note) VALUES (?,?,?,?)', [$r['id'], $a['id'], 'resolved', 'Admin: ' . $outcome]);
    audit($a, 'resolve_tenant_report', 'tenant_report', (int)$r['id'], $outcome);
    notify((int)$r['user_id'], 'tenant_report', 'Admin update on your report', $outcome, ['report_id' => (int)$r['id']]);
    json_out(['ok' => true]);
});

/** "Other" areas suggested by owners wait here. */
route('GET', '/admin/areas/pending', function () {
    require_admin();
    json_out(['areas' => rows("SELECT a.id, a.name, c.name AS city_name FROM areas a JOIN cities c ON c.id = a.city_id WHERE a.status = 'pending' ORDER BY a.id")]);
});

route('POST', '/admin/areas/{id}/decide', function ($p) {
    $a = require_admin();
    $ar = must_find(row("SELECT * FROM areas WHERE id = ? AND status = 'pending'", [pid($p)]), 'Area');
    if (in_enum('decision', ['approve', 'reject']) === 'approve') {
        q("UPDATE areas SET status = 'approved' WHERE id = ?", [$ar['id']]);
        q('UPDATE buildings SET area_id = ?, area_other = NULL WHERE area_other = ? AND city_id = ?', [$ar['id'], $ar['name'], $ar['city_id']]);
    } else {
        q('UPDATE areas SET active = 0 WHERE id = ?', [$ar['id']]);
    }
    audit($a, 'area_decision', 'area', (int)$ar['id']);
    json_out(['ok' => true]);
});

/* ---------- admin accounts ---------- */

route('GET', '/admin/admins', function () {
    require_admin();
    json_out(['admins' => rows("SELECT id, name, username, admin_tier, promoted_by, promoted_at, status FROM users WHERE role = 'admin' ORDER BY FIELD(admin_tier, 'root', 'seeded', 'promoted'), id")]);
});

/**
 * Turn a normal user into an admin WITHOUT their consent (they are notified straight after).
 * Only seeded admins promote. Only "user" accounts qualify, and not while money or a tenancy is attached.
 */
route('POST', '/admin/users/{id}/promote', function ($p) {
    $a = require_seeded();
    $t = admin_user_row(pid($p));
    if ($t['role'] !== 'user' || $t['status'] !== 'active') {
        fail(422, 'Only active normal users can be made admins (not owners or agents).', 'validation');
    }
    $busy = (int)val("SELECT COUNT(*) FROM tenancy_processes WHERE user_id = ? AND status IN ('started','agreement_read','method_chosen','proof_uploaded','verified')", [$t['id']])
        + (int)val("SELECT COUNT(*) FROM tenancies WHERE user_id = ? AND status IN ('reserved','active')", [$t['id']])
        + (int)val("SELECT COUNT(*) FROM payment_disputes d JOIN payments py ON py.id = d.payment_id JOIN tenancy_processes tp ON tp.id = py.process_id WHERE tp.user_id = ? AND d.status = 'open'", [$t['id']])
        + (int)val("SELECT COUNT(*) FROM viewings WHERE user_id = ? AND status IN ('pending','accepted','date_change_requested')", [$t['id']]);
    if ($busy > 0) {
        fail(409, 'This user has an open viewing, payment, dispute or tenancy. Try again when it is closed.', 'user_busy');
    }
    q("UPDATE users SET role = 'admin', admin_tier = 'promoted', promoted_by = ?, promoted_at = ? WHERE id = ?", [$a['id'], now(), $t['id']]);
    audit($a, 'promote_admin', 'user', (int)$t['id'], in_str('reason', 500));
    notify((int)$t['id'], 'promoted_admin', 'You are now an admin', 'An admin gave you admin access. Log in again to see your admin pages.', []);
    send_mail($t['email'], 'You are now an admin', 'An admin has given your account admin access. Log in again to see your admin pages.');
    json_out(['ok' => true]);
});

route('POST', '/admin/admins/{id}/demote', function ($p) {
    $a = require_seeded();
    $t = admin_user_row(pid($p));
    if ($t['role'] !== 'admin' || $t['admin_tier'] !== 'promoted' || !admin_may_touch($a, $t)) {
        fail(403, 'You can only demote promoted admins.', 'forbidden_tier');
    }
    q("UPDATE users SET role = 'user', admin_tier = NULL WHERE id = ?", [$t['id']]);
    q('DELETE FROM api_tokens WHERE user_id = ?', [$t['id']]);
    audit($a, 'demote_admin', 'user', (int)$t['id'], in_str('reason', 500));
    json_out(['ok' => true]);
});

route('GET', '/admin/audit', function () {
    $a = require_admin();
    $own = !is_seeded_admin($a);
    json_out(['entries' => rows('SELECT l.*, u.name AS actor_name FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id' . ($own ? ' WHERE l.actor_id = ?' : '') . ' ORDER BY l.id DESC LIMIT 200', $own ? [$a['id']] : [])]);
});

/* =====================================================================
   MONEY: seeded and root admins only
   ===================================================================== */

function admin_payment(int $id): array
{
    return must_find(row("SELECT py.*, tp.user_id, tp.room_id, tp.building_id, tp.duration_months FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.id = ? AND py.method = 'app'", [$id]), 'Payment');
}

route('GET', '/admin/payments', function () {
    require_seeded();
    $status = in_enum('status', ['proof_uploaded', 'verified', 'paid_to_owner', 'owner_confirmed', 'rejected', 'refunded'], false) ?? 'proof_uploaded';
    $out = [];
    foreach (rows("SELECT py.*, tp.user_id, tp.room_id, tp.building_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.method = 'app' AND py.status = ? ORDER BY py.id", [$status]) as $r) {
        $out[] = ['id' => (int)$r['id'], 'status' => $r['status'], 'amount' => (float)$r['amount'], 'fee_amount' => (float)$r['fee_amount'], 'owner_amount' => (float)$r['owner_amount'],
                  'user' => user_card_by_id((int)$r['user_id']), 'reference_username' => $r['reference_username'], 'transaction_id' => $r['transaction_id'],
                  'building' => building_title((int)$r['building_id'], (int)$r['room_id']), 'created_at' => $r['created_at']];
    }
    json_out(['payments' => $out]);
});

/** Details including the owner's payout account: shown only here, only to seeded admins, only when needed to pay out. */
route('GET', '/admin/payments/{id}', function ($p) {
    $a = require_seeded();
    $py = admin_payment(pid($p));
    $owner = building_owner_id((int)$py['building_id']);
    $payout = in_array($py['status'], ['verified', 'paid_to_owner'], true) ? row('SELECT * FROM owner_payout_details WHERE owner_id = ?', [$owner]) : null;
    if ($payout) {
        audit($a, 'view_owner_payout', 'payment', (int)$py['id']);
    }
    $dup = $py['transaction_id'] ? (int)val('SELECT COUNT(*) FROM payments WHERE transaction_id = ? AND id <> ?', [$py['transaction_id'], $py['id']]) : 0;
    json_out(['payment' => $py, 'user' => user_card_by_id((int)$py['user_id']), 'owner' => user_card_by_id($owner), 'owner_payout' => $payout,
              'proof_path' => '/tenancy/payments/' . $py['id'] . '/proof', 'duplicate_transaction' => $dup > 0,
              'check' => 'Compare the amount, date and reference (' . $py['reference_username'] . ') with the real bank or MoMo statement, not just the photo.']);
});

route('POST', '/admin/payments/{id}/verify', function ($p) {
    $a = require_seeded();
    $py = admin_payment(pid($p));
    if ($py['status'] !== 'proof_uploaded') {
        fail(409, 'This payment is not waiting for verification.', 'already_decided');
    }
    if ((int)$py['user_id'] === (int)$a['id']) {
        fail(403, 'You cannot verify your own payment.', 'conflict_of_interest');
    }
    $tp = row('SELECT * FROM tenancy_processes WHERE id = ?', [$py['process_id']]);
    $action = in_enum('action', ['approve', 'reject']);
    if ($action === 'approve') {
        if ($py['transaction_id'] && (int)val('SELECT COUNT(*) FROM payments WHERE transaction_id = ? AND id <> ?', [$py['transaction_id'], $py['id']])) {
            fail(409, 'This transaction ID was used on another payment.', 'duplicate_receipt');
        }
        tx(function () use ($py, $tp, $a) {
            q("UPDATE payments SET status = 'verified', verifier_id = ?, verifier_role = 'admin', verified_at = ? WHERE id = ?", [$a['id'], now(), $py['id']]);
            q("UPDATE tenancy_processes SET status = 'verified', verified_at = ? WHERE id = ?", [now(), $tp['id']]);
            create_tenancy(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]), row('SELECT * FROM payments WHERE id = ?', [$py['id']]));
            audit($a, 'verify_payment', 'payment', (int)$py['id'], null, null, ['amount' => $py['amount']]);
        });
        notify((int)$tp['user_id'], 'payment_verified', 'Payment verified', 'Your payment is verified. Once the owner confirms receiving it you can choose your move-in date.', ['process_id' => (int)$tp['id']]);
    } else {
        $reason = in_str('reason', 200, true);
        reject_contact_info($reason, 'reason');
        q("UPDATE payments SET status = 'rejected', rejection_reason = ?, resubmit_by = ?, verifier_id = ?, verifier_role = 'admin' WHERE id = ?", [$reason, plus('+' . (int)setting('proof_resubmit_hours', '24') . ' hours'), $a['id'], $py['id']]);
        q("UPDATE tenancy_processes SET status = 'method_chosen' WHERE id = ?", [$tp['id']]);
        audit($a, 'reject_payment', 'payment', (int)$py['id'], $reason);
        notify((int)$tp['user_id'], 'payment_rejected', 'Payment proof rejected', "Reason: $reason. Upload corrected proof within 24 hours.", ['process_id' => (int)$tp['id']]);
    }
    json_out(['status' => $action === 'approve' ? 'verified' : 'rejected']);
});

/** After the admin pays the owner (minus the 5%), the owner is asked to tap "Payment received". */
route('POST', '/admin/payments/{id}/paid-to-owner', function ($p) {
    $a = require_seeded();
    $py = admin_payment(pid($p));
    if ($py['status'] !== 'verified') {
        fail(409, 'Verify the payment first.', 'not_ready');
    }
    q("UPDATE payments SET status = 'paid_to_owner', paid_to_owner_at = ? WHERE id = ?", [now(), $py['id']]);
    audit($a, 'paid_to_owner', 'payment', (int)$py['id'], null, null, ['owner_amount' => $py['owner_amount'], 'fee' => $py['fee_amount']]);
    notify(building_owner_id((int)$py['building_id']), 'payout_sent', 'Payment sent to you', 'GHS ' . number_format((float)$py['owner_amount'], 2) . ' was sent to you. Tap "Payment received" when it arrives.', ['payment_id' => (int)$py['id']]);
    json_out(['status' => 'paid_to_owner']);
});

/** Refund: decide the money outside the app, then record it here. Cancels a reserved tenancy and frees the room. */
route('POST', '/admin/payments/{id}/refund', function ($p) {
    $a = require_seeded();
    $py = must_find(row('SELECT py.*, tp.user_id, tp.room_id, tp.building_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.id = ?', [pid($p)]), 'Payment');
    if (in_array($py['status'], ['refunded', 'awaiting_proof'], true)) {
        fail(409, 'Nothing to refund.', 'not_ready');
    }
    $reason = in_str('reason', 500, true);
    tx(function () use ($py, $a, $reason) {
        q("UPDATE payments SET status = 'refunded', rejection_reason = ? WHERE id = ?", [$reason, $py['id']]);
        $t = row("SELECT * FROM tenancies WHERE process_id = ? AND status = 'reserved'", [$py['process_id']]);
        if ($t) {
            q("UPDATE tenancies SET status = 'terminated' WHERE id = ?", [$t['id']]);
            q("UPDATE rooms SET status = 'available' WHERE id = ? AND status = 'reserved'", [$t['room_id']]);
        }
        audit($a, 'refund', 'payment', (int)$py['id'], $reason);
    });
    notify((int)$py['user_id'], 'payment_refunded', 'Payment refunded', $reason, []);
    notify(building_owner_id((int)$py['building_id']), 'payment_refunded', 'Payment refunded', 'A payment for ' . building_title((int)$py['building_id'], (int)$py['room_id']) . ' was refunded.', []);
    json_out(['status' => 'refunded']);
});

/* ---------- disputes (users and owners can open one on a payment) ---------- */

route('POST', '/payments/{id}/dispute', function ($p) {
    $u = require_role('user', 'owner');
    $py = must_find(row('SELECT py.*, tp.user_id, tp.building_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.id = ?', [pid($p)]), 'Payment');
    $party = (int)$py['user_id'] === (int)$u['id'] || ($u['role'] === 'owner' && building_owner_id((int)$py['building_id']) === (int)$u['id']);
    if (!$party) {
        fail(404, 'Payment not found', 'not_found');
    }
    if (val("SELECT 1 FROM payment_disputes WHERE payment_id = ? AND status = 'open'", [$py['id']])) {
        fail(409, 'A dispute is already open for this payment.', 'duplicate');
    }
    $reason = in_str('reason', 500, true);
    reject_contact_info($reason, 'reason');
    $ev = uploaded_files('evidence')[0] ?? null;
    q('INSERT INTO payment_disputes (payment_id, opened_by, reason, evidence_path) VALUES (?,?,?,?)', [$py['id'], $u['id'], $reason, $ev ? save_image($ev, 'disputes', true, 2000) : null]);
    notify_admins('payment_dispute', 'Payment dispute opened', mb_substr($reason, 0, 100), true, ['payment_id' => (int)$py['id']]);
    json_out(['id' => insert_id()], 201);
});

route('GET', '/admin/disputes', function () {
    require_seeded();
    $out = [];
    foreach (rows("SELECT d.*, py.amount, py.method, py.transaction_id, tp.user_id, tp.building_id, tp.room_id FROM payment_disputes d JOIN payments py ON py.id = d.payment_id JOIN tenancy_processes tp ON tp.id = py.process_id WHERE d.status = 'open' ORDER BY d.id") as $d) {
        $d['opened_by_user'] = user_card_by_id((int)$d['opened_by']);
        $d['tenant'] = user_card_by_id((int)$d['user_id']);
        $d['owner'] = user_card_by_id(building_owner_id((int)$d['building_id']));
        $d['building'] = building_title((int)$d['building_id'], (int)$d['room_id']);
        $out[] = $d;
    }
    json_out(['disputes' => $out]);
});

route('POST', '/admin/disputes/{id}/resolve', function ($p) {
    $a = require_seeded();
    $d = must_find(row("SELECT d.*, tp.user_id, tp.building_id FROM payment_disputes d JOIN payments py ON py.id = d.payment_id JOIN tenancy_processes tp ON tp.id = py.process_id WHERE d.id = ? AND d.status = 'open'", [pid($p)]), 'Dispute');
    if ((int)$d['user_id'] === (int)$a['id']) {
        fail(403, 'You cannot rule on your own dispute.', 'conflict_of_interest');
    }
    $outcome = in_str('outcome', 500, true);
    q("UPDATE payment_disputes SET status = 'resolved', handled_by = ?, outcome = ?, resolved_at = ? WHERE id = ?", [$a['id'], $outcome, now(), $d['id']]);
    audit($a, 'resolve_dispute', 'payment_dispute', (int)$d['id'], $outcome);
    notify((int)$d['opened_by'], 'dispute_resolved', 'Dispute resolved', $outcome, ['payment_id' => (int)$d['payment_id']]);
    $other = (int)$d['opened_by'] === (int)$d['user_id'] ? building_owner_id((int)$d['building_id']) : (int)$d['user_id'];
    notify($other, 'dispute_resolved', 'Dispute resolved', $outcome, ['payment_id' => (int)$d['payment_id']]);
    json_out(['ok' => true]);
});

/* ---------- settings ---------- */

const ADMIN_SETTINGS = [
    'platform_fee_percent' => ['float', 0, 50], 'fee_payer' => ['enum', ['owner', 'tenant']],
    'tenant_process_days' => ['int', 1, 30], 'owner_verify_hours' => ['int', 1, 720], 'proof_resubmit_hours' => ['int', 1, 168],
    'viewing_date_change_days' => ['int', 1, 14], 'viewing_request_expiry_hours' => ['int', 1, 336], 'viewing_max_date_rounds' => ['int', 1, 10],
    'chat_delete_days' => ['int', 1, 90], 'renewal_prompt_start_days' => ['int', 1, 365], 'renewal_prompt_end_days' => ['int', 1, 365],
    'move_in_window_days' => ['int', 1, 365], 'max_building_photos' => ['int', 1, 30], 'max_room_photos' => ['int', 1, 30],
    'verification_code_minutes' => ['int', 1, 120], 'sms_code_minutes' => ['int', 1, 60], 'sms_daily_cap_per_number' => ['int', 1, 20],
    'tenant_report_wait_days' => ['int', 0, 30], 'unverified_delete_days' => ['int', 1, 90],
];

route('GET', '/admin/settings', function () {
    require_seeded();
    json_out(['settings' => rows('SELECT `key`, value, description, updated_at FROM settings ORDER BY `key`')]);
});

route('PUT', '/admin/settings', function () {
    $a = require_seeded();
    $changed = [];
    foreach (input()['settings'] ?? [] as $k => $v) {
        if (!isset(ADMIN_SETTINGS[$k])) {
            fail(422, "Unknown setting: $k", 'validation');
        }
        $rule = ADMIN_SETTINGS[$k];
        if ($rule[0] === 'enum') {
            if (!in_array($v, $rule[1], true)) {
                fail(422, "$k must be one of " . implode(', ', $rule[1]), 'validation');
            }
        } elseif (!is_numeric($v) || (float)$v < $rule[1] || (float)$v > $rule[2] || ($rule[0] === 'int' && (string)(int)$v !== (string)$v && (string)(int)$v !== (string)(float)$v)) {
            fail(422, "$k must be a number between {$rule[1]} and {$rule[2]}", 'validation');
        }
        $old = val('SELECT value FROM settings WHERE `key` = ?', [$k]);
        q('UPDATE settings SET value = ?, updated_by = ? WHERE `key` = ?', [(string)$v, $a['id'], $k]);
        audit($a, 'change_setting', 'setting', null, $k, ['value' => $old], ['value' => (string)$v]);
        $changed[$k] = (string)$v;
    }
    json_out(['changed' => $changed]);
});

/* ---------- the app's own payment details: root admin only ---------- */

route('GET', '/admin/app-payment-details', function () {
    require_seeded();
    json_out(['details' => row('SELECT bank_name, account_name, account_number, momo_network, momo_number, momo_name, updated_at FROM app_payment_details WHERE id = 1'), 'can_edit' => current_user()['admin_tier'] === 'root']);
});

route('PUT', '/admin/app-payment-details', function () {
    $a = require_root();
    if (!password_verify((string)(input()['password'] ?? ''), $a['password_hash'])) {
        fail(403, 'Enter your password to change the app payment details.', 'bad_credentials');
    }
    $new = ['bank_name' => in_str('bank_name', 80), 'account_name' => in_str('account_name', 120), 'account_number' => in_str('account_number', 40),
            'momo_network' => in_str('momo_network', 30), 'momo_number' => in_str('momo_number', 16), 'momo_name' => in_str('momo_name', 120)];
    if ($new['momo_number'] !== null) {
        $new['momo_number'] = normalize_phone($new['momo_number']) ?? fail(422, 'Enter a valid Ghana MoMo number.', 'validation', ['field' => 'momo_number']);
    }
    if (!$new['account_number'] && !$new['momo_number']) {
        fail(422, 'Add a bank account, a MoMo number, or both.', 'validation');
    }
    $old = row('SELECT bank_name, account_name, account_number, momo_network, momo_number, momo_name FROM app_payment_details WHERE id = 1');
    tx(function () use ($new, $old, $a) {
        q('UPDATE app_payment_details SET bank_name=?, account_name=?, account_number=?, momo_network=?, momo_number=?, momo_name=?, updated_by=? WHERE id = 1',
            [$new['bank_name'], $new['account_name'], $new['account_number'], $new['momo_network'], $new['momo_number'], $new['momo_name'], $a['id']]);
        q('INSERT INTO app_payment_details_history (changed_by, old_values, new_values) VALUES (?,?,?)', [$a['id'], json_encode($old), json_encode($new)]);
        audit($a, 'change_app_payment_details', 'app_payment_details', 1, null, $old, $new);
    });
    notify_admins('app_payment_details_changed', 'App payment details changed', 'The root admin changed the app bank / MoMo details.', false);   // every other admin is told
    json_out(['ok' => true, 'note' => 'New details apply to new payment steps only. Users already paying keep the details they were shown.']);
});

/* ---------- lists: cities, policies, amenities (seeded admins) ---------- */

route('POST', '/admin/cities', function () {
    $a = require_seeded();
    $region = in_int('region_id', true);
    must_find(row('SELECT id FROM regions WHERE id = ?', [$region]), 'Region');
    q('INSERT INTO cities (region_id, name) VALUES (?,?)', [$region, in_str('name', 80, true)]);
    audit($a, 'add_city', 'city', insert_id());
    json_out(['id' => insert_id()], 201);
});

route('POST', '/admin/areas', function () {
    $a = require_seeded();
    $city = in_int('city_id', true);
    must_find(row('SELECT id FROM cities WHERE id = ?', [$city]), 'City');
    q('INSERT INTO areas (city_id, name) VALUES (?,?)', [$city, in_str('name', 80, true)]);
    audit($a, 'add_area', 'area', insert_id());
    json_out(['id' => insert_id()], 201);
});

route('POST', '/admin/termination-policies', function () {
    $a = require_seeded();
    $basis = in_enum('basis', ['remaining_duration', 'remaining_rent', 'forfeit_months', 'no_refund']);
    $pct = input()['percent'] ?? null;
    $months = in_int('months');
    if (in_array($basis, ['remaining_duration', 'remaining_rent'], true) && (!is_numeric($pct) || (float)$pct <= 0 || (float)$pct > 100)) {
        fail(422, 'Enter a percent between 1 and 100.', 'validation', ['field' => 'percent']);
    }
    q('INSERT INTO termination_policies (label, basis, percent, months) VALUES (?,?,?,?)', [in_str('label', 120, true), $basis, is_numeric($pct) ? (float)$pct : null, $months]);
    audit($a, 'add_policy', 'termination_policy', insert_id());
    json_out(['id' => insert_id()], 201);
});

route('POST', '/admin/amenities', function () {
    $a = require_seeded();
    $code = in_str('code', 40, true);
    if (!preg_match('/^[a-z0-9_]+$/', $code)) {
        fail(422, 'Code: lowercase letters, numbers, underscores.', 'validation', ['field' => 'code']);
    }
    $kind = in_enum('kind', ['yes_no', 'choice']);
    tx(function () use ($code, $kind) {
        q('INSERT INTO amenity_definitions (code, scope, label, kind, searchable, sort_order) VALUES (?,?,?,?,?,?)',
            [$code, in_enum('scope', ['building', 'room']), in_str('label', 80, true), $kind, in_bool('searchable') ? 1 : 0, in_int('sort_order') ?? 99]);
        $id = insert_id();
        if ($kind === 'choice') {
            $opts = input()['options'] ?? [];
            if (!is_array($opts) || count($opts) < 2) {
                fail(422, 'Add at least two options.', 'validation');
            }
            foreach ($opts as $o) {
                q('INSERT INTO amenity_options (amenity_id, value, label, needs_payer) VALUES (?,?,?,?)', [$id, (string)($o['value'] ?? ''), (string)($o['label'] ?? ''), !empty($o['needs_payer']) ? 1 : 0]);
            }
        }
    });
    audit($a, 'add_amenity', 'amenity', null, $code);
    json_out(['ok' => true], 201);
});
