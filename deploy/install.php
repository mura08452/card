<?php
declare(strict_types=1);

// CLI only. Existing installation and config.php are required.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
umask(0077);

function copyTree(string $from, string $to, bool $public = false): void
{
    if (is_link($from) || is_link($to)) {
        throw new RuntimeException('Symbolic links are not supported: ' . $to);
    }
    if (is_dir($from)) {
        if (!is_dir($to) && !mkdir($to, $public ? 0755 : 0700, true)) {
            throw new RuntimeException('Cannot create directory: ' . $to);
        }
        foreach (new DirectoryIterator($from) as $entry) {
            if (!$entry->isDot()) {
                copyTree($entry->getPathname(), $to . '/' . $entry->getFilename(), $public);
            }
        }
        return;
    }
    $tmp = $to . '.deploy-' . bin2hex(random_bytes(6));
    if (!copy($from, $tmp) || !chmod($tmp, $public ? 0644 : 0600) || !rename($tmp, $to)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot copy file: ' . $to);
    }
}

function runPhp(string $script): void
{
    $process = proc_open([PHP_BINARY, $script], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('PHP command failed: ' . $script);
    }
}

function writeAtomic(string $path, string $content): void
{
    if (is_link($path)) {
        throw new RuntimeException('Symbolic link refused: ' . $path);
    }
    $tmp = $path . '.deploy-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $content) === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write: ' . $path);
    }
}

$maintenance = false;
try {
    if (count($argv) !== 4 || !preg_match('/^[a-f0-9]{40}$/D', $argv[3])) {
        throw new RuntimeException('Usage: php install.php PRIVATE_DIR PUBLIC_DIR COMMIT_SHA');
    }
    $private = realpath($argv[1]);
    $public = realpath($argv[2]);
    $source = dirname(__DIR__);
    if (!$private || !$public || basename($public) !== 'card' || basename(dirname($public)) !== 'www') {
        throw new RuntimeException('Expected existing private directory and /home/ACCOUNT/www/card directory.');
    }
    $webRoot = dirname($public);
    if ($private === $webRoot || str_starts_with($private, $webRoot . '/') ||
        $private === $source || str_starts_with($source, $private . '/')) {
        throw new RuntimeException('Private and release directories must be separate and outside www.');
    }
    foreach (['pdo_mysql', 'curl', 'fileinfo'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Missing PHP extension: ' . $extension);
        }
    }
    if (PHP_VERSION_ID < 80200 || !is_file($private . '/config.php') || !is_file($public . '/index.php')) {
        throw new RuntimeException('PHP >= 8.2 and an existing installation are required.');
    }
    $lock = fopen($private . '/.deploy.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another deployment is running.');
    }
    if (is_file($private . '/.deploy-pending')) {
        throw new RuntimeException('Previous deployment needs recovery. See .deploy-pending and deploy/README.md.');
    }
    $config = require $private . '/config.php';
    $data = realpath($config['data_dir'] ?? '');
    if (($config['base_url'] ?? '') !== 'https://over.rulez.jp/card' || !$data ||
        $data === $webRoot || str_starts_with($data, $webRoot . '/')) {
        throw new RuntimeException('Check base_url and private data directory.');
    }
    require_once $source . '/private/src/core.php';
    $targetDb = database($config);
    $legacy = str_contains(file_get_contents($private . '/src/core.php'), "'sqlite:'");
    if ($legacy && (!is_file($data . '/app.sqlite') || !extension_loaded('pdo_sqlite'))) {
        throw new RuntimeException('SQLite source and pdo_sqlite are required for the first migration.');
    }
    if ($legacy && $targetDb->query('SHOW TABLES')->fetchColumn() !== false) {
        throw new RuntimeException('Select a new empty MySQL database before migrating from SQLite.');
    }
    if (!$legacy && !$targetDb->query('SELECT version FROM card_storage_version WHERE version=1')->fetchColumn()) {
        throw new RuntimeException('Existing MySQL installation not found.');
    }
    $targetDb = null;
    foreach (['private', 'public'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Release contains symbolic links.');
            }
            if ($file->getExtension() === 'php') {
                $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
                if (!is_resource($process) || proc_close($process) !== 0) {
                    throw new RuntimeException('Release syntax check failed.');
                }
            }
        }
    }
    $entry = file_get_contents($source . '/public/index.php');
    $needle = '$private = dirname(__DIR__).\'/private\';';
    if (substr_count($entry, $needle) !== 1) {
        throw new RuntimeException('Unexpected entrypoint; private path was not changed.');
    }
    $entry = str_replace($needle, '$private = ' . var_export($private, true) . ';', $entry);
    $backup = $private . '/deploy-backups/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    copyTree($public, $backup . '/public');
    foreach (['src', 'bin', 'schema.sql'] as $item) {
        copyTree($private . '/' . $item, $backup . '/private/' . $item);
    }
    // Marker survives failures so a second deployment cannot hide a broken release.
    if (file_put_contents($private . '/.deploy-pending', $backup . "\n") === false) {
        throw new RuntimeException('Cannot write recovery marker.');
    }
    writeAtomic($public . '/.htaccess', "RewriteEngine On\nRewriteRule ^ - [R=503,L]\nErrorDocument 503 \"Updating. Please try again shortly.\"\n");
    $maintenance = true;
    runPhp($private . '/bin/backup.php');
    foreach (['src', 'bin', 'schema.sql'] as $item) {
        copyTree($source . '/private/' . $item, $private . '/' . $item);
    }
    runPhp($private . '/bin/migrate.php');
    $db = database($config);
    if (!$db->query('SELECT version FROM card_storage_version WHERE version=1')->fetchColumn()) {
        throw new RuntimeException('MySQL storage verification failed.');
    }
    $db = null;
    foreach (new DirectoryIterator($source . '/public') as $file) {
        if (!$file->isDot() && !in_array($file->getFilename(), ['index.php', '.htaccess'], true)) {
            copyTree($file->getPathname(), $public . '/' . $file->getFilename(), true);
        }
    }
    writeAtomic($public . '/index.php', $entry);
    copyTree($source . '/public/.htaccess', $public . '/.htaccess', true);
    $maintenance = false;
    unlink($private . '/.deploy-pending');
    file_put_contents($private . '/deployed-commit', $argv[3] . "\n");
    echo "Deployed " . $argv[3] . "\nCode backup: " . $backup . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    if ($maintenance) {
        fwrite(STDERR, "Maintenance remains enabled. Recover using deploy/README.md; do not remove the marker blindly.\n");
    }
    exit(1);
}
