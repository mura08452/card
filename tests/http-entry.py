"""Exercise real PHP entrypoint with synthetic CGI globals, no network server."""
import json,pathlib,shutil,subprocess,sys,tempfile,time,hashlib
php=sys.argv[1] if len(sys.argv)>1 else 'php'
base=sys.argv[2] if len(sys.argv)>2 else '/card'
cookie_name='__Secure-trailcard-'+hashlib.sha256(base.encode()).hexdigest()[:12]
source=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='trail-entry-') as tmp:
    app=pathlib.Path(tmp)/'app';shutil.copytree(source,app,ignore=shutil.ignore_patterns('config.php','data'))
    private=app/'private';public=app/'public'
    (private/'config.php').write_text("<?php return ['base_url'=>'https://over.rulez.jp"+base+"','data_dir'=>__DIR__.'/data','identity_key'=>'"+'b'*64+"','providers'=>['google'=>['client_id'=>'fixture','client_secret'=>'fixture']]];")
    subprocess.run([php,str(private/'bin/init.php')],check=True,stdout=subprocess.DEVNULL)
    def request(path,method='GET',cookie=None,headers=None,absolute=False):
        server={'REQUEST_URI':path if absolute else base+path,'REQUEST_METHOD':method,'DOCUMENT_ROOT':str(public),'HTTP_HOST':'over.rulez.jp','HTTPS':'on'};server.update(headers or {})
        payload=json.dumps({'server':server,'cookie':{cookie_name:cookie} if cookie else {},'entry':str(public/'index.php')})
        code='''$in=json_decode(stream_get_contents(STDIN),true);$_SERVER=$in['server'];$_COOKIE=$in['cookie'];$_GET=[];parse_str(parse_url($_SERVER['REQUEST_URI'],PHP_URL_QUERY)??'',$_GET);register_shutdown_function(function(){fwrite(STDERR,json_encode(['status'=>http_response_code()?:200,'cookie'=>session_get_cookie_params()]));});require $in['entry'];'''
        r=subprocess.run([php,'-r',code],input=payload,text=True,capture_output=True,check=True)
        meta=json.loads(r.stderr)
        assert meta['cookie']['path']==base+'/' and meta['cookie']['secure'] and meta['cookie']['httponly']
        return meta['status'],r.stdout
    if base:
        assert request('/api/state',absolute=True)[0]==404
        assert request(base+'inal/api/state',absolute=True)[0]==404
    assert request('/api/state')[0]==401
    assert request('/api/state',headers={'HTTP_OAI_AUTHENTICATED_USER_ID':'forged'})[0]==401
    status,body=request('/api/session');assert status==200 and json.loads(body)['authenticated'] and json.loads(body)['linked']==[]
    sid='a'*32
    session=private/'data/sessions'/('sess_'+sid)
    session.write_text('identity|s:1:"A";csrf|s:5:"token";expires|i:'+str(int(time.time())+300)+';')
    status,body=request('/api/state',cookie=sid);assert status==200 and json.loads(body)['revision']==0
    assert request('/api/state','PUT',sid,{'HTTP_ORIGIN':'https://evil.example','HTTP_X_CSRF_TOKEN':'token'})[0]==403
    assert request('/api/state','PUT',sid,{'HTTP_ORIGIN':'https://over.rulez.jp','HTTP_X_CSRF_TOKEN':'wrong'})[0]==403
    assert request('/')[0]==200
    assert request('/auth/logout','POST',sid,{'HTTP_ORIGIN':'https://over.rulez.jp','HTTP_X_CSRF_TOKEN':'token'})[0]==200
    assert request('/api/state',cookie=sid)[0]==401
    assert request('/auth/callback?provider=google&state=wrong&code=fake')[0]==400
    print('PASS PHP entrypoint: login required, forged header ignored, session API, CSRF and logout invalidation')
