<?php
declare(strict_types=1);

/* =====================================================================
   VIEWINGS
   user requests (agent + date from the agent's available weekdays)
   -> agent accepts / rejects / asks for another date (2-day timer)
   -> agent taps "Viewing complete" (or the user confirms at next app open)
   The server runs all timers (see cron/run.php).
   ===================================================================== */

function agent_weekdays(int $agent_id, int $building_id): array
{
    $d = rows('SELECT weekday FROM agent_availability WHERE agent_id = ? AND building_id = ?', [$agent_id, $building_id]);
    if (!$d) {
        $d = rows('SELECT weekday FROM agent_availability WHERE agent_id = ? AND building_id IS NULL', [$agent_id]);
    }
    return array_map('intval', array_column($d, 'weekday'));
}

function check_viewing_date(int $agent_id, int $building_id, string $date): void
{
    if ($date <= today()) {
        fail(422, 'Choose a date from tomorrow onwards.', 'validation', ['field' => 'date']);
    }
    if ($date > gmdate('Y-m-d', strtotime('+90 days'))) {
        fail(422, 'Choose a date within the next 90 days.', 'validation', ['field' => 'date']);
    }
    $wd = (int)gmdate('N', strtotime($date . ' UTC'));
    if (!in_array($wd, agent_weekdays($agent_id, $building_id), true)) {
        fail(422, 'The agent is not available on that day of the week.', 'agent_unavailable', ['available_weekdays' => agent_weekdays($agent_id, $building_id)]);
    }
}

function viewing_chat_id(int $viewing_id): ?int
{
    $c = val('SELECT id FROM chats WHERE viewing_id = ? ORDER BY id LIMIT 1', [$viewing_id]);
    return $c ? (int)$c : null;
}

/** Everything the pinned status card / bar needs. */
function viewing_view(array $v, array $viewer): array
{
    $bar = null;
    if ($v['status'] === 'date_change_requested') {
        $bar = ['question' => 'Has a new viewing date been selected?', 'answer' => 'not_yet', 'ends_at' => $v['timer_ends_at'], 'seconds_left' => max(0, ts($v['timer_ends_at']) - time()),
                'who_acts' => 'user', 'action' => $viewer['id'] == $v['user_id'] ? 'pick_new_date' : 'wait_for_user'];
    } elseif ($v['status'] === 'pending' && (int)$v['round_count'] > 1) {
        $bar = ['question' => 'Has a new viewing date been selected?', 'answer' => 'selected', 'ends_at' => null, 'seconds_left' => null, 'who_acts' => 'agent', 'action' => $viewer['id'] == $v['agent_id'] ? 'decide' : 'wait_for_agent'];
    }
    $chat = viewing_chat_id((int)$v['id']);
    $chat_row = $chat ? row('SELECT status, delete_after FROM chats WHERE id = ?', [$chat]) : null;
    return [
        'id' => (int)$v['id'], 'status' => $v['status'], 'requested_date' => $v['requested_date'], 'time_slot' => $v['time_slot'],
        'round_count' => (int)$v['round_count'], 'max_rounds' => (int)setting('viewing_max_date_rounds', '3'),
        'request_expires_at' => $v['request_expires_at'], 'reject_reason' => $v['reject_reason'],
        'completed_at' => $v['completed_at'], 'rent_decision' => $v['rent_decision'],
        'building' => ['id' => (int)$v['building_id'], 'name' => (string)val('SELECT name FROM buildings WHERE id = ?', [$v['building_id']])],
        'room' => $v['room_id'] ? ['id' => (int)$v['room_id'], 'label' => (string)val('SELECT label FROM rooms WHERE id = ?', [$v['room_id']])] : null,
        'user' => user_card_by_id((int)$v['user_id']), 'agent' => user_card_by_id((int)$v['agent_id']),
        'agent_weekdays' => agent_weekdays((int)$v['agent_id'], (int)$v['building_id']),
        'chat_id' => $chat, 'chat_status' => $chat_row['status'] ?? null, 'chat_delete_after' => $chat_row['delete_after'] ?? null,
        'bar' => $bar,
        'history' => rows('SELECT proposed_by, proposed_date, action, created_at FROM viewing_date_history WHERE viewing_id = ? ORDER BY id', [$v['id']]),
    ];
}

