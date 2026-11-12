<?php
/**
 * Creates the TEST database from database.sql:   php tests/create-test-db.php
 * The test database name comes from config (db_name) and must end with "_test".
 * Put   'db_name' => 'visitor_slideshow_test'   in config/config.local.php of the copy you test.
 */
$cfg = require dirname(__DIR__) . '/config/config.php';
$name = $cfg['db_name'];
if (substr($name, -5) !== '_test') { fwrite(STDERR, "db_name is '$name'; it must end with _test (see config/config.local.php).\n"); exit(2); }
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']), $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");
$sql = file_get_contents(dirname(__DIR__) . '/database.sql');
$sql = preg_replace('/^CREATE DATABASE.*?;\s*USE `[^`]+`;/ms', '', $sql, 1);   // keep only the tables
$pdo->exec($sql);
echo "Test database '$name' is ready.\n";
