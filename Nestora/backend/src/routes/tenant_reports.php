<?php
declare(strict_types=1);

/* =====================================================================
   TENANT SECTION: report problems with amenities and the room.
   To the owner by default; tenants can also report to the admin
   (after the owner has been silent for a while, or straight away for conduct/safety issues).
   ===================================================================== */

function active_tenancy_for(array $u, int $tenancy_id): array
{
    return must_find(row("SELECT * FROM tenancies WHERE id = ? AND user_id = ? AND status IN ('reserved','active')", [$tenancy_id, $u['id']]), 'Tenancy');
}

function report_view(array $r): array
{
    return [
        'id' => (int)$r['id'], 'tenancy_id' => (int)$r['tenancy_id'], 'category' => $r['category'], 'amenity_id' => $r['amenity_id'] ? (int)$r['amenity_id'] : null,
        'amenity' => $r['amenity_id'] ? (string)val('SELECT label FROM amenity_definitions WHERE id = ?', [$r['amenity_id']]) : null,
        'description' => $r['description'], 'status' => $r['status'], 'created_at' => $r['created_at'], 'resolved_at' => $r['resolved_at'],
        'escalated_to_admin' => (bool)$r['escalated_to_admin'], 'admin_outcome' => $r['admin_outcome'],
        'photos' => array_map(fn($p) => ['id' => (int)$p['id'], 'path' => '/tenant-reports/photos/' . $p['id']], rows('SELECT id FROM tenant_report_photos WHERE report_id = ?', [$r['id']])),
        'updates' => rows('SELECT status, note, created_at FROM tenant_report_updates WHERE report_id = ? ORDER BY id', [$r['id']]),
        'can_escalate' => !(int)$r['escalated_to_admin'] && $r['status'] !== 'resolved' && ts($r['created_at']) < time() - (int)setting('tenant_report_wait_days', '3') * 86400,
    ];
}

route('GET', '/tenant/reports/options', function () {
    $u = require_role('user');
    $t = row("SELECT building_id FROM tenancies WHERE user_id = ? AND status IN ('reserved','active') ORDER BY id DESC LIMIT 1", [$u['id']]);
    $amen = $t ? rows(
        "SELECT d.id, d.label FROM building_amenity_answers a JOIN amenity_definitions d ON d.id = a.amenity_id WHERE a.building_id = ? ORDER BY d.sort_order", [$t['building_id']]
    ) : [];
    json_out(['categories' => ['water', 'electricity', 'amenity', 'room', 'other'], 'amenities' => $amen,
              'emergency_note' => 'For urgent issues such as fire, gas or flooding, call local emergency services and the owner directly. Do not wait for a reply here.']);
});

/** multipart: tenancy_id, category, amenity_id?, description, photos[] (max 5) */
route('POST', '/tenant/reports', function () {
    $u = require_role('user');
    $t = active_tenancy_for($u, in_int('tenancy_id', true));
    $cat = in_enum('category', ['water', 'electricity', 'amenity', 'room', 'other']);
    $amenity = in_int('amenity_id');
    if ($cat === 'amenity' && $amenity === null) {
        fail(422, 'Pick the amenity that has a problem.', 'validation', ['field' => 'amenity_id']);
    }
    if ($amenity !== null && !val('SELECT 1 FROM building_amenity_answers WHERE building_id = ? AND amenity_id = ? UNION SELECT 1 FROM room_amenity_answers WHERE room_id = ? AND amenity_id = ?', [$t['building_id'], $amenity, $t['room_id'], $amenity])) {
        fail(422, 'That amenity is not listed for your building or room.', 'validation', ['field' => 'amenity_id']);
    }
    $desc = in_str('description', 1000, true);
    reject_contact_info($desc, 'description');
    if ((int)val("SELECT COUNT(*) FROM tenant_reports WHERE tenancy_id = ? AND status <> 'resolved'", [$t['id']]) >= 5) {
        fail(429, 'You have too many open reports. Wait for some to be resolved.', 'rate_limited');
    }
    $files = uploaded_files('photos');
    if (count($files) > 5) {
        fail(422, 'You can add at most 5 photos.', 'photo_limit');
    }
    $rid = tx(function () use ($t, $cat, $amenity, $desc, $files) {
        q('INSERT INTO tenant_reports (tenancy_id, category, amenity_id, description) VALUES (?,?,?,?)', [$t['id'], $cat, $amenity, $desc]);
        $id = insert_id();
        foreach ($files as $f) {
            q('INSERT INTO tenant_report_photos (report_id, path) VALUES (?,?)', [$id, save_image($f, 'reports', true)]);
        }
        return $id;
    });
    notify((int)$t['owner_id'], 'tenant_report', 'Problem reported', $u['name'] . ' reported a problem in ' . building_title((int)$t['building_id'], (int)$t['room_id']) . '.', ['report_id' => $rid]);
    json_out(['report' => report_view(row('SELECT * FROM tenant_reports WHERE id = ?', [$rid]))], 201);
});

route('GET', '/tenant/reports', function () {
    $u = require_role('user');
    $list = rows('SELECT r.* FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE t.user_id = ? ORDER BY r.id DESC LIMIT 100', [$u['id']]);
    json_out(['reports' => array_map('report_view', $list)]);
});

