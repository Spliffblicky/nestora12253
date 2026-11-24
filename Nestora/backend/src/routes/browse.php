<?php
declare(strict_types=1);

/* =====================================================================
   BROWSE AND SEARCH (all roles). Only live buildings and available rooms appear.
   Banned buildings, drafts and occupied rooms stay out.
   ===================================================================== */

function building_card(array $b): array
{
    $id = (int)$b['id'];
    return [
        'id'        => $id,
        'name'      => $b['name'],
        'region'    => $b['region_name'] ?? null,
        'city'      => $b['city_name'] ?? null,
        'area'      => $b['area_name'] ?? $b['area_other'] ?? null,
        'cover_url' => photo_url($b['cover'] ?? null),
        'owner'     => ['id' => (int)$b['owner_id'], 'name' => $b['owner_name'] ?? null, 'photo_url' => photo_url($b['owner_photo'] ?? null)],
        'rooms_available' => (int)($b['rooms_available'] ?? 0),
        'min_rent'  => isset($b['min_rent']) ? (float)$b['min_rent'] : null,
        'max_rent'  => isset($b['max_rent']) ? (float)$b['max_rent'] : null,
        'rating'    => rating_summary('building', $id),
    ];
}

/**
 * Search. Category picks what the text is matched against:
 * location | name | owner | agent | room_type. Filters: amenity codes, price range, region/city/area.
 */
route('GET', '/search', function () {
    require_user();
    $cat   = in_enum('category', ['location', 'name', 'owner', 'agent', 'room_type'], false) ?? 'name';
    $text  = in_str('q', 100);
    $where = ["b.status = 'live'"];
    $args  = [];

    foreach (['region_id' => 'c.region_id', 'city_id' => 'b.city_id', 'area_id' => 'b.area_id'] as $k => $col) {
        if (($v = in_int($k)) !== null) {
            $where[] = "$col = ?";
            $args[] = $v;
        }
    }
    if ($text !== null) {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $text) . '%';
        switch ($cat) {
            case 'location':
                $where[] = '(c.name LIKE ? OR a.name LIKE ? OR rg.name LIKE ? OR b.landmark LIKE ? OR b.area_other LIKE ?)';
                array_push($args, $like, $like, $like, $like, $like);
                break;
            case 'owner':
                $where[] = 'ow.name LIKE ?';
                $args[] = $like;
                break;
            case 'agent':
                $where[] = "EXISTS (SELECT 1 FROM agency_applications aa JOIN users ag ON ag.id = aa.agent_id WHERE aa.building_id = b.id AND aa.status = 'approved' AND ag.name LIKE ?)";
                $args[] = $like;
                break;
            case 'room_type':
                $where[] = "EXISTS (SELECT 1 FROM rooms rr LEFT JOIN room_types rt ON rt.id = rr.room_type_id WHERE rr.building_id = b.id AND rr.status = 'available' AND (rt.name LIKE ? OR rr.label LIKE ?))";
                array_push($args, $like, $like);
                break;
            default:
                $where[] = 'b.name LIKE ?';
                $args[] = $like;
        }
    }
    $min = input()['min_price'] ?? null;
    $max = input()['max_price'] ?? null;
    if (is_numeric($min)) {
        $where[] = "EXISTS (SELECT 1 FROM rooms r1 WHERE r1.building_id = b.id AND r1.status = 'available' AND r1.monthly_rent >= ?)";
        $args[] = (float)$min;
    }
    if (is_numeric($max)) {
        $where[] = "EXISTS (SELECT 1 FROM rooms r2 WHERE r2.building_id = b.id AND r2.status = 'available' AND r2.monthly_rent <= ?)";
        $args[] = (float)$max;
    }
    // amenity filters: "water_supply:borehole", "private_bathroom" style -> code[:value] or code=yes
    $filters = input()['amenities'] ?? [];
    if (is_string($filters)) {
        $filters = array_filter(explode(',', $filters));
    }
    foreach (array_slice((array)$filters, 0, 12) as $f) {
        $f = (string)$f;
        [$code, $value] = array_pad(explode(':', $f, 2), 2, 'yes');
        $def = row('SELECT id, scope, kind FROM amenity_definitions WHERE code = ?', [$code]);
        if (!$def) {
            continue;
        }
        $cond = $def['kind'] === 'yes_no' ? 'x.answer = ?' : 'x.choice_value = ?';
        if ($def['scope'] === 'building') {
            $where[] = "EXISTS (SELECT 1 FROM building_amenity_answers x WHERE x.building_id = b.id AND x.amenity_id = ? AND $cond)";
        } else {
            $where[] = "EXISTS (SELECT 1 FROM room_amenity_answers x JOIN rooms rx ON rx.id = x.room_id WHERE rx.building_id = b.id AND rx.status = 'available' AND x.amenity_id = ? AND $cond)";
        }
        array_push($args, (int)$def['id'], $value);
    }

    $page = max(1, in_int('page') ?? 1);
    $per = 20;
    $list = rows(
        "SELECT b.*, c.name AS city_name, rg.name AS region_name, a.name AS area_name, ow.name AS owner_name, ow.profile_photo AS owner_photo,
                (SELECT path FROM building_photos p WHERE p.building_id = b.id ORDER BY sort_order, id LIMIT 1) AS cover,
                (SELECT COUNT(*) FROM rooms r WHERE r.building_id = b.id AND r.status = 'available') AS rooms_available,
                (SELECT MIN(monthly_rent) FROM rooms r WHERE r.building_id = b.id AND r.status = 'available') AS min_rent,
                (SELECT MAX(monthly_rent) FROM rooms r WHERE r.building_id = b.id AND r.status = 'available') AS max_rent
         FROM buildings b JOIN cities c ON c.id = b.city_id JOIN regions rg ON rg.id = c.region_id
         LEFT JOIN areas a ON a.id = b.area_id JOIN users ow ON ow.id = b.owner_id
         WHERE " . implode(' AND ', $where) . ' AND ow.status = \'active\'
         HAVING rooms_available > 0 OR 1 = 1
         ORDER BY b.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per),
        $args
    );
    $more = count($list) > $per;
    $list = array_slice($list, 0, $per);
    json_out(['buildings' => array_map('building_card', $list), 'page' => $page, 'has_more' => $more]);
});

