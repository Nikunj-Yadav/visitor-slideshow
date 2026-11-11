<?php
define('MW_JSON', true);
require __DIR__ . '/../../../includes/bootstrap.php';
require_method('POST');
require_same_origin();
start_admin_session();
require_csrf();
$_SESSION = [];
session_destroy();
json_out(['ok' => true]);
