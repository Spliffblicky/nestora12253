<?php
declare(strict_types=1);

/* =====================================================================
   OWNER: buildings, rooms, photos, amenities, agreements, payout details,
   agent applications, revocation, tenants, payment verification
   ===================================================================== */

function photo_rows(string $table, string $col, int $id): array
{
    return array_map(
        fn($p) => ['id' => (int)$p['id'], 'url' => photo_url($p['path']), 'sort_order' => (int)$p['sort_order']],
        rows("SELECT id, path, sort_order FROM $table WHERE $col = ? ORDER BY sort_order, id", [$id])
    );
}

function check_location(int $city_id, ?int $area_id, ?string $area_other, ?int $owner_id = null): void
{
    if (!val('SELECT 1 FROM cities WHERE id = ? AND active = 1', [$city_id])) {
        fail(422, 'Choose a city from the list.', 'validation', ['field' => 'city_id']);
    }
    if ($area_id !== null && !val("SELECT 1 FROM areas WHERE id = ? AND city_id = ? AND status = 'approved'", [$area_id, $city_id])) {
        fail(422, 'That area is not in the chosen city.', 'validation', ['field' => 'area_id']);
    }
    if ($area_other !== null) {
        reject_contact_info($area_other, 'area_other');
        q("INSERT IGNORE INTO areas (city_id, name, status, suggested_by) VALUES (?,?, 'pending', ?)", [$city_id, $area_other, $owner_id]);
    }
}

function building_fields(array $b, bool $creating): array
{
    $name = in_str('name', 120, $creating);
    $city = in_int('city_id', $creating);
    $area = in_int('area_id');
    $other = in_str('area_other', 80);
    $gps = in_str('gps_address', 20);
    $land = in_str('landmark', 150);
    reject_contact_info($land, 'landmark');
    reject_contact_info($name, 'name');
    if ($gps !== null && !preg_match('/^[A-Za-z]{2}-\d{3,4}-\d{4}$/', $gps)) {
        fail(422, 'Ghana Post GPS looks like GA-123-4567.', 'validation', ['field' => 'gps_address']);
    }
    $policy = in_int('termination_policy_id');
    if ($policy !== null && !val('SELECT 1 FROM termination_policies WHERE id = ? AND active = 1', [$policy])) {
        fail(422, 'Choose a termination policy from the list.', 'validation', ['field' => 'termination_policy_id']);
    }
    $scope = in_enum('agreement_scope', ['general', 'by_floor', 'by_room_type'], false);
    $floors = in_int('floors_count');
    if ($floors !== null && ($floors < 1 || $floors > 60)) {
        fail(422, 'Number of floors must be between 1 and 60.', 'validation', ['field' => 'floors_count']);
    }
    $city = $city ?? (int)($b['city_id'] ?? 0);
    $area = array_key_exists('area_id', input()) ? $area : ($b['area_id'] ?? null);
    $other = array_key_exists('area_other', input()) ? $other : ($b['area_other'] ?? null);
    check_location($city, $area !== null ? (int)$area : null, $other, null);
    return [
        'name' => $name ?? $b['name'], 'description' => in_str('description', 3000) ?? ($b['description'] ?? null),
        'city_id' => $city, 'area_id' => $area, 'area_other' => $area ? null : $other,
        'gps_address' => $gps !== null ? strtoupper($gps) : ($b['gps_address'] ?? null),
        'landmark' => array_key_exists('landmark', input()) ? $land : ($b['landmark'] ?? null),
        'termination_policy_id' => $policy ?? ($b['termination_policy_id'] ?? null),
        'agreement_scope' => $scope ?? ($b['agreement_scope'] ?? null),
        'floors_count' => $floors ?? ($b['floors_count'] ?? null),
    ];
}

function rematch_rooms(array $building): void
{
    $b = row('SELECT * FROM buildings WHERE id = ?', [$building['id']]);
    foreach (rows('SELECT * FROM rooms WHERE building_id = ?', [$b['id']]) as $r) {
        q('UPDATE rooms SET agreement_id = ? WHERE id = ?', [match_agreement($b, $r), $r['id']]);
    }
}

/** If a live building stops being complete after an edit, it goes back to draft. */
function revalidate_live(int $building_id): bool
{
    $b = row('SELECT status FROM buildings WHERE id = ?', [$building_id]);
    if ($b && $b['status'] === 'live' && building_missing($building_id)) {
        q("UPDATE buildings SET status = 'draft' WHERE id = ?", [$building_id]);
        return true;
    }
    return false;
}

function building_editor(array $b): array
{
    $id = (int)$b['id'];
    $rooms = [];
    foreach (rows('SELECT * FROM rooms WHERE building_id = ? ORDER BY floor_number, id', [$id]) as $r) {
        $rooms[] = $r + [
            'photos'   => photo_rows('room_photos', 'room_id', (int)$r['id']),
            'durations' => array_map('intval', array_column(rows('SELECT duration_months FROM room_rent_options WHERE room_id = ? ORDER BY 1', [$r['id']]), 'duration_months')),
            'amenities' => amenity_answers_view('room', (int)$r['id']),
            'custom'   => rows('SELECT label, answer, note FROM custom_amenities WHERE room_id = ?', [$r['id']]),
            'missing'  => room_missing((int)$r['id']),
        ];
    }
    return [
        'building' => $b,
        'photos'   => photo_rows('building_photos', 'building_id', $id),
        'amenities' => amenity_answers_view('building', $id),
        'custom'   => rows('SELECT label, answer, note FROM custom_amenities WHERE building_id = ?', [$id]),
        'room_types' => rows('SELECT id, name FROM room_types WHERE building_id = ? ORDER BY name', [$id]),
        'agreements' => rows('SELECT id, scope, floor_number, room_type_id, version, uploaded_at FROM agreements WHERE building_id = ? AND is_current = 1 ORDER BY scope, floor_number', [$id]),
        'rooms'    => $rooms,
        'missing'  => building_missing($id),
        'rating'   => rating_summary('building', $id),
    ];
}

