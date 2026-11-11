<?php
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_get();
$pdo = db();
$one = function (string $sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };
$recent = [];
foreach ($pdo->query('SELECT p.id, p.filename, p.status, p.created_at, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id ORDER BY p.id DESC LIMIT 8')->fetchAll() as $r) {
    $recent[] = ['id' => (int) $r['id'], 'url' => photo_url($r['filename']), 'status' => $r['status'], 'name' => $r['name'], 'createdAt' => iso($r['created_at'])];
}
$activity = [];
foreach ($pdo->query('SELECT message, created_at FROM activity ORDER BY id DESC LIMIT 10')->fetchAll() as $a) {
    $activity[] = ['message' => $a['message'], 'createdAt' => iso($a['created_at'])];
}
json_out([
    'visitors' => $one('SELECT COUNT(*) FROM visitors'),
    'approvedPhotos' => $one("SELECT COUNT(*) FROM photos WHERE status = 'approved'"),
    'hiddenPhotos' => $one("SELECT COUNT(*) FROM photos WHERE status = 'hidden'"),
    'last24h' => $one('SELECT COUNT(*) FROM photos WHERE created_at >= (NOW() - INTERVAL 1 DAY)'),
    'storageBytes' => $one('SELECT COALESCE(SUM(bytes), 0) FROM photos'),
    'recentUploads' => $recent,
    'activity' => $activity,
    'settings' => get_settings($pdo),
]);
