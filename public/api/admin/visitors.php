<?php
/** Admin list of visitors. Query: q (name), sort (newest|name|photos). */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_get();
$sorts = ['newest' => 'v.id DESC', 'name' => 'v.name ASC', 'photos' => 'photos DESC, v.id DESC'];
$sort = $sorts[$_GET['sort'] ?? ''] ?? $sorts['newest'];
$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT v.id, v.name, v.created_at, v.consent_at, COUNT(p.id) AS photos, MAX(p.created_at) AS last_upload
        FROM visitors v LEFT JOIN photos p ON p.visitor_id = v.id'
     . ($q !== '' ? ' WHERE LOCATE(LOWER(?), LOWER(v.name)) > 0' : '')
     . " GROUP BY v.id, v.name, v.created_at, v.consent_at ORDER BY $sort LIMIT 500";
$st = db()->prepare($sql);
$st->execute($q !== '' ? [$q] : []);
$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'photos' => (int) $r['photos'], 'joinedAt' => iso($r['created_at']),
              'lastUpload' => iso($r['last_upload']), 'consent' => $r['consent_at'] !== null];
}
json_out(['visitors' => $out]);
