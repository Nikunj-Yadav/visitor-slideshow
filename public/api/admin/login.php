<?php
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_method('POST');
require_same_origin();
rate_limit('login', (int) cfg('rate_login'));
start_admin_session();
require_csrf();
$body = read_json();
$row = db()->query('SELECT id, password_hash FROM admin_users WHERE id = 1')->fetch();
if (!$row) { fail(409, 'Admin setup is not finished yet.'); }
$password = is_string($body['password'] ?? null) ? $body['password'] : '';
if (!password_verify($password, $row['password_hash'])) { fail(401, 'Incorrect password.'); }
session_regenerate_id(true);     // new session id after login (prevents session fixation)
$_SESSION['admin_id'] = 1;
$_SESSION['csrf'] = bin2hex(random_bytes(32));
json_out(['ok' => true]);
