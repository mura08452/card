<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/storage.php';
umask(0077);
$c=require dirname(__DIR__).'/config.php';
$db=database($c);
$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$target=$c['data_dir'].'/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
$complete = $target;
$target .= '.partial';
mkdir($target,0700,true);
// Images are immutable and retained; snapshot DB first, then copy its referenced files.
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->beginTransaction();
$dump = fopen($target . '/data.sql', 'xb');
function dumpLine($file, string $line): void {
    if (fwrite($file, $line) !== strlen($line)) {
        throw new RuntimeException('Incomplete database backup');
    }
}
dumpLine($dump, "SET NAMES utf8mb4;\n" . file_get_contents(dirname(__DIR__) . '/schema.sql') . "\nSTART TRANSACTION;\n");
foreach (CARD_TABLES + ['card_storage_version' => 'version'] as $table => $columns) {
    foreach ($db->query("SELECT $columns FROM $table") as $row) {
        $values = array_map(static fn($v) => is_int($v) ? (string)$v :
            "CONVERT(X'" . bin2hex((string)$v) . "' USING utf8mb4)", $row);
        dumpLine($dump, "INSERT INTO $table ($columns) VALUES (" . implode(',', $values) . ");\n");
    }
}
dumpLine($dump, "COMMIT;\n");
if (!fclose($dump)) throw new RuntimeException('Incomplete database backup');
foreach($db->query('SELECT profile FROM accounts') as $row) {
    $p=json_decode($row['profile'],true);
    foreach(['icon','image'] as $kind) {
        $value=$p[$kind]??'';
        if(!str_starts_with($value,imagePrefix($c)))continue;
        $key=rawurldecode(substr($value,strlen(imagePrefix($c))));
        if(!preg_match('~^[a-f0-9-]+/(icon|image)/[a-f0-9-]+$~D',$key))throw new RuntimeException('Invalid image reference');
        $dest=$target.'/images/'.$key;
        if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);
        if(!copy($c['data_dir'].'/images/'.$key,$dest))throw new RuntimeException('Image backup failed');
    }
}
$db->commit();
if (!copy(dirname(__DIR__).'/config.php',$target.'/config.php') || !rename($target, $complete)) {
    throw new RuntimeException('Incomplete backup');
}
$target = $complete;
echo "Backup: $target\n";
