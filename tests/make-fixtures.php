<?php
// Creates the picture files used by the browser test:  php tests/make-fixtures.php
$dir = __DIR__ . '/fixtures'; @mkdir($dir);
$colors = [[42, 111, 151], [188, 71, 73], [106, 153, 78]];
foreach ($colors as $i => [$r, $g, $b]) {
    $im = imagecreatetruecolor(1600, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagestring($im, 5, 40, 40, 'Photo ' . ($i + 1), imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, "$dir/pic$i.jpg", 85);
}
file_put_contents("$dir/notes.jpg", 'this is not really an image');   // a fake photo to test rejection
echo "fixtures written to tests/fixtures\n";
