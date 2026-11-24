<?php
declare(strict_types=1);

/* =====================================================================
   REVIEWS AND RATINGS
   Users review agents, buildings, rooms (after a completed viewing).
   Agents review only buildings and rooms they are approved for.
   Owners cannot review. One rating per reviewer per target, editable ("edited" tag).
   Revoked agents' reviews are locked. Re-approved agents may add comments but not a new rating.
   Separate User and Agent scores per building and room.
   ===================================================================== */

function review_target(string $type, int $id): array
{
    $t = match ($type) {
        'agent'    => row("SELECT id, id AS building_id FROM users WHERE id = ? AND role = 'agent'", [$id]),
        'building' => row('SELECT id, id AS building_id FROM buildings WHERE id = ?', [$id]),
        'room'     => row('SELECT id, building_id FROM rooms WHERE id = ?', [$id]),
        default    => null,
    };
    return must_find($t, 'Review target');
}

/** The server decides who may review what; the app only reflects it. */
function can_review(array $u, string $type, int $id): ?string
{
    $t = review_target($type, $id);
    if ($u['role'] === 'owner' || $u['role'] === 'admin') {
        return 'Owners and admins cannot leave reviews.';
    }
    if ($u['role'] === 'agent') {
        if ($type === 'agent') {
            return 'Agents cannot review other agents.';
        }
        return approved_agent((int)$u['id'], (int)$t['building_id']) ? null : 'You can only review buildings you are approved for.';
    }
    // normal users (tenants too): need a completed viewing
    if ($type === 'agent') {
        $ok = val("SELECT 1 FROM viewings WHERE user_id = ? AND agent_id = ? AND status = 'completed' LIMIT 1", [$u['id'], $id]);
    } elseif ($type === 'building') {
        $ok = val("SELECT 1 FROM viewings WHERE user_id = ? AND building_id = ? AND status = 'completed' LIMIT 1", [$u['id'], $id]);
    } else {
        $ok = val("SELECT 1 FROM viewings WHERE user_id = ? AND building_id = ? AND status = 'completed' AND (room_id = ? OR room_id IS NULL) LIMIT 1", [$u['id'], $t['building_id'], $id])
            ?: val("SELECT 1 FROM tenancies WHERE user_id = ? AND room_id = ?", [$u['id'], $id]);
    }
    return $ok ? null : 'You can review after a completed viewing.';
}

function review_view(array $r, ?array $viewer = null): array
{
    $author = user_card_by_id((int)$r['reviewer_id']);
    return [
        'id' => (int)$r['id'], 'rating' => (int)$r['rating'], 'comment' => $r['comment'], 'edited' => (bool)$r['is_edited'], 'edited_at' => $r['edited_at'],
        'locked' => (bool)$r['is_locked'], 'created_at' => $r['created_at'],
        'reviewer_role' => $r['reviewer_role'],                         // drives the "Agent" label
        'author' => $author ?: ['name' => 'Former user', 'photo_url' => null],
        'mine' => $viewer && (int)$viewer['id'] === (int)$r['reviewer_id'],
        'comments' => array_map(fn($c) => ['id' => (int)$c['id'], 'body' => $c['body'], 'edited' => (bool)$c['is_edited'], 'after_reapproval' => (bool)$c['after_reapproval'], 'created_at' => $c['created_at'], 'author' => user_card_by_id((int)$c['author_id'])],
            rows('SELECT * FROM review_comments WHERE review_id = ? ORDER BY id', [$r['id']])),
    ];
}

route('GET', '/reviews', function () {
    $u = require_user();
    $type = in_enum('target_type', ['agent', 'building', 'room']);
    $id = in_int('target_id', true);
    review_target($type, $id);
    $role = in_enum('reviewer_role', ['user', 'agent'], false);
    $sql = 'SELECT * FROM reviews WHERE target_type = ? AND target_id = ?' . ($role ? ' AND reviewer_role = ?' : '') . ' ORDER BY id DESC LIMIT 100';
    $list = rows($sql, $role ? [$type, $id, $role] : [$type, $id]);
    json_out([
        'summary' => rating_summary($type, $id),
        'reviews' => array_map(fn($r) => review_view($r, $u), $list),
        'can_review' => can_review($u, $type, $id) === null,
        'cannot_reason' => can_review($u, $type, $id),
        'my_review_id' => ($m = val('SELECT id FROM reviews WHERE reviewer_id = ? AND target_type = ? AND target_id = ?', [$u['id'], $type, $id])) ? (int)$m : null,
    ]);
});

