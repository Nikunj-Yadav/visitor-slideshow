<?php
/** Approve or hide one photo. Body: {"id":1,"status":"hidden"} */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_post();
$body = read_json();
$status = $body['status'] ?? '';
if (!in_array($status, ['approved', 'hidden'], true)) { fail(400, 'Invalid status.'); }
$id = (int) ($body['id'] ?? 0);
$found = with_transaction(function (PDO $pdo) use ($id, $status) {
    $st = $pdo->prepare('SELECT status FROM photos WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    if ($st->fetchColumn() === false) { return false; }
    $pdo->prepare('UPDATE photos SET status = ? WHERE id = ?')->execute([$status, $id]);
    log_activity($pdo, 'Admin ' . ($status === 'hidden' ? 'hid' : 'approved') . ' a photo');
    bump_version($pdo);
    return true;
});
if (!$found) { fail(404, 'Photo not found.'); }
json_out(['ok' => true]);
