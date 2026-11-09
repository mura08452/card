<?php
declare(strict_types=1);
function fail(string $message, int $status=400): never {
    throw new RuntimeException($message,$status);
}

function encode(mixed $v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function uid(): string {
    $h=bin2hex(random_bytes(16));
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function txt(mixed $v,int $n=300): string {
    if(!is_string($v))return '';
    if($n>65535)return substr($v,0,$n);
    preg_match('/\A.{0,'.$n.'}/us',$v,$m);
    return $m[0]??'';
}

function appPath(array $c): string {
    return (string)(parse_url($c['base_url'],PHP_URL_PATH) ?: '');
}

function appOrigin(array $c): string {
    return substr($c['base_url'],0,strlen($c['base_url'])-strlen(appPath($c)));
}

function imagePrefix(array $c): string {
    return appPath($c).'/api/image?key=';
}

function emptyState(): array {
    return ['me'=>array_merge(array_fill_keys(['name','intro','genres','links','videos','icon','image'],''),['template'=>'forest','cardMode'=>'template']),'contacts'=>[],'event'=>'','autoGPS'=>false];
}

function database(array $c): PDO {
    $mysql = $c['mysql'] ?? [];
    foreach (['host', 'database', 'user', 'password'] as $field) {
        if (!is_string($mysql[$field] ?? null) || $mysql[$field] === '') {
            fail('MySQLの接続設定が必要です', 503);
        }
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $mysql['host'], $mysql['port'] ?? 3306, $mysql['database']);
    $db = new PDO($dsn, $mysql['user'], $mysql['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}

function query(PDO $db,string $sql,array $args=[]): PDOStatement {
    $s=$db->prepare($sql);
    $s->execute($args);
    return $s;
}

function one(PDO $db,string $sql,array $args=[]): array|false {
    return query($db,$sql,$args)->fetch();
}

function account(PDO $db,string $identity): array {
    $a=one($db,'SELECT * FROM accounts WHERE auth_id=?',[$identity]);
    if($a)return $a;
    $e=emptyState();
    query($db,'INSERT INTO accounts VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE auth_id=auth_id',[uid(),$identity,encode($e['me']),encode($e)]);
    return one($db,'SELECT * FROM accounts WHERE auth_id=?',[$identity]);
}

function history(mixed $h): array {
    if(!is_array($h))fail('記録の形式が不正です');
    $gps=null;
    if(!empty($h['gps'])) {
        $g=$h['gps'];
        foreach(['lat','lng','accuracy'] as $k)if(!isset($g[$k])||!is_numeric($g[$k])||!is_finite((float)$g[$k]))fail('位置情報が不正です');
        if(abs($g['lat'])>90||abs($g['lng'])>180||$g['accuracy']<0)fail('位置情報が不正です');
        $gps=['lat'=>(float)$g['lat'],'lng'=>(float)$g['lng'],'accuracy'=>(float)$g['accuracy'],'acquiredAt'=>txt($g['acquiredAt']??'',40)];
    }
    $r=[];
    foreach(['id'=>100,'date'=>40,'name'=>50,'place'=>200,'memo'=>3000] as $k=>$n)$r[$k]=txt($h[$k]??'',$n);
    $r['gps']=$gps;
    return $r;
}

function profile(mixed $v): array {
    if(!is_array($v))fail('名刺の形式が不正です');
    $p=[];
    foreach(['name'=>50,'intro'=>300,'genres'=>200,'links'=>4000,'videos'=>4000,'icon'=>2800000,'image'=>2800000] as $k=>$n) {
        $p[$k]=txt($v[$k]??'',$n);
        if(in_array($k,['links','videos']))foreach(explode("\n",$p[$k]) as $line)if(trim($line)!==''&&!preg_match('~^https?://~i',trim($line)))fail('リンクはhttp(s)のURLを指定してください');
    }
    $p['name']=trim($p['name']);
    if(!$p['name'])fail('ハンドルネームを入力してください');
    $p['template']=in_array($v['template']??'',['forest','ridge','simple'])?$v['template']:'forest';
    $p['cardMode']=in_array($v['cardMode']??'',['template','upload'])?$v['cardMode']:($p['image']?'upload':'template');
    return $p;
}
// Every write is a short transaction. Files created by a rejected write are removed.
function api(PDO $db,array $c,string $identity,string $action,string $method,array $body=[],array $params=[]): array {
    $a=account($db,$identity);
    $id=$a['id'];
    $rows=query($db,'SELECT * FROM connections WHERE left_id=? OR right_id=?',[$id,$id])->fetchAll();
    if($action==='state'&&$method==='GET') {
        $state=json_decode($a['state'],true);
        $contacts=[];
        foreach($rows as $r) {
            $other=$r['left_id']===$id?$r['right_id']:$r['left_id'];
            $p=json_decode(one($db,'SELECT profile FROM accounts WHERE id=?',[$other])['profile'],true);
            $old=null;
            foreach($state['contacts'] as $x)if($x['id']===$other)$old=$x;
            $contacts[]=array_merge($p,['id'=>$other,'synced'=>true,'history'=>$old['history']??[['id'=>'exchange-'.$r['id'],'date'=>$r['met_at'],'name'=>$r['left_id']===$other?$r['left_name']:$r['right_name'],'place'=>'','memo'=>'','gps'=>null]]]);
        }
        $state['me']=json_decode($a['profile'],true);
        $state['contacts']=$contacts;
        return ['id'=>$id,'revision'=>(int)$a['revision'],'state'=>$state];
    }
    if($action==='image'&&$method==='GET') {
        $key=$params['key']??'';
        if(!preg_match('~^[a-f0-9-]+/(icon|image)/[a-f0-9-]+$~D',$key))fail('画像が見つかりません',404);
        $owner=explode('/',$key)[0];
        $ids=[$id,$owner];
        sort($ids);
        if($owner!==$id&&!one($db,'SELECT id FROM connections WHERE left_id=? AND right_id=?',$ids)&&!one($db,"SELECT id FROM exchange_requests WHERE status='pending' AND ((owner_id=? AND requester_id=?) OR (owner_id=? AND requester_id=?))",[$id,$owner,$owner,$id]))fail('アクセスできません',403);
        $path=$c['data_dir'].'/images/'.$key;
        if(!is_file($path))fail('画像が見つかりません',404);
        return ['file'=>$path,'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($path)];
    }
    if($action==='state'&&$method==='PUT') {
        if(!is_int($body['revision']??null))fail('更新番号が必要です');
        $p=profile($body['state']['me']??null);
        $created=[];
        $allowed=array_map(fn($r)=>$r['left_id']===$id?$r['right_id']:$r['left_id'],$rows);
        $contacts=[];
        foreach(($body['state']['contacts']??[]) as $contact)if(in_array($contact['id']??null,$allowed,true))$contacts[]=['id'=>$contact['id'],'history'=>array_map('history',array_slice($contact['history']??[],0,2000))];
        try {
            $db->beginTransaction();
            if((int)one($db,'SELECT revision FROM accounts WHERE id=? FOR UPDATE',[$id])['revision']!==$body['revision'])fail('別の端末で更新されました。書き出してから最新データを読み込んでください。',409);
            foreach(['icon','image'] as $kind) {
                $value=$p[$kind];
                if(!$value)continue;
                if(str_starts_with($value,'data:')) {
                    if(!preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D',$value,$m))fail('画像の形式が不正です');
                    $bytes=base64_decode($m[2],true);
                    if($bytes===false||strlen($bytes)>2000000)fail('画像は2MB以下にしてください');
                    $info=@getimagesizefromstring($bytes);
                    if(!$info||($info['mime']??'')!=='image/'.$m[1])fail('画像の内容が不正です');
                    $key=$id.'/'.$kind.'/'.uid();
                    $path=$c['data_dir'].'/images/'.$key;
                    if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
                    $created[]=$path;
                    if(file_put_contents($path,$bytes,LOCK_EX)===false)fail('画像を保存できません',503);
                    chmod($path,0600);
                    $p[$kind]=imagePrefix($c).rawurlencode($key);
                }
                else {
                    $prefix=imagePrefix($c);
                    if(!str_starts_with($value,$prefix))fail('画像の形式が不正です');
                    $key=rawurldecode(substr($value,strlen($prefix)));
                    if(!preg_match('~^'.preg_quote($id,'~').'/(icon|image)/[a-f0-9-]+$~D',$key)||!is_file($c['data_dir'].'/images/'.$key))fail('自分の画像を指定してください');
                }
            }
            $state=['me'=>$p,'contacts'=>$contacts,'event'=>txt($body['state']['event']??'',200),'autoGPS'=>(bool)($body['state']['autoGPS']??false)];
            if(strlen(encode($state))>1000000)fail('記録の容量が上限を超えています',413);
            query($db,'UPDATE accounts SET profile=?,state=?,revision=revision+1 WHERE id=? AND revision=?',[encode($p),encode($state),$id,$body['revision']]);
            $db->exec('COMMIT');
            return ['revision'=>$body['revision']+1,'profile'=>$p];
        }
        catch(Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            }
            catch(Throwable) {
            }
            foreach($created as $file)@unlink($file);
            throw $e;
        }
    }
    if($action==='ticket'&&$method==='POST') {
        if(!json_decode($a['profile'],true)['name'])fail('先に名刺を登録してください');
        $token=bin2hex(random_bytes(32));
        $expires=(int)(microtime(true)*1000)+900000;
        query($db,'INSERT INTO tickets VALUES(?,?,?)',[hash('sha256',$token),$id,$expires]);
        query($db,'DELETE FROM tickets WHERE owner_id=? AND expires<? AND hash NOT IN (SELECT ticket_hash FROM exchange_requests)',[$id,(int)(microtime(true)*1000)]);
        return ['url'=>$c['base_url'].'/?exchange='.$token,'expires'=>$expires];
    }
    if(($action==='preview'&&$method==='GET')||($action==='exchange'&&$method==='POST')) {
        $token=txt($action==='preview'?($params['token']??''):($body['token']??''),100);
        $hash=hash('sha256',$token);
        $t=one($db,'SELECT * FROM tickets WHERE hash=?',[$hash]);
        if(!$t||$t['expires']<microtime(true)*1000)fail('QRコードの有効期限が切れています');
        if($t['owner_id']===$id)fail('自分自身とは交換できません');
        $p=json_decode(one($db,'SELECT profile FROM accounts WHERE id=?',[$t['owner_id']])['profile'],true);
        $ids=[$id,$t['owner_id']];
        sort($ids);
        $connected=(bool)one($db,'SELECT id FROM connections WHERE left_id=? AND right_id=?',$ids);
        if($action==='preview')return [
            'profile'=>array_intersect_key($p,array_flip(['name','intro','genres'])),
            'alreadyConnected'=>$connected,
        ];
        if(!json_decode($a['profile'],true)['name'])fail('先に自分の名刺を登録してください');
        if($connected)return ['alreadyConnected'=>true,'id'=>$t['owner_id'],'profile'=>$p];
        query($db,'INSERT INTO exchange_requests VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[uid(),$hash,$t['owner_id'],$id,'pending',gmdate('Y-m-d\TH:i:s.000\Z')]);
        return ['profile'=>$p,'pending'=>true];
    }
    if($action==='requests'&&$method==='GET') {
        $rs=query($db,"SELECT r.*,a.profile FROM exchange_requests r JOIN accounts a ON a.id=CASE WHEN r.owner_id=? THEN r.requester_id ELSE r.owner_id END WHERE (r.owner_id=? OR r.requester_id=?) AND (r.status='pending' OR r.created>?) ORDER BY r.created DESC LIMIT 100",[$id,$id,$id,gmdate('Y-m-d\TH:i:s.000\Z',time()-86400)])->fetchAll();
        return ['requests'=>array_map(fn($r)=>['id'=>$r['id'],'status'=>$r['status'],'created'=>$r['created'],'incoming'=>$r['owner_id']===$id,'profile'=>json_decode($r['profile'],true)],$rs)];
    }
    if($action==='approve'&&$method==='POST') {
        $db->beginTransaction();
        try {
            $r=one($db,'SELECT * FROM exchange_requests WHERE id=? AND owner_id=? FOR UPDATE',[txt($body['id']??'',100),$id]);
            if(!$r)fail('申請が見つかりません',404);
            $status=$r['status'];
            if($status==='pending') {
                $status=($body['accept']??true)===false?'declined':'approved';
                if($status==='approved') {
                    $other=one($db,'SELECT profile FROM accounts WHERE id=?',[$r['requester_id']]);
                    $ids=[$id,$r['requester_id']];
                    sort($ids);
                    $names=array_map(fn($i)=>json_decode($i===$id?$a['profile']:$other['profile'],true)['name'],$ids);
                    query($db,'INSERT INTO connections VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[uid(),...$ids,$r['created'],...$names]);
                }
                query($db,'UPDATE exchange_requests SET status=? WHERE id=?',[$status,$r['id']]);
            }
            $db->exec('COMMIT');
            return ['status'=>$status];
        }
        catch(Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
    fail('操作が見つかりません',404);
}