function load_viewing(int $id, array $u): array
{
    $v = must_find(row('SELECT * FROM viewings WHERE id = ?', [$id]), 'Viewing');
    if ($u['role'] !== 'admin' && (int)$v['user_id'] !== (int)$u['id'] && (int)$v['agent_id'] !== (int)$u['id']) {
        fail(404, 'Viewing not found', 'not_found');
    }
    return $v;
}

function vh(int $viewing_id, int $by, string $date, string $action): void
{
    q('INSERT INTO viewing_date_history (viewing_id, proposed_by, proposed_date, action) VALUES (?,?,?,?)', [$viewing_id, $by, $date, $action]);
}

/** Step 1: the agents the user can pick from (approved for this building) with their ratings and weekdays. */
route('GET', '/viewings/agents', function () {
    require_role('user');
    $bid = in_int('building_id', true);
    must_find(row("SELECT id FROM buildings WHERE id = ? AND status = 'live'", [$bid]), 'Building');
    $out = [];
    foreach (rows("SELECT agent_id FROM agency_applications WHERE building_id = ? AND status = 'approved'", [$bid]) as $a) {
        $card = user_card_by_id((int)$a['agent_id']);
        if (!$card) {
            continue;
        }
        $card['rating'] = rating_summary('agent', (int)$a['agent_id'])['user'];
        $card['weekdays'] = agent_weekdays((int)$a['agent_id'], $bid);
        $card['online'] = ts((string)val('SELECT last_seen_at FROM users WHERE id = ?', [$a['agent_id']])) > time() - 300;
        $out[] = $card;
    }
    json_out(['agents' => $out, 'none_available' => count($out) === 0]);
});

/** Steps 2-4: user picks agent + date; chat opens straight away. */
route('POST', '/viewings', function () {
    $u = require_role('user');
    $bid = in_int('building_id', true);
    $rid = in_int('room_id');
    $aid = in_int('agent_id', true);
    $date = in_date('date');
    $slot = in_enum('time_slot', ['morning', 'afternoon', 'evening'], false);
    must_find(row("SELECT id FROM buildings WHERE id = ? AND status = 'live'", [$bid]), 'Building');
    if ($rid !== null) {
        must_find(row("SELECT id FROM rooms WHERE id = ? AND building_id = ? AND status = 'available'", [$rid, $bid]), 'Room');
    }
    if (!approved_agent($aid, $bid)) {
        fail(403, 'That agent is not approved for this building.', 'not_approved');
    }
    check_viewing_date($aid, $bid, $date);
    if (val("SELECT 1 FROM viewings WHERE user_id = ? AND agent_id = ? AND building_id = ? AND status IN ('pending','accepted','date_change_requested')", [$u['id'], $aid, $bid])) {
        fail(409, 'You already have an open viewing request with this agent for this building.', 'duplicate');
    }
    if ((int)val("SELECT COUNT(*) FROM viewings WHERE user_id = ? AND status IN ('pending','date_change_requested') AND created_at > ?", [$u['id'], plus('-1 day')]) >= 5) {
        fail(429, 'Too many open requests. Wait for agents to respond.', 'rate_limited');
    }
    $vid = tx(function () use ($u, $bid, $rid, $aid, $date, $slot) {
        q('INSERT INTO viewings (user_id, agent_id, building_id, room_id, requested_date, time_slot, request_expires_at) VALUES (?,?,?,?,?,?,?)',
            [$u['id'], $aid, $bid, $rid, $date, $slot, plus('+' . (int)setting('viewing_request_expiry_hours', '48') . ' hours')]);
        $vid = insert_id();
        vh($vid, (int)$u['id'], $date, 'requested');
        $chat = chat_create('viewing', $bid, $rid, $vid, null, [(int)$u['id'], $aid]);
        chat_system($chat, $u['name'] . ' requested a viewing on ' . $date . ($slot ? " ($slot)" : '') . '.');
        return $vid;
    });
    notify($aid, 'viewing_request', 'New viewing request', $u['name'] . ' wants to view ' . building_title($bid, $rid) . ' on ' . $date . '.', ['viewing_id' => $vid, 'chat_id' => viewing_chat_id($vid)]);
    json_out(['viewing' => viewing_view(row('SELECT * FROM viewings WHERE id = ?', [$vid]), $u)], 201);
});

