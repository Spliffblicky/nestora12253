<?php
declare(strict_types=1);

require_once __DIR__ . '/../lifecycle.php';

/* =====================================================================
   BECOMING A TENANT (in order)
   1. start (viewing complete, phone verified) -> 5-day clock starts, room is held
   2. room check (same room? or pick another from the slideshow)
   3. download + read the tenancy agreement (chat with the owner opens)
   4. choose app or third party
   5. payment details revealed only now (server-checked), user pays and uploads proof within 5 days
   6. verifier acts (owner: third party, admin: app). Once proof is uploaded the process waits for the verifier.
   7. user picks a move-in date, owner records signing on move-in day (witness, printed agreement)
   Then: tenancy section, problem reports, renewal prompts, early exit.
   ===================================================================== */

function load_process(int $id, array $u): array
{
    $tp = must_find(row('SELECT * FROM tenancy_processes WHERE id = ?', [$id]), 'Tenant process');
    if ((int)$tp['user_id'] !== (int)$u['id']) {
        fail(404, 'Tenant process not found', 'not_found');
    }
    return $tp;
}

function payment_of(int $process_id): ?array
{
    return row('SELECT * FROM payments WHERE process_id = ? ORDER BY id DESC LIMIT 1', [$process_id]);
}

function assert_process_open(array $tp): void
{
    if (!in_array($tp['status'], process_open_statuses(), true)) {
        fail(409, 'This tenant process has ended.', 'process_ended');
    }
}

/** Before proof is uploaded the 5-day clock applies; a rejected proof gets its own short resubmit window. */
function assert_not_timed_out(array $tp): void
{
    $p = payment_of((int)$tp['id']);
    if ($p && $p['status'] === 'rejected' && $p['resubmit_by']) {
        if (ts($p['resubmit_by']) < time()) {
            fail(410, 'The time to upload corrected proof has ended.', 'process_expired');
        }
        return;
    }
    if (ts($tp['expires_at']) < time()) {
        fail(410, 'The 5 days for this tenant process have ended.', 'process_expired');
    }
}

function terms_for(array $tp): array
{
    $room = row('SELECT r.*, rt.name AS room_type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?', [$tp['room_id']]);
    $b = row('SELECT b.*, pol.label AS pol_label, pol.basis AS pol_basis, pol.percent AS pol_percent FROM buildings b LEFT JOIN termination_policies pol ON pol.id = b.termination_policy_id WHERE b.id = ?', [$tp['building_id']]);
    $durations = array_map('intval', array_column(rows('SELECT duration_months FROM room_rent_options WHERE room_id = ? ORDER BY 1', [$room['id']]), 'duration_months'));
    $monthly = (float)$room['monthly_rent'];
    $dur = $tp['duration_months'] ? (int)$tp['duration_months'] : null;
    $total = $dur ? money($monthly * $dur) : null;
    $example = null;
    if ($b['pol_basis'] && $dur) {                                       // worked example for a mid-term exit
        $ex = exit_quote(['end_date' => gmdate('Y-m-d', strtotime('+' . (int)floor($dur / 2) . ' months')), 'rent_monthly' => $monthly,
                          'termination_basis' => $b['pol_basis'], 'termination_percent' => $b['pol_percent'], 'termination_label' => $b['pol_label']]);
        $example = 'Leaving with about ' . (int)floor($dur / 2) . ' months left: ' . $ex['note'] . ' = GHS ' . number_format($ex['deduction'], 2) . ' deducted.';
    }
    return [
        'room' => ['id' => (int)$room['id'], 'label' => $room['label'], 'type' => $room['room_type_name'], 'floor' => $room['floor_number']],
        'building' => ['id' => (int)$b['id'], 'name' => $b['name']],
        'monthly_rent' => $monthly, 'durations' => $durations, 'duration_months' => $dur, 'rent_total' => $total,
        'termination' => ['label' => $b['pol_label'], 'basis' => $b['pol_basis'], 'percent' => $b['pol_percent'] !== null ? (float)$b['pol_percent'] : null, 'example' => $example],
        'fee_preview' => $total ? ['app' => fee_breakdown($total, 'app'), 'third_party' => fee_breakdown($total, 'third_party')] : null,
    ];
}

