<?php
/**
 * Loaded first by every page and API script.
 * Sets up configuration, error handling and security headers.
 * API scripts define('MW_JSON', true) before including this file so errors are returned as JSON.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Memory Wall needs PHP 8.0 or newer (XAMPP 8.x).');
}

define('ROOT_DIR', dirname(__DIR__));
define('PUBLIC_DIR', ROOT_DIR . '/public');
$GLOBALS['mw_config'] = require ROOT_DIR . '/config/config.php';

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');      // never show PHP errors to visitors
ini_set('log_errors', '1');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/ratelimit.php';
require __DIR__ . '/images.php';
require __DIR__ . '/layout.php';

send_security_headers();

// Any uncaught error: log the details, show a short friendly message.
set_exception_handler(function (Throwable $e): void {
    error_log('[MemoryWall] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    if (defined('MW_JSON')) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['error' => 'Something went wrong on our side. Please try again.']);
    } else {
        echo 'Something went wrong on our side. Please try again.';
    }
});
