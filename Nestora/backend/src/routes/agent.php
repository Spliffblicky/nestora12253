<?php
declare(strict_types=1);

/* =====================================================================
   AGENT: weekly availability, applying for agency status per building, my buildings
   ===================================================================== */

route('GET', '/agent/availability', function () {
    $a = require_role('agent');
    $global = array_map('intval', array_column(rows('SELECT weekday FROM agent_availability WHERE agent_id = ? AND building_id IS NULL ORDER BY weekday', [$a['id']]), 'weekday'));
    $per = [];
    foreach (rows('SELECT building_id, weekday FROM agent_availability WHERE agent_id = ? AND building_id IS NOT NULL ORDER BY weekday', [$a['id']]) as $r) {
        $per[(int)$r['building_id']][] = (int)$r['weekday'];
    }
    json_out(['weekdays' => $global, 'per_building' => (object)$per]);
});

/** weekdays: [1..7] (1 = Monday). building_id optional: a per-building override of the main schedule. */
route('PUT', '/agent/availability', function () {
    $a = require_role('agent');
    $days = input()['weekdays'] ?? null;
    if (!is_array($days)) {
        fail(422, 'Choose the days you are available.', 'validation', ['field' => 'weekdays']);
    }
    $days = array_values(array_unique(array_map('intval', $days)));
    foreach ($days as $d) {
        if ($d < 1 || $d > 7) {
            fail(422, 'Days are 1 (Monday) to 7 (Sunday).', 'validation', ['field' => 'weekdays']);
        }
    }
    $bid = in_int('building_id');
    if ($bid !== null && !approved_agent((int)$a['id'], $bid)) {
        fail(403, 'You are not approved for that building.', 'not_approved');
    }
    // removing a weekday that has a confirmed viewing is blocked unless the agent confirms
    $conflicts = [];
    foreach (rows("SELECT v.id, v.requested_date, v.user_id FROM viewings v WHERE v.agent_id = ? AND v.status = 'accepted' AND v.requested_date >= ?" . ($bid !== null ? ' AND v.building_id = ?' : ''), $bid !== null ? [$a['id'], today(), $bid] : [$a['id'], today()]) as $v) {
        $wd = (int)gmdate('N', strtotime($v['requested_date'] . ' UTC'));
        if (!in_array($wd, $days, true)) {
            $conflicts[] = $v;
        }
    }
    if ($conflicts && !in_bool('confirm_conflicts')) {
        fail(409, count($conflicts) . ' confirmed viewing(s) fall on days you are removing.', 'has_confirmed_viewings', ['viewings' => $conflicts]);
    }
    tx(function () use ($a, $days, $bid, $conflicts) {
        if ($bid === null) {
            q('DELETE FROM agent_availability WHERE agent_id = ? AND building_id IS NULL', [$a['id']]);
        } else {
            q('DELETE FROM agent_availability WHERE agent_id = ? AND building_id = ?', [$a['id'], $bid]);
        }
        foreach ($days as $d) {
            q('INSERT INTO agent_availability (agent_id, building_id, weekday) VALUES (?,?,?)', [$a['id'], $bid, $d]);
        }
        foreach ($conflicts as $v) {
            notify((int)$v['user_id'], 'viewing_day_removed', 'Agent availability changed', 'Your agent is no longer free on your viewing day. Please agree a new date in chat.', ['viewing_id' => (int)$v['id']]);
        }
    });
    json_out(['ok' => true]);
});

/** Apply for agency status on a building. A declined or revoked agent may apply again. */
route('POST', '/agent/applications', function () {
    $a = require_role('agent');
    $bid = in_int('building_id', true);
    must_find(row("SELECT id FROM buildings WHERE id = ? AND status = 'live'", [$bid]), 'Building');
    $cur = agent_application((int)$a['id'], $bid);
    if ($cur && in_array($cur['status'], ['pending', 'approved'], true)) {
        fail(409, $cur['status'] === 'pending' ? 'Your application is already waiting.' : 'You are already an agent for this building.', 'duplicate');
    }
    q('INSERT INTO agency_applications (agent_id, building_id) VALUES (?,?)', [$a['id'], $bid]);
    $id = insert_id();
    notify(building_owner_id($bid), 'agency_application', 'New agent application', $a['name'] . ' wants to be an agent for ' . building_title($bid) . '.', ['application_id' => $id, 'building_id' => $bid]);
    json_out(['application_id' => $id, 'status' => 'pending'], 201);
});

route('GET', '/agent/applications', function () {
    $a = require_role('agent');
    json_out(['applications' => rows(
        'SELECT aa.id, aa.building_id, aa.status, aa.applied_at, aa.decided_at, b.name AS building_name FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE aa.agent_id = ? ORDER BY aa.id DESC', [$a['id']]
    )]);
});

route('GET', '/agent/buildings', function () {
    $a = require_role('agent');
    $out = [];
    foreach (rows(
        "SELECT b.id, b.name, b.status, c.name AS city_name, aa.id AS application_id,
                (SELECT path FROM building_photos p WHERE p.building_id = b.id ORDER BY sort_order, id LIMIT 1) AS cover
         FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id JOIN cities c ON c.id = b.city_id
         WHERE aa.agent_id = ? AND aa.status = 'approved'", [$a['id']]) as $b) {
        $b['cover_url'] = photo_url($b['cover']);
        $b['rating'] = rating_summary('building', (int)$b['id']);
        $b['chat_id'] = ($c = val("SELECT c.id FROM chats c JOIN chat_members m ON m.chat_id = c.id AND m.user_id = ? WHERE c.type = 'agent_owner' AND c.building_id = ? ORDER BY c.id DESC LIMIT 1", [$a['id'], $b['id']])) ? (int)$c : null;
        $out[] = $b;
    }
    json_out(['buildings' => $out]);
});

/** Agent's own page, including the comments people left. */
route('GET', '/agent/me', function () {
    $a = require_role('agent');
    json_out([
        'agent' => user_card($a),
        'rating' => rating_summary('agent', (int)$a['id']),
        'pending_viewings' => (int)val("SELECT COUNT(*) FROM viewings WHERE agent_id = ? AND status = 'pending'", [$a['id']]),
        'pending_surveys' => (int)val('SELECT COUNT(*) FROM revocation_surveys WHERE respondent_id = ? AND submitted_at IS NULL', [$a['id']]),
    ]);
});