function process_view(array $tp): array
{
    $pay = payment_of((int)$tp['id']);
    $tenancy = row('SELECT id, status, move_in_date, move_in_deadline, end_date FROM tenancies WHERE process_id = ?', [$tp['id']]);
    $open = in_array($tp['status'], process_open_statuses(), true);
    $waiting = $tp['status'] === 'proof_uploaded' || ($pay && $pay['status'] === 'proof_uploaded');
    $countdown = null;
    if ($open && !$waiting) {
        $end = ($pay && $pay['status'] === 'rejected' && $pay['resubmit_by']) ? $pay['resubmit_by'] : $tp['expires_at'];
        $left = max(0, ts($end) - time());
        $countdown = ['ends_at' => $end, 'seconds_left' => $left, 'level' => $left < 6 * 3600 ? 'red' : ($left < 24 * 3600 ? 'amber' : 'normal'),
                      'label' => ($pay && $pay['status'] === 'rejected') ? 'Upload corrected proof' : 'Upload your payment proof'];
    }
    $waiting_for = null;
    if ($waiting && $pay) {
        $waiting_for = $pay['method'] === 'app' ? 'admin' : 'owner';
    }
    $steps = [
        ['key' => 'started', 'done' => true],
        ['key' => 'room_check', 'done' => (bool)$tp['room_confirmed_at']],
        ['key' => 'agreement', 'done' => (bool)$tp['agreement_read_at']],
        ['key' => 'method', 'done' => (bool)$tp['payment_method']],
        ['key' => 'proof', 'done' => $pay ? in_array($pay['status'], ['proof_uploaded', 'verified', 'paid_to_owner', 'owner_confirmed'], true) : false],
        ['key' => 'verified', 'done' => $pay ? in_array($pay['status'], ['verified', 'paid_to_owner', 'owner_confirmed'], true) : false],
        ['key' => 'move_in', 'done' => (bool)($tenancy['move_in_date'] ?? null)],
    ];
    return [
        'id' => (int)$tp['id'], 'status' => $tp['status'], 'is_renewal' => process_is_renewal($tp), 'started_at' => $tp['started_at'], 'expires_at' => $tp['expires_at'],
        'room_confirmed' => (bool)$tp['room_confirmed_at'], 'agreement_opened' => (bool)$tp['agreement_opened_at'], 'agreement_read' => (bool)$tp['agreement_read_at'],
        'payment_method' => $tp['payment_method'], 'countdown' => $countdown, 'waiting_for' => $waiting_for, 'steps' => $steps,
        'terms' => terms_for($tp),
        'payment' => $pay ? ['id' => (int)$pay['id'], 'status' => $pay['status'], 'method' => $pay['method'], 'amount' => (float)$pay['amount'], 'fee_percent' => (float)$pay['fee_percent'],
                             'fee_amount' => (float)$pay['fee_amount'], 'owner_amount' => (float)$pay['owner_amount'], 'rejection_reason' => $pay['rejection_reason'], 'resubmit_by' => $pay['resubmit_by'],
                             'proof_uploaded' => (bool)$pay['proof_path']] : null,
        'tenancy' => $tenancy ? ['id' => (int)$tenancy['id'], 'status' => $tenancy['status'], 'move_in_date' => $tenancy['move_in_date'], 'move_in_deadline' => $tenancy['move_in_deadline'], 'end_date' => $tenancy['end_date'],
                                 'can_pick_move_in' => $pay ? payment_ready_for_move_in($pay) && !$tenancy['move_in_date'] : false] : null,
        'chat_id' => ($c = val("SELECT id FROM chats WHERE process_id = ? AND type = 'tenant_owner' LIMIT 1", [$tp['id']])) ? (int)$c : null,
        'owner' => user_card_by_id(building_owner_id((int)$tp['building_id'])),
        'agent' => $tp['agent_id'] ? user_card_by_id((int)$tp['agent_id']) : null,
    ];
}

/* ---------- 1. start ---------- */

