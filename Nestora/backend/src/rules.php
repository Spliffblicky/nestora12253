<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/* =====================================================================
   Business rules shared by several routes
   ===================================================================== */

/* ---------- agents ---------- */

function agent_application(int $agent_id, int $building_id): ?array
{
    return row('SELECT * FROM agency_applications WHERE agent_id = ? AND building_id = ? ORDER BY id DESC LIMIT 1', [$agent_id, $building_id]);
}

function approved_agent(int $agent_id, int $building_id): bool
{
    $a = agent_application($agent_id, $building_id);
    return $a && $a['status'] === 'approved';
}

function building_owner_id(int $building_id): int
{
    return (int)val('SELECT owner_id FROM buildings WHERE id = ?', [$building_id]);
}

function owned_building(array $owner, int $building_id): array
{
    $b = row('SELECT * FROM buildings WHERE id = ? AND owner_id = ?', [$building_id, $owner['id']]);
    return must_find($b, 'Building');
}

function owned_room(array $owner, int $room_id): array
{
    $r = row('SELECT r.* FROM rooms r JOIN buildings b ON b.id = r.building_id WHERE r.id = ? AND b.owner_id = ?', [$room_id, $owner['id']]);
    return must_find($r, 'Room');
}

/* ---------- amenities ---------- */

function amenity_defs(string $scope): array
{
    $defs = rows('SELECT * FROM amenity_definitions WHERE scope = ? AND active = 1 ORDER BY sort_order', [$scope]);
    foreach ($defs as &$d) {
        $d['options'] = $d['kind'] === 'choice'
            ? rows('SELECT value, label, needs_payer FROM amenity_options WHERE amenity_id = ?', [$d['id']])
            : [];
    }
    return $defs;
}

/** Validates one answer; returns [answer, choice_value, payer, note]. No defaults: everything must be answered. */
function normalize_amenity_item(array $def, array $item): array
{
    $note = isset($item['note']) ? trim((string)$item['note']) : '';
    $answer = $choice = $payer = null;
    $ctx = ['amenity_id' => (int)$def['id']];

    if ($def['kind'] === 'yes_no') {
        $answer = $item['answer'] ?? null;
        if (!in_array($answer, ['yes', 'no'], true)) {
            fail(422, "Answer yes or no for: {$def['label']}", 'validation', $ctx);
        }
        if ($answer === 'no') {
            $note = '';
        }
    } else {
        $choice = $item['choice_value'] ?? null;
        $opt = null;
        foreach ($def['options'] as $o) {
            if ($o['value'] === $choice) {
                $opt = $o;
            }
        }
        if (!$opt) {
            fail(422, "Pick an option for: {$def['label']}", 'validation', $ctx);
        }
        if ((int)$opt['needs_payer']) {
            $payer = $item['payer'] ?? null;
            if (!in_array($payer, ['tenant', 'owner', 'split', 'included_in_rent'], true)) {
                fail(422, "Say who pays for: {$def['label']}", 'validation', $ctx);
            }
        }
    }
    if ($note !== '') {
        if (word_count($note) > 10) {
            fail(422, "Notes are limited to 10 words ({$def['label']}).", 'note_too_long', $ctx);
        }
        reject_contact_info($note, 'note');
    }
    return [$answer, $choice, $payer, $note !== '' ? $note : null];
}

function save_amenity_answers(string $scope, int $target_id, array $items): void
{
    $table = $scope === 'building' ? 'building_amenity_answers' : 'room_amenity_answers';
    $col   = $scope === 'building' ? 'building_id' : 'room_id';
    $defs  = [];
    foreach (amenity_defs($scope) as $d) {
        $defs[(int)$d['id']] = $d;
    }
    foreach ($items as $item) {
        $aid = (int)($item['amenity_id'] ?? 0);
        if (!isset($defs[$aid])) {
            fail(422, 'Unknown amenity.', 'validation');
        }
        [$answer, $choice, $payer, $note] = normalize_amenity_item($defs[$aid], $item);
        q(
            "INSERT INTO $table ($col, amenity_id, answer, choice_value, payer, note) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), choice_value = VALUES(choice_value), payer = VALUES(payer), note = VALUES(note)",
            [$target_id, $aid, $answer, $choice, $payer, $note]
        );
    }
}