/* ---------- fee notice ---------- */

route('POST', '/owner/fee-ack', function () {
    $o = require_role('owner');
    if (!in_bool('confirm')) {
        fail(422, 'Tick the box to confirm you understand the fee.', 'validation');
    }
    q('INSERT INTO owner_fee_acknowledgements (owner_id, fee_percent) VALUES (?,?)', [$o['id'], (float)setting('platform_fee_percent', '5')]);
    json_out(['ok' => true]);
});

route('GET', '/owner/status', function () {
    $o = require_role('owner');
    json_out([
        'fee_acknowledged' => (bool)val('SELECT 1 FROM owner_fee_acknowledgements WHERE owner_id = ?', [$o['id']]),
        'fee_percent'      => (float)setting('platform_fee_percent', '5'),
        'fee_payer'        => setting('fee_payer', 'owner'),
        'phone_verified'   => (bool)$o['phone_verified_at'],
        'has_payout'       => (bool)val('SELECT 1 FROM owner_payout_details WHERE owner_id = ? AND (momo_number IS NOT NULL OR account_number IS NOT NULL)', [$o['id']]),
    ]);
});

/* ---------- buildings ---------- */

route('GET', '/owner/buildings', function () {
    $o = require_role('owner');
    $out = [];
    foreach (rows(
        "SELECT b.*, c.name AS city_name, a.name AS area_name,
                (SELECT COUNT(*) FROM rooms r WHERE r.building_id = b.id) AS room_count,
                (SELECT path FROM building_photos p WHERE p.building_id = b.id ORDER BY sort_order, id LIMIT 1) AS cover
         FROM buildings b JOIN cities c ON c.id = b.city_id LEFT JOIN areas a ON a.id = b.area_id
         WHERE b.owner_id = ? ORDER BY b.id DESC", [$o['id']]) as $b) {
        $b['cover_url'] = photo_url($b['cover']);
        $b['rating'] = rating_summary('building', (int)$b['id']);
        $b['missing_count'] = $b['status'] === 'live' ? 0 : count(building_missing((int)$b['id']));
        $out[] = $b;
    }
    json_out(['buildings' => $out]);
});

route('POST', '/owner/buildings', function () {
    $o = require_role('owner');
    if (!val('SELECT 1 FROM owner_fee_acknowledgements WHERE owner_id = ?', [$o['id']])) {
        fail(428, 'Please read and acknowledge the 5% fee notice first.', 'fee_ack_required');
    }
    $f = building_fields([], true);
    q(
        'INSERT INTO buildings (owner_id, name, description, city_id, area_id, area_other, gps_address, landmark, termination_policy_id, agreement_scope, floors_count) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$o['id'], $f['name'], $f['description'], $f['city_id'], $f['area_id'], $f['area_other'], $f['gps_address'], $f['landmark'], $f['termination_policy_id'], $f['agreement_scope'], $f['floors_count']]
    );
    json_out(building_editor(row('SELECT * FROM buildings WHERE id = ?', [insert_id()])), 201);
});

route('GET', '/owner/buildings/{id}', function ($p) {
    $o = require_role('owner');
    json_out(building_editor(owned_building($o, pid($p))));
});

route('PUT', '/owner/buildings/{id}', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    if ($b['status'] === 'banned') {
        fail(403, 'This building has been banned by an admin.', 'building_banned');
    }
    $f = building_fields($b, false);
    $scope_changed = $f['agreement_scope'] !== $b['agreement_scope'];
    tx(function () use ($f, $b, $scope_changed) {
        q(
            'UPDATE buildings SET name=?, description=?, city_id=?, area_id=?, area_other=?, gps_address=?, landmark=?, termination_policy_id=?, agreement_scope=?, floors_count=? WHERE id=?',
            [$f['name'], $f['description'], $f['city_id'], $f['area_id'], $f['area_other'], $f['gps_address'], $f['landmark'], $f['termination_policy_id'], $f['agreement_scope'], $f['floors_count'], $b['id']]
        );
        if ($scope_changed) {                                    // old agreements are replaced; signed tenants keep their copy
            q('UPDATE agreements SET is_current = 0 WHERE building_id = ?', [$b['id']]);
            rematch_rooms($b);
        }
    });
    $unpublished = revalidate_live((int)$b['id']);
    json_out(building_editor(row('SELECT * FROM buildings WHERE id = ?', [$b['id']])) + ['unpublished' => $unpublished]);
});

