<?php
declare(strict_types=1);
function deviceCookie(array $c): string {
    return '__Secure-traildevice-'.substr(hash('sha256',appPath($c)),0,12);
}

function migrateIdentity(PDO $db): void {
    $db->exec("INSERT INTO identities (subject, auth_id, provider)
        SELECT a.auth_id, a.auth_id, 'legacy' FROM accounts a
        WHERE a.auth_id NOT LIKE 'guest:%'
        ON DUPLICATE KEY UPDATE provider=identities.provider");
}

function deviceIssue(PDO $db,array $c,string $identity): void {
    $old=$_COOKIE[deviceCookie($c)]??'';
    if(is_string($old)&&$old!=='')query($db,'DELETE FROM devices WHERE hash=?',[hash('sha256',$old)]);
    $token=bin2hex(random_bytes(32));
    $expires=time()+180*86400;
    query($db,'INSERT INTO devices VALUES(?,?,?)',[hash('sha256',$token),$identity,$expires]);
    setcookie(deviceCookie($c),$token,['expires'=>$expires,'path'=>appPath($c).'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    $_COOKIE[deviceCookie($c)]=$token;
    $_SESSION['identity']=$identity;
    $_SESSION['expires']=$expires;
}

function deviceRestore(PDO $db,array $c): bool {
    $token=$_COOKIE[deviceCookie($c)]??'';
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
    $row=one($db,'SELECT auth_id FROM devices WHERE hash=? AND expires>?',[hash('sha256',$token),time()]);
    if(!$row)return false;
    $_SESSION['identity']=$row['auth_id'];
    $_SESSION['expires']=time()+604800;
    return true;
}

function guestStart(PDO $db,array $c): void {
    if(!empty($_SESSION['identity'])) {
        if(empty($_COOKIE[deviceCookie($c)]))deviceIssue($db,$c,$_SESSION['identity']);
        return;
    }
    if(deviceRestore($db,$c))return;
    $identity='guest:'.bin2hex(random_bytes(32));
    account($db,$identity);
    session_regenerate_id(true);
    deviceIssue($db,$c,$identity);
}

function linkedProviders(PDO $db,string $identity): array {
    return array_column(query($db,'SELECT DISTINCT provider FROM identities WHERE auth_id=?',[$identity])->fetchAll(),'provider');
}

function linkIdentity(PDO $db,string $subject,string $provider,string $current): void {
    $db->beginTransaction();
    try {
        query($db, 'INSERT INTO identities VALUES(?,?,?) ON DUPLICATE KEY UPDATE subject=subject',
            [$subject, $current, $provider]);
        $row=one($db,'SELECT auth_id FROM identities WHERE subject=? FOR UPDATE',[$subject]);
        if($row&&$row['auth_id']!==$current)fail('この外部アカウントは別の名刺アカウントに連携済みです。設定の「以前のアカウントを使う」から切り替えてください。自動統合は行いません。',409);
        query($db,'UPDATE identities SET provider=? WHERE subject=?',[$provider,$subject]);
        $db->exec('COMMIT');
    }
    catch(Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}
