<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/core.php';
umask(0077);
$c=require dirname(__DIR__).'/config.php';
$db=database($c);
$target=$c['data_dir'].'/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($target,0700,true);
// Images are immutable and retained; snapshot DB first, then copy its referenced files.
$db->exec('VACUUM INTO '.$db->quote($target.'/app.sqlite'));
$snapshot=new PDO('sqlite:'.$target.'/app.sqlite');
foreach($snapshot->query('SELECT profile FROM accounts') as $row) {
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
copy(dirname(__DIR__).'/config.php',$target.'/config.php');
echo "Backup: $target\n";