route('DELETE', '/owner/buildings/{id}', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    if (in_str('confirm_name', 120, true) !== $b['name']) {
        fail(422, 'Type the building name exactly to delete it.', 'confirm_name_mismatch');
    }
    if ((int)val("SELECT COUNT(*) FROM tenancies WHERE building_id = ? AND status IN ('reserved','active')", [$b['id']])
        || (int)val("SELECT COUNT(*) FROM tenancy_processes WHERE building_id = ? AND status IN ('started','agreement_read','method_chosen','proof_uploaded')", [$b['id']])) {
        fail(409, 'This building has tenants or a payment in progress and cannot be deleted.', 'in_use');
    }
    if ((int)val('SELECT COUNT(*) FROM viewings WHERE building_id = ?', [$b['id']]) || (int)val('SELECT COUNT(*) FROM tenancies WHERE building_id = ?', [$b['id']])) {
        fail(409, 'This building has history (viewings or tenancies). Ask an admin to remove it.', 'in_use');
    }
    foreach (rows('SELECT path FROM building_photos WHERE building_id = ?', [$b['id']]) as $ph) {
        delete_file($ph['path']);
    }
    foreach (rows('SELECT path FROM room_photos WHERE room_id IN (SELECT id FROM rooms WHERE building_id = ?)', [$b['id']]) as $ph) {
        delete_file($ph['path']);
    }
    foreach (rows('SELECT pdf_path FROM agreements WHERE building_id = ?', [$b['id']]) as $a) {
        delete_file($a['pdf_path'], true);
    }
    q('DELETE FROM agency_applications WHERE building_id = ? AND status IN (\'pending\',\'declined\')', [$b['id']]);
    q('DELETE FROM buildings WHERE id = ?', [$b['id']]);
    json_out(['deleted' => true]);
});

route('POST', '/owner/buildings/{id}/photos', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    $files = uploaded_files('photos');
    if (!$files) {
        fail(422, 'Choose at least one photo.', 'validation');
    }
    $max = (int)setting('max_building_photos', '7');
    $have = (int)val('SELECT COUNT(*) FROM building_photos WHERE building_id = ?', [$b['id']]);
    if ($have + count($files) > $max) {
        fail(422, "A building can have at most $max photos ($have already added).", 'photo_limit');
    }
    $next = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM building_photos WHERE building_id = ?', [$b['id']]);
    foreach ($files as $f) {
        q('INSERT INTO building_photos (building_id, path, sort_order) VALUES (?,?,?)', [$b['id'], save_image($f, 'buildings'), ++$next]);
    }
    json_out(['photos' => photo_rows('building_photos', 'building_id', (int)$b['id'])], 201);
});

route('DELETE', '/owner/buildings/{id}/photos/{photo}', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    $ph = must_find(row('SELECT * FROM building_photos WHERE id = ? AND building_id = ?', [pid($p, 'photo'), $b['id']]), 'Photo');
    q('DELETE FROM building_photos WHERE id = ?', [$ph['id']]);
    delete_file($ph['path']);
    json_out(['photos' => photo_rows('building_photos', 'building_id', (int)$b['id'])]);
});

route('PUT', '/owner/buildings/{id}/amenities', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    $in = input();
    tx(function () use ($b, $in) {
        save_amenity_answers('building', (int)$b['id'], is_array($in['answers'] ?? null) ? $in['answers'] : []);
        if (isset($in['custom']) && is_array($in['custom'])) {
            save_custom_amenities((int)$b['id'], null, $in['custom']);
        }
    });
    $unpublished = revalidate_live((int)$b['id']);
    json_out(['missing' => amenities_missing('building', (int)$b['id']), 'unpublished' => $unpublished]);
});

route('POST', '/owner/buildings/{id}/room-types', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    $name = in_str('name', 80, true);
    reject_contact_info($name, 'name');
    q('INSERT INTO room_types (building_id, name) VALUES (?,?)', [$b['id'], $name]);
    json_out(['room_types' => rows('SELECT id, name FROM room_types WHERE building_id = ? ORDER BY name', [$b['id']])], 201);
});

route('POST', '/owner/buildings/{id}/agreements', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    if (!$b['agreement_scope']) {
        fail(422, 'Choose general or specified agreements first.', 'validation');
    }
    $scope = in_enum('scope', ['general', 'floor', 'room_type']);
    $expect = ['general' => 'general', 'by_floor' => 'floor', 'by_room_type' => 'room_type'][$b['agreement_scope']];
    if ($scope !== $expect) {
        fail(422, 'That agreement type does not match this building\'s setting.', 'validation');
    }
    $floor = $type = null;
    if ($scope === 'floor') {
        $floor = in_int('floor_number', true);
        if ($floor < 0 || $floor > 60) {
            fail(422, 'Floor number out of range.', 'validation');
        }
    } elseif ($scope === 'room_type') {
        $type = in_int('room_type_id', true);
        must_find(row('SELECT id FROM room_types WHERE id = ? AND building_id = ?', [$type, $b['id']]), 'Room type');
    }
    $f = uploaded_files('pdf')[0] ?? null;
    if (!$f) {
        fail(422, 'Choose the agreement PDF.', 'validation');
    }
    $path = save_pdf($f, 'agreements');
    $prev = row('SELECT MAX(version) AS v FROM agreements WHERE building_id = ? AND scope = ? AND floor_number <=> ? AND room_type_id <=> ?', [$b['id'], $scope, $floor, $type]);
    tx(function () use ($b, $scope, $floor, $type, $path, $prev, $f) {
        q('UPDATE agreements SET is_current = 0 WHERE building_id = ? AND scope = ? AND floor_number <=> ? AND room_type_id <=> ?', [$b['id'], $scope, $floor, $type]);
        q(
            'INSERT INTO agreements (building_id, scope, floor_number, room_type_id, pdf_path, file_hash, version) VALUES (?,?,?,?,?,?,?)',
            [$b['id'], $scope, $floor, $type, $path, hash_file('sha256', APP_ROOT . '/storage/private/' . $path), ((int)($prev['v'] ?? 0)) + 1]
        );
        rematch_rooms($b);
    });
    json_out(['agreements' => rows('SELECT id, scope, floor_number, room_type_id, version, uploaded_at FROM agreements WHERE building_id = ? AND is_current = 1', [$b['id']]), 'missing' => building_missing((int)$b['id'])], 201);
});