/** Slides: building photos first, then every room's photos, each captioned. */
function slideshow_slides(int $building_id): array
{
    $b = (string)val('SELECT name FROM buildings WHERE id = ?', [$building_id]);
    $slides = [];
    foreach (rows('SELECT path FROM building_photos WHERE building_id = ? ORDER BY sort_order, id', [$building_id]) as $p) {
        $slides[] = ['url' => photo_url($p['path']), 'caption' => $b . ' (building)', 'room_id' => null];
    }
    foreach (rows("SELECT id, label FROM rooms WHERE building_id = ? AND status IN ('available','reserved','occupied') ORDER BY floor_number, id", [$building_id]) as $r) {
        foreach (rows('SELECT path FROM room_photos WHERE room_id = ? ORDER BY sort_order, id', [$r['id']]) as $p) {
            $slides[] = ['url' => photo_url($p['path']), 'caption' => $r['label'], 'room_id' => (int)$r['id']];
        }
    }
    return $slides;
}

function room_card(array $r): array
{
    $id = (int)$r['id'];
    return [
        'id' => $id, 'building_id' => (int)$r['building_id'], 'label' => $r['label'], 'floor_number' => $r['floor_number'] !== null ? (int)$r['floor_number'] : null,
        'room_type' => $r['room_type_name'] ?? null, 'monthly_rent' => (float)$r['monthly_rent'], 'status' => $r['status'],
        'durations' => array_map('intval', array_column(rows('SELECT duration_months FROM room_rent_options WHERE room_id = ? ORDER BY 1', [$id]), 'duration_months')),
        'photos' => array_map(fn($p) => photo_url($p['path']), rows('SELECT path FROM room_photos WHERE room_id = ? ORDER BY sort_order, id', [$id])),
        'amenities' => amenity_answers_view('room', $id),
        'custom_amenities' => rows('SELECT label, answer, note FROM custom_amenities WHERE room_id = ?', [$id]),
        'agreement_type' => ($a = row('SELECT scope, floor_number FROM agreements WHERE id = ?', [$r['agreement_id']])) ? $a['scope'] : null,
        'rating' => rating_summary('room', $id),
    ];
}

function visible_building(int $id, ?array $viewer = null): array
{
    $b = must_find(row("SELECT * FROM buildings WHERE id = ? AND status = 'live'", [$id]), 'Building');
    if ($viewer && $viewer['role'] === 'admin') {
        return $b;
    }
    return $b;
}