route('POST', '/tenancy/start', function () {
    $u = require_role('user');
    if (!$u['phone_verified_at']) {
        fail(428, 'Verify your phone number to start renting.', 'phone_verification_required');
    }
    $v = must_find(row("SELECT * FROM viewings WHERE id = ? AND user_id = ? AND status = 'completed'", [in_int('viewing_id', true), $u['id']]), 'Completed viewing');
    if (val("SELECT 1 FROM tenancy_processes WHERE user_id = ? AND status IN ('started','agreement_read','method_chosen','proof_uploaded')", [$u['id']])) {
        fail(409, 'You already have a tenant process in progress.', 'duplicate');
    }
    if (!val("SELECT 1 FROM buildings WHERE id = ? AND status = 'live'", [$v['building_id']])) {
        fail(409, 'This building is not available.', 'room_unavailable');
    }
    $room_id = $v['room_id'] ? (int)$v['room_id'] : in_int('room_id', true);
    $room = must_find(row('SELECT * FROM rooms WHERE id = ? AND building_id = ?', [$room_id, $v['building_id']]), 'Room');
    $b = row('SELECT * FROM buildings WHERE id = ?', [$v['building_id']]);

    $id = tx(function () use ($u, $v, $room, $b) {
        reserve_room((int)$room['id']);
        q('INSERT INTO tenancy_processes (user_id, building_id, room_id, viewing_id, agent_id, expires_at, agreement_id, room_confirmed_at) VALUES (?,?,?,?,?,?,?,?)',
            [$u['id'], $v['building_id'], $room['id'], $v['id'], $v['agent_id'], plus('+' . (int)setting('tenant_process_days', '5') . ' days'), match_agreement($b, $room), $v['room_id'] ? null : now()]);
        $id = insert_id();
        q("UPDATE viewings SET rent_decision = 'rent_now' WHERE id = ?", [$v['id']]);
        return $id;
    });
    $title = building_title((int)$v['building_id'], (int)$room['id']);
    notify(building_owner_id((int)$v['building_id']), 'tenant_interest', 'A user wants to rent', $u['name'] . " wants to rent $title.", ['process_id' => $id]);
    notify((int)$v['agent_id'], 'tenant_interest', 'Your lead wants to rent', $u['name'] . " wants to rent $title.", ['process_id' => $id]);
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$id]))], 201);
});

route('GET', '/tenancy/processes', function () {
    $u = require_role('user');
    json_out(['processes' => array_map('process_view', rows('SELECT * FROM tenancy_processes WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']]))]);
});

route('GET', '/tenancy/processes/{id}', function ($p) {
    $u = require_role('user');
    json_out(['process' => process_view(load_process(pid($p), $u))]);
});

/* ---------- 2. room check ---------- */

route('POST', '/tenancy/processes/{id}/room', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    assert_not_timed_out($tp);
    if ($tp['status'] === 'proof_uploaded') {
        fail(409, 'You can no longer change the room.', 'not_ready');
    }
    $same = in_bool('same_room');
    if ($same) {
        q('UPDATE tenancy_processes SET room_confirmed_at = ? WHERE id = ?', [now(), $tp['id']]);
        json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]))]);
    }
    // "No": switch to another available room from the slideshow
    $new = in_int('room_id', true);
    if ($new === (int)$tp['room_id']) {
        fail(422, 'That is the room you already have.', 'validation');
    }
    $room = must_find(row('SELECT * FROM rooms WHERE id = ? AND building_id = ?', [$new, $tp['building_id']]), 'Room');
    $b = row('SELECT * FROM buildings WHERE id = ?', [$tp['building_id']]);
    $agr = match_agreement($b, $room);
    tx(function () use ($tp, $room, $agr) {
        reserve_room((int)$room['id']);
        release_room((int)$tp['room_id']);
        q('INSERT INTO tenancy_process_room_changes (process_id, from_room_id, to_room_id) VALUES (?,?,?)', [$tp['id'], $tp['room_id'], $room['id']]);
        $same_agreement = $agr !== null && (int)$agr === (int)$tp['agreement_id'];
        // price and agreement can change: payment choice is reset, and the new agreement must be read if it differs
        q("DELETE FROM payments WHERE process_id = ? AND status = 'awaiting_proof'", [$tp['id']]);
        q("UPDATE tenancy_processes SET room_id = ?, agreement_id = ?, room_confirmed_at = ?, payment_method = NULL, duration_months = NULL, status = ?,
           agreement_opened_at = IF(?, agreement_opened_at, NULL), agreement_read_at = IF(?, agreement_read_at, NULL) WHERE id = ?",
            [$room['id'], $agr, now(), $same_agreement && $tp['agreement_read_at'] ? 'agreement_read' : 'started', $same_agreement ? 1 : 0, $same_agreement ? 1 : 0, $tp['id']]);
    });
    $title = building_title((int)$tp['building_id'], (int)$room['id']);
    notify(building_owner_id((int)$tp['building_id']), 'tenant_room_changed', 'Tenant changed room', $u['name'] . " now wants $title.", ['process_id' => (int)$tp['id']]);
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]))]);
});

/* ---------- 3. agreement ---------- */

route('GET', '/tenancy/processes/{id}/agreement', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    if (!$tp['room_confirmed_at']) {
        fail(409, 'Confirm the room first.', 'not_ready');
    }
    $a = must_find($tp['agreement_id'] ? row('SELECT * FROM agreements WHERE id = ?', [$tp['agreement_id']]) : null, 'Agreement');
    q('UPDATE tenancy_processes SET agreement_opened_at = COALESCE(agreement_opened_at, ?) WHERE id = ?', [now(), $tp['id']]);
    serve_private($a['pdf_path'], 'application/pdf', 'tenancy-agreement.pdf');
});

