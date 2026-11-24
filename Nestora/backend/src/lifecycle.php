<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/services/notify.php';

/* =====================================================================
   Tenancy lifecycle pieces shared by the routes and the cron job.
   ===================================================================== */

/** Hold a room for a tenant process. Locks the row so two people cannot both reserve it. */
function reserve_room(int $room_id): void
{
    $status = val('SELECT status FROM rooms WHERE id = ? FOR UPDATE', [$room_id]);
    if ($status !== 'available') {
        fail(409, 'Sorry, this room is no longer available.', 'room_unavailable');
    }
    q("UPDATE rooms SET status = 'reserved' WHERE id = ?", [$room_id]);
}

function release_room(int $room_id): void
{
    q("UPDATE rooms SET status = 'available' WHERE id = ? AND status = 'reserved'
       AND NOT EXISTS (SELECT 1 FROM tenancies t WHERE t.room_id = rooms.id AND t.status IN ('reserved','active'))", [$room_id]);
}

function process_is_renewal(array $tp): bool
{
    return $tp['renewal_of_tenancy_id'] !== null;
}

function process_open_statuses(): array
{
    return ['started', 'agreement_read', 'method_chosen', 'proof_uploaded'];
}

/** Ends a process that did not finish: releases the room and hides the owner's payment details again. */
function end_process(array $tp, string $status, string $reason): void
{
    tx(function () use ($tp, $status, $reason) {
        q('UPDATE tenancy_processes SET status = ?, ended_reason = ? WHERE id = ?', [$status, $reason, $tp['id']]);
        q("UPDATE payments SET status = 'rejected', rejection_reason = ? WHERE process_id = ? AND status IN ('awaiting_proof','rejected')", [$reason, $tp['id']]);
        if (!process_is_renewal($tp)) {
            release_room((int)$tp['room_id']);
        }
        foreach (rows("SELECT id FROM chats WHERE process_id = ? AND type = 'tenant_owner'", [$tp['id']]) as $c) {
            chat_system((int)$c['id'], 'The tenant process ended: ' . $reason);
            chat_close((int)$c['id'], 'closed');
        }
    });
    $title = building_title((int)$tp['building_id'], (int)$tp['room_id']);
    $label = $status === 'expired' ? 'Tenant process expired' : 'Tenant process cancelled';
    notify((int)$tp['user_id'], 'process_' . $status, $label, "$title: $reason", ['process_id' => (int)$tp['id']]);
    notify(building_owner_id((int)$tp['building_id']), 'process_' . $status, $label, "$title: $reason", ['process_id' => (int)$tp['id']]);
    if ($tp['agent_id']) {
        notify((int)$tp['agent_id'], 'process_' . $status, $label, "$title: $reason", ['process_id' => (int)$tp['id']]);
    }
}

/**
 * Called when a payment is verified (by the owner on the third-party route, or by an admin on the app route).
 * Copies the terms into the tenancy so later owner edits never affect this tenant.
 */
function create_tenancy(array $tp, array $payment): int
{
    $room = must_find(row('SELECT * FROM rooms WHERE id = ?', [$tp['room_id']]), 'Room');
    $b = must_find(row('SELECT * FROM buildings WHERE id = ?', [$tp['building_id']]), 'Building');
    $pol = row('SELECT * FROM termination_policies WHERE id = ?', [$b['termination_policy_id']]) ?: ['label' => 'None', 'basis' => 'no_refund', 'percent' => null];
    $agr = $tp['agreement_id'] ? row('SELECT * FROM agreements WHERE id = ?', [$tp['agreement_id']]) : null;
    $months = (int)$tp['duration_months'];
    $rent = (float)$room['monthly_rent'];

    $move_in = null;
    $end = null;
    $deadline = gmdate('Y-m-d', strtotime('+' . (int)setting('move_in_window_days', '30') . ' days'));
    if (process_is_renewal($tp)) {                                      // renewal: the new term starts when the old one ends
        $old = must_find(row('SELECT * FROM tenancies WHERE id = ?', [$tp['renewal_of_tenancy_id']]), 'Tenancy');
        $move_in = $old['end_date'];
        $end = add_months($move_in, $months);
        $deadline = $move_in;
    }
    q(
        "INSERT INTO tenancies (process_id, user_id, owner_id, building_id, room_id, rent_monthly, duration_months, termination_label, termination_basis, termination_percent,
                                agreement_id, agreement_version, agreement_path, move_in_deadline, move_in_date, end_date, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'reserved')",
        [$tp['id'], $tp['user_id'], $b['owner_id'], $tp['building_id'], $tp['room_id'], $rent, $months, $pol['label'], $pol['basis'], $pol['percent'],
         $agr['id'] ?? null, $agr['version'] ?? null, $agr['pdf_path'] ?? null, $deadline, $move_in, $end]
    );
    $tid = insert_id();
    $title = building_title((int)$tp['building_id'], (int)$tp['room_id']);
    notify((int)$b['owner_id'], 'payment_verified', 'Payment verified', "A tenant's payment for $title is verified.", ['tenancy_id' => $tid]);
    if ($tp['agent_id']) {
        notify((int)$tp['agent_id'], 'payment_verified', 'Your lead is renting', "A tenant's payment for $title is verified.", ['tenancy_id' => $tid]);
    }
    return $tid;
}

/** Third-party: payment "verified" is enough. App route: the owner must also tap "Payment received". */
function payment_ready_for_move_in(array $payment): bool
{
    return $payment['method'] === 'third_party' ? $payment['status'] === 'verified' : $payment['status'] === 'owner_confirmed';
}

/**
 * Early exit quote using the terms locked at payment.
 * "15% of the remaining duration deducted" is read as 15% of the rent value for the remaining months.
 * (The plan left this open. Change here if you decide on 15% of the number of months instead.)
 */
function exit_quote(array $t): array
{
    $remaining_days = max(0, days_between(today(), (string)$t['end_date']));
    $months = round($remaining_days / 30, 2);
    $value = money($months * (float)$t['rent_monthly']);
    $deduction = 0.0;
    $note = '';
    switch ($t['termination_basis']) {
        case 'remaining_duration':
        case 'remaining_rent':
            $deduction = money($value * (float)$t['termination_percent'] / 100);
            $note = (float)$t['termination_percent'] . "% of the remaining rent value ($months months x GHS " . number_format((float)$t['rent_monthly'], 2) . ')';
            break;
        case 'forfeit_months':
            $deduction = money((float)$t['rent_monthly']);
            $note = 'One month of rent is forfeited';
            break;
        default:
            $deduction = $value;
            $note = 'No refund on early termination';
    }
    return ['remaining_days' => $remaining_days, 'remaining_months' => $months, 'remaining_value' => $value, 'deduction' => min($deduction, $value), 'note' => $note, 'policy' => $t['termination_label']];
}
