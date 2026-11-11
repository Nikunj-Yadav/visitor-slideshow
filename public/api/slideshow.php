<?php
/**
 * Public list of photos for the TV display. Only approved photos of visitors who gave consent.
 * ?since=N : if nothing changed since version N, answer with {"unchanged":true} (very cheap).
 */
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('GET');
$pdo = db();
$version = data_version($pdo);
if (isset($_GET['since']) && (int) $_GET['since'] === $version) {
    json_out(['unchanged' => true, 'version' => $version]);
}
$settings = get_settings($pdo);
$rows = $pdo->query("SELECT p.id, p.filename, v.name FROM photos p JOIN visitors v ON v.id = p.visitor_id
                     WHERE p.status = 'approved' AND v.consent_at IS NOT NULL ORDER BY p.id")->fetchAll();
$photos = [];
foreach ($rows as $r) {
    $first = explode(' ', (string) $r['name'])[0];   // only a first name is ever shown
    $photos[] = ['id' => (int) $r['id'], 'url' => photo_url($r['filename']), 'name' => $settings['showNames'] ? $first : null];
}
json_out(['version' => $version, 'settings' => $settings, 'photos' => $photos]);