route('GET', '/viewings', function () {
    $u = require_role('user', 'agent');
    $col = $u['role'] === 'agent' ? 'agent_id' : 'user_id';
    $status = in_enum('status', ['pending', 'accepted', 'date_change_requested', 'rejected', 'cancelled', 'expired', 'completed'], false);
    $sql = "SELECT * FROM viewings WHERE $col = ?" . ($status ? ' AND status = ?' : '') . ' ORDER BY id DESC LIMIT 100';
    json_out(['viewings' => array_map(fn($v) => viewing_view($v, $u), rows($sql, $status ? [$u['id'], $status] : [$u['id']]))]);
});

route('GET', '/viewings/{id}', function ($p) {
    $u = require_user();
    json_out(['viewing' => viewing_view(load_viewing(pid($p), $u), $u)]);
});

/** Agent decides: accept | reject | ask_date. Each has a confirmation dialog in the app. */
route('POST', '/viewings/{id}/respond', function ($p) {
    $u = require_role('agent');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['agent_id'] !== (int)$u['id'] || $v['status'] !== 'pending') {
        fail(409, 'This request is not waiting for your answer.', 'not_ready');
    }
    if (!approved_agent((int)$u['id'], (int)$v['building_id'])) {
        fail(403, 'You are no longer approved for this building.', 'not_approved');
    }
    $action = in_enum('action', ['accept', 'reject', 'ask_date']);
    $chat = viewing_chat_id((int)$v['id']);
    $title = building_title((int)$v['building_id'], $v['room_id'] ? (int)$v['room_id'] : null);
    $data = ['viewing_id' => (int)$v['id'], 'chat_id' => $chat];

    if ($action === 'accept') {
        q("UPDATE viewings SET status = 'accepted', request_expires_at = NULL, timer_ends_at = NULL WHERE id = ?", [$v['id']]);
        vh((int)$v['id'], (int)$u['id'], $v['requested_date'], 'accepted');
        // confirmed viewing chats are deleted one week after the viewing date
        q('UPDATE chats SET delete_after = ? WHERE viewing_id = ?', [plus('+' . (int)setting('chat_delete_days', '7') . ' days', $v['requested_date'] . ' 23:59:59'), $v['id']]);
        $chat && chat_system($chat, 'Viewing confirmed for ' . $v['requested_date'] . '.');
        notify((int)$v['user_id'], 'viewing_accepted', 'Viewing confirmed', "$title on {$v['requested_date']}.", $data);
    } elseif ($action === 'reject') {
        $reason = in_enum('reason', ['not_available', 'building_unavailable', 'room_taken', 'other']);
        q("UPDATE viewings SET status = 'rejected', reject_reason = ?, request_expires_at = NULL WHERE id = ?", [$reason, $v['id']]);
        vh((int)$v['id'], (int)$u['id'], $v['requested_date'], 'rejected');
        if ($chat) {
            chat_system($chat, 'The agent declined this viewing.');
            chat_close($chat, 'closed');
        }
        notify((int)$v['user_id'], 'viewing_rejected', 'Viewing declined', "$title: " . str_replace('_', ' ', $reason) . '.', $data);
    } else {
        if ((int)$v['round_count'] >= (int)setting('viewing_max_date_rounds', '3')) {
            fail(409, 'The date has already been changed the most times allowed. Accept, decline, or settle it in chat.', 'max_rounds');
        }
        $ends = plus('+' . (int)setting('viewing_date_change_days', '2') . ' days');
        q("UPDATE viewings SET status = 'date_change_requested', timer_ends_at = ?, request_expires_at = NULL, reminder_stage = 0 WHERE id = ?", [$ends, $v['id']]);
        vh((int)$v['id'], (int)$u['id'], $v['requested_date'], 'date_change_asked');
        $chat && chat_system($chat, 'The agent asked for another date. The user has 2 days to pick a new one.');
        notify((int)$v['user_id'], 'viewing_date_change', 'Agent asked for another date', "Pick a new date for $title within 2 days or the viewing is cancelled.", $data);
    }
    json_out(['viewing' => viewing_view(row('SELECT * FROM viewings WHERE id = ?', [$v['id']]), $u)]);
});

