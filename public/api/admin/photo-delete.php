<?php
/** Delete one photo permanently. Body: {"id":1} */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_post();
$id = (int) (read_json()['id'] ?? 0);
$filename = with_transaction(function (PDO $pdo) use ($id) {
    $st = $pdo->prepare('SELECT filename FROM photos WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetchColumn();
    if ($f === false) { return null; }
    $pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
    log_activity($pdo, 'Admin deleted a photo');
    bump_version($pdo);
    return $f;
});
if ($filename === null) { fail(404, 'Photo not found.'); }
remove_files([$filename]);
json_out(['ok' => true]);
