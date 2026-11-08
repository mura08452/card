<?php
declare(strict_types=1);
require_once __DIR__.'/guest.php';
require_once __DIR__.'/google.php';
function providers(array $c): array {
    $configured = array_intersect_key($c['providers'] ?? [], array_flip(['google', 'x']));
    return array_filter($configured, function ($provider): bool {
        return is_array($provider) &&
            is_string($provider['client_id'] ?? null) && trim($provider['client_id']) !== '' &&
            is_string($provider['client_secret'] ?? null) && trim($provider['client_secret']) !== '';
    });
}

function remoteJson(string $url, ?array $post=null,array $headers=[]): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);
    if($post!==null) {
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));
    }
    $raw=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw===false||$status!==200)fail('外部サービスでログインを確認できませんでした',400);
    $r=json_decode($raw,true);
    if(!is_array($r))fail('認証サービスの応答が不正です');
    return $r;
}

function auth(array $c,string $action): never {
    if($action==='logout') {
        if($_SERVER['REQUEST_METHOD']!=='POST')fail('POSTが必要です');
        mutationGuard($c);
        $token=$_COOKIE[deviceCookie($c)]??'';
        query(database($c),'DELETE FROM devices WHERE hash=?',[hash('sha256',$token)]);
        setcookie(deviceCookie($c),'',['expires'=>1,'path'=>appPath($c).'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        $_SESSION=[];
        session_destroy();
        setcookie(session_name(),'',['expires'=>1,'path'=>appPath($c).'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Content-Type: application/json');
        echo '{}';
        exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')fail('GETが必要です');
    $provider=$_GET['provider']??'';
    if(!isset(providers($c)[$provider]))fail('このログイン方法は設定されていません',503);
    $p=$c['providers'][$provider];
    $redirect=$c['base_url'].'/auth/callback?provider='.$provider;
    if($action==='login') {
        $mode=($_GET['mode']??'link')==='recover'?'recover':'link';
        if(empty($_SESSION['identity']))fail('先にトップページを開いてください',401);
        if(!hash_equals($_SESSION['csrf'],$_GET['csrf']??''))fail('画面を再読み込みしてください',403);
        $state=bin2hex(random_bytes(32));
        $verifier=bin2hex(random_bytes(32));
        $nonce=bin2hex(random_bytes(32));
        $return='/';
        if(preg_match('/^[a-f0-9-]{32,100}$/D',$_GET['exchange']??''))$return='/?exchange='.$_GET['exchange'];
        $_SESSION['oauth']=['state'=>$state,'verifier'=>$verifier,'provider'=>$provider,'time'=>time(),'return'=>$return,'mode'=>$mode,'current'=>$_SESSION['identity'],'nonce'=>$nonce];
        $args=['response_type'=>'code','client_id'=>$p['client_id'],'redirect_uri'=>$redirect,'scope'=>$provider==='google'?'openid':'users.read tweet.read','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
        if ($provider === 'google') {
            $args['nonce'] = $nonce;
            $args['prompt'] = 'select_account';
        }
        $url=$provider==='google'?'https://accounts.google.com/o/oauth2/v2/auth':'https://x.com/i/oauth2/authorize';
        header('Location: '.$url.'?'.http_build_query($args),true,302);
        exit;
    }
    if($action!=='callback')fail('操作が見つかりません',404);
    $flow=$_SESSION['oauth']??null;
    unset($_SESSION['oauth']);
    if(!$flow||$flow['provider']!==$provider||time()-$flow['time']>600||!is_string($_GET['state']??null)||!hash_equals($flow['state'],$_GET['state'])||empty($_GET['code']))fail('ログインが期限切れまたはキャンセルされました。もう一度ログインしてください');
    if (($flow['current'] ?? '') !== ($_SESSION['identity'] ?? '')) {
        fail('アカウントが変わりました。最初からやり直してください', 409);
    }
    $fields=['grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$redirect,'client_id'=>$p['client_id'],'code_verifier'=>$flow['verifier']];
    $headers=[];
    if($provider==='google')$fields['client_secret']=$p['client_secret'];
    else $headers[]='Authorization: Basic '.base64_encode($p['client_id'].':'.$p['client_secret']);
    $token=remoteJson($provider==='google'?'https://oauth2.googleapis.com/token':'https://api.x.com/2/oauth2/token',$fields,$headers);
    if(empty($token['access_token']))fail('認証に失敗しました');
    if ($provider === 'google') {
        $subject = googleSubject($c, $p, $flow, $token);
    } else {
        $info = remoteJson('https://api.x.com/2/users/me', null, ['Authorization: Bearer ' . $token['access_token']]);
        $subject = $info['data']['id'] ?? '';
    }
    if(!is_string($subject)||$subject==='')fail('認証に失敗しました');
    // OAuth tokens, name and email are never persisted. HMAC key must survive backups.
    $subjectKey=hash_hmac('sha256',$provider.':'.$subject,$c['identity_key']);
    $db=database($c);
    if(($flow['current']??'')!==($_SESSION['identity']??''))fail('アカウントが変わりました。最初からやり直してください',409);
    if(($flow['mode']??'link')==='recover') {
        $existing=one($db,'SELECT auth_id FROM identities WHERE subject=?',[$subjectKey]);
        if(!$existing)fail('この外部アカウントに連携された名刺はありません。設定から連携してください');
        $identity=$existing['auth_id'];
    }
    else {
        $identity=$flow['current'];
        linkIdentity($db,$subjectKey,$provider,$identity);
    }
    deviceIssue($db,$c,$identity);
    session_regenerate_id(true);
    $_SESSION=['identity'=>$identity,'provider'=>$provider,'expires'=>time()+604800,'csrf'=>bin2hex(random_bytes(32))];
    header('Location: '.$c['base_url'].$flow['return'],true,303);
    exit;
}
