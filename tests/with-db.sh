#!/bin/sh
set -eu
export CARD_TEST_DATABASE="card_test_$(openssl rand -hex 8)"
mysql -h "${MYSQL_HOST:-127.0.0.1}" -u "${MYSQL_USER:-root}" -e "CREATE DATABASE $CARD_TEST_DATABASE CHARACTER SET utf8mb4"
trap 'mysql -h "${MYSQL_HOST:-127.0.0.1}" -u "${MYSQL_USER:-root}" -e "DROP DATABASE $CARD_TEST_DATABASE"' EXIT
"$@"