/** The user picks a new date while the timer runs. The timer stops; the agent decides again. */
route('POST', '/viewings/{id}/new-date', function ($p) {
    $u = require_role('user');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['user_id'] !== (int)$u['id'] || $v['status'] !== 'date_change_requested') {
        fail(409, 'You are not being asked for a new date.', 'not_ready');
    }
    if (ts($v['timer_ends_at']) <= time()) {
        fail(409, 'The time to pick a new date has ended.', 'timer_ended');
    }
    $date = in_date('date');
    if (!approved_agent((int)$v['agent_id'], (int)$v['building_id'])) {
        fail(403, 'The agent is no longer approved for this building.', 'not_approved');
    }
    check_viewing_date((int)$v['agent_id'], (int)$v['building_id'], $date);
    q("UPDATE viewings SET status = 'pending', requested_date = ?, round_count = round_count + 1, timer_ends_at = NULL, request_expires_at = ? WHERE id = ?",
        [$date, plus('+' . (int)setting('viewing_request_expiry_hours', '48') . ' hours'), $v['id']]);
    vh((int)$v['id'], (int)$u['id'], $date, 'new_date_picked');
    $chat = viewing_chat_id((int)$v['id']);
    $chat && chat_system($chat, $u['name'] . ' picked a new date: ' . $date . '. Waiting for the agent.');
    notify((int)$v['agent_id'], 'viewing_new_date', 'New viewing date chosen', $u['name'] . ' chose ' . $date . '.', ['viewing_id' => (int)$v['id'], 'chat_id' => $chat]);
    json_out(['viewing' => viewing_view(row('SELECT * FROM viewings WHERE id = ?', [$v['id']]), $u)]);
});

/** Either side can cancel before it is completed (with a confirmation in the app). */
route('POST', '/viewings/{id}/cancel', function ($p) {
    $u = require_role('user', 'agent');
    $v = load_viewing(pid($p), $u);
    if (!in_array($v['status'], ['pending', 'accepted', 'date_change_requested'], true)) {
        fail(409, 'This viewing can no longer be cancelled.', 'not_ready');
    }
    q("UPDATE viewings SET status = 'cancelled', timer_ends_at = NULL, request_expires_at = NULL WHERE id = ?", [$v['id']]);
    $chat = viewing_chat_id((int)$v['id']);
    if ($chat) {
        chat_system($chat, $u['name'] . ' cancelled this viewing.');
        chat_close($chat, 'closed');
    }
    $other = (int)$u['id'] === (int)$v['user_id'] ? (int)$v['agent_id'] : (int)$v['user_id'];
    notify($other, 'viewing_cancelled', 'Viewing cancelled', $u['name'] . ' cancelled the viewing.', ['viewing_id' => (int)$v['id']]);
    json_out(['status' => 'cancelled']);
});

/** Agent taps "Viewing complete" on or after the viewing day. */
route('POST', '/viewings/{id}/complete', function ($p) {
    $u = require_role('agent');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['agent_id'] !== (int)$u['id'] || $v['status'] !== 'accepted') {
        fail(409, 'Only a confirmed viewing can be completed.', 'not_ready');
    }
    if ($v['requested_date'] > today()) {
        fail(422, 'You can mark it complete from the viewing day.', 'too_early');
    }
    complete_viewing($v, 'agent');
    json_out(['status' => 'completed']);
});

function complete_viewing(array $v, string $by): void
{
    q("UPDATE viewings SET status = 'completed', completed_by = ?, completed_at = ?, user_answer = IF(? = 'user', 'yes', user_answer) WHERE id = ?", [$by, now(), $by, $v['id']]);
    $chat = viewing_chat_id((int)$v['id']);
    $chat && chat_system($chat, 'Viewing completed.');
    $title = building_title((int)$v['building_id'], $v['room_id'] ? (int)$v['room_id'] : null);
    if ($by === 'agent') {
        notify((int)$v['user_id'], 'viewing_complete', 'How was your viewing?', "Do you want to rent $title? You can also review the agent and building.", ['viewing_id' => (int)$v['id']]);
    } else {
        notify((int)$v['agent_id'], 'viewing_user_confirmed', 'User confirmed the viewing', 'The user says the viewing took place. Open it if this is wrong.', ['viewing_id' => (int)$v['id']]);
    }
}

/** If the agent forgets: at next open after the viewing day the user is asked "Did you have your viewing with X?" */
route('GET', '/viewings/prompts/confirm', function () {
    $u = require_role('user');
    $list = [];
    foreach (rows("SELECT * FROM viewings WHERE user_id = ? AND status = 'accepted' AND requested_date < ? ORDER BY requested_date", [$u['id'], today()]) as $v) {
        $list[] = ['viewing_id' => (int)$v['id'], 'date' => $v['requested_date'], 'agent' => user_card_by_id((int)$v['agent_id']), 'building' => building_title((int)$v['building_id'], $v['room_id'] ? (int)$v['room_id'] : null)];
    }
    json_out(['prompts' => $list]);
});

