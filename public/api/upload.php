<?php
/**
 * Visitor upload. Fields: photos[] (files), consent=true, and either name (new visitor) or code (returning).
 * Steps: limits -> consent -> validate every file with GD -> save files -> ONE database transaction.
 */
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('POST');
rate_limit('upload', (int) cfg('rate_upload'));
$pdo = db();
$maxMb = (int) cfg('max_file_mb');
$maxFiles = (int) cfg('max_files_per_request');
$maxPhotos = (int) cfg('max_photos_per_visitor');

// A body larger than php.ini post_max_size arrives completely empty.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail(413, "A photo is larger than the {$maxMb} MB limit.");
}

// Collect the uploaded files (supports photos[] with several files, or a single "photos").
$files = [];
if (isset($_FILES['photos'])) {
    $f = $_FILES['photos'];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            $files[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } else {
        $files[] = ['name' => $f['name'], 'type' => $f['type'], 'tmp' => $f['tmp_name'], 'error' => $f['error'], 'size' => $f['size']];
    }
}
$files = array_values(array_filter($files, function ($x) { return $x['error'] !== UPLOAD_ERR_NO_FILE; }));
if (!$files) { fail(400, 'Choose at least one photo.'); }
if (count($files) > $maxFiles) { fail(400, "You can upload up to {$maxFiles} photos at a time."); }
foreach ($files as $x) {
    if ($x['error'] === UPLOAD_ERR_INI_SIZE || $x['error'] === UPLOAD_ERR_FORM_SIZE || $x['size'] > $maxMb * 1024 * 1024) {
        fail(413, "A photo is larger than the {$maxMb} MB limit.");
    }
}
if (($_POST['consent'] ?? '') !== 'true') { fail(400, 'Please agree to the public display of your photos.'); }

$code = trim((string) ($_POST['code'] ?? ''));
$name = clean_name($_POST['name'] ?? '');
$existingId = null;
if ($code !== '') {
    $existing = find_visitor_by_code($pdo, $code);
    if (!$existing) { fail(404, 'We do not recognise that visitor code.'); }
    $existingId = (int) $existing['id'];
} elseif ($name === '') {
    fail(400, 'Please enter your name.');
}

// 1) Validate and clean every file BEFORE anything is saved or any visitor is created.
$results = array_fill(0, count($files), null);
$good = [];
foreach ($files as $i => $x) {
    $label = mb_substr(preg_replace('/[\x00-\x1f]/u', '', basename(str_replace('\\', '/', (string) $x['name']))) ?? 'photo', 0, 80);
    if ($x['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($x['tmp'])) {
        $why = $x['error'] === UPLOAD_ERR_PARTIAL ? 'The upload was interrupted. Please try again.' : 'The file could not be uploaded.';
        $results[$i] = ['name' => $label, 'status' => 'rejected', 'reason' => $why];
        continue;
    }
    // 2) Save as *.part, then rename: a half-written file can never be served.
    $filename = bin2hex(random_bytes(16)) . '.jpg';
    $part = upload_path($filename) . '.part';
    $r = process_image($x['tmp'], (string) $x['type'], (string) $x['name'], $part);
    if (!$r['ok']) {
        @unlink($part);
        $results[$i] = ['name' => $label, 'status' => 'rejected', 'reason' => $r['reason']];
        continue;
    }
    rename($part, upload_path($filename));
    $good[] = ['i' => $i, 'label' => $label, 'filename' => $filename, 'sha' => $r['sha'], 'bytes' => $r['bytes'], 'saved' => false];
}
if (!$good) { json_out(['error' => 'None of the photos could be accepted.', 'results' => $results], 422); }

// 3) One atomic transaction: lock the visitor, enforce limits, skip duplicates, insert.
$outcome = with_transaction(function (PDO $pdo) use (&$good, &$results, $existingId, $name, $maxPhotos) {
    foreach ($good as $k => $g) { $good[$k]['saved'] = false; }   // (reset in case MySQL asks us to retry)
    $newCode = null;
    if ($existingId !== null) {
        // FOR UPDATE: simultaneous uploads of the same visitor wait here, one after the other.
        $st = $pdo->prepare('SELECT id, name, consent_at FROM visitors WHERE id = ? FOR UPDATE');
        $st->execute([$existingId]);
        $v = $st->fetch();
        if (!$v) { return null; }   // visitor was removed while uploading
        if ($v['consent_at'] === null) { $pdo->prepare('UPDATE visitors SET consent_at = NOW() WHERE id = ?')->execute([$v['id']]); }
        $visitor = ['id' => (int) $v['id'], 'name' => $v['name']];
    } else {
        $visitor = null;
        for ($try = 0; $try < 5 && $visitor === null; $try++) {
            $newCode = generate_code();
            try {
                $pdo->prepare('INSERT INTO visitors (name, code_hash, consent_at) VALUES (?, ?, NOW())')->execute([$name, hash_code($newCode)]);
                $visitor = ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }   // 1062 = this random code already exists: try another
            }
        }
        if ($visitor === null) { throw new RuntimeException('Could not create a visitor code.'); }
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE visitor_id = ?');
    $st->execute([$visitor['id']]);
    $count = (int) $st->fetchColumn();
    $accepted = 0;
    foreach ($good as $k => $g) {
        $st = $pdo->prepare('SELECT 1 FROM photos WHERE visitor_id = ? AND sha256 = ?');
        $st->execute([$visitor['id'], $g['sha']]);
        if ($st->fetchColumn()) { $results[$g['i']] = ['name' => $g['label'], 'status' => 'duplicate', 'reason' => 'You already uploaded this photo.']; continue; }
        if ($count >= $maxPhotos) { $results[$g['i']] = ['name' => $g['label'], 'status' => 'rejected', 'reason' => "Limit of {$maxPhotos} photos reached."]; continue; }
        try {
            $pdo->prepare('INSERT INTO photos (visitor_id, filename, sha256, bytes) VALUES (?, ?, ?, ?)')->execute([$visitor['id'], $g['filename'], $g['sha'], $g['bytes']]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }   // unique index caught a duplicate
            $results[$g['i']] = ['name' => $g['label'], 'status' => 'duplicate', 'reason' => 'You already uploaded this photo.'];
            continue;
        }
        $good[$k]['saved'] = true;
        $count++;
        $accepted++;
        $results[$g['i']] = ['name' => $g['label'], 'status' => 'accepted'];
    }
    if ($accepted) { log_activity($pdo, $visitor['name'] . ' added ' . $accepted . ' photo' . ($accepted > 1 ? 's' : '')); bump_version($pdo); }
    return ['visitor' => $visitor, 'newCode' => $newCode, 'count' => $count];
});

// Delete files that were not stored (duplicates, limit reached, or the transaction failed).
remove_files(array_map(function ($g) { return $g['filename']; }, array_filter($good, function ($g) { return !$g['saved']; })));
if ($outcome === null) { fail(404, 'That visitor no longer exists.'); }

$visitorOut = ['name' => $outcome['visitor']['name'], 'photoCount' => $outcome['count']];
if ($outcome['newCode'] !== null) { $visitorOut['code'] = $outcome['newCode']; }   // the code is shown only once
json_out(['visitor' => $visitorOut, 'results' => $results]);
