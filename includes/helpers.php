<?php
/** Small helper functions used everywhere. */

function cfg(string $key)
{
    return $GLOBALS['mw_config'][$key] ?? null;
}

/** Escape text for safe output inside HTML. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_security_headers(): void
{
    if (headers_sent()) { return; }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header_remove('X-Powered-By');
}

/** Send a JSON response and stop. */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Send a JSON error like {"error":"..."} and stop. */
function fail(int $status, string $message, array $extra = []): void
{
    json_out(['error' => $message] + $extra, $status);
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        fail(405, 'Method not allowed.');
    }
}

/** Read a small JSON request body. Only application/json is accepted. */
function read_json(): array
{
    $type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($type, 'application/json') !== 0) { fail(415, 'Please send JSON.'); }
    $raw = file_get_contents('php://input', false, null, 0, 10241);
    if ($raw === false || strlen($raw) > 10240) { fail(413, 'Request too large.'); }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return substr($ip, 0, 45);
}

function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/**
 * URL path of the /public folder with a trailing slash, e.g. "/visitor-slideshow/public/".
 * Worked out from the current script, so the project can live in any htdocs sub-folder.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) { return $base; }
    if (cfg('base_url')) { return $base = rtrim((string) cfg('base_url'), '/') . '/'; }
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $public = realpath(PUBLIC_DIR) ?: PUBLIC_DIR;
    $script = str_replace('\\', '/', $script);
    $public = str_replace('\\', '/', $public);
    $name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    if ($script !== '' && strncasecmp($script, $public . '/', strlen($public) + 1) === 0) {
        $relative = substr($script, strlen($public) + 1);
        if ($relative !== '' && strcasecmp(substr($name, -strlen($relative)), $relative) === 0) {
            return $base = substr($name, 0, strlen($name) - strlen($relative));
        }
    }
    return $base = '/';
}

function photo_url(string $filename): string
{
    return base_url() . 'photo.php?f=' . rawurlencode($filename);
}

/** MySQL DATETIME (UTC) -> ISO 8601 string for the browser. */
function iso(?string $d): ?string
{
    return $d ? str_replace(' ', 'T', $d) . 'Z' : null;
}

function clean_name($s): string
{
    $s = preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return trim(mb_substr(trim($s), 0, 40));
}

// Visitor codes avoid look-alike characters (0/O, 1/I) so they are easy to type.
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function generate_code(): string
{
    $c = '';
    for ($i = 0; $i < 10; $i++) { $c .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)]; }
    return substr($c, 0, 5) . '-' . substr($c, 5);
}

function normalize_code($s): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s)) ?? '';
}

function hash_code($s): string
{
    return hash('sha256', normalize_code($s));
}

function get_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'durationSeconds' => (int) ($rows['duration_seconds'] ?? 8),
        'showNames' => ($rows['show_names'] ?? 'false') === 'true',
    ];
}

function data_version(PDO $pdo): int
{
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'")->fetchColumn();
    return (int) $v;
}

/** Tell the slideshow that something changed (it polls this number). */
function bump_version(PDO $pdo): void
{
    $pdo->exec("UPDATE settings SET setting_value = CAST(setting_value AS UNSIGNED) + 1 WHERE setting_key = 'data_version'");
}

function log_activity(PDO $pdo, string $message): void
{
    $pdo->prepare('INSERT INTO activity (message) VALUES (?)')->execute([mb_substr($message, 0, 255)]);
    // keep only the latest 200 rows (derived table is required by MySQL)
    $pdo->exec('DELETE FROM activity WHERE id <= (SELECT m FROM (SELECT CAST(MAX(id) AS SIGNED) - 200 AS m FROM activity) t)');
}

function valid_filename(string $f): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}\.jpg$/', $f);
}

function upload_path(string $filename): string
{
    return rtrim((string) cfg('upload_dir'), '/\\') . '/' . $filename;
}

/** Delete photo files from disk (after the database rows are gone). */
function remove_files(array $names): void
{
    foreach ($names as $n) {
        if (valid_filename($n) && is_file(upload_path($n))) { @unlink(upload_path($n)); }
    }
}

/** Find a visitor row by the code they typed (spaces, dashes and case are ignored). */
function find_visitor_by_code(PDO $pdo, $code): ?array
{
    $n = normalize_code($code);
    if (strlen($n) !== 10) { return null; }
    $st = $pdo->prepare('SELECT id, name, consent_at FROM visitors WHERE code_hash = ?');
    $st->execute([hash_code($n)]);
    $row = $st->fetch();
    return $row ?: null;
}
