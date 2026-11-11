<?php
/** Withdraw consent: delete every photo AND the visitor record. Nothing is kept. */
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('POST');
rate_limit('lookup', (int) cfg('rate_lookup'));
$pdo = db();
$body = read_json();
$v = find_visitor_by_code($pdo, $body['code'] ?? '');
if (!$v) { fail(404, 'We do not recognise that visitor code.'); }
$names = with_transaction(function (PDO $pdo) use ($v) {
    $st = $pdo->prepare('SELECT filename FROM photos WHERE visitor_id = ?');
    $st->execute([$v['id']]);
    $list = $st->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM visitors WHERE id = ?')->execute([$v['id']]);   // photo rows go too (ON DELETE CASCADE)
    log_activity($pdo, 'A visitor withdrew consent and removed ' . count($list) . ' photo' . (count($list) === 1 ? '' : 's'));
    bump_version($pdo);
    return $list;
});
remove_files($names);
json_out(['ok' => true, 'removed' => count($names)]);
