<?php
/**
 * Setup check: shows what is working and what is missing (PHP version, extensions, database,
 * upload folder). Only reachable from the same computer (localhost), never from the network.
 */
require __DIR__ . '/../includes/bootstrap.php';
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('The setup check can only be opened on the computer that runs the server.'); }

$checks = [];
$add = function (string $label, bool $ok, string $detail) use (&$checks) { $checks[] = [$label, $ok, $detail]; };

$add('PHP 8.0 or newer', PHP_VERSION_ID >= 80000, 'You have PHP ' . PHP_VERSION);
foreach (['gd' => 'GD (image processing)', 'pdo_mysql' => 'PDO MySQL (database)', 'mbstring' => 'mbstring (text handling)'] as $ext => $label) {
    $add($label, extension_loaded($ext), extension_loaded($ext) ? 'enabled' : "Open php.ini (XAMPP Control Panel > Apache > Config) and remove the ; before extension=$ext, then restart Apache.");
}
if (function_exists('gd_info')) {
    $gd = gd_info();
    $add('GD can read/write JPG, PNG and WebP', !empty($gd['JPEG Support']) && !empty($gd['PNG Support']) && !empty($gd['WebP Support']),
        'JPEG: ' . (!empty($gd['JPEG Support']) ? 'yes' : 'no') . ', PNG: ' . (!empty($gd['PNG Support']) ? 'yes' : 'no') . ', WebP: ' . (!empty($gd['WebP Support']) ? 'yes' : 'no'));
}
try {
    $pdo = db();
    $add('Database connection', true, 'Connected to "' . cfg('db_name') . '" on ' . cfg('db_host'));
    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['visitors', 'photos', 'settings', 'activity', 'admin_users', 'rate_limit_hits'];
    $missing = array_diff($need, $have);
    $add('Database tables imported', !$missing, $missing ? 'Missing: ' . implode(', ', $missing) . '. Import database.sql in phpMyAdmin.' : 'all ' . count($need) . ' tables found');
} catch (Throwable $e) {
    $add('Database connection', false, 'Cannot connect. Is MySQL started in XAMPP? Did you import database.sql? Are the settings in config/config.local.php right?');
}
$dir = (string) cfg('upload_dir');
$add('Upload folder is writable', is_dir($dir) && is_writable($dir), is_dir($dir) && is_writable($dir) ? 'ok' : 'On macOS/Linux run: chmod -R 777 uploads');
$add('Upload limits in PHP', true, 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . ' (the app allows ' . (int) cfg('max_file_mb') . ' MB per photo)');

page_head('Setup check', ['noindex' => true]);
?>
  <main class="page page--narrow">
    <header class="page-header"><h1>Setup check</h1><p>Everything should say OK before you invite visitors.</p></header>
    <section class="card"><ul class="list">
<?php foreach ($checks as [$label, $ok, $detail]) : ?>
      <li><span><strong><?= h($label) ?></strong><br><span class="hint"><?= h($detail) ?></span></span>
          <span class="badge <?= $ok ? 'badge--ok' : 'badge--bad' ?>"><?= $ok ? 'OK' : 'Fix needed' ?></span></li>
<?php endforeach; ?>
    </ul></section>
    <div class="row"><a class="btn btn--primary" href="index.php">Open the visitor page</a><a class="btn" href="admin/index.php">Admin</a></div>
  </main>
<?php page_foot('notfound.js'); ?>
