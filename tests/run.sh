#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
PHP_BIN=${PHP_BIN:-php}
find private public deploy -name '*.php' -print | while IFS= read -r file; do
    "$PHP_BIN" -l "$file" || exit 1
done
sh tests/with-db.sh "$PHP_BIN" tests/api.php
"$PHP_BIN" tests/auth.php
sh tests/with-db.sh "$PHP_BIN" tests/google.php
sh tests/with-db.sh "$PHP_BIN" tests/guest.php
sh tests/with-db.sh python3 tests/http-entry.py "$PHP_BIN"
sh tests/with-db.sh python3 tests/http-entry.py "$PHP_BIN" ''
sh tests/with-db.sh python3 tests/backup.py "$PHP_BIN"
node --check public/app.js
node --check readable/app.js
node tests/notifications.mjs
node tests/subpath.mjs
sh tests/with-db.sh python3 tests/deploy.py "$PHP_BIN"
python3 tests/upload.py "$PHP_BIN"
