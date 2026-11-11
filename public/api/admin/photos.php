<?php
/** Admin list of photos. Query: q (visitor name), status (approved|hidden), sort (newest|oldest|name|size). */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_get();
$sorts = ['newest' => 'p.id DESC', 'oldest' => 'p.id ASC', 'name' => 'v.name ASC, p.id DESC', 'size' => 'p.bytes DESC'];
$sort = $sorts[$_GET['sort'] ?? ''] ?? $sorts['newest'];   // only whitelisted text is ever put into the SQL
$where = [];
$args = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') { $where[] = 'LOCATE(LOWER(?), LOWER(v.name)) > 0'; $args[] = $q; }
$status = $_GET['status'] ?? '';
if (in_array($status, ['approved', 'hidden'], true)) { $where[] = 'p.status = ?'; $args[] = $status; }
$sql = 'SELECT p.id, p.filename, p.status, p.bytes, p.created_at, v.id AS visitor_id, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $sort LIMIT 500";
$st = db()->prepare($sql);
$st->execute($args);
$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = ['id' => (int) $r['id'], 'url' => photo_url($r['filename']), 'status' => $r['status'], 'bytes' => (int) $r['bytes'],
              'visitorId' => (int) $r['visitor_id'], 'name' => $r['name'], 'createdAt' => iso($r['created_at'])];
}
json_out(['photos' => $out]);
