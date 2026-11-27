<?php
declare(strict_types=1);
// Recycle bin: everything deleted in the office (tasks and research, RFQs, finance drafts / void documents, companies, contacts, vendors, prospects) is first copied here,
// so an administrator can bring it back from the Logs page ("Restore") or from the bin itself. Copies are kept 90 days.
//   GET  /api/recycle[?kind=&q=]        the bin (Root Admin / Super Admin)       POST /api/recycle/{id}/restore
require_once __DIR__ . '/migrate.php';

const BIN_DAYS = 90;
const BIN_KINDS = ['task' => 'Task', 'rfq' => 'RFQ', 'doc' => 'Finance document', 'company' => 'Company', 'contact' => 'Contact', 'vendor' => 'Vendor', 'signal' => 'Prospect'];
const BIN_ACTION = ['task_deleted' => 'task', 'rfq_deleted' => 'rfq', 'fin_doc_deleted' => 'doc', 'company_deleted' => 'company', 'contact_deleted' => 'contact', 'vendor_deleted' => 'vendor', 'prospect_deleted' => 'signal'];

function bin_ensure(): void {
    static $d = false; if ($d) return; $d = true;
    try { if (!one("SHOW TABLES LIKE 'recycle_bin'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Recycle bin schema: ' . $e->getMessage()); }
}
function bin_rows(string $table, string $col, int $id): array { try { return all("SELECT * FROM `$table` WHERE `$col`=?", [$id]); } catch (Throwable $e) { return []; } }
/** Keep a copy. $children: [[table, foreign column, rows]...]; $relinks: [[table, column, [ids]]...] (rows that pointed at this one and were detached). */
function bin_put(string $kind, array $row, string $title, array $children, array $relinks, ?int $uid): int {
    bin_ensure(); $payload = ['row' => $row, 'children' => $children, 'relinks' => $relinks];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); if ($json === false) $json = json_encode(['row' => $row, 'children' => [], 'relinks' => []], JSON_INVALID_UTF8_SUBSTITUTE);
    q('INSERT INTO recycle_bin (kind,ref_id,title,payload,deleted_by) VALUES (?,?,?,?,?)', [$kind, (int)($row['id'] ?? 0), mb_substr($title, 0, 300), $json, $uid]); return (int)db()->lastInsertId();
}
function bin_insert(string $table, array $row, bool $ignore): int {
    $cols = array_keys($row); $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($row)); return (int)db()->lastInsertId();
}
/** Put it back. Returns [ok, message, new id]. Foreign links that no longer exist are dropped rather than failing. */
function bin_restore(array $b, array $u): array {
    $pl = json_decode((string)$b['payload'], true); if (!is_array($pl) || empty($pl['row'])) return [false, 'This copy is damaged and cannot be restored', 0];
    $table = ['task' => 'tasks', 'rfq' => 'rfqs', 'doc' => 'fin_docs', 'company' => 'companies', 'contact' => 'contacts', 'vendor' => 'vendors', 'signal' => 'pr_signals'][$b['kind']] ?? ''; if ($table === '') return [false, 'Unknown kind', 0];
    $row = $pl['row']; $old = (int)($row['id'] ?? 0);
    // links to things that are gone are dropped
    foreach (['assignee_id' => 'users', 'creator_id' => 'users', 'created_by' => 'users', 'owner_id' => 'users', 'department_id' => 'departments', 'company_id' => 'companies', 'contact_id' => 'contacts', 'project_id' => 'projects', 'vendor_id' => 'vendors', 'task_id' => 'tasks', 'po_task_id' => 'tasks', 'quote_doc_id' => 'fin_docs', 'parent_id' => null, 'user_id' => 'users'] as $col => $ref) {
        if ($ref === null) { if (array_key_exists($col, $row)) $row[$col] = null; continue; }
        if (array_key_exists($col, $row) && $row[$col] !== null && !one("SELECT 1 FROM `$ref` WHERE id=?", [(int)$row[$col]])) $row[$col] = null; }
    if (array_key_exists('creator_id', $row) && $row['creator_id'] === null) $row['creator_id'] = (int)$u['id'];
    try { $new = 0; $taken = $old && one("SELECT 1 FROM `$table` WHERE id=?", [$old]);
        if ($taken) unset($row['id']);   // the number was reused meanwhile: it comes back with a new one
        try { $new = bin_insert($table, $row, false); } catch (PDOException $e) { if (!isset($row['id'])) throw $e; unset($row['id']); $new = bin_insert($table, $row, false); }
        $id = isset($row['id']) ? (int)$row['id'] : $new;
    } catch (Throwable $e) { error_log('Restore ' . $b['kind'] . ': ' . $e->getMessage()); return [false, 'It could not be restored: ' . mb_substr($e->getMessage(), 0, 140), 0]; }
    foreach ((array)($pl['children'] ?? []) as [$ct, $fk, $rows]) { foreach ((array)$rows as $cr) { if (!is_array($cr)) continue; if ($fk !== '') $cr[$fk] = $id; try { bin_insert((string)$ct, $cr, true); } catch (Throwable $e) { error_log('Restore child ' . $ct . ': ' . $e->getMessage()); try { unset($cr['id']); bin_insert((string)$ct, $cr, true); } catch (Throwable $e2) {} } } }
    foreach ((array)($pl['relinks'] ?? []) as [$rt, $rc, $ids]) { $ids = array_values(array_filter(array_map('intval', (array)$ids))); if (!$ids) continue; try { q("UPDATE `$rt` SET `$rc`=? WHERE `$rc` IS NULL AND id IN (" . implode(',', $ids) . ')', [$id]); } catch (Throwable $e) { error_log('Restore link ' . $rt . ': ' . $e->getMessage()); } }
    q('UPDATE recycle_bin SET restored_at=NOW(), restored_by=? WHERE id=?', [(int)$u['id'], (int)$b['id']]); log_act((int)$u['id'], 'restored', (BIN_KINDS[$b['kind']] ?? $b['kind']) . ': ' . $b['title']);
    return [true, 'Restored', $id];
}
function bin_purge(): void {
    static $d = false; if ($d) return; $d = true; bin_ensure();
    try { foreach (all('SELECT id,kind,payload FROM recycle_bin WHERE deleted_at < (NOW() - INTERVAL ' . BIN_DAYS . ' DAY) LIMIT 200') as $b) {
            if ($b['kind'] === 'rfq') { $pl = json_decode((string)$b['payload'], true) ?: []; foreach ((array)($pl['children'] ?? []) as [$ct, $fk, $rows]) if ($ct === 'rfq_files') foreach ((array)$rows as $f) { if (!empty($f['path'])) { $p = ((string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage')) . '/rfq/' . basename((string)$f['path']); if (is_file($p)) @unlink($p); } } }
            q('DELETE FROM recycle_bin WHERE id=?', [$b['id']]); } } catch (Throwable $e) { error_log('Bin purge: ' . $e->getMessage()); }
}

if (!isset($r) || $r !== 'recycle') return;
$u = need_user(); if (!is_root($u) && ($u['role'] ?? '') !== 'super_admin') fail('Only administrators can use the recycle bin', 403); bin_ensure(); bin_purge(); $s2 = $seg[1] ?? ''; $s3 = $seg[2] ?? '';
if ($method === 'GET' && $s2 === '') {
    $w = ['1=1']; $p = []; $kind = (string)($_GET['kind'] ?? ''); if (isset(BIN_KINDS[$kind])) { $w[] = 'b.kind=?'; $p[] = $kind; } if (empty($_GET['all'])) $w[] = 'b.restored_at IS NULL';
    if (($qq = trim((string)($_GET['q'] ?? ''))) !== '') { $w[] = '(b.title LIKE ? OR du.name LIKE ?)'; $lk = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($qq, 0, 60)) . '%'; array_push($p, $lk, $lk); }
    $rows = all('SELECT b.id,b.kind,b.ref_id,b.title,b.deleted_at,b.restored_at,du.name AS deleted_by,ru.name AS restored_by FROM recycle_bin b LEFT JOIN users du ON du.id=b.deleted_by LEFT JOIN users ru ON ru.id=b.restored_by WHERE ' . implode(' AND ', $w) . ' ORDER BY b.id DESC LIMIT 300', $p);
    out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'kind' => $x['kind'], 'kind_label' => BIN_KINDS[$x['kind']] ?? $x['kind'], 'title' => $x['title'], 'deleted_at' => $x['deleted_at'], 'deleted_by' => $x['deleted_by'], 'restored_at' => $x['restored_at'], 'restored_by' => $x['restored_by']], $rows), 'kinds' => BIN_KINDS, 'keep_days' => BIN_DAYS]);
}
if ($method === 'POST' && ctype_digit($s2) && $s3 === 'restore') {
    $b = one('SELECT * FROM recycle_bin WHERE id=?', [(int)$s2]); if (!$b) fail('Not found', 404); if ($b['restored_at']) fail('This was already restored', 409);
    [$ok, $msg, $newId] = bin_restore($b, $u); if (!$ok) fail($msg, 409); out(['ok' => true, 'id' => $newId, 'kind' => $b['kind']]);
}
fail('Bad request', 404);
