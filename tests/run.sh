#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
PHP_BIN=${PHP_BIN:-php}
find private public deploy -name '*.php' -print | while IFS= read -r file; do
    "$PHP_BIN" -l "$file" || exit 1
done
"$PHP_BIN" tests/api.php
"$PHP_BIN" tests/auth.php
"$PHP_BIN" tests/guest.php
python3 tests/http-entry.py "$PHP_BIN"
python3 tests/http-entry.py "$PHP_BIN" ''
python3 tests/backup.py "$PHP_BIN"
node --check public/app.js
node --check readable/app.js
node tests/notifications.mjs
node tests/subpath.mjs
python3 tests/deploy.py "$PHP_BIN"
