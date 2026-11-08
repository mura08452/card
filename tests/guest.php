<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/src/bootstrap.php';
require dirname(__DIR__).'/private/src/guest.php';
function check($ok,$label) {
    if(!$ok)throw new RuntimeException($label);
}
$dir=sys_get_temp_dir().'/guest-'.bin2hex(random_bytes(8));
mkdir($dir);
mkdir($dir.'/sessions');
$c=['data_dir'=>$dir,'base_url'=>'https://over.rulez.jp/card'];
require_once dirname(__DIR__) . '/private/src/storage.php';
$c['mysql'] = require __DIR__ . '/mysql.php';
initializeStorage($c);
$db = database($c);
$old=account($db,'old-oauth-hash');
migrateIdentity($db);
migrateIdentity($db);
check(one($db,'SELECT auth_id FROM identities WHERE subject=?',['old-oauth-hash'])['auth_id']==='old-oauth-hash','legacy preserved');
sessionBoot($c);
guestStart($db,$c);
$a=$_SESSION['identity'];
$token=$_COOKIE[deviceCookie($c)];
$id=account($db,$a)['id'];
check(str_starts_with($a,'guest:'),'guest created');
check(strlen($token)===64,'random token');
check(!one($db,'SELECT hash FROM devices WHERE hash=?',[$token]),'only hash stored');
guestStart($db,$c);
check($_SESSION['identity']===$a,'repeat visit');
$_SESSION=[];
check(deviceRestore($db,$c)&&$_SESSION['identity']===$a,'restore after session loss');
linkIdentity($db,'google-hash','google',$a);
linkIdentity($db,'google-hash','google',$a);
linkIdentity($db,'x-hash','x',$a);
check(count(linkedProviders($db,$a))===2,'two providers');
check(account($db,$a)['id']===$id,'same account after linking');
$b='guest:other';
account($db,$b);
try {
    linkIdentity($db,'google-hash','google',$b);
    throw new Exception('conflict not rejected');
}
catch(RuntimeException $e) {
    check($e->getCode()===409,'conflict status');
}
check(one($db,'SELECT auth_id FROM identities WHERE subject=?',['google-hash'])['auth_id']===$a,'no merge');
$_SESSION=[];
$_COOKIE[deviceCookie($c)]=str_repeat('0',64);
check(!deviceRestore($db,$c),'invalid token');
guestStart($db,$c);
check($_SESSION['identity']!==$a,'cookie loss new account');
$_SESSION=[];
$_COOKIE[deviceCookie($c)]=$token;
query($db,'UPDATE devices SET expires=0');
check(!deviceRestore($db,$c),'expiry enforced');
session_write_close();
$db=null;
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
rmdir($dir);
echo "PASS guest creation, repeat visits, durable token restoration, expiry, linking, conflicts and legacy migration\n";
