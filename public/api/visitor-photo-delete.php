<?php
/** A visitor removes ONE of their own photos. */
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('POST');
rate_limit('lookup', (int) cfg('rate_lookup'));
$pdo = db();
$body = read_json();
$v = find_visitor_by_code($pdo, $body['code'] ?? '');
if (!$v) { fail(404, 'We do not recognise that visitor code.'); }
$photoId = (int) ($body['photoId'] ?? 0);
$filename = with_transaction(function (PDO $pdo) use ($v, $photoId) {
    $st = $pdo->prepare('SELECT filename FROM photos WHERE id = ? AND visitor_id = ?');
    $st->execute([$photoId, $v['id']]);
    $f = $st->fetchColumn();
    if ($f === false) { return null; }
    $pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$photoId]);
    log_activity($pdo, $v['name'] . ' removed a photo');
    bump_version($pdo);
    return $f;
});
if ($filename === null) { fail(404, 'That photo was not found.'); }
remove_files([$filename]);
json_out(['ok' => true]);
