<?php
declare(strict_types=1);
// Read helpers for uploaded documents and their checked claims. Used by the team routes and the Agent site.
// Only functions here: no routing. Requires core.php and agent_token.php.

/** Documents for a list screen, newest first, with counts a person can scan (no ids shown to users, only names).
 *  $onlyUploader limits the list to what that user uploaded: staff see their own, the owner area and managers pass null and see all. */
function agent_doc_list(?int $companyId = null, int $limit = 200, ?int $onlyUploader = null): array {
    $where = []; $args = [];
    if ($companyId) { $where[] = 'd.company_id = ?'; $args[] = $companyId; }
    if ($onlyUploader !== null) { $where[] = 'd.uploaded_by = ?'; $args[] = $onlyUploader; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return all("SELECT d.id, d.original_name, d.status, d.status_note, d.created_at, d.checked_at, d.company_id,
                       c.name AS company_name, t.title AS task_title, u.name AS uploaded_by_name,
                       (SELECT COUNT(*) FROM document_claims x WHERE x.document_id = d.id) AS claim_total,
                       (SELECT COUNT(*) FROM document_claims x WHERE x.document_id = d.id AND x.label = 'supported') AS claim_supported,
                       (SELECT COUNT(*) FROM document_claims x WHERE x.document_id = d.id AND x.label = 'cannot_check') AS claim_cannot_check
                FROM documents d LEFT JOIN companies c ON c.id = d.company_id LEFT JOIN tasks t ON t.id = d.task_id LEFT JOIN users u ON u.id = d.uploaded_by
                $w ORDER BY d.id DESC LIMIT $limit", $args);
}

/** One document with its scorecard, summary, action points, conflicts and claims. Null if it does not exist. */
function agent_doc_detail(int $id): ?array {
    $d = one("SELECT d.id, d.original_name, d.mime, d.size_bytes, d.status, d.status_note, d.created_at, d.checked_at, d.company_id,
                     c.name AS company_name, t.title AS task_title, u.name AS uploaded_by_name
              FROM documents d LEFT JOIN companies c ON c.id = d.company_id LEFT JOIN tasks t ON t.id = d.task_id LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.id = ?", [$id]);
    if (!$d) return null;
    $r = one('SELECT scorecard, summary, action_points, conflicts FROM document_reviews WHERE document_id = ?', [$id]);
    $d['review'] = $r ? ['scorecard' => json_decode($r['scorecard'], true), 'summary' => $r['summary'],
                         'action_points' => json_decode((string)$r['action_points'], true) ?: [], 'conflicts' => json_decode((string)$r['conflicts'], true) ?: []] : null;
    $d['claims'] = all('SELECT position, claim_text, claim_kind, label, reason, source_url, source_tier, quote, check_priority FROM document_claims WHERE document_id = ? ORDER BY position', [$id]);
    $hb = one('SELECT last_seen_at FROM agent_heartbeat WHERE id = 1');
    $d['agent_last_seen_at'] = $hb['last_seen_at'] ?? null;
    return $d;
}