/** The tenant confirms it is fixed, or reopens it. */
route('POST', '/tenant/reports/{id}/confirm', function ($p) {
    $u = require_role('user');
    $r = must_find(row('SELECT r.* FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE r.id = ? AND t.user_id = ?', [pid($p), $u['id']]), 'Report');
    $resolved = in_bool('resolved');
    q('UPDATE tenant_reports SET status = ?, resolved_at = ? WHERE id = ?', [$resolved ? 'resolved' : 'reopened', $resolved ? now() : null, $r['id']]);
    q('INSERT INTO tenant_report_updates (report_id, author_id, status, note) VALUES (?,?,?,?)', [$r['id'], $u['id'], $resolved ? 'resolved' : 'reopened', $resolved ? 'Tenant confirmed it is fixed' : 'Tenant says it is not fixed']);
    $t = row('SELECT owner_id FROM tenancies WHERE id = ?', [$r['tenancy_id']]);
    notify((int)$t['owner_id'], 'tenant_report', $resolved ? 'Report resolved' : 'Report reopened', 'Report #' . $r['id'], ['report_id' => (int)$r['id']]);
    json_out(['ok' => true]);
});

/**
 * Report to admin. category 'owner_ignored' needs the owner to have been silent for a few days (attaches history);
 * 'conduct' and 'safety' reports (harassment, false listing, money asked outside the app) skip the wait.
 */
route('POST', '/tenant/reports/{id}/escalate', function ($p) {
    $u = require_role('user');
    $r = must_find(row('SELECT r.* FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE r.id = ? AND t.user_id = ?', [pid($p), $u['id']]), 'Report');
    if ($r['escalated_to_admin']) {
        fail(409, 'Already sent to the admin.', 'duplicate');
    }
    $kind = in_enum('reason', ['owner_ignored', 'conduct', 'safety']);
    if ($kind === 'owner_ignored' && !report_view($r)['can_escalate']) {
        fail(409, 'Give the owner ' . (int)setting('tenant_report_wait_days', '3') . ' days to respond first.', 'too_early');
    }
    q('UPDATE tenant_reports SET escalated_to_admin = 1, escalated_at = ? WHERE id = ?', [now(), $r['id']]);
    q('INSERT INTO tenant_report_updates (report_id, author_id, status, note) VALUES (?,?,?,?)', [$r['id'], $u['id'], 'escalated', 'Tenant sent this to the admin (' . $kind . ')']);
    notify_admins('tenant_report_admin', 'Tenant report sent to admin', "Report #{$r['id']} ($kind)", false, ['report_id' => (int)$r['id']]);
    $t = row('SELECT owner_id FROM tenancies WHERE id = ?', [$r['tenancy_id']]);
    notify((int)$t['owner_id'], 'tenant_report', 'A report was sent to the admin', 'Category: ' . $r['category'], ['report_id' => (int)$r['id']]);
    json_out(['ok' => true]);
});

route('GET', '/tenant-reports/photos/{id}', function ($p) {
    $u = require_user();
    $ph = must_find(row('SELECT ph.path, t.user_id, t.owner_id FROM tenant_report_photos ph JOIN tenant_reports r ON r.id = ph.report_id JOIN tenancies t ON t.id = r.tenancy_id WHERE ph.id = ?', [pid($p)]), 'Photo');
    if ((int)$ph['user_id'] !== (int)$u['id'] && (int)$ph['owner_id'] !== (int)$u['id'] && $u['role'] !== 'admin') {
        fail(404, 'Not found', 'not_found');
    }
    serve_private($ph['path'], 'image/jpeg');
});

/* ---------- owner side ---------- */

route('GET', '/owner/tenant-reports', function () {
    $o = require_role('owner');
    $out = [];
    foreach (rows('SELECT r.*, t.user_id, t.room_id, t.building_id FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE t.owner_id = ? ORDER BY r.id DESC LIMIT 100', [$o['id']]) as $r) {
        $v = report_view($r);
        $v['tenant'] = user_card_by_id((int)$r['user_id']);
        $v['room'] = building_title((int)$r['building_id'], (int)$r['room_id']);
        $out[] = $v;
    }
    json_out(['reports' => $out]);
});

route('POST', '/owner/tenant-reports/{id}/status', function ($p) {
    $o = require_role('owner');
    $r = must_find(row('SELECT r.*, t.user_id FROM tenant_reports r JOIN tenancies t ON t.id = r.tenancy_id WHERE r.id = ? AND t.owner_id = ?', [pid($p), $o['id']]), 'Report');
    $status = in_enum('status', ['seen', 'in_progress', 'resolved']);
    $note = in_str('note', 500);
    reject_contact_info($note, 'note');
    q('UPDATE tenant_reports SET status = ?, resolved_at = ? WHERE id = ?', [$status, $status === 'resolved' ? now() : null, $r['id']]);
    q('INSERT INTO tenant_report_updates (report_id, author_id, status, note) VALUES (?,?,?,?)', [$r['id'], $o['id'], $status, $note]);
    notify((int)$r['user_id'], 'tenant_report', 'Report update', 'Your report is now: ' . str_replace('_', ' ', $status) . '.', ['report_id' => (int)$r['id']]);
    json_out(['ok' => true]);
});
