<?php
declare(strict_types=1);
// 設置時、private フォルダーの絶対パスへ変更してください。
$private = dirname(__DIR__).'/private';
require $private.'/src/bootstrap.php';
securityHeaders();
try {
    $c=config();
    sessionBoot($c);
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $method=$_SERVER['REQUEST_METHOD'];
    $base=appPath($c);
    if($base!==''&&!str_starts_with($path,$base.'/'))fail('ページが見つかりません',404);
    $path=substr($path,strlen($base));
    require_once $private.'/src/guest.php';
    $db=database($c);
    if(empty($_SESSION['identity']))deviceRestore($db,$c);
    if($method==='GET'&&in_array($path,['/','/index.php','/api/session'],true))guestStart($db,$c);
    if(str_starts_with($path,'/auth/')) {
        require $private.'/src/auth.php';
        auth($c,substr($path,6));
    }
    if($path==='/api/session') {
        if($method!=='GET')fail('GETが必要です');
        require $private.'/src/auth.php';
        header('Content-Type: application/json');
        echo encode(['authenticated'=>isset($_SESSION['identity']),'csrf'=>$_SESSION['csrf'],'providers'=>array_keys(providers($c)),'linked'=>linkedProviders($db,$_SESSION['identity'])]);
        exit;
    }
    if(str_starts_with($path,'/api/')) {
        if(empty($_SESSION['identity']))fail('ログインが必要です',401);
        $body=[];
        if($method!=='GET') {
            mutationGuard($c);
            if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))fail('JSON形式が必要です');
            $raw=file_get_contents('php://input',false,null,0,6500001);
            if(strlen($raw)>6500000)fail('データ容量が上限を超えています',413);
            $body=json_decode($raw,true);
            if(!is_array($body))fail('JSON形式が不正です');
        }
        $identity=$_SESSION['identity'];
        session_write_close();
        $result=api(database($c),$c,$identity,substr($path,5),$method,$body,$_GET);
        if(isset($result['file'])) {
            header('Content-Type: '.$result['mime']);
            readfile($result['file']);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo encode($result);
        exit;
    }
    if($path!=='/'&&$path!=='/index.php')fail('ページが見つかりません',404);
    session_write_close();
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/shell.html');
}
catch(Throwable $e) {
    errorResponse($e);
}