/** "I have read the tenancy agreement". Only possible after the PDF was opened. Opens chat with the owner. */
route('POST', '/tenancy/processes/{id}/agreement-read', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    assert_not_timed_out($tp);
    if (!$tp['agreement_opened_at']) {
        fail(409, 'Please open and read the agreement first.', 'agreement_not_opened');
    }
    if (!in_bool('confirm')) {
        fail(422, 'Tick the box to confirm you have read it.', 'validation');
    }
    if (!$tp['agreement_read_at']) {
        q("UPDATE tenancy_processes SET agreement_read_at = ?, status = IF(status = 'started', 'agreement_read', status) WHERE id = ?", [now(), $tp['id']]);
    }
    $owner = building_owner_id((int)$tp['building_id']);
    if (!val("SELECT 1 FROM chats WHERE process_id = ? AND type = 'tenant_owner'", [$tp['id']])) {
        $cid = chat_create('tenant_owner', (int)$tp['building_id'], (int)$tp['room_id'], null, (int)$tp['id'], [(int)$u['id'], $owner]);
        chat_system($cid, $u['name'] . ' confirmed they want to rent and has read the tenancy agreement.');
    }
    notify($owner, 'tenant_agreement_read', 'Tenant read the agreement', $u['name'] . ' wants to rent ' . building_title((int)$tp['building_id'], (int)$tp['room_id']) . '. You can chat now.', ['process_id' => (int)$tp['id']]);
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]))]);
});

/* ---------- 4. choose app / third party ---------- */

route('POST', '/tenancy/processes/{id}/method', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    assert_not_timed_out($tp);
    if (!$tp['room_confirmed_at'] || !$tp['agreement_read_at']) {
        fail(409, 'Confirm the room and read the agreement first.', 'not_ready');
    }
    if (!$u['phone_verified_at']) {
        fail(428, 'Verify your phone number first.', 'phone_verification_required');
    }
    $pay = payment_of((int)$tp['id']);
    if ($tp['status'] === 'proof_uploaded' || ($pay && in_array($pay['status'], ['proof_uploaded', 'verified', 'paid_to_owner', 'owner_confirmed'], true))) {
        fail(409, 'Payment is already in progress.', 'not_ready');
    }
    $method = in_enum('method', ['app', 'third_party']);
    $months = in_int('duration_months', true);
    $room = row('SELECT * FROM rooms WHERE id = ?', [$tp['room_id']]);
    if (!val('SELECT 1 FROM room_rent_options WHERE room_id = ? AND duration_months = ?', [$room['id'], $months])) {
        fail(422, 'That rent duration is not offered for this room.', 'validation', ['field' => 'duration_months']);
    }
    $total = money((float)$room['monthly_rent'] * $months);
    $fb = fee_breakdown($total, $method);
    tx(function () use ($tp, $method, $months, $fb) {
        q("DELETE FROM payments WHERE process_id = ? AND status IN ('awaiting_proof','rejected')", [$tp['id']]);      // switching method hides the other route's details again
        q('INSERT INTO payments (process_id, method, amount, fee_percent, fee_amount, owner_amount, fee_payer) VALUES (?,?,?,?,?,?,?)',
            [$tp['id'], $method, $fb['amount'], $fb['fee_percent'], $fb['fee_amount'], $fb['owner_amount'], $fb['fee_payer']]);
        q("UPDATE tenancy_processes SET payment_method = ?, duration_months = ?, status = 'method_chosen' WHERE id = ?", [$method, $months, $tp['id']]);
    });
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]))]);
});

/* ---------- 5. payment details (server-gated) and proof ---------- */

