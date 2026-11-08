<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/storage.php';
umask(0077);
$config = require dirname(__DIR__) . '/config.php';
foreach (['', '/images', '/sessions', '/backups'] as $suffix) {
    $path = $config['data_dir'] . $suffix;
    if (!is_dir($path) && !mkdir($path, 0700, true)) {
        throw new RuntimeException('Cannot create private storage directory.');
    }
}
initializeStorage($config);
echo "MySQL storage ready.\n";
