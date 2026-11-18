<?php
declare(strict_types=1);
// Shared by the laptop side (agent_relay.php) and the phone side (agent_relay_owner.php). Requires core.php.
// An "envelope" is opaque text made on a device, both URL-safe base64:
//   v1.<nonce>.<ciphertext>              the first phone, before several browsers could share the mailbox (no tag)
//   v2.<tag>.<nonce>.<ciphertext>        every browser paired since; the tag (11 characters) says which browser it is for
// The server checks the shape and size and reads ONLY the tag, which it uses to hand each browser its own messages. It cannot read
// anything else, and the tag is a random label, not a name or a key.

const RELAY_MAX_ENVELOPE = 32000;
const RELAY_MAX_QUEUED = 300;      // per direction (and, for replies to browsers, per tag); a stuck laptop or browser cannot fill the database
const RELAY_BATCH = 50;
const RELAY_KEEP_DAYS = 7;         // nobody collected it for a week: drop it
const RELAY_KEEP_REMOVAL_DAYS = 90; // a removal notice waits as long as the chat history is kept (owner's choice, 2026-11-12)
const RELAY_V1 = '/^v1\.[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{16,}$/';
const RELAY_V2 = '/^v2\.([A-Za-z0-9_-]{11})\.[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{16,}$/';
const RELAY_TAG = '/^[A-Za-z0-9_-]{11}$/';

function relay_dir_ok(string $d): bool { return $d === 'to_laptop' || $d === 'to_phone'; }

/** A tag asked for in a request: null if none was given, a 400 if it is not an 11-character tag. */
function relay_tag_param(mixed $raw): ?string {
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match(RELAY_TAG, $raw)) fail('Bad request', 400);
    return $raw;
}

/** $keep is 'removal' only when the laptop sends a "this browser was removed" notice (the laptop's token is the only way to set it).
 *  Such a notice is kept RELAY_KEEP_REMOVAL_DAYS days, is stored once however often the laptop retries the same envelope, and is accepted even when
 *  that browser's box is full, because a browser that never collects is exactly the one that most needs to be told. */
function relay_put(string $direction, mixed $envelope, mixed $keep = null): int {
    if (!relay_dir_ok($direction)) fail('Bad request', 400);
    if (!is_string($envelope) || strlen($envelope) > RELAY_MAX_ENVELOPE) fail('That message is not valid', 422);
    $tag = null;
    if (preg_match(RELAY_V2, $envelope, $m)) $tag = $m[1];
    elseif (!preg_match(RELAY_V1, $envelope)) fail('That message is not valid', 422);
    if ($keep !== null && $keep !== 'removal') fail('That message is not valid', 422);
    if ($keep === 'removal') {
        // The first phone has no tag (v1), and removing it is the likeliest real case (a lost phone), so an untagged notice is allowed too.
        if ($direction !== 'to_phone') fail('That message is not valid', 422);
        // Only an IDENTICAL envelope counts as a retry. A different one is kept too: otherwise whoever got a bogus row in first could stop the real notice.
        $already = one('SELECT id FROM agent_relay WHERE direction = ? AND tag <=> ? AND lasting = 1 AND envelope = ? ORDER BY id LIMIT 1', [$direction, $tag, $envelope]);
        if ($already) return (int)$already['id'];
        q('INSERT INTO agent_relay (direction, tag, lasting, envelope) VALUES (?,?,1,?)', [$direction, $tag, $envelope]);
        return (int)db()->lastInsertId();
    }
    // Replies for browsers are limited per tag, so one browser that has not collected cannot stop the others from getting theirs.
    $waiting = $direction === 'to_phone'
        ? one('SELECT COUNT(*) c FROM agent_relay WHERE direction = ? AND tag <=> ?', [$direction, $tag])
        : one('SELECT COUNT(*) c FROM agent_relay WHERE direction = ?', [$direction]);
    if ((int)$waiting['c'] >= RELAY_MAX_QUEUED) fail('Too many messages are waiting. Try again once the other side has caught up.', 429);
    q('INSERT INTO agent_relay (direction, tag, envelope) VALUES (?,?,?)', [$direction, $tag, $envelope]);
    return (int)db()->lastInsertId();
}

/** The waiting messages for one direction. They stay until acknowledged, so a lost response loses nothing.
 *  The laptop collects everything sent to it ($all). A browser collects only its own tag, or, with no tag, only the older untagged messages. */
function relay_collect(string $direction, ?string $tag = null, bool $all = false): array {
    if (!relay_dir_ok($direction)) fail('Bad request', 400);
    $sql = 'SELECT id, envelope FROM agent_relay WHERE direction = ?' . ($all ? '' : ' AND tag <=> ?') . ' ORDER BY id LIMIT ' . RELAY_BATCH;
    return array_map(fn($r) => ['id' => (int)$r['id'], 'envelope' => $r['envelope']], all($sql, $all ? [$direction] : [$direction, $tag]));
}

/** Deletes messages the receiver has stored. Only ids of the right direction, and (for a browser) of its own tag, can be removed:
 *  a page that knows nothing of tags can only ever delete the older untagged messages. */
function relay_delete(string $direction, mixed $ids, ?string $tag = null, bool $all = false): int {
    if (!relay_dir_ok($direction) || !is_array($ids)) fail('Bad request', 400);
    $ids = array_values(array_unique(array_map('intval', array_slice($ids, 0, 200))));
    $n = 0;
    foreach ($ids as $i) {
        if ($i <= 0) continue;
        $n += $all
            ? q('DELETE FROM agent_relay WHERE id = ? AND direction = ?', [$i, $direction])->rowCount()
            : q('DELETE FROM agent_relay WHERE id = ? AND direction = ? AND tag <=> ?', [$i, $direction, $tag])->rowCount();
    }
    return $n;
}

function relay_purge_old(): int {
    return q('DELETE FROM agent_relay WHERE (lasting = 0 AND created_at < (NOW() - INTERVAL ' . RELAY_KEEP_DAYS . ' DAY))'
        . ' OR (lasting = 1 AND created_at < (NOW() - INTERVAL ' . RELAY_KEEP_REMOVAL_DAYS . ' DAY))')->rowCount();
}