route('GET', '/tenancy/processes/{id}/payment-details', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    assert_not_timed_out($tp);
    $pay = payment_of((int)$tp['id']);
    // only users who are in the tenant process, have read the agreement, picked a method, and still need to pay
    if (!$u['phone_verified_at'] || !$tp['room_confirmed_at'] || !$tp['agreement_read_at'] || !$pay || !in_array($pay['status'], ['awaiting_proof', 'rejected'], true)) {
        fail(403, 'Payment details are not available yet.', 'details_locked');
    }
    $owner_id = building_owner_id((int)$tp['building_id']);
    if ($pay['method'] === 'app') {
        $d = row('SELECT bank_name, account_name, account_number, momo_network, momo_number, momo_name FROM app_payment_details WHERE id = 1');
        if (!$d || (!$d['account_number'] && !$d['momo_number'])) {
            fail(503, 'App payment details are not set up yet. Please try again later.', 'app_details_missing');
        }
        q("INSERT INTO payment_detail_reveals (process_id, user_id, owner_id, kind) VALUES (?,?,?, 'app_details')", [$tp['id'], $u['id'], $owner_id]);
        json_out(['kind' => 'app', 'details' => $d, 'reference' => $u['username'], 'amount' => (float)$pay['amount'], 'fee_percent' => (float)$pay['fee_percent'], 'fee_amount' => (float)$pay['fee_amount'], 'owner_amount' => (float)$pay['owner_amount'],
                  'instructions' => 'Put your username (' . $u['username'] . ') in the payment reference, then upload a photo of your payment.']);
    }
    $d = row('SELECT momo_network, momo_number, momo_name, bank_name, account_name, account_number FROM owner_payout_details WHERE owner_id = ?', [$owner_id]);
    if (!$d) {
        fail(503, 'The owner has not added payment details yet.', 'owner_details_missing');
    }
    q("INSERT INTO payment_detail_reveals (process_id, user_id, owner_id, kind) VALUES (?,?,?, 'owner_details')", [$tp['id'], $u['id'], $owner_id]);
    json_out(['kind' => 'third_party', 'details' => $d, 'owner' => user_card_by_id($owner_id), 'amount' => (float)$pay['amount'],
              'instructions' => 'Pay the owner, then tap "Payment made" and upload your receipt.']);
});

/** "Payment made" + receipt. Must happen within the 5 days (or the resubmit window after a rejection). */
route('POST', '/tenancy/processes/{id}/proof', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    assert_not_timed_out($tp);
    $pay = payment_of((int)$tp['id']);
    if (!$pay || !in_array($pay['status'], ['awaiting_proof', 'rejected'], true)) {
        fail(409, 'No payment is waiting for proof.', 'not_ready');
    }
    $f = uploaded_files('proof')[0] ?? null;
    if (!$f) {
        fail(422, 'Upload a photo of your payment or receipt.', 'validation', ['field' => 'proof']);
    }
    $txn = in_str('transaction_id', 80);
    if ($txn !== null) {
        $txn = strtoupper(preg_replace('/\s+/', '', $txn));
        if (val('SELECT 1 FROM payments WHERE transaction_id = ? AND id <> ?', [$txn, $pay['id']])) {
            fail(409, 'That transaction ID was already used.', 'duplicate_receipt');
        }
    }
    $rel = save_image($f, 'proofs', true, 2000);
    delete_file($pay['proof_path'], true);
    $verify_by = $pay['method'] === 'third_party' ? plus('+' . (int)setting('owner_verify_hours', '48') . ' hours') : null;
    tx(function () use ($pay, $tp, $rel, $txn, $verify_by) {
        q("UPDATE payments SET proof_path = ?, transaction_id = ?, status = 'proof_uploaded', verify_by = ?, rejection_reason = NULL, resubmit_by = NULL, reminder_stage = 0, reference_username = ? WHERE id = ?",
            [$rel, $txn, $verify_by, $pay['method'] === 'app' ? (string)val('SELECT username FROM users WHERE id = ?', [$tp['user_id']]) : null, $pay['id']]);
        q("UPDATE tenancy_processes SET status = 'proof_uploaded', proof_uploaded_at = ? WHERE id = ?", [now(), $tp['id']]);      // the 5-day countdown stops here
    });
    $title = building_title((int)$tp['building_id'], (int)$tp['room_id']);
    if ($pay['method'] === 'third_party') {
        notify(building_owner_id((int)$tp['building_id']), 'verify_payment', 'Verify a payment', $u['name'] . " says they paid for $title. Check your account and confirm.", ['payment_id' => (int)$pay['id'], 'process_id' => (int)$tp['id']]);
    } else {
        notify_admins('verify_payment', 'App payment to verify', $u['name'] . " uploaded proof for $title.", true, ['payment_id' => (int)$pay['id']]);
        notify(building_owner_id((int)$tp['building_id']), 'payment_proof_uploaded', 'Payment proof uploaded', $u['name'] . ' paid through the app. It is being verified.', ['process_id' => (int)$tp['id']]);
    }
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]))]);
});

