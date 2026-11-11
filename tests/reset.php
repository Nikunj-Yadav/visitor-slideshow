<?php
/**
 * Empties the TEST database and the uploads folder so a test run starts from a clean state.
 *   php tests/reset.php
 * Safety: refuses to run unless the database name ends with "_test".
 */
function reset_test_environment(): void
{
    $cfg = require dirname(__DIR__) . '/config/config.php';
    if (substr($cfg['db_name'], -5) !== '_test') {
        fwrite(STDERR, "Refusing to reset database '{$cfg['db_name']}': its name must end with _test.\n"
            . "Run with  MW_DB_NAME=visitor_slideshow_test  (and create that database first).\n");
        exit(2);
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
        $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DELETE FROM photos');
    $pdo->exec('DELETE FROM visitors');
    foreach (['activity', 'admin_users', 'rate_limit_hits'] as $t) { $pdo->exec("DELETE FROM $t"); }
    $pdo->exec("UPDATE settings SET setting_value = '8' WHERE setting_key = 'duration_seconds'");
    $pdo->exec("UPDATE settings SET setting_value = 'false' WHERE setting_key = 'show_names'");
    $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'data_version'");
    foreach (glob(rtrim($cfg['upload_dir'], '/\\') . '/*') ?: [] as $f) {
        if (is_file($f) && !in_array(basename($f), ['.htaccess', '.gitkeep'], true)) { unlink($f); }
    }
}
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    reset_test_environment();
    echo "Test database and uploads folder emptied.\n";
}
