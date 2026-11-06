<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/src/core.php';
require dirname(__DIR__).'/private/src/auth.php';
function verify(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}
$c=['base_url'=>'https://over.rulez.jp/card','providers'=>['google'=>['client_id'=>'test','client_secret'=>'test'],'x'=>['client_id'=>'','client_secret'=>'']]];
if(($argv[1]??'')==='login') {
    $_GET=['provider'=>'google','exchange'=>$argv[2]??''];
    $_SERVER['REQUEST_METHOD']='GET';
    $_SESSION=['identity'=>'guest:test','csrf'=>'csrf'];
    $_GET['csrf']='csrf';
    register_shutdown_function(function() {
        echo encode($_SESSION);
    }
    );
    auth($c,'login');
}
verify(array_keys(providers($c))===['google'],'unconfigured provider hidden');
function rejectedAuth(array $flow,array $params,string $label): void {
    global $c;
    $_SESSION=['oauth'=>$flow];
    $_GET=$params;
    $_SERVER['REQUEST_METHOD']='GET';
    try {
        auth($c,'callback');
    }
    catch(RuntimeException $e) {
        verify($e->getCode()===400,$label);
        verify(!isset($_SESSION['oauth']),'OAuth state consumed');
        return;
    }
    throw new RuntimeException('unexpected success');
}
$flow=['state'=>'expected','provider'=>'google','verifier'=>'verifier','time'=>time(),'return'=>'/'];
rejectedAuth($flow,['provider'=>'google','state'=>'wrong','code'=>'dummy'],'mismatched state rejected');
rejectedAuth(array_merge($flow,['time'=>time()-601]),['provider'=>'google','state'=>'expected','code'=>'dummy'],'expired login rejected');
rejectedAuth($flow,['provider'=>'google','state'=>'expected','error'=>'access_denied'],'cancelled login rejected');
rejectedAuth(array_merge($flow,['provider'=>'x']),['provider'=>'google','state'=>'expected','code'=>'dummy'],'provider mix-up rejected');
function loginFlow(string $exchange): array {
    $command=[PHP_BINARY,'-n',__FILE__,'login',$exchange];
    $proc=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);
    $err=stream_get_contents($pipes[2]);
    foreach($pipes as $p)fclose($p);
    if(proc_close($proc)!==0)throw new RuntimeException($err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR)['oauth'];
}
$a=loginFlow(str_repeat('a',64));
$b=loginFlow('https://evil.example');
verify($a['return']==='/?exchange='.str_repeat('a',64),'exchange retained through login');
verify($b['return']==='/','external return URL rejected');
verify(strlen($a['state'])===64&&strlen($a['verifier'])===64&&$a['state']!==$b['state']&&$a['verifier']!==$b['verifier'],'random state and PKCE verifier');
echo "Auth checks passed; live provider callbacks require registered credentials.\n";
