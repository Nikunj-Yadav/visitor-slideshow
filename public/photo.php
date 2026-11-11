<?php
/**
 * Serves one photo from the protected uploads/ folder.
 * Public visitors only get approved photos of visitors who gave consent; an admin may see hidden ones.
 */
require __DIR__ . '/../includes/bootstrap.php';
$file = $_GET['f'] ?? '';
if (!is_string($file) || !valid_filename($file)) { http_response_code(404); exit; }   // blocks ../ and any odd name
$st = db()->prepare('SELECT p.status, v.consent_at FROM photos p JOIN visitors v ON v.id = p.visitor_id WHERE p.filename = ?');
$st->execute([$file]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit; }
$public = $row['status'] === 'approved' && $row['consent_at'] !== null;
if (!$public) {
    // only look at the session if the browser sent a session cookie
    if (!isset($_COOKIE['mw_admin']) || !is_admin()) { http_response_code(404); exit; }
}
$path = upload_path($file);
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: ' . ($public ? 'public, max-age=3600' : 'private, no-store'));
readfile($path);
