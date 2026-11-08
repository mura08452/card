"""Exercise installation in isolated paths; never connects to a server."""
import pathlib
import shutil
import subprocess
import sys
import tempfile

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
source = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='trail-deploy-') as tmp:
    root = pathlib.Path(tmp)
    private = root / 'private'
    public = root / 'www/card'
    release = root / 'release'
    shutil.copytree(source / 'private', private)
    shutil.copytree(source / 'public', public)
    shutil.copytree(source, release, ignore=shutil.ignore_patterns('.git'))
    config = "<?php return ['mysql'=>require '" + str(pathlib.Path(__file__).resolve().with_name('mysql.php')) + "','base_url'=>'https://over.rulez.jp/card','data_dir'=>__DIR__.'/data','identity_key'=>'" + 'c' * 64 + "'];"
    (private / 'config.php').write_text(config)
    subprocess.run([php, str(private / 'bin/init.php')], check=True)
    (public / 'keep.txt').write_text('preserve')
    (root / 'www/other-site.txt').write_text('untouched')
    command = [php, str(release / 'deploy/install.php'), str(private), str(public), 'a' * 40]
    subprocess.run(command, check=True)
    assert (private / 'config.php').read_text() == config
    assert (public / 'keep.txt').read_text() == 'preserve'
    assert (root / 'www/other-site.txt').read_text() == 'untouched'
    assert str(private) in (public / 'index.php').read_text()
    assert (public / '.htaccess').read_bytes() == (source / 'public/.htaccess').read_bytes()
    assert not (private / '.deploy-pending').exists()
    assert list((private / 'data/backups').iterdir())
    assert list((private / 'deploy-backups').iterdir())
    # Migration failure must retain maintenance mode and stop subsequent deploys.
    (release / 'private/bin/migrate.php').write_text('<?php exit(1);')
    assert subprocess.run(command).returncode != 0
    assert 'R=503' in (public / '.htaccess').read_text()
    assert (private / '.deploy-pending').exists()
    before = len(list((private / 'deploy-backups').iterdir()))
    assert subprocess.run(command).returncode != 0
    assert len(list((private / 'deploy-backups').iterdir())) == before
    print('PASS deployment: paths, config/data preservation, backups and fail-closed maintenance')