route('POST', '/viewings/{id}/confirm', function ($p) {
    $u = require_role('user');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['user_id'] !== (int)$u['id'] || $v['status'] !== 'accepted' || $v['requested_date'] >= today()) {
        fail(409, 'There is nothing to confirm.', 'not_ready');
    }
    if (in_enum('answer', ['yes', 'no']) === 'yes') {
        complete_viewing($v, 'user');
        json_out(['status' => 'completed']);
    }
    $follow = in_enum('follow_up', ['reschedule', 'report_no_show']);
    q("UPDATE viewings SET status = 'cancelled', user_answer = 'no' WHERE id = ?", [$v['id']]);
    $chat = viewing_chat_id((int)$v['id']);
    if ($chat) {
        chat_system($chat, 'The user says the viewing did not take place.');
        chat_close($chat, 'closed');
    }
    if ($follow === 'report_no_show') {
        q("INSERT INTO reports (reporter_id, target_type, target_id, reason) VALUES (?, 'user', ?, 'Agent no-show reported by user')", [$u['id'], $v['agent_id']]);
        notify_admins('report', 'Agent no-show reported', user_card_by_id((int)$v['agent_id'])['name'] ?? '');
    }
    notify((int)$v['agent_id'], 'viewing_no_show', 'Viewing not confirmed', 'The user says the viewing did not take place.', ['viewing_id' => (int)$v['id']]);
    json_out(['status' => 'cancelled', 'next' => $follow]);
});

/** Agent disagrees with a user-confirmed viewing: flagged for admin review (never blocks the user). */
route('POST', '/viewings/{id}/dispute', function ($p) {
    $u = require_role('agent');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['agent_id'] !== (int)$u['id'] || $v['status'] !== 'completed' || $v['completed_by'] !== 'user') {
        fail(409, 'Nothing to dispute.', 'not_ready');
    }
    q('UPDATE viewings SET agent_disputes = 1 WHERE id = ?', [$v['id']]);
    $reason = in_str('reason', 500) ?? 'Agent says this viewing did not happen';
    reject_contact_info($reason, 'reason');
    q("INSERT INTO reports (reporter_id, target_type, target_id, reason) VALUES (?, 'user', ?, ?)", [$u['id'], $v['user_id'], 'Viewing dispute: ' . $reason]);
    notify_admins('report', 'Viewing dispute', "Agent disputes viewing #{$v['id']}");
    json_out(['ok' => true]);
});

/** Completed viewings where the user has not yet decided about renting (shown at app open, a few times). */
route('GET', '/viewings/prompts/rent', function () {
    $u = require_role('user');
    $list = [];
    foreach (rows("SELECT * FROM viewings WHERE user_id = ? AND status = 'completed' AND (rent_decision IS NULL OR rent_decision = 'not_now') AND rent_prompt_count < 4 ORDER BY completed_at DESC", [$u['id']]) as $v) {
        $room = $v['room_id'] ? row("SELECT status FROM rooms WHERE id = ?", [$v['room_id']]) : null;
        if (!val("SELECT 1 FROM buildings WHERE id = ? AND status = 'live'", [$v['building_id']])) {
            continue;
        }
        if ((int)val("SELECT COUNT(*) FROM tenancy_processes WHERE viewing_id = ? AND status NOT IN ('expired','cancelled')", [$v['id']])) {
            continue;
        }
        q('UPDATE viewings SET rent_prompt_count = rent_prompt_count + 1 WHERE id = ?', [$v['id']]);
        $list[] = ['viewing_id' => (int)$v['id'], 'agent' => user_card_by_id((int)$v['agent_id']), 'building' => building_title((int)$v['building_id'], $v['room_id'] ? (int)$v['room_id'] : null),
                   'room_available' => $room ? $room['status'] === 'available' : null];
    }
    json_out(['prompts' => $list]);
});

route('POST', '/viewings/{id}/rent-decision', function ($p) {
    $u = require_role('user');
    $v = load_viewing(pid($p), $u);
    if ((int)$v['user_id'] !== (int)$u['id'] || $v['status'] !== 'completed') {
        fail(409, 'Not available.', 'not_ready');
    }
    $d = in_enum('decision', ['not_now', 'not_interested']);
    q('UPDATE viewings SET rent_decision = ? WHERE id = ?', [$d, $v['id']]);
    json_out(['ok' => true]);
});