route('GET', '/owner/agreements/{id}/file', function ($p) {
    $o = require_role('owner');
    $a = must_find(row('SELECT a.* FROM agreements a JOIN buildings b ON b.id = a.building_id WHERE a.id = ? AND b.owner_id = ?', [pid($p), $o['id']]), 'Agreement');
    serve_private($a['pdf_path'], 'application/pdf', 'tenancy-agreement.pdf');
});

route('GET', '/owner/buildings/{id}/completeness', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    json_out(['missing' => building_missing((int)$b['id'])]);
});

route('POST', '/owner/buildings/{id}/publish', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    if ($b['status'] === 'banned') {
        fail(403, 'This building has been banned by an admin.', 'building_banned');
    }
    $missing = building_missing((int)$b['id']);
    if ($missing) {
        fail(422, 'Some required details are missing.', 'incomplete', ['missing' => $missing]);
    }
    q("UPDATE buildings SET status = 'live' WHERE id = ?", [$b['id']]);
    json_out(['status' => 'live']);
});

/* ---------- rooms ---------- */

function room_fields(array $b, array $r): array
{
    $label = in_str('label', 80, !$r);
    reject_contact_info($label, 'label');
    $type = array_key_exists('room_type_id', input()) ? in_int('room_type_id') : ($r['room_type_id'] ?? null);
    if ($type !== null && !val('SELECT 1 FROM room_types WHERE id = ? AND building_id = ?', [$type, $b['id']])) {
        fail(422, 'Unknown room type.', 'validation', ['field' => 'room_type_id']);
    }
    $floor = array_key_exists('floor_number', input()) ? in_int('floor_number') : ($r['floor_number'] ?? null);
    if ($floor !== null && ($floor < 0 || $floor > 60)) {
        fail(422, 'Floor number out of range.', 'validation', ['field' => 'floor_number']);
    }
    $rent = array_key_exists('monthly_rent', input()) ? input()['monthly_rent'] : ($r['monthly_rent'] ?? null);
    if ($rent !== null && $rent !== '' && (!is_numeric($rent) || (float)$rent <= 0 || (float)$rent > 100000000)) {
        fail(422, 'Enter the monthly rent in GHS.', 'validation', ['field' => 'monthly_rent']);
    }
    return [
        'label' => $label ?? $r['label'], 'description' => in_str('description', 2000) ?? ($r['description'] ?? null),
        'room_type_id' => $type, 'floor_number' => $floor,
        'monthly_rent' => ($rent === null || $rent === '') ? null : money((float)$rent),
    ];
}

function save_durations(int $room_id): void
{
    $d = input()['durations'] ?? null;
    if (!is_array($d)) {
        return;
    }
    q('DELETE FROM room_rent_options WHERE room_id = ?', [$room_id]);
    foreach (array_unique(array_map('intval', $d)) as $m) {
        if (!in_array($m, [6, 12, 24], true)) {
            fail(422, 'Durations can be 6, 12 or 24 months.', 'validation');
        }
        q('INSERT INTO room_rent_options (room_id, duration_months) VALUES (?,?)', [$room_id, $m]);
    }
}

route('POST', '/owner/buildings/{id}/rooms', function ($p) {
    $o = require_role('owner');
    $b = owned_building($o, pid($p));
    if (!val('SELECT 1 FROM owner_fee_acknowledgements WHERE owner_id = ?', [$o['id']])) {
        fail(428, 'Please read and acknowledge the 5% fee notice first.', 'fee_ack_required');
    }
    $f = room_fields($b, []);
    tx(function () use ($b, $f) {
        q('INSERT INTO rooms (building_id, room_type_id, floor_number, label, description, monthly_rent) VALUES (?,?,?,?,?,?)', [$b['id'], $f['room_type_id'], $f['floor_number'], $f['label'], $f['description'], $f['monthly_rent']]);
        $id = insert_id();
        save_durations($id);
        $room = row('SELECT * FROM rooms WHERE id = ?', [$id]);
        q('UPDATE rooms SET agreement_id = ? WHERE id = ?', [match_agreement($b, $room), $id]);
    });
    json_out(building_editor(row('SELECT * FROM buildings WHERE id = ?', [$b['id']])), 201);
});

