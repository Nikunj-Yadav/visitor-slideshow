<?php
/** Returning visitor: find them by code and list their photos. */
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('POST');
rate_limit('lookup', (int) cfg('rate_lookup'));
$pdo = db();
$body = read_json();
$v = find_visitor_by_code($pdo, $body['code'] ?? '');
if (!$v) { fail(404, 'We do not recognise that visitor code.'); }
$st = $pdo->prepare('SELECT id, filename, status, created_at FROM photos WHERE visitor_id = ? ORDER BY id DESC');
$st->execute([$v['id']]);
$photos = [];
foreach ($st->fetchAll() as $p) {
    $photos[] = ['id' => (int) $p['id'], 'url' => photo_url($p['filename']), 'status' => $p['status'], 'createdAt' => iso($p['created_at'])];
}
json_out(['name' => $v['name'], 'consent' => $v['consent_at'] !== null, 'maxPhotosPerVisitor' => (int) cfg('max_photos_per_visitor'), 'photos' => $photos]);
