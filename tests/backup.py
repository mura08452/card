"""CLI initialization, backup and restore verification in an isolated copy."""
import json, os, pathlib, shutil, sqlite3, subprocess, sys, tempfile
php=sys.argv[1] if len(sys.argv)>1 else 'php'
source=pathlib.Path(__file__).resolve().parents[1]/'private'
with tempfile.TemporaryDirectory(prefix='trail-backup-') as tmp:
    p=pathlib.Path(tmp)/'private';shutil.copytree(source,p,ignore=shutil.ignore_patterns('config.php','data'))
    (p/'config.php').write_text("<?php return ['base_url'=>'https://over.rulez.jp/card','data_dir'=>__DIR__.'/data','identity_key'=>'"+'a'*64+"'];")
    subprocess.run([php,str(p/'bin/init.php')],check=True)
    db=sqlite3.connect(p/'data/app.sqlite')
    key='a-b/image/c-d';image=p/'data/images'/key;image.parent.mkdir(parents=True);image.write_bytes(b'backup fixture')
    db.execute('INSERT INTO accounts VALUES(?,?,?,?,0)',('a-b','identity',json.dumps({'image':'/card/api/image?key=a-b%2Fimage%2Fc-d','icon':''}),'{}'));db.commit();db.close()
    subprocess.run([php,str(p/'bin/init.php')],check=True)
    subprocess.run([php,str(p/'bin/backup.php')],check=True)
    snap=next((p/'data/backups').iterdir())
    assert (snap/'images'/key).read_bytes()==b'backup fixture'
    assert (snap/'config.php').read_bytes()==(p/'config.php').read_bytes()
    shutil.copy(snap/'app.sqlite',p/'data/restored.sqlite')
    restored=sqlite3.connect(p/'data/restored.sqlite')
    assert restored.execute('PRAGMA integrity_check').fetchone()==('ok',)
    assert restored.execute('SELECT auth_id FROM accounts').fetchone()==('identity',)
    restored.close()
    print('PASS initialization is idempotent; consistent DB/image/config backup and SQLite restore')
