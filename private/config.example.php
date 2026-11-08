<?php
return [
    // 設置URL（サブフォルダーを含む）。末尾の / は不要。
    'base_url' => 'https://over.rulez.jp/card',
    'mysql' => [
        'host' => 'MYSQL_HOST',
        'database' => 'DATABASE_NAME',
        'user' => 'DATABASE_USER',
        'password' => 'DATABASE_PASSWORD',
    ],
    'data_dir' => __DIR__ . '/data',
    // php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' で生成。後から変更しない。
    'identity_key' => 'REPLACE_WITH_64_HEX_CHARACTERS',
    'providers' => [
        // Google Cloud の OAuth クライアント（ウェブアプリ）。詳細は docs/google-auth.md。
        'google' => ['client_id' => '', 'client_secret' => ''],
        'x' => ['client_id' => '', 'client_secret' => ''],
    ],
];
