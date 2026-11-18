<?php
// Public image host for marketing emails: /img.php?i=ID&k=KEY  (the key stops people guessing other images)
declare(strict_types=1);
require __DIR__ . '/api/lib/core.php';
$id = (int)($_GET['i'] ?? 0); $k = (string)($_GET['k'] ?? '');
$r = $id ? one('SELECT mime,data,k FROM camp_images WHERE id=?', [$id]) : null;
if (!$r || !hash_equals((string)$r['k'], $k)) { http_response_code(404); exit; }
header('Content-Type: ' . $r['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=31536000, immutable'); header('Content-Length: ' . strlen($r['data'])); echo $r['data'];