function save_custom_amenities(?int $building_id, ?int $room_id, array $items): void
{
    if (count($items) > 5) {
        fail(422, 'You can add at most 5 custom amenities.', 'validation');
    }
    if ($building_id) {
        q('DELETE FROM custom_amenities WHERE building_id = ?', [$building_id]);
    } else {
        q('DELETE FROM custom_amenities WHERE room_id = ?', [$room_id]);
    }
    foreach ($items as $it) {
        $label = trim((string)($it['label'] ?? ''));
        $answer = $it['answer'] ?? 'yes';
        $note = trim((string)($it['note'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60 || !in_array($answer, ['yes', 'no'], true)) {
            fail(422, 'Each custom amenity needs a short name and yes/no.', 'validation');
        }
        reject_contact_info($label, 'label');
        if ($note !== '') {
            if (word_count($note) > 10) {
                fail(422, 'Custom amenity notes are limited to 10 words.', 'note_too_long');
            }
            reject_contact_info($note, 'note');
        }
        q(
            'INSERT INTO custom_amenities (building_id, room_id, label, answer, note) VALUES (?,?,?,?,?)',
            [$building_id, $room_id, $label, $answer, $answer === 'yes' && $note !== '' ? $note : null]
        );
    }
}

/** Labels of amenities not yet fully answered. */
function amenities_missing(string $scope, int $target_id): array
{
    $table = $scope === 'building' ? 'building_amenity_answers' : 'room_amenity_answers';
    $col   = $scope === 'building' ? 'building_id' : 'room_id';
    $answers = [];
    foreach (rows("SELECT * FROM $table WHERE $col = ?", [$target_id]) as $a) {
        $answers[(int)$a['amenity_id']] = $a;
    }
    $missing = [];
    foreach (amenity_defs($scope) as $d) {
        $a = $answers[(int)$d['id']] ?? null;
        $done = false;
        if ($a) {
            if ($d['kind'] === 'yes_no') {
                $done = $a['answer'] !== null;
            } else {
                $opt = null;
                foreach ($d['options'] as $o) {
                    if ($o['value'] === $a['choice_value']) {
                        $opt = $o;
                    }
                }
                $done = $opt && (!(int)$opt['needs_payer'] || $a['payer'] !== null);
            }
        }
        if (!$done) {
            $missing[] = $d['label'];
        }
    }
    return $missing;
}

function amenity_answers_view(string $scope, int $target_id): array
{
    $table = $scope === 'building' ? 'building_amenity_answers' : 'room_amenity_answers';
    $col   = $scope === 'building' ? 'building_id' : 'room_id';
    return rows(
        "SELECT d.id AS amenity_id, d.code, d.label, d.kind, a.answer, a.choice_value, a.payer, a.note,
                (SELECT o.label FROM amenity_options o WHERE o.amenity_id = d.id AND o.value = a.choice_value) AS choice_label
         FROM $table a JOIN amenity_definitions d ON d.id = a.amenity_id
         WHERE a.$col = ? ORDER BY d.sort_order",
        [$target_id]
    );
}

/* ---------- agreements ---------- */

function match_agreement(array $building, array $room): ?int
{
    $id = null;
    switch ($building['agreement_scope']) {
        case 'general':
            $id = val("SELECT id FROM agreements WHERE building_id = ? AND scope = 'general' AND is_current = 1 ORDER BY id DESC LIMIT 1", [$building['id']]);
            break;
        case 'by_floor':
            if ($room['floor_number'] !== null) {
                $id = val("SELECT id FROM agreements WHERE building_id = ? AND scope = 'floor' AND floor_number = ? AND is_current = 1 ORDER BY id DESC LIMIT 1", [$building['id'], $room['floor_number']]);
            }
            break;
        case 'by_room_type':
            if ($room['room_type_id'] !== null) {
                $id = val("SELECT id FROM agreements WHERE building_id = ? AND scope = 'room_type' AND room_type_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1", [$building['id'], $room['room_type_id']]);
            }
            break;
    }
    return $id ? (int)$id : null;
}

/* ---------- completeness (the server refuses to post anything incomplete) ---------- */

function building_missing(int $building_id): array
{
    $b = must_find(row('SELECT * FROM buildings WHERE id = ?', [$building_id]), 'Building');
    $m = [];

    if (!(int)val('SELECT COUNT(*) FROM owner_fee_acknowledgements WHERE owner_id = ?', [$b['owner_id']])) {
        $m[] = 'Acknowledge the 5% fee notice';
    }
    if (!$b['area_id'] && !$b['area_other']) {
        $m[] = 'Area / neighbourhood';
    }
    if (!$b['termination_policy_id']) {
        $m[] = 'Termination policy';
    }
    foreach (amenities_missing('building', $building_id) as $label) {
        $m[] = "Amenity: $label";
    }

    if (!$b['agreement_scope']) {
        $m[] = 'Tenancy agreement type (general or specified)';
    } elseif ($b['agreement_scope'] === 'general') {
        if (!(int)val("SELECT COUNT(*) FROM agreements WHERE building_id = ? AND scope = 'general' AND is_current = 1", [$building_id])) {
            $m[] = 'Upload the tenancy agreement PDF';
        }
    } elseif ($b['agreement_scope'] === 'by_floor') {
        if (!$b['floors_count']) {
            $m[] = 'Number of floors';
        } else {
            $have = (int)val("SELECT COUNT(DISTINCT floor_number) FROM agreements WHERE building_id = ? AND scope = 'floor' AND is_current = 1", [$building_id]);
            if ($have < (int)$b['floors_count']) {
                $m[] = 'Tenancy agreement for every floor (' . $have . ' of ' . $b['floors_count'] . ' uploaded)';
            }
        }
    } else {
        $types = (int)val('SELECT COUNT(*) FROM room_types WHERE building_id = ?', [$building_id]);
        $have  = (int)val("SELECT COUNT(DISTINCT room_type_id) FROM agreements WHERE building_id = ? AND scope = 'room_type' AND is_current = 1", [$building_id]);
        if ($types === 0) {
            $m[] = 'Add your room types';
        } elseif ($have < $types) {
            $m[] = 'Tenancy agreement for every room type (' . $have . ' of ' . $types . ' uploaded)';
        }
    }

    $p = row('SELECT * FROM owner_payout_details WHERE owner_id = ?', [$b['owner_id']]);
    $owner = row('SELECT phone_verified_at FROM users WHERE id = ?', [$b['owner_id']]);
    if (!$owner || !$owner['phone_verified_at']) {
        $m[] = 'Verify your phone number';
    }
    if (!$p || (!$p['momo_number'] && !$p['account_number'])) {
        $m[] = 'Add payout details (MoMo and/or bank)';
    }
    return $m;
}

function room_missing(int $room_id): array
{
    $r = must_find(row('SELECT * FROM rooms WHERE id = ?', [$room_id]), 'Room');
    $b = must_find(row('SELECT * FROM buildings WHERE id = ?', [$r['building_id']]), 'Building');
    $m = [];
    if ($r['monthly_rent'] === null || (float)$r['monthly_rent'] <= 0) {
        $m[] = 'Monthly rent';
    }
    if (!(int)val('SELECT COUNT(*) FROM room_rent_options WHERE room_id = ?', [$room_id])) {
        $m[] = 'Rent durations offered (6 months, 1 year, 2 years)';
    }
    foreach (amenities_missing('room', $room_id) as $label) {
        $m[] = "Amenity: $label";
    }
    if (!$b['agreement_scope']) {
        $m[] = 'Choose the building\'s tenancy agreement type first';
    } elseif ($b['agreement_scope'] === 'by_floor' && $r['floor_number'] === null) {
        $m[] = 'Floor number';
    } elseif ($b['agreement_scope'] === 'by_room_type' && $r['room_type_id'] === null) {
        $m[] = 'Room type';
    }
    if ($b['agreement_scope'] && !match_agreement($b, $r)) {
        $m[] = 'A matching tenancy agreement PDF for this room';
    }
    return $m;
}

/* ---------- chat ---------- */

function chat_create(string $type, int $building_id, ?int $room_id, ?int $viewing_id, ?int $process_id, array $members): int
{
    q('INSERT INTO chats (type, building_id, room_id, viewing_id, process_id) VALUES (?,?,?,?,?)', [$type, $building_id, $room_id, $viewing_id, $process_id]);
    $id = insert_id();
    foreach (array_unique($members) as $m) {
        q('INSERT IGNORE INTO chat_members (chat_id, user_id) VALUES (?,?)', [$id, $m]);
    }
    return $id;
}

function chat_system(int $chat_id, string $text): void
{
    q("INSERT INTO messages (chat_id, sender_id, type, body) VALUES (?, NULL, 'system', ?)", [$chat_id, $text]);
}

/** $delete = true schedules deletion one week later (cron removes it, unless a report is holding it). */
function chat_close(int $chat_id, string $status = 'closed', bool $delete = true): void
{
    q(
        'UPDATE chats SET status = ?, closed_at = COALESCE(closed_at, ?), delete_after = ? WHERE id = ?',
        [$status, now(), $delete ? plus('+' . (int)setting('chat_delete_days', '7') . ' days') : null, $chat_id]
    );
}

function chat_member_ids(int $chat_id): array
{
    return array_map('intval', array_column(rows('SELECT user_id FROM chat_members WHERE chat_id = ?', [$chat_id]), 'user_id'));
}

/* ---------- money ---------- */

/** Shows how the 5% is applied. Third-party payments carry no app fee. */
function fee_breakdown(float $rent_total, string $method): array
{
    if ($method !== 'app') {
        return ['amount' => money($rent_total), 'fee_percent' => 0.0, 'fee_amount' => 0.0, 'owner_amount' => money($rent_total), 'fee_payer' => null];
    }
    $pct   = (float)setting('platform_fee_percent', '5');
    $payer = setting('fee_payer', 'owner');
    $fee   = money($rent_total * $pct / 100);
    if ($payer === 'tenant') {
        return ['amount' => money($rent_total + $fee), 'fee_percent' => $pct, 'fee_amount' => $fee, 'owner_amount' => money($rent_total), 'fee_payer' => 'tenant'];
    }
    return ['amount' => money($rent_total), 'fee_percent' => $pct, 'fee_amount' => $fee, 'owner_amount' => money($rent_total - $fee), 'fee_payer' => 'owner'];
}

/* ---------- ratings ---------- */

function rating_summary(string $type, int $id): array
{
    $out = ['user' => ['average' => null, 'count' => 0], 'agent' => ['average' => null, 'count' => 0]];
    foreach (rows('SELECT reviewer_role, average_rating, review_count FROM v_ratings WHERE target_type = ? AND target_id = ?', [$type, $id]) as $r) {
        $out[$r['reviewer_role']] = ['average' => (float)$r['average_rating'], 'count' => (int)$r['review_count']];
    }
    return $out;
}

/* ---------- misc ---------- */

function days_between(string $from_date, string $to_date): int
{
    return (int)((strtotime($to_date . ' UTC') - strtotime($from_date . ' UTC')) / 86400);
}

function add_months(string $date, int $months): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify("+$months months")->format('Y-m-d');
}

function building_title(int $building_id, ?int $room_id = null): string
{
    $b = (string)val('SELECT name FROM buildings WHERE id = ?', [$building_id]);
    if ($room_id) {
        return $b . ', ' . (string)val('SELECT label FROM rooms WHERE id = ?', [$room_id]);
    }
    return $b;
}