route('GET', '/tenancy/payments/{id}/proof', function ($p) {
    $u = require_user();
    $pay = must_find(row('SELECT py.*, tp.user_id, tp.building_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id WHERE py.id = ?', [pid($p)]), 'Payment');
    $allowed = (int)$pay['user_id'] === (int)$u['id']
        || ($u['role'] === 'owner' && building_owner_id((int)$pay['building_id']) === (int)$u['id'] && $pay['method'] === 'third_party')
        || is_seeded_admin($u);
    if (!$allowed || !$pay['proof_path']) {
        fail(404, 'Not found', 'not_found');
    }
    serve_private($pay['proof_path'], 'image/jpeg');
});

route('POST', '/tenancy/processes/{id}/cancel', function ($p) {
    $u = require_role('user');
    $tp = load_process(pid($p), $u);
    assert_process_open($tp);
    if ($tp['status'] === 'proof_uploaded') {
        fail(409, 'You have already uploaded payment proof. Open a dispute or contact the admin instead.', 'not_ready');
    }
    end_process($tp, 'cancelled', 'Cancelled by the tenant');
    json_out(['status' => 'cancelled']);
});

/** Owner pinned container: third-party payments waiting for them (shown first at next app open). */
route('GET', '/owner/pending-verifications', function () {
    $o = require_role('owner');
    $out = [];
    foreach (rows(
        "SELECT py.*, tp.user_id, tp.room_id, tp.building_id, tp.duration_months FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id JOIN buildings b ON b.id = tp.building_id
         WHERE b.owner_id = ? AND py.method = 'third_party' AND py.status = 'proof_uploaded' ORDER BY py.id", [$o['id']]) as $r) {
        $out[] = ['payment_id' => (int)$r['id'], 'amount' => (float)$r['amount'], 'user' => user_card_by_id((int)$r['user_id']), 'room' => building_title((int)$r['building_id'], (int)$r['room_id']),
                  'duration_months' => (int)$r['duration_months'], 'transaction_id' => $r['transaction_id'], 'verify_by' => $r['verify_by'], 'past_48h' => $r['verify_by'] && ts($r['verify_by']) < time(), 'proof_url_path' => '/tenancy/payments/' . $r['id'] . '/proof'];
    }
    // app payments the admin has paid out: the owner confirms receipt
    foreach (rows(
        "SELECT py.*, tp.user_id, tp.room_id, tp.building_id FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id JOIN buildings b ON b.id = tp.building_id
         WHERE b.owner_id = ? AND py.method = 'app' AND py.status = 'paid_to_owner' ORDER BY py.id", [$o['id']]) as $r) {
        $out[] = ['payment_id' => (int)$r['id'], 'kind' => 'confirm_received', 'amount' => (float)$r['owner_amount'], 'user' => user_card_by_id((int)$r['user_id']), 'room' => building_title((int)$r['building_id'], (int)$r['room_id'])];
    }
    json_out(['pending' => $out]);
});

/* ---------- 6/7. move-in date ---------- */

function tenancy_view(array $t): array
{
    $q = null;
    if ($t['status'] === 'active' && $t['end_date']) {
        $q = exit_quote($t);
    }
    $days_left = $t['end_date'] ? days_between(today(), $t['end_date']) : null;
    return [
        'id' => (int)$t['id'], 'status' => $t['status'], 'process_id' => (int)$t['process_id'],
        'room' => ['id' => (int)$t['room_id'], 'label' => (string)val('SELECT label FROM rooms WHERE id = ?', [$t['room_id']])],
        'building' => ['id' => (int)$t['building_id'], 'name' => (string)val('SELECT name FROM buildings WHERE id = ?', [$t['building_id']])],
        'owner' => user_card_by_id((int)$t['owner_id']),
        'rent_monthly' => (float)$t['rent_monthly'], 'duration_months' => (int)$t['duration_months'],
        'termination' => ['label' => $t['termination_label'], 'basis' => $t['termination_basis'], 'percent' => $t['termination_percent'] !== null ? (float)$t['termination_percent'] : null],
        'agreement_version' => $t['agreement_version'],
        'move_in_deadline' => $t['move_in_deadline'], 'move_in_date' => $t['move_in_date'], 'end_date' => $t['end_date'], 'days_left' => $days_left,
        'signed_at' => $t['signed_at'], 'witness_name' => $t['witness_name'],
        'renewal_answer' => $t['renewal_answer'], 'renewal_answered_at' => $t['renewal_answered_at'],
        'terminate_requested_at' => $t['terminate_requested_at'],
        'exit_quote' => $q,
        'signing_reminder' => ($t['status'] === 'reserved' && $t['move_in_date']) ? 'On move-in day, bring a witness and valid ID. The owner provides the printed agreement; you do not need to print it.' : null,
        'chat_id' => ($c = val("SELECT id FROM chats WHERE process_id = ? AND type = 'tenant_owner' LIMIT 1", [$t['process_id']])) ? (int)$c : null,
    ];
}

function my_tenancy(array $u, int $id): array
{
    return must_find(row('SELECT * FROM tenancies WHERE id = ? AND user_id = ?', [$id, $u['id']]), 'Tenancy');
}

route('POST', '/tenancy/{id}/move-in', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    $pay = must_find(payment_of((int)$t['process_id']), 'Payment');
    if ($t['move_in_date']) {
        fail(409, 'Your move-in date is already set.', 'already_decided');
    }
    if (!payment_ready_for_move_in($pay)) {
        fail(409, $pay['method'] === 'app' ? 'Waiting for the owner to confirm they received the payout.' : 'Waiting for the owner to verify your payment.', 'not_ready');
    }
    $date = in_date('date');
    if ($date < today()) {
        fail(422, 'Choose today or a later date.', 'validation', ['field' => 'date']);
    }
    if ($t['move_in_deadline'] && $date > $t['move_in_deadline']) {
        fail(422, 'Choose a move-in date on or before ' . $t['move_in_deadline'] . '.', 'move_in_window', ['deadline' => $t['move_in_deadline']]);
    }
    $end = add_months($date, (int)$t['duration_months']);
    q('UPDATE tenancies SET move_in_date = ?, end_date = ? WHERE id = ?', [$date, $end, $t['id']]);
    notify((int)$t['owner_id'], 'move_in_chosen', 'Move-in date chosen', $u['name'] . ' will move in on ' . $date . '. Bring the printed agreement for signing.', ['tenancy_id' => (int)$t['id']]);
    json_out(['tenancy' => tenancy_view(row('SELECT * FROM tenancies WHERE id = ?', [$t['id']]))]);
});

/* ---------- tenancy section ---------- */

route('GET', '/tenancy/mine', function () {
    $u = require_role('user');
    $list = rows("SELECT * FROM tenancies WHERE user_id = ? AND status IN ('reserved','active') ORDER BY id DESC", [$u['id']]);
    json_out(['tenancies' => array_map('tenancy_view', $list), 'past' => array_map('tenancy_view', rows("SELECT * FROM tenancies WHERE user_id = ? AND status IN ('ended','terminated') ORDER BY id DESC LIMIT 20", [$u['id']]))]);
});

route('GET', '/tenancy/{id}/agreement', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    if (!$t['agreement_path']) {
        fail(404, 'No agreement saved.', 'not_found');
    }
    serve_private($t['agreement_path'], 'application/pdf', 'tenancy-agreement.pdf');
});