route('PUT', '/owner/rooms/{id}', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    $b = row('SELECT * FROM buildings WHERE id = ?', [$r['building_id']]);
    $f = room_fields($b, $r);
    tx(function () use ($b, $r, $f) {
        q('UPDATE rooms SET room_type_id=?, floor_number=?, label=?, description=?, monthly_rent=? WHERE id=?', [$f['room_type_id'], $f['floor_number'], $f['label'], $f['description'], $f['monthly_rent'], $r['id']]);
        save_durations((int)$r['id']);
        $room = row('SELECT * FROM rooms WHERE id = ?', [$r['id']]);
        q('UPDATE rooms SET agreement_id = ? WHERE id = ?', [match_agreement($b, $room), $r['id']]);
    });
    $unavailable = false;
    if ($r['status'] === 'available' && room_missing((int)$r['id'])) {
        q("UPDATE rooms SET status = 'draft' WHERE id = ?", [$r['id']]);
        $unavailable = true;
    }
    json_out(['room' => row('SELECT * FROM rooms WHERE id = ?', [$r['id']]), 'missing' => room_missing((int)$r['id']), 'unpublished' => $unavailable]);
});

route('DELETE', '/owner/rooms/{id}', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    if (in_array($r['status'], ['reserved', 'occupied'], true)
        || (int)val('SELECT COUNT(*) FROM tenancies WHERE room_id = ?', [$r['id']])
        || (int)val('SELECT COUNT(*) FROM viewings WHERE room_id = ?', [$r['id']])) {
        fail(409, 'This room has tenants or history and cannot be deleted.', 'in_use');
    }
    foreach (rows('SELECT path FROM room_photos WHERE room_id = ?', [$r['id']]) as $ph) {
        delete_file($ph['path']);
    }
    q('DELETE FROM rooms WHERE id = ?', [$r['id']]);
    json_out(['deleted' => true]);
});

route('POST', '/owner/rooms/{id}/photos', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    $files = uploaded_files('photos');
    if (!$files) {
        fail(422, 'Choose at least one photo.', 'validation');
    }
    $max = (int)setting('max_room_photos', '10');
    $have = (int)val('SELECT COUNT(*) FROM room_photos WHERE room_id = ?', [$r['id']]);
    if ($have + count($files) > $max) {
        fail(422, "A room can have at most $max photos ($have already added).", 'photo_limit');
    }
    $next = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM room_photos WHERE room_id = ?', [$r['id']]);
    foreach ($files as $f) {
        q('INSERT INTO room_photos (room_id, path, sort_order) VALUES (?,?,?)', [$r['id'], save_image($f, 'rooms'), ++$next]);
    }
    json_out(['photos' => photo_rows('room_photos', 'room_id', (int)$r['id'])], 201);
});

route('DELETE', '/owner/rooms/{id}/photos/{photo}', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    $ph = must_find(row('SELECT * FROM room_photos WHERE id = ? AND room_id = ?', [pid($p, 'photo'), $r['id']]), 'Photo');
    q('DELETE FROM room_photos WHERE id = ?', [$ph['id']]);
    delete_file($ph['path']);
    json_out(['photos' => photo_rows('room_photos', 'room_id', (int)$r['id'])]);
});

route('PUT', '/owner/rooms/{id}/amenities', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    $in = input();
    tx(function () use ($r, $in) {
        save_amenity_answers('room', (int)$r['id'], is_array($in['answers'] ?? null) ? $in['answers'] : []);
        if (isset($in['custom']) && is_array($in['custom'])) {
            save_custom_amenities(null, (int)$r['id'], $in['custom']);
        }
    });
    json_out(['missing' => room_missing((int)$r['id'])]);
});

route('POST', '/owner/rooms/{id}/publish', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    $b = row('SELECT * FROM buildings WHERE id = ?', [$r['building_id']]);
    if ($b['status'] !== 'live') {
        fail(422, 'Post the building first. Rooms go live once their building is live.', 'building_not_live');
    }
    if (in_array($r['status'], ['reserved', 'occupied'], true)) {
        fail(409, 'This room is already in use.', 'in_use');
    }
    $missing = room_missing((int)$r['id']);
    if ($missing) {
        fail(422, 'Some required details are missing.', 'incomplete', ['missing' => $missing]);
    }
    q("UPDATE rooms SET status = 'available', agreement_id = ? WHERE id = ?", [match_agreement($b, $r), $r['id']]);
    json_out(['status' => 'available']);
});

route('POST', '/owner/rooms/{id}/unpublish', function ($p) {
    $o = require_role('owner');
    $r = owned_room($o, pid($p));
    if ($r['status'] !== 'available') {
        fail(409, 'Only available rooms can be taken down.', 'in_use');
    }
    q("UPDATE rooms SET status = 'draft' WHERE id = ?", [$r['id']]);
    json_out(['status' => 'draft']);
});

/* ---------- payout details (only shown during a user's tenant process) ---------- */

route('GET', '/owner/payout-details', function () {
    $o = require_role('owner');
    json_out(['payout' => row('SELECT momo_network, momo_number, momo_name, bank_name, account_name, account_number FROM owner_payout_details WHERE owner_id = ?', [$o['id']]), 'phone_verified' => (bool)$o['phone_verified_at']]);
});

