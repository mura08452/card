"""Round trip a legacy fixture through MySQL migration, backup and restoration."""
import json
import os
import pathlib
import shutil
import sqlite3
import subprocess
import sys
import tempfile

php = sys.argv[1]
tests = pathlib.Path(__file__).resolve().parent
mysql = ['mysql', '-h', os.getenv('MYSQL_HOST', '127.0.0.1'), '-u',
         os.getenv('MYSQL_USER', 'root'), '-NB', os.environ['CARD_TEST_DATABASE']]
with tempfile.TemporaryDirectory(prefix='trail-backup-') as tmp:
    p = pathlib.Path(tmp) / 'private'
    shutil.copytree(tests.parent / 'private', p)
    (p / 'config.php').write_text(
        f"<?php return ['mysql'=>require '{tests}/mysql.php','base_url'=>'https://over.rulez.jp/card',"
        "'data_dir'=>__DIR__.'/data'];")
    key = 'a-b/image/c-d'
    image = p / 'data/images' / key
    image.parent.mkdir(parents=True)
    image.write_bytes(b'backup fixture')
    profile = json.dumps({'name': "瓢箪屋🏕'\\", 'image': '/card/api/image?key=a-b%2Fimage%2Fc-d'}, ensure_ascii=False)
    with sqlite3.connect(p / 'data/app.sqlite') as old:
        old.executescript((tests / 'legacy-schema.sql').read_text())
        old.executescript('CREATE TABLE identities(subject TEXT,auth_id TEXT,provider TEXT);'
                          'CREATE TABLE devices(hash TEXT,auth_id TEXT,expires INTEGER);')
        old.execute('INSERT INTO accounts VALUES(?,?,?,?,?)', ('a-b', 'guest:A', profile, '{}', 3))
        old.execute('INSERT INTO accounts VALUES(?,?,?,?,?)', ('b-c', 'guest:B', '{}', '{}', 1))
        old.execute("INSERT INTO tickets VALUES('ticket','a-b',2000000000000)")
        old.execute("INSERT INTO exchange_requests VALUES('r','ticket','a-b','b-c','approved','2026')")
        old.execute("INSERT INTO connections VALUES('c','a-b','b-c','2026','甲','乙')")
        old.execute("INSERT INTO identities VALUES('subject','guest:A','google')")
        old.execute("INSERT INTO devices VALUES('hash','guest:A',2000000000)")
    for _ in range(2):
        subprocess.run([php, p / 'bin/init.php'], check=True)
    subprocess.run([php, p / 'bin/backup.php'], check=True)
    snap, = (p / 'data/backups').iterdir()
    assert (snap / 'images' / key).read_bytes() == image.read_bytes()
    assert (snap / 'config.php').read_bytes() == (p / 'config.php').read_bytes()
    tables = ['devices', 'identities', 'connections', 'exchange_requests', 'tickets', 'accounts', 'card_storage_version']
    subprocess.run(mysql + ['-e', ';'.join('DROP TABLE ' + t for t in tables)], check=True)
    with (snap / 'data.sql').open('rb') as dump:
        subprocess.run(mysql, stdin=dump, check=True)
    def select(sql):
        return subprocess.check_output(mysql + ['-e', sql], text=True).strip()
    assert bytes.fromhex(select("SELECT HEX(profile) FROM accounts WHERE id='a-b'")).decode() == profile
    assert [int(select('SELECT COUNT(*) FROM ' + t)) for t in tables] == [1, 1, 1, 1, 1, 2, 1]
    assert select('SELECT expires FROM tickets') == '2000000000000'
    print('PASS SQLite to MySQL migration, idempotent retry, MySQL restore and referenced images')
