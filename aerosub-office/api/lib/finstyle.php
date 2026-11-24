<?php
declare(strict_types=1);
// Look of finance documents: font, size, weight, colour and spacing for any part of a document (heading, table header, rows, totals ...).
// Saved either for one document or for every document of that type. Only plain, checked values are stored, never free CSS.
//   GET    /api/finance/doc/{id}/styles              the saved looks for this type and for this document
//   PUT    /api/finance/doc/{id}/styles              {type_styles?: {...}, doc_styles?: {...}}   (Finance only)
//   DELETE /api/finance/doc/{id}/styles?scope=doc|type
const FS_FONTS = ['calibri', 'poppins', 'inter', 'arial', 'times', 'georgia', 'verdana', 'courier'];
function fs_hex($v): ?string { $v = trim((string)$v); return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? strtolower($v) : null; }
function fs_num($v, float $lo, float $hi): ?float { if (!is_numeric($v)) return null; $n = (float)$v; return $n >= $lo && $n <= $hi ? round($n, 2) : null; }
/** One element's settings, reduced to values that are known to be safe. */
function fs_clean_props($p): array {
    if (!is_array($p)) return []; $o = [];
    if (isset($p['fontFamily']) && in_array($p['fontFamily'], FS_FONTS, true)) $o['fontFamily'] = $p['fontFamily'];
    if (($n = fs_num($p['fontSize'] ?? null, 6, 60)) !== null) $o['fontSize'] = $n;
    if (isset($p['fontWeight']) && in_array((string)$p['fontWeight'], ['300', '400', '500', '600', '700', '800'], true)) $o['fontWeight'] = (string)$p['fontWeight'];
    if (isset($p['fontStyle']) && in_array($p['fontStyle'], ['normal', 'italic'], true)) $o['fontStyle'] = $p['fontStyle'];
    foreach (['color', 'background', 'borderColor'] as $k) if (isset($p[$k]) && ($h = fs_hex($p[$k])) !== null) $o[$k] = $h;
    if (isset($p['background']) && $p['background'] === 'none') $o['background'] = 'none';
    if (isset($p['textAlign']) && in_array($p['textAlign'], ['left', 'center', 'right', 'justify'], true)) $o['textAlign'] = $p['textAlign'];
    foreach (['lineHeight' => [0.8, 3], 'letterSpacing' => [-2, 10], 'paddingY' => [0, 60], 'paddingX' => [0, 60], 'marginTop' => [0, 80], 'marginBottom' => [0, 80], 'borderWidth' => [0, 6]] as $k => [$lo, $hi]) if (($n = fs_num($p[$k] ?? null, $lo, $hi)) !== null) $o[$k] = $n;
    return $o;
}
function fs_clean($raw): array {
    $out = []; if (!is_array($raw)) return $out;
    foreach (array_slice($raw, 0, 40, true) as $k => $p) { if (!is_string($k) || !preg_match('/^[a-z][A-Za-z0-9]{1,24}$/', $k)) continue; $c = fs_clean_props($p); if ($c) $out[$k] = $c; }
    return $out;
}
function fs_get(string $scope): array { try { $r = one('SELECT data FROM fin_styles WHERE scope=?', [$scope]); return $r ? (fs_clean(json_decode((string)$r['data'], true)) ?: []) : []; } catch (Throwable $e) { return []; } }
function fs_put(string $scope, array $data, int $uid): void {
    if (!$data) { q('DELETE FROM fin_styles WHERE scope=?', [$scope]); return; }
    q('INSERT INTO fin_styles (scope,data,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), updated_by=VALUES(updated_by), updated_at=NOW()', [$scope, json_encode($data, JSON_UNESCAPED_UNICODE), $uid]);
}
/** What the document page needs to paint a document: the look saved for its type, and the look saved for this one document. */
function fs_for_doc(array $d): array { $t = fs_get('type:' . $d['doc_type']); $o = fs_get('doc:' . (int)$d['id']); return ['type' => $t ?: new stdClass(), 'doc' => $o ?: new stdClass()]; }   // an empty look is {} (not [])