route('PUT', '/owner/payout-details', function () {
    $o = require_role('owner');
    if (!$o['phone_verified_at']) {
        fail(428, 'Verify your phone number before adding payout details.', 'phone_verification_required');
    }
    $momo = in_str('momo_number', 20);
    $momo = $momo !== null ? normalize_phone($momo) : null;
    if (in_str('momo_number', 20) !== null && !$momo) {
        fail(422, 'Enter a valid Ghana MoMo number.', 'validation', ['field' => 'momo_number']);
    }
    $acct = in_str('account_number', 20);
    if ($acct !== null && !preg_match('/^\d{6,20}$/', $acct)) {
        fail(422, 'Account number should be digits only.', 'validation', ['field' => 'account_number']);
    }
    if (!$momo && !$acct) {
        fail(422, 'Add MoMo details, bank details, or both.', 'validation');
    }
    if ($momo && !in_bool('confirm_different_number') && $momo !== $o['phone']) {
        fail(409, 'This MoMo number is different from your verified phone. Confirm it is yours.', 'momo_differs', ['verified_phone' => mask_account($o['phone'])]);
    }
    q(
        'INSERT INTO owner_payout_details (owner_id, momo_network, momo_number, momo_name, bank_name, account_name, account_number) VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE momo_network=VALUES(momo_network), momo_number=VALUES(momo_number), momo_name=VALUES(momo_name), bank_name=VALUES(bank_name), account_name=VALUES(account_name), account_number=VALUES(account_number)',
        [$o['id'], in_str('momo_network', 30), $momo, in_str('momo_name', 120), in_str('bank_name', 80), in_str('account_name', 120), $acct]
    );
    json_out(['ok' => true]);
});

/* ---------- agent applications and revocation ---------- */

route('GET', '/owner/applications', function () {
    $o = require_role('owner');
    $status = in_enum('status', ['pending', 'approved', 'declined', 'revoked'], false);
    $sql = 'SELECT aa.*, b.name AS building_name FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE b.owner_id = ?';
    $args = [$o['id']];
    if ($status) {
        $sql .= ' AND aa.status = ?';
        $args[] = $status;
    }
    $out = [];
    foreach (rows($sql . ' ORDER BY aa.id DESC', $args) as $a) {
        $a['agent'] = user_card_by_id((int)$a['agent_id']);
        $a['agent_rating'] = rating_summary('agent', (int)$a['agent_id'])['user'];
        $out[] = $a;
    }
    json_out(['applications' => $out]);
});

route('POST', '/owner/applications/{id}/decide', function ($p) {
    $o = require_role('owner');
    $a = must_find(row('SELECT aa.* FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE aa.id = ? AND b.owner_id = ?', [pid($p), $o['id']]), 'Application');
    if ($a['status'] !== 'pending') {
        fail(409, 'This application was already decided.', 'already_decided');
    }
    $decision = in_enum('decision', ['approve', 'decline']);
    $title = building_title((int)$a['building_id']);
    if ($decision === 'approve') {
        tx(function () use ($a, $o) {
            q("UPDATE agency_applications SET status = 'approved', decided_at = ? WHERE id = ?", [now(), $a['id']]);
            chat_create('agent_owner', (int)$a['building_id'], null, null, null, [(int)$o['id'], (int)$a['agent_id']]);
        });
        notify((int)$a['agent_id'], 'agency_approved', 'Agency approved', "You are now an agent for $title.", ['building_id' => (int)$a['building_id']]);
    } else {
        q("UPDATE agency_applications SET status = 'declined', decided_at = ? WHERE id = ?", [now(), $a['id']]);
        notify((int)$a['agent_id'], 'agency_declined', 'Agency declined', "Your application for $title was declined.", ['building_id' => (int)$a['building_id']]);
    }
    json_out(['status' => $decision === 'approve' ? 'approved' : 'declined']);
});

route('GET', '/owner/agents', function () {
    $o = require_role('owner');
    $out = [];
    foreach (rows("SELECT aa.id AS application_id, aa.building_id, aa.agent_id, aa.decided_at, b.name AS building_name FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE b.owner_id = ? AND aa.status = 'approved' ORDER BY b.name", [$o['id']]) as $r) {
        $r['agent'] = user_card_by_id((int)$r['agent_id']);
        $r['rating'] = rating_summary('agent', (int)$r['agent_id'])['user'];
        $r['chat_id'] = ($c = val("SELECT c.id FROM chats c JOIN chat_members m1 ON m1.chat_id = c.id AND m1.user_id = ? JOIN chat_members m2 ON m2.chat_id = c.id AND m2.user_id = ? WHERE c.type = 'agent_owner' AND c.building_id = ? AND c.status = 'open' ORDER BY c.id DESC LIMIT 1", [$o['id'], $r['agent_id'], $r['building_id']])) ? (int)$c : null;
        $out[] = $r;
    }
    json_out(['agents' => $out]);
});