route('GET', '/buildings/{id}', function ($p) {
    $u = require_user();
    $b = row(
        "SELECT b.*, c.name AS city_name, rg.name AS region_name, a.name AS area_name, pol.label AS termination_label, pol.basis AS termination_basis, pol.percent AS termination_percent
         FROM buildings b JOIN cities c ON c.id = b.city_id JOIN regions rg ON rg.id = c.region_id LEFT JOIN areas a ON a.id = b.area_id
         LEFT JOIN termination_policies pol ON pol.id = b.termination_policy_id
         WHERE b.id = ?", [pid($p)]);
    must_find($b, 'Building');
    $is_owner = $u['role'] === 'owner' && (int)$b['owner_id'] === (int)$u['id'];
    if ($b['status'] !== 'live' && !$is_owner && $u['role'] !== 'admin') {
        fail(404, 'Building not found', 'not_found');
    }
    $room_sql = "SELECT r.*, rt.name AS room_type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.building_id = ?" . ($is_owner || $u['role'] === 'admin' ? '' : " AND r.status = 'available'") . ' ORDER BY r.floor_number, r.id';
    $owner = row('SELECT id, name, username, profile_photo FROM users WHERE id = ?', [$b['owner_id']]);
    $agents = [];
    foreach (rows("SELECT agent_id FROM agency_applications WHERE building_id = ? AND status = 'approved'", [$b['id']]) as $a) {
        $agent = user_card_by_id((int)$a['agent_id']);
        $agent['rating'] = rating_summary('agent', (int)$a['agent_id'])['user'];
        $agents[] = $agent;
    }
    $my_application = $u['role'] === 'agent' ? agent_application((int)$u['id'], (int)$b['id']) : null;
    json_out([
        'building' => [
            'id' => (int)$b['id'], 'name' => $b['name'], 'description' => $b['description'], 'status' => $b['status'],
            'region' => $b['region_name'], 'city' => $b['city_name'], 'area' => $b['area_name'] ?? $b['area_other'],
            'gps_address' => $b['gps_address'], 'landmark' => $b['landmark'],
            'directions_url' => $b['gps_address'] ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($b['gps_address']) : null,
            'termination_policy' => ['label' => $b['termination_label'], 'basis' => $b['termination_basis'], 'percent' => $b['termination_percent'] !== null ? (float)$b['termination_percent'] : null],
        ],
        'owner' => user_card($owner),                                   // name and photo only, no contact details
        'slides' => slideshow_slides((int)$b['id']),
        'amenities' => amenity_answers_view('building', (int)$b['id']),
        'custom_amenities' => rows('SELECT label, answer, note FROM custom_amenities WHERE building_id = ?', [$b['id']]),
        'rooms' => array_map('room_card', rows($room_sql, [$b['id']])),
        'agents' => $agents,
        'rating' => rating_summary('building', (int)$b['id']),
        'my_application' => $my_application ? ['id' => (int)$my_application['id'], 'status' => $my_application['status']] : null,
        'fee_percent' => (float)setting('platform_fee_percent', '5'),
    ]);
});

route('GET', '/rooms/{id}', function ($p) {
    $u = require_user();
    $r = must_find(row('SELECT r.*, rt.name AS room_type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id JOIN buildings b ON b.id = r.building_id WHERE r.id = ?' . " AND b.status = 'live'", [pid($p)]), 'Room');
    json_out(['room' => room_card($r)]);
});

/** Slideshow of other available rooms in the same building: used at the tenant "same room?" step. */
route('GET', '/buildings/{id}/available-rooms', function ($p) {
    require_user();
    $b = visible_building(pid($p));
    $out = [];
    foreach (rows("SELECT r.*, rt.name AS room_type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.building_id = ? AND r.status = 'available' ORDER BY r.floor_number, r.id", [$b['id']]) as $r) {
        $out[] = room_card($r);
    }
    json_out(['rooms' => $out]);
});

/** Public profile of an agent: rating, approved buildings, weekly availability. */
route('GET', '/agents/{id}', function ($p) {
    require_user();
    $a = must_find(row("SELECT id, name, username, role, profile_photo FROM users WHERE id = ? AND role = 'agent' AND status = 'active'", [pid($p)]), 'Agent');
    json_out([
        'agent' => user_card($a),
        'rating' => rating_summary('agent', (int)$a['id']),
        'buildings' => rows("SELECT b.id, b.name FROM agency_applications aa JOIN buildings b ON b.id = aa.building_id WHERE aa.agent_id = ? AND aa.status = 'approved' AND b.status = 'live'", [$a['id']]),
        'weekdays' => array_map('intval', array_column(rows('SELECT DISTINCT weekday FROM agent_availability WHERE agent_id = ? ORDER BY weekday', [$a['id']]), 'weekday')),
    ]);
});

/** Public card for an owner: name and photo and their live buildings. No contact details. */
route('GET', '/owners/{id}', function ($p) {
    require_user();
    $o = must_find(row("SELECT id, name, username, role, profile_photo FROM users WHERE id = ? AND role = 'owner' AND status = 'active'", [pid($p)]), 'Owner');
    json_out(['owner' => user_card($o), 'buildings' => rows("SELECT id, name FROM buildings WHERE owner_id = ? AND status = 'live'", [$o['id']])]);
});
