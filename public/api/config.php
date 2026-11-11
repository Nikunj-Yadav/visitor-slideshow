<?php
define('MW_JSON', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_method('GET');
json_out([
    'maxFileMb' => (int) cfg('max_file_mb'),
    'maxFilesPerRequest' => (int) cfg('max_files_per_request'),
    'maxPhotosPerVisitor' => (int) cfg('max_photos_per_visitor'),
    'allowedTypes' => ['image/jpeg', 'image/png', 'image/webp'],
]);
