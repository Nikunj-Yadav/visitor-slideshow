<?php
/** First-time setup: create the admin password. Works only while no admin exists. */
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_method('POST');
require_same_origin();
rate_limit('login', (int) cfg('rate_login'));
start_admin_session();
require_csrf();
$body = read_json();
$pw = is_string($body['password'] ?? null) ? $body['password'] : '';
if (mb_strlen($pw) < 10) { fail(400, 'Please choose a password with at least 10 characters.'); }
if (($body['confirm'] ?? '') !== $pw) { fail(400, 'The two passwords do not match.'); }
try {
    // id is the primary key and always 1, so a second admin can never be created
    db()->prepare('INSERT INTO admin_users (id, password_hash) VALUES (1, ?)')->execute([password_hash($pw, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) { fail(409, 'An admin password already exists.'); }
    throw $e;
}
session_regenerate_id(true);
$_SESSION['admin_id'] = 1;
$_SESSION['csrf'] = bin2hex(random_bytes(32));
log_activity(db(), 'Admin account created');
json_out(['ok' => true]);