/* ---------- renewal ---------- */

/** Asked at every app open from 60 days to 45 days before the end; pinned (until answered) below 45 days. */
route('GET', '/tenancy/renewal-prompt', function () {
    $u = require_role('user');
    $out = [];
    foreach (rows("SELECT * FROM tenancies WHERE user_id = ? AND status = 'active' AND end_date IS NOT NULL ORDER BY end_date", [$u['id']]) as $t) {
        if (in_array($t['renewal_answer'], ['yes', 'no'], true)) {
            continue;
        }
        $left = days_between(today(), $t['end_date']);
        $start = (int)setting('renewal_prompt_start_days', '60');
        $end = (int)setting('renewal_prompt_end_days', '45');
        if ($left > $start || $left < 0) {
            continue;
        }
        $mode = $left > $end ? 'ask' : 'pinned';
        $out[] = ['mode' => $mode, 'tenancy_id' => (int)$t['id'], 'days_left' => $left, 'end_date' => $t['end_date'], 'building' => building_title((int)$t['building_id'], (int)$t['room_id']), 'answer' => $t['renewal_answer']];
        q('INSERT INTO renewal_prompts (tenancy_id, kind) VALUES (?, ?)', [$t['id'], $mode === 'ask' ? 'in_app' : 'pinned']);
    }
    json_out(['prompts' => $out]);
});

route('POST', '/tenancy/{id}/renewal-answer', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    if ($t['status'] !== 'active') {
        fail(409, 'This tenancy is not active.', 'not_ready');
    }
    $a = in_enum('answer', ['yes', 'no', 'not_sure']);
    q('UPDATE tenancies SET renewal_answer = ?, renewal_answered_at = ? WHERE id = ?', [$a, now(), $t['id']]);
    $label = ['yes' => 'plans to renew', 'no' => 'is leaving at the end of the lease', 'not_sure' => 'is not sure about renewing'][$a];
    notify((int)$t['owner_id'], 'renewal_answer', 'Renewal answer', $u['name'] . " $label (" . building_title((int)$t['building_id'], (int)$t['room_id']) . ').', ['tenancy_id' => (int)$t['id']]);
    json_out(['tenancy' => tenancy_view(row('SELECT * FROM tenancies WHERE id = ?', [$t['id']]))]);
});

