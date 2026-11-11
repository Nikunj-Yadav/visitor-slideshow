<?php
/** Save display settings. Body: {"durationSeconds":8,"showNames":false} */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_admin_post();
$body = read_json();
$d = $body['durationSeconds'] ?? null;
if (!is_int($d) || $d < 3 || $d > 120) { fail(400, 'Display time must be a whole number from 3 to 120 seconds.'); }
if (!is_bool($body['showNames'] ?? null)) { fail(400, 'Invalid names setting.'); }
$pdo = db();
with_transaction(function (PDO $pdo) use ($d, $body) {
    $set = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $set->execute(['duration_seconds', (string) $d]);
    $set->execute(['show_names', $body['showNames'] ? 'true' : 'false']);
    bump_version($pdo);
});
json_out(['settings' => get_settings($pdo)]);