route('POST', '/owner/applications/{id}/revoke', function ($p) {
    $o = require_role('owner');
    $a = must_find(row('SELECT aa.* FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE aa.id = ? AND b.owner_id = ?', [pid($p), $o['id']]), 'Application');
    if ($a['status'] !== 'approved') {
        fail(409, 'Only approved agents can be revoked.', 'already_decided');
    }
    $bid = (int)$a['building_id'];
    $agent = (int)$a['agent_id'];
    tx(function () use ($a, $bid, $agent, $o) {
        q("UPDATE agency_applications SET status = 'revoked', revoked_at = ? WHERE id = ?", [now(), $a['id']]);
        // the agent's reviews and comments on this building and its rooms lock immediately
        q(
            "UPDATE reviews SET is_locked = 1, locked_at = ? WHERE reviewer_id = ? AND reviewer_role = 'agent' AND is_locked = 0
             AND ((target_type = 'building' AND target_id = ?) OR (target_type = 'room' AND target_id IN (SELECT id FROM rooms WHERE building_id = ?)))",
            [now(), $agent, $bid, $bid]
        );
        // chats between them become read-only; open viewings with this agent are cancelled
        foreach (rows("SELECT c.id FROM chats c JOIN chat_members m ON m.chat_id = c.id AND m.user_id = ? WHERE c.building_id = ? AND c.type = 'agent_owner' AND c.status = 'open'", [$agent, $bid]) as $c) {
            chat_close((int)$c['id'], 'read_only', false);
            chat_system((int)$c['id'], 'The owner ended this agency. This chat is now read-only.');
        }
        foreach (rows("SELECT id, user_id FROM viewings WHERE agent_id = ? AND building_id = ? AND status IN ('pending','accepted','date_change_requested')", [$agent, $bid]) as $v) {
            q("UPDATE viewings SET status = 'cancelled' WHERE id = ?", [$v['id']]);
            foreach (rows('SELECT id FROM chats WHERE viewing_id = ?', [$v['id']]) as $c) {
                chat_system((int)$c['id'], 'This viewing was cancelled because the agent is no longer approved for this building.');
                chat_close((int)$c['id'], 'closed');
            }
            notify((int)$v['user_id'], 'viewing_cancelled', 'Viewing cancelled', 'The agent is no longer approved for this building.', ['viewing_id' => (int)$v['id']]);
        }
        // both sides complete a short survey on the reason
        $deadline = plus('+7 days');
        q('INSERT INTO revocation_surveys (application_id, respondent_id, respondent_role, deadline_at) VALUES (?,?,?,?)', [$a['id'], $o['id'], 'owner', $deadline]);
        q('INSERT INTO revocation_surveys (application_id, respondent_id, respondent_role, deadline_at) VALUES (?,?,?,?)', [$a['id'], $agent, 'agent', $deadline]);
    });
    $title = building_title($bid);
    notify($agent, 'agency_revoked', 'Agency ended', "The owner ended your agency for $title. Please complete a short survey.", ['application_id' => (int)$a['id']]);
    notify((int)$o['id'], 'survey_due', 'Survey needed', 'Please tell us why you ended this agency.', ['application_id' => (int)$a['id']]);
    json_out(['status' => 'revoked']);
});

/* ---------- surveys (owner and agent) ---------- */

route('GET', '/surveys/mine', function () {
    $u = require_user();
    json_out(['surveys' => rows(
        'SELECT s.id, s.application_id, s.respondent_role, s.deadline_at, b.name AS building_name
         FROM revocation_surveys s JOIN agency_applications aa ON aa.id = s.application_id JOIN buildings b ON b.id = aa.building_id
         WHERE s.respondent_id = ? AND s.submitted_at IS NULL', [$u['id']]
    ), 'reasons' => ['unresponsive' => 'Not responding', 'poor_service' => 'Poor service', 'conduct' => 'Conduct or behaviour', 'no_longer_needed' => 'No longer needed', 'building_sold' => 'Building sold or changed', 'other' => 'Other']]);
});

route('POST', '/surveys/{id}/submit', function ($p) {
    $u = require_user();
    $s = must_find(row('SELECT * FROM revocation_surveys WHERE id = ? AND respondent_id = ?', [pid($p), $u['id']]), 'Survey');
    if ($s['submitted_at']) {
        fail(409, 'Already submitted.', 'already_decided');
    }
    $reason = in_enum('reason_code', ['unresponsive', 'poor_service', 'conduct', 'no_longer_needed', 'building_sold', 'other']);
    $note = in_str('note', 500);
    reject_contact_info($note, 'note');
    q('UPDATE revocation_surveys SET reason_code = ?, note = ?, submitted_at = ? WHERE id = ?', [$reason, $note, now(), $s['id']]);
    json_out(['ok' => true]);
});

/* ---------- tenants ---------- */

