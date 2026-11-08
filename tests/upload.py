"""Exercise the SSH uploader against real PHP with stdin-consuming startup."""
import importlib.util
import pathlib
import shlex
import subprocess
import sys
import tempfile
from unittest.mock import patch

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
source = pathlib.Path(__file__).resolve().parents[1] / 'deploy/upload-release.py'
spec = importlib.util.spec_from_file_location('upload_release', source)
uploader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(uploader)
real_run = subprocess.run

with tempfile.TemporaryDirectory(prefix='trail-upload-') as temp:
    root = pathlib.Path(temp)
    archive = root / 'input.tgz'
    payload = bytes(range(256)) * 150
    archive.write_bytes(payload)
    stage = '.trail-card-deploy/123-1'
    destination = root / stage
    destination.mkdir(parents=True)
    part = destination / 'release.tgz.part'
    final = destination / 'release.tgz'

    def transport(args, **kwargs):
        assert args[:2] == ['ssh', '-n']
        assert kwargs['stdin'] == subprocess.DEVNULL
        assert len(args[-1]) < 32768
        assert 0 < kwargs['timeout'] <= 60
        # Reproduce a login startup script that consumes stdin before the command.
        return real_run(
            ['/bin/sh', '-c', 'cat >/dev/null; exec "$@"', 'sh', *shlex.split(args[-1])],
            cwd=root, **kwargs,
        )

    with patch.object(uploader.subprocess, 'run', side_effect=transport):
        uploader.upload(archive, 'fixture', php, stage, ['-o', 'StrictHostKeyChecking=yes'])
    assert final.read_bytes() == payload
    assert final.stat().st_mode & 0o777 == 0o600
    assert not part.exists()
    final.unlink()

    # A successful SSH exit is insufficient if the received bytes were changed.
    count = 0
    def corrupt(args, **kwargs):
        global count
        count += 1
        if count == 6:  # reset + four data chunks, then checksum
            part.write_bytes(part.read_bytes() + b'corruption')
        return transport(args, **kwargs)

    with patch.object(uploader.subprocess, 'run', side_effect=corrupt):
        try:
            uploader.upload(archive, 'fixture', php, stage, [])
            raise AssertionError('Corrupt upload was accepted')
        except subprocess.CalledProcessError:
            pass
    assert not final.exists()

    # Disconnects and timeouts must prevent finalizing an incomplete archive.
    for error in [subprocess.CalledProcessError(255, 'ssh'), subprocess.TimeoutExpired('ssh', 60)]:
        count = 0
        def fail(args, **kwargs):
            global count
            count += 1
            if count == 3:
                raise error
            return transport(args, **kwargs)
        with patch.object(uploader.subprocess, 'run', side_effect=fail):
            try:
                uploader.upload(archive, 'fixture', php, stage, [])
                raise AssertionError('Failed upload was accepted')
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                pass
        assert not final.exists()

print('PASS SSH command upload: binary data, stdin-consuming startup, checksum rejection, disconnect and timeout')
