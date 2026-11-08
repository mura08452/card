<?php
$dbname = getenv('CARD_TEST_DATABASE');
if (!preg_match('/^card_test_[a-f0-9]{16}$/D', (string)$dbname)) {
    throw new RuntimeException('Run sh tests/run.sh to provision an isolated test database.');
}
return [
    'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
    'user' => getenv('MYSQL_USER') ?: 'root',
    'password' => getenv('MYSQL_PWD'),
    'database' => $dbname,
];