route('GET', '/owner/tenants', function () {
    $o = require_role('owner');
    $processes = [];
    foreach (rows(
        "SELECT tp.*, r.label AS room_label, b.name AS building_name, py.id AS payment_id, py.status AS payment_status, py.method AS payment_method, py.amount, py.verify_by
         FROM tenancy_processes tp JOIN buildings b ON b.id = tp.building_id JOIN rooms r ON r.id = tp.room_id
         LEFT JOIN payments py ON py.id = (SELECT MAX(id) FROM payments WHERE process_id = tp.id)
         WHERE b.owner_id = ? AND tp.status IN ('started','agreement_read','method_chosen','proof_uploaded','expired') AND tp.started_at > ? ORDER BY tp.id DESC", [$o['id'], plus('-30 days')]) as $r) {
        $r['user'] = user_card_by_id((int)$r['user_id']);
        $r['agent'] = user_card_by_id($r['agent_id'] ? (int)$r['agent_id'] : null);
        $r['chat_id'] = ($c = val("SELECT id FROM chats WHERE process_id = ? AND type = 'tenant_owner' LIMIT 1", [$r['id']])) ? (int)$c : null;
        $processes[] = $r;
    }
    $tenancies = [];
    foreach (rows(
        "SELECT t.*, r.label AS room_label, b.name AS building_name FROM tenancies t JOIN buildings b ON b.id = t.building_id JOIN rooms r ON r.id = t.room_id
         WHERE t.owner_id = ? ORDER BY t.id DESC", [$o['id']]) as $t) {
        $t['user'] = user_card_by_id((int)$t['user_id']);
        $t['days_left'] = $t['end_date'] ? days_between(today(), $t['end_date']) : null;
        $t['payment'] = row('SELECT id, method, status, amount, owner_amount FROM payments WHERE process_id = ? ORDER BY id DESC LIMIT 1', [$t['process_id']]);
        $t['open_reports'] = (int)val("SELECT COUNT(*) FROM tenant_reports WHERE tenancy_id = ? AND status <> 'resolved'", [$t['id']]);
        $t['chat_id'] = ($c = val("SELECT id FROM chats WHERE process_id = ? AND type = 'tenant_owner' LIMIT 1", [$t['process_id']])) ? (int)$c : null;
        $tenancies[] = $t;
    }
    json_out(['processes' => $processes, 'tenancies' => $tenancies]);
});

/** Third-party route: the owner checks the receipt within 48 hours of the upload (or at next app open). */
route('POST', '/owner/payments/{id}/verify', function ($p) {
    $o = require_role('owner');
    $py = must_find(row(
        "SELECT py.* FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id JOIN buildings b ON b.id = tp.building_id WHERE py.id = ? AND b.owner_id = ? AND py.method = 'third_party'",
        [pid($p), $o['id']]), 'Payment');
    if ($py['status'] !== 'proof_uploaded') {
        fail(409, 'This payment is not waiting for you.', 'already_decided');
    }
    $tp = row('SELECT * FROM tenancy_processes WHERE id = ?', [$py['process_id']]);
    $action = in_enum('action', ['approve', 'reject']);
    if ($action === 'approve') {
        tx(function () use ($py, $tp, $o) {
            q("UPDATE payments SET status = 'verified', verifier_id = ?, verifier_role = 'owner', verified_at = ? WHERE id = ?", [$o['id'], now(), $py['id']]);
            q("UPDATE tenancy_processes SET status = 'verified', verified_at = ? WHERE id = ?", [now(), $tp['id']]);
            create_tenancy(row('SELECT * FROM tenancy_processes WHERE id = ?', [$tp['id']]), row('SELECT * FROM payments WHERE id = ?', [$py['id']]));
        });
        notify((int)$tp['user_id'], 'payment_verified', 'Payment confirmed', 'The owner confirmed your payment. Choose your move-in date.', ['process_id' => (int)$tp['id']]);
    } else {
        $reason = in_str('reason', 200, true);
        reject_contact_info($reason, 'reason');
        q("UPDATE payments SET status = 'rejected', rejection_reason = ?, resubmit_by = ?, verifier_id = ?, verifier_role = 'owner' WHERE id = ?", [$reason, plus('+' . (int)setting('proof_resubmit_hours', '24') . ' hours'), $o['id'], $py['id']]);
        q("UPDATE tenancy_processes SET status = 'method_chosen' WHERE id = ?", [$tp['id']]);
        notify((int)$tp['user_id'], 'payment_rejected', 'Payment proof rejected', "Reason: $reason. Upload corrected proof within 24 hours.", ['process_id' => (int)$tp['id']]);
    }
    json_out(['status' => $action === 'approve' ? 'verified' : 'rejected']);
});

/** App route: after the admin pays the owner, the owner taps "Payment received". */
route('POST', '/owner/payments/{id}/received', function ($p) {
    $o = require_role('owner');
    $py = must_find(row(
        "SELECT py.* FROM payments py JOIN tenancy_processes tp ON tp.id = py.process_id JOIN buildings b ON b.id = tp.building_id WHERE py.id = ? AND b.owner_id = ? AND py.method = 'app'",
        [pid($p), $o['id']]), 'Payment');
    if ($py['status'] !== 'paid_to_owner') {
        fail(409, 'The admin has not marked this payment as sent to you yet.', 'not_ready');
    }
    q("UPDATE payments SET status = 'owner_confirmed', owner_received_at = ? WHERE id = ?", [now(), $py['id']]);
    $tp = row('SELECT * FROM tenancy_processes WHERE id = ?', [$py['process_id']]);
    notify((int)$tp['user_id'], 'payment_received', 'Owner received the payment', 'You can now choose your move-in date.', ['process_id' => (int)$tp['id']]);
    json_out(['status' => 'owner_confirmed']);
});

/** On move-in day the owner prints the agreement and marks it signed; the tenancy becomes active. */
route('POST', '/owner/tenancies/{id}/sign', function ($p) {
    $o = require_role('owner');
    $t = must_find(row('SELECT * FROM tenancies WHERE id = ? AND owner_id = ?', [pid($p), $o['id']]), 'Tenancy');
    if ($t['status'] !== 'reserved' || !$t['move_in_date']) {
        fail(409, 'This tenancy is not waiting for signing.', 'not_ready');
    }
    if ($t['move_in_date'] > today()) {
        fail(422, 'Signing can be recorded from the move-in date (' . $t['move_in_date'] . ').', 'too_early');
    }
    $witness = in_str('witness_name', 100, true);
    check_name($witness);
    tx(function () use ($t, $witness) {
        q("UPDATE tenancies SET status = 'active', signed_at = ?, witness_name = ? WHERE id = ?", [now(), $witness, $t['id']]);
        q("UPDATE rooms SET status = 'occupied' WHERE id = ?", [$t['room_id']]);
    });
    notify((int)$t['user_id'], 'tenancy_active', 'Tenancy active', 'Your agreement was signed. Welcome home!', ['tenancy_id' => (int)$t['id']]);
    json_out(['status' => 'active']);
});
