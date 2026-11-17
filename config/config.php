<?php
/**
 * Loads the application settings.
 * Order of priority (highest first):
 *   1. environment variables starting with MW_   (used by the automated tests / servers)
 *   2. config/config.local.php                   (your own copy of config.example.php)
 *   3. the defaults below, which match a fresh XAMPP install
 * No passwords are stored in this file.
 */
$config = [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'visitor_slideshow',
    'db_user' => 'root',          // XAMPP default
    'db_pass' => '',              // XAMPP default (empty). Change it if you set a MySQL password.
    'upload_dir' => dirname(__DIR__) . '/uploads',
    'max_file_mb' => 8,
    'max_files_per_request' => 10,
    'max_photos_per_visitor' => 30,
    'max_pixels' => 40000000,     // refuse images above 40 megapixels (memory safety for GD)
    'session_hours' => 8,
    'cookie_secure' => null,      // null = automatic (secure cookie when the site uses HTTPS)
    'trust_proxy' => false,       // true only behind your own reverse proxy
    'rate_upload' => 200,         // requests per 15 minutes per IP
    'rate_lookup' => 60,
    'rate_login' => 10,
    'debug' => false,
    'base_url' => null,           // normally detected automatically
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, (array) require $local);
}

// MW_DB_HOST, MW_MAX_FILE_MB, ... override everything (numbers and booleans are converted).
foreach ($config as $key => $default) {
    $name = 'MW_' . strtoupper($key);
    $value = getenv($name);
    if ($value === false && isset($_SERVER[$name])) { $value = $_SERVER[$name]; }
    if ($value === false || $value === '') { continue; }
    if (is_int($default))      { $config[$key] = (int) $value; }
    elseif (is_bool($default)) { $config[$key] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true); }
    else                       { $config[$key] = $value; }
}
return $config;