route('POST', '/reviews', function () {
    $u = require_role('user', 'agent');
    $type = in_enum('target_type', ['agent', 'building', 'room']);
    $id = in_int('target_id', true);
    $why = can_review($u, $type, $id);
    if ($why) {
        fail(403, $why, 'cannot_review');
    }
    $rating = in_int('rating', true);
    if ($rating < 1 || $rating > 5) {
        fail(422, 'Rating is 1 to 5.', 'validation', ['field' => 'rating']);
    }
    $comment = in_str('comment', 1000);
    reject_contact_info($comment, 'comment');
    $existing = row('SELECT * FROM reviews WHERE reviewer_id = ? AND target_type = ? AND target_id = ?', [$u['id'], $type, $id]);
    if ($existing) {
        fail(409, $existing['is_locked'] ? 'Your earlier rating is locked and cannot be changed. You can add a comment.' : 'You already reviewed this. Edit your review instead.', 'duplicate');
    }
    $vid = $u['role'] === 'user' ? val("SELECT id FROM viewings WHERE user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$u['id']]) : null;
    q('INSERT INTO reviews (reviewer_id, reviewer_role, target_type, target_id, rating, comment, viewing_id) VALUES (?,?,?,?,?,?,?)', [$u['id'], $u['role'], $type, $id, $rating, $comment, $vid]);
    json_out(['id' => insert_id(), 'summary' => rating_summary($type, $id)], 201);
});

route('PUT', '/reviews/{id}', function ($p) {
    $u = require_role('user', 'agent');
    $r = must_find(row('SELECT * FROM reviews WHERE id = ? AND reviewer_id = ?', [pid($p), $u['id']]), 'Review');
    if ($r['is_locked']) {
        fail(403, 'This review is locked and cannot be edited.', 'locked');
    }
    $rating = in_int('rating', true);
    if ($rating < 1 || $rating > 5) {
        fail(422, 'Rating is 1 to 5.', 'validation', ['field' => 'rating']);
    }
    $comment = in_str('comment', 1000);
    reject_contact_info($comment, 'comment');
    if ($rating !== (int)$r['rating'] || $comment !== $r['comment']) {
        q('UPDATE reviews SET rating = ?, comment = ?, is_edited = 1, edited_at = ? WHERE id = ?', [$rating, $comment, now(), $r['id']]);
    }
    json_out(['summary' => rating_summary($r['target_type'], (int)$r['target_id'])]);
});

route('DELETE', '/reviews/{id}', function ($p) {
    $u = require_role('user', 'agent');
    $r = must_find(row('SELECT * FROM reviews WHERE id = ? AND reviewer_id = ?', [pid($p), $u['id']]), 'Review');
    if ($r['is_locked']) {
        fail(403, 'This review is locked and cannot be deleted.', 'locked');
    }
    q('DELETE FROM reviews WHERE id = ?', [$r['id']]);
    json_out(['summary' => rating_summary($r['target_type'], (int)$r['target_id'])]);
});

/**
 * Extra comments: a reapproved agent may add comments (never a new rating) under their locked review,
 * only while currently approved. Normal reviewers can also add follow-up comments.
 */
route('POST', '/reviews/{id}/comments', function ($p) {
    $u = require_role('user', 'agent');
    $r = must_find(row('SELECT * FROM reviews WHERE id = ?', [pid($p)]), 'Review');
    $body = in_str('body', 1000, true);
    reject_contact_info($body, 'body');
    if ((int)$r['reviewer_id'] !== (int)$u['id']) {
        fail(403, 'You can only comment on your own review.', 'forbidden_role');
    }
    $after = 0;
    if ($u['role'] === 'agent') {
        $bid = $r['target_type'] === 'room' ? (int)val('SELECT building_id FROM rooms WHERE id = ?', [$r['target_id']]) : (int)$r['target_id'];
        if (!approved_agent((int)$u['id'], $bid)) {
            fail(403, 'You can comment only while you are approved for this building.', 'not_approved');
        }
        $after = $r['is_locked'] ? 1 : 0;
    }
    q('INSERT INTO review_comments (review_id, author_id, body, after_reapproval) VALUES (?,?,?,?)', [$r['id'], $u['id'], $body, $after]);
    json_out(['id' => insert_id()], 201);
});

route('PUT', '/review-comments/{id}', function ($p) {
    $u = require_role('user', 'agent');
    $c = must_find(row('SELECT rc.*, r.is_locked FROM review_comments rc JOIN reviews r ON r.id = rc.review_id WHERE rc.id = ? AND rc.author_id = ?', [pid($p), $u['id']]), 'Comment');
    if (!(int)$c['after_reapproval'] && (int)$c['is_locked']) {
        fail(403, 'This comment is locked.', 'locked');
    }
    $body = in_str('body', 1000, true);
    reject_contact_info($body, 'body');
    q('UPDATE review_comments SET body = ?, is_edited = 1, edited_at = ? WHERE id = ?', [$body, now(), $c['id']]);
    json_out(['ok' => true]);
});

route('DELETE', '/review-comments/{id}', function ($p) {
    $u = require_role('user', 'agent');
    $c = must_find(row('SELECT rc.*, r.is_locked FROM review_comments rc JOIN reviews r ON r.id = rc.review_id WHERE rc.id = ? AND rc.author_id = ?', [pid($p), $u['id']]), 'Comment');
    if ($c['is_locked'] && !(int)$c['after_reapproval']) {
        fail(403, 'This comment is locked.', 'locked');
    }
    q('DELETE FROM review_comments WHERE id = ?', [$c['id']]);
    json_out(['ok' => true]);
});
