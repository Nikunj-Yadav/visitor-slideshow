<?php
/**
 * Admin authentication: a PHP session (HttpOnly, SameSite=Strict cookie) + a CSRF token.
 * Visitors never use sessions: their visitor code travels in the request itself.
 */

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $secure = cfg('cookie_secure');
    if ($secure === null) { $secure = is_https(); }
    session_name('mw_admin');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => base_url(), 'secure' => (bool) $secure,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
    // sign out after too long without activity
    if (isset($_SESSION['last']) && time() - (int) $_SESSION['last'] > (int) cfg('session_hours') * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
}

function is_admin(): bool
{
    start_admin_session();
    return !empty($_SESSION['admin_id']);
}

function admin_exists(): bool
{
    return (bool) db()->query('SELECT 1 FROM admin_users LIMIT 1')->fetchColumn();
}

function csrf_token(): string
{
    start_admin_session();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}

/** Block requests whose Origin header names another website. */
function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') { return; }
    $host = parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $originHost = $host . ($port ? ':' . $port : '');
    if (!$host || strcasecmp($originHost, $_SERVER['HTTP_HOST'] ?? '') !== 0) { fail(403, 'Cross-site request blocked.'); }
}

/** The request must carry the session's CSRF token in the X-CSRF-Token header. */
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        fail(403, 'Your session expired. Please reload the page and try again.');
    }
}

/** Use at the top of every admin API endpoint that changes data (POST). */
function require_admin_post(): void
{
    require_method('POST');
    require_same_origin();
    if (!is_admin()) { fail(401, 'Please sign in as administrator.'); }
    require_csrf();
}

/** Use at the top of admin API endpoints that only read data (GET). */
function require_admin_get(): void
{
    require_method('GET');
    if (!is_admin()) { fail(401, 'Please sign in as administrator.'); }
}

/** Use at the top of admin pages: not signed in -> go to the sign-in (or first-time setup) page. */
function require_admin_page(): void
{
    if (!admin_exists()) { header('Location: ' . base_url() . 'admin/setup.php'); exit; }
    if (!is_admin()) { header('Location: ' . base_url() . 'admin/login.php'); exit; }
    header('Cache-Control: no-store');
}
