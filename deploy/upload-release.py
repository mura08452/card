"""Upload through bounded SSH command arguments, never through remote stdin."""
import base64
import hashlib
import json
import pathlib
import shlex
import subprocess
import sys
import time


def upload(archive, target, php, stage, ssh_options, limit=120):
    payload = pathlib.Path(archive).read_bytes()
    part = json.dumps(stage + '/release.tgz.part')
    final = json.dumps(stage + '/release.tgz')
    deadline = time.monotonic() + limit

    def remote(code):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise subprocess.TimeoutExpired('release upload', limit)
        # PHP source and archive data are encoded inside the command argument.
        # No raw $, quotes or binary data reach the remote login shell.
        encoded = base64.b64encode(code.encode()).decode()
        wrapper = f'eval(base64_decode("{encoded}"));'
        command = shlex.quote(php) + ' -r ' + shlex.quote(wrapper)
        subprocess.run(
            ['ssh', '-n', *ssh_options, target, command],
            stdin=subprocess.DEVNULL,
            timeout=min(60, remaining),
            check=True,
        )

    remote(f'umask(0077); $p={part}; if (is_link($p) || '
           'file_put_contents($p, "") === false) { exit(1); }')
    # Encoding twice still leaves each command well below OS argument limits.
    chunk_size = 12 * 1024
    total = (len(payload) + chunk_size - 1) // chunk_size
    for index, offset in enumerate(range(0, len(payload), chunk_size), 1):
        chunk = payload[offset:offset + chunk_size]
        encoded = base64.b64encode(chunk).decode()
        print(f'[3/4] Uploading chunk {index}/{total}...', flush=True)
        remote(f'$p={part}; clearstatcache(); '
               f'if (is_link($p) || filesize($p) !== {offset}) {{ exit(1); }} '
               f'$d=base64_decode("{encoded}", true); '
               f'if ($d === false || file_put_contents($p, $d, FILE_APPEND | LOCK_EX) !== {len(chunk)}) '
               '{ exit(1); }')
    digest = hashlib.sha256(payload).hexdigest()
    remote(f'$p={part}; $f={final}; '
           f'if (is_link($p) || is_link($f) || hash_file("sha256", $p) !== "{digest}" '
           '|| !rename($p, $f)) { fwrite(STDERR, "Upload checksum/rename failed\\n"); exit(1); } '
           'echo "Upload-complete (SHA-256 verified)\\n";')


def main():
    try:
        upload(*sys.argv[1:5], sys.argv[5:])
    except subprocess.TimeoutExpired:
        print('Release upload timed out before installation.', file=sys.stderr)
        return 124
    except subprocess.CalledProcessError as error:
        # Avoid displaying command arguments containing the encoded payload.
        print(f'Release upload command failed (exit {error.returncode}).', file=sys.stderr)
        return error.returncode if error.returncode > 0 else 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
