<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/src/core.php';
function check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo "PASS $label\n";
}

function rejected(callable $f,int $status,string $label): void {
    try {
        $f();
    }
    catch(RuntimeException $e) {
        check($e->getCode()===$status,$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
$dir=sys_get_temp_dir().'/trail-test-'.bin2hex(random_bytes(6));
mkdir($dir,0700);
$c=['data_dir'=>$dir,'base_url'=>'https://over.rulez.jp'.($argv[1]??'/card')];
$db=new PDO('sqlite:'.$dir.'/app.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(dirname(__DIR__).'/private/schema.sql'));
$db=database($c);
function callApi(string $user,string $action,string $method='GET',array $body=[],array $params=[]): array {
    global $db,$c;
    return api($db,$c,$user,$action,$method,$body,$params);
}
foreach(['A','B','C'] as $u) {
    $s=callApi($u,'state');
    $s['state']['me']['name']='投稿者'.$u;
    callApi($u,'state','PUT',['state'=>$s['state'],'revision'=>0]);
}
$a=callApi('A','state');
$b=callApi('B','state');
$cid=callApi('C','state')['id'];
check($a['id']!==$b['id'],'independent identities');
rejected(fn()=>callApi('A','state','PUT',['revision'=>0,'state'=>$a['state']]),409,'optimistic lock');
$t=callApi('A','ticket','POST');
parse_str(parse_url($t['url'],PHP_URL_QUERY),$params);
$token=$params['exchange'];
check(str_starts_with($t['url'],$c['base_url'].'/?exchange='),'ticket contains install path');
rejected(fn()=>callApi('A','preview','GET',[],['token'=>$token]),400,'self exchange rejected');
check(callApi('B','preview','GET',[],['token'=>$token])['profile']['name']==='投稿者A','preview profile');
check(count(callApi('A','requests')['requests'])===0,'preview creates no request');
callApi('B','exchange','POST',['token'=>$token]);
callApi('B','exchange','POST',['token'=>$token]);
$requests=callApi('A','requests')['requests'];
check(count($requests)===1,'idempotent request');
$rid=$requests[0]['id'];
rejected(fn()=>callApi('C','approve','POST',['id'=>$rid]),404,'only owner approves');
callApi('A','approve','POST',['id'=>$rid]);
callApi('A','approve','POST',['id'=>$rid]);
$a=callApi('A','state');
$b=callApi('B','state');
check(count($a['state']['contacts'])===1&&count($b['state']['contacts'])===1,'mutual connection');
check(count(callApi('C','state')['state']['contacts'])===0,'unrelated account isolated');
$a['state']['contacts'][0]['history'][0]['memo']='Aだけの秘密';
$a['state']['contacts'][0]['history'][0]['gps']=['lat'=>35.1,'lng'=>139.2,'accuracy'=>5,'acquiredAt'=>'2026-11-05'];
$a['state']['me']['name']='改名A';
$a['state']['me']['template']='ridge';
$a['state']['me']['image']='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV0cAAAAASUVORK5CYII=';
$saved=callApi('A','state','PUT',['revision'=>$a['revision'],'state'=>$a['state']]);
check(str_starts_with($saved['profile']['image'],appPath($c).'/api/image?key='),'image URL contains install path');
check($saved['profile']['template']==='ridge','template preserved');
parse_str(parse_url($saved['profile']['image'],PHP_URL_QUERY),$ip);
check(is_file(callApi('A','image','GET',[],$ip)['file']),'image persisted');
check(is_file(callApi('B','image','GET',[],$ip)['file']),'connected user reads image');
rejected(fn()=>callApi('C','image','GET',[],$ip),403,'image privacy');
rejected(fn()=>callApi('A','image','GET',[],['key'=>'../../config.php']),404,'path traversal rejected');
$b=callApi('B','state');
check($b['state']['contacts'][0]['name']==='改名A'&&$b['state']['contacts'][0]['history'][0]['name']==='投稿者A','current and historical names');
check(!str_contains(encode($b),'Aだけの秘密'),'private memo');
check($b['state']['contacts'][0]['history'][0]['gps']===null,'private GPS');
$a=callApi('A','state');
$a['state']['me']['image']='data:image/png;base64,aGVsbG8=';
rejected(fn()=>callApi('A','state','PUT',['revision'=>$a['revision'],'state'=>$a['state']]),400,'fake image rejected');
$a=callApi('A','state');
$a['state']['contacts'][0]['history'][0]['gps']['lat']=999;
rejected(fn()=>callApi('A','state','PUT',['revision'=>$a['revision'],'state'=>$a['state']]),400,'GPS range');
$b['state']['me']['image']=$saved['profile']['image'];
rejected(fn()=>callApi('B','state','PUT',['revision'=>$b['revision'],'state'=>$b['state']]),400,'cannot claim another image');
check(callApi('B','exchange','POST',['token'=>$token])['alreadyConnected'],'repeat meeting');
callApi('C','exchange','POST',['token'=>$token]);
$req=array_values(array_filter(callApi('A','requests')['requests'],fn($r)=>$r['status']==='pending'))[0];
callApi('A','approve','POST',['id'=>$req['id'],'accept'=>false]);
rejected(fn()=>callApi('C','image','GET',[],$ip),403,'declined request has no image access');
query($db,'UPDATE tickets SET expires=0');
rejected(fn()=>callApi('C','preview','GET',[],['token'=>$token]),400,'expired QR');
// Test anti-CSRF independently of OAuth credentials.
require dirname(__DIR__).'/private/src/bootstrap.php';
$_SESSION=['csrf'=>'token'];
$_SERVER['HTTP_ORIGIN']=appOrigin($c);
$_SERVER['HTTP_X_CSRF_TOKEN']='token';
mutationGuard($c);
$_SERVER['HTTP_ORIGIN']='https://evil.example';
rejected(fn()=>mutationGuard($c),403,'cross-origin blocked');
$_SERVER['HTTP_ORIGIN']=appOrigin($c);
$_SERVER['HTTP_X_CSRF_TOKEN']='wrong';
rejected(fn()=>mutationGuard($c),403,'CSRF token checked');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $f) {
    $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
}
rmdir($dir);
echo "All PHP API checks passed.\n";
