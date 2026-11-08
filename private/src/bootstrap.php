<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function config(): array {
    $path=dirname(__DIR__).'/config.php';
    if(!is_file($path))fail('管理者による初期設定が必要です',503);
    $c=require $path;
    if(!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]+)?(?:/[a-zA-Z0-9_-]+)*$~D',$c['base_url']??'')||!preg_match('/^[a-f0-9]{64}$/D',$c['identity_key']??''))fail('管理者による設定の確認が必要です',503);
    $data=realpath($c['data_dir']);
    $public=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if(!$data||($public&&($data===$public||str_starts_with($data,$public.'/'))))fail('保存先は公開ディレクトリーの外に設定してください',503);
    return $c;
}

function sessionBoot(array $c): void {
    session_name('__Secure-trailcard-'.substr(hash('sha256',appPath($c)),0,12));
    session_save_path($c['data_dir'].'/sessions');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.gc_maxlifetime','604800');
    session_set_cookie_params(['lifetime'=>604800,'path'=>appPath($c).'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if(isset($_SESSION['expires'])&&$_SESSION['expires']<time())$_SESSION=[];
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
}

function mutationGuard(array $c): void {
    if(($_SERVER['HTTP_ORIGIN']??'')!==appOrigin($c)||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('送信元を確認できません。再読み込みしてください',403);
}

function securityHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
}

function errorResponse(Throwable $e): never {
    $status=($e instanceof RuntimeException&&in_array($e->getCode(),[400,401,403,404,409,413,429,503]))?$e->getCode():500;
    if($e instanceof PDOException&&in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true))$status=503;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo encode(['message'=>$status<500?$e->getMessage():($status===503?'初期設定または一時的な混雑のため利用できません。時間をおいて再試行してください。':'処理に失敗しました。時間をおいて再試行してください。')]);
    exit;
}
