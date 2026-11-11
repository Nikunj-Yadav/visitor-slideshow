<?php
/** Delete a visitor and all their photos (e.g. a removal request). Body: {"id":1} */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_post();
$id = (int) (read_json()['id'] ?? 0);
$res = with_transaction(function (PDO $pdo) use ($id) {
    $st = $pdo->prepare('SELECT name FROM visitors WHERE id = ?');
    $st->execute([$id]);
    $name = $st->fetchColumn();
    if ($name === false) { return null; }
    $st = $pdo->prepare('SELECT filename FROM photos WHERE visitor_id = ?');
    $st->execute([$id]);
    $files = $st->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM visitors WHERE id = ?')->execute([$id]);
    log_activity($pdo, 'Admin deleted visitor ' . $name . ' and ' . count($files) . ' photo' . (count($files) === 1 ? '' : 's'));
    bump_version($pdo);
    return $files;
});
if ($res === null) { fail(404, 'Visitor not found.'); }
remove_files($res);
json_out(['ok' => true]);