/** "Yes, renew" leads through the rent process again for the same room, counted from the old end date. */
route('POST', '/tenancy/{id}/start-renewal', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    if ($t['status'] !== 'active' || !$t['end_date']) {
        fail(409, 'This tenancy is not active.', 'not_ready');
    }
    if (days_between(today(), $t['end_date']) > (int)setting('renewal_prompt_start_days', '60')) {
        fail(409, 'Renewal opens ' . (int)setting('renewal_prompt_start_days', '60') . ' days before your lease ends.', 'too_early');
    }
    if (!$u['phone_verified_at']) {
        fail(428, 'Verify your phone number first.', 'phone_verification_required');
    }
    if (val("SELECT 1 FROM tenancy_processes WHERE renewal_of_tenancy_id = ? AND status IN ('started','agreement_read','method_chosen','proof_uploaded','verified')", [$t['id']])) {
        fail(409, 'A renewal is already in progress.', 'duplicate');
    }
    $room = row('SELECT * FROM rooms WHERE id = ?', [$t['room_id']]);
    $b = row('SELECT * FROM buildings WHERE id = ?', [$t['building_id']]);
    q('INSERT INTO tenancy_processes (user_id, building_id, room_id, viewing_id, agent_id, expires_at, agreement_id, room_confirmed_at, renewal_of_tenancy_id) VALUES (?,?,?,NULL,NULL,?,?,?,?)',
        [$u['id'], $t['building_id'], $t['room_id'], plus('+' . (int)setting('tenant_process_days', '5') . ' days'), match_agreement($b, $room), now(), $t['id']]);
    $id = insert_id();
    q("UPDATE tenancies SET renewal_answer = 'yes', renewal_answered_at = ? WHERE id = ?", [now(), $t['id']]);
    notify((int)$t['owner_id'], 'renewal_started', 'Tenant is renewing', $u['name'] . ' started a renewal for ' . building_title((int)$t['building_id'], (int)$t['room_id']) . '.', ['process_id' => $id]);
    json_out(['process' => process_view(row('SELECT * FROM tenancy_processes WHERE id = ?', [$id])), 'note' => 'Current terms are shown. The owner may have changed prices for new agreements.'], 201);
});

/* ---------- early exit ---------- */

route('GET', '/tenancy/{id}/exit-quote', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    if ($t['status'] !== 'active') {
        fail(409, 'This tenancy is not active.', 'not_ready');
    }
    json_out(['quote' => exit_quote($t)]);
});

route('POST', '/tenancy/{id}/request-exit', function ($p) {
    $u = require_role('user');
    $t = my_tenancy($u, pid($p));
    if ($t['status'] !== 'active' || $t['terminate_requested_at']) {
        fail(409, 'You cannot request an early exit now.', 'not_ready');
    }
    q('UPDATE tenancies SET terminate_requested_at = ? WHERE id = ?', [now(), $t['id']]);
    $quote = exit_quote($t);
    notify((int)$t['owner_id'], 'exit_requested', 'Tenant wants to leave early', $u['name'] . ' asked to end the lease early. Policy: ' . $quote['note'] . '.', ['tenancy_id' => (int)$t['id']]);
    json_out(['quote' => $quote, 'requested' => true]);
});

route('POST', '/owner/tenancies/{id}/confirm-exit', function ($p) {
    $o = require_role('owner');
    $t = must_find(row('SELECT * FROM tenancies WHERE id = ? AND owner_id = ?', [pid($p), $o['id']]), 'Tenancy');
    if ($t['status'] !== 'active' || !$t['terminate_requested_at']) {
        fail(409, 'No early exit was requested.', 'not_ready');
    }
    tx(function () use ($t) {
        q("UPDATE tenancies SET status = 'terminated' WHERE id = ?", [$t['id']]);
        q("UPDATE rooms SET status = 'available' WHERE id = ?", [$t['room_id']]);
    });
    notify((int)$t['user_id'], 'tenancy_terminated', 'Lease ended', 'The owner confirmed your early exit.', ['tenancy_id' => (int)$t['id']]);
    json_out(['status' => 'terminated']);
});
