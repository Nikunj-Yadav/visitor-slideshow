<?php
/**
 * Image validation and cleaning with PHP GD.
 *   1. look at the first bytes ("magic numbers") - the file name and browser type are never trusted
 *   2. let GD fully decode the picture (fake or damaged files fail here)
 *   3. fix phone rotation, shrink to 2560 px, save as a new JPEG
 *      -> a new JPEG has no EXIF data, so GPS location and camera details are removed
 */

const ALLOWED_EXTENSIONS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

function sniff_mime(string $b): ?string
{
    if (strlen($b) > 3 && strncmp($b, "\xFF\xD8\xFF", 3) === 0) { return 'image/jpeg'; }
    if (strlen($b) > 8 && strncmp($b, "\x89PNG\r\n\x1A\n", 8) === 0) { return 'image/png'; }
    if (strlen($b) > 12 && substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') { return 'image/webp'; }
    return null;
}

/** Read the EXIF "Orientation" number (1-8) from a JPEG, 1 if there is none. */
function jpeg_orientation(string $b): int
{
    $len = strlen($b);
    $i = 2;
    while ($i + 4 <= $len && $b[$i] === "\xFF") {
        $marker = ord($b[$i + 1]);
        if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
        if ($marker === 0xDA || $marker === 0xD9) { break; }
        $segLen = unpack('n', substr($b, $i + 2, 2))[1];
        if ($marker === 0xE1 && substr($b, $i + 4, 6) === "Exif\0\0") {
            $t = $i + 10;                 // start of the TIFF header
            $end = $i + 2 + $segLen;
            $order = substr($b, $t, 2);
            if ($order !== 'II' && $order !== 'MM') { return 1; }
            $le = ($order === 'II');
            $u16 = function (int $o) use ($b, $t, $le): int { return unpack($le ? 'v' : 'n', substr($b, $t + $o, 2))[1]; };
            $u32 = function (int $o) use ($b, $t, $le): int { return unpack($le ? 'V' : 'N', substr($b, $t + $o, 4))[1]; };
            if ($t + 8 > $end) { return 1; }
            $ifd = $u32(4);
            if ($t + $ifd + 2 > $end) { return 1; }
            $count = $u16($ifd);
            for ($k = 0; $k < $count; $k++) {
                $e = $ifd + 2 + 12 * $k;
                if ($t + $e + 12 > $end) { break; }
                if ($u16($e) === 0x0112) {
                    $v = $u16($e + 8);
                    return ($v >= 1 && $v <= 8) ? $v : 1;
                }
            }
            return 1;
        }
        $i += 2 + $segLen;
    }
    return 1;
}

/** Turn the picture the right way up according to its EXIF orientation. */
function apply_orientation($img, int $o)
{
    switch ($o) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3: $img = imagerotate($img, 180, 0); break;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5: $img = imagerotate($img, -90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 7: $img = imagerotate($img, 90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 8: $img = imagerotate($img, 90, 0); break;
    }
    return $img;
}

/**
 * Validate one uploaded file and write the cleaned JPEG to $destPart.
 * Returns ['ok' => true, 'sha' => ..., 'bytes' => ...] or ['ok' => false, 'reason' => '...'].
 */
function process_image(string $tmpPath, string $declaredMime, string $origName, string $destPart): array
{
    if (function_exists('ini_get') && (int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 512) {
        @ini_set('memory_limit', '512M');   // GD needs about 5 bytes per pixel
    }
    $bytes = @file_get_contents($tmpPath);
    if ($bytes === false || $bytes === '') { return ['ok' => false, 'reason' => 'The file could not be read.']; }

    // 1. magic bytes, browser-declared type and file extension must all agree
    $real = sniff_mime($bytes);
    if ($real === null) { return ['ok' => false, 'reason' => 'Not a JPG, PNG or WebP image.']; }
    $declared = strtolower(trim($declaredMime));
    if ($declared !== '' && $declared !== 'application/octet-stream' && $declared !== $real) {
        return ['ok' => false, 'reason' => 'File type does not match its content.'];
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!isset(ALLOWED_EXTENSIONS[$ext])) { return ['ok' => false, 'reason' => 'Not a JPG, PNG or WebP image.']; }
    if (ALLOWED_EXTENSIONS[$ext] !== $real) { return ['ok' => false, 'reason' => 'File extension does not match its content.']; }

    // 2. size in pixels, then a full decode
    $info = @getimagesizefromstring($bytes);
    if ($info === false || $info[0] < 1 || $info[1] < 1) { return ['ok' => false, 'reason' => 'Image is damaged or cannot be read.']; }
    if ($info[0] * $info[1] > (int) cfg('max_pixels')) { return ['ok' => false, 'reason' => 'Image has too many pixels.']; }

    // GD does not complain about a JPEG that was cut short (it just shows a grey picture), so check the
    // end-of-image marker (FF D9). Entropy-coded JPEG data can never contain FF D9 by accident.
    if ($real === 'image/jpeg' && strpos(substr($bytes, -2048), "\xFF\xD9") === false) {
        return ['ok' => false, 'reason' => 'Image is damaged or cannot be read.'];
    }

    $warnings = [];
    set_error_handler(function ($no, $msg) use (&$warnings) { $warnings[] = $msg; return true; });
    $img = imagecreatefromstring($bytes);
    restore_error_handler();
    if ($img === false) { return ['ok' => false, 'reason' => 'Image is damaged or cannot be read.']; }
    foreach ($warnings as $w) {   // libjpeg says this when a JPEG is cut off
        if (stripos($w, 'premature end') !== false) { return ['ok' => false, 'reason' => 'Image is damaged or cannot be read.']; }
    }

    // 3. orientation, resize, flatten onto white, save as JPEG
    if ($real === 'image/jpeg') { $img = apply_orientation($img, jpeg_orientation($bytes)); }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 2560 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $saved = imagejpeg($canvas, $destPart, 85);
    unset($img, $canvas);
    if (!$saved || !is_file($destPart)) { return ['ok' => false, 'reason' => 'The image could not be processed.']; }

    return ['ok' => true, 'sha' => hash('sha256', $bytes), 'bytes' => (int) filesize($destPart)];
}
