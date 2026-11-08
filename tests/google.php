<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/src/core.php';
require dirname(__DIR__) . '/private/src/auth.php';

function googleCheck(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}

function googleFixture(array $claims, OpenSSLAsymmetricKey $key, array $header = []): string
{
    $encode = fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $input = $encode(json_encode($header ?: ['alg' => 'RS256', 'kid' => 'fixture'])) . '.' .
        $encode(json_encode($claims));
    openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
    return $input . '.' . $encode($signature);
}

function googleReject(string $token, string $nonce, array $keys, string $label): void
{
    try {
        googleIdentity($token, 'client.apps.googleusercontent.com', $nonce, $keys, 1000);
    } catch (RuntimeException $error) {
        googleCheck($error->getCode() === 400, $label);
        return;
    }
    throw new RuntimeException('Accepted invalid token: ' . $label);
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$keys = ['fixture' => openssl_pkey_get_details($key)['key']];
$nonce = str_repeat('a', 64);
$claims = ['iss' => 'https://accounts.google.com', 'aud' => 'client.apps.googleusercontent.com',
    'sub' => '123456789', 'nonce' => $nonce, 'iat' => 900, 'exp' => 2000];
$token = googleFixture($claims, $key);
googleCheck(googleIdentity($token, $claims['aud'], $nonce, $keys, 1000) === $claims['sub'], 'verified Google subject only');
foreach ([
    'iss' => 'https://evil.example', 'aud' => 'other-client', 'sub' => '',
    'exp' => 900, 'iat' => 1100, 'nonce' => 'wrong', 'azp' => 'other-client',
] as $field => $value) {
    googleReject(googleFixture(array_replace($claims, [$field => $value]), $key), $nonce, $keys, 'reject wrong ' . $field);
}
googleReject(googleFixture(array_replace($claims, ['exp' => '2000']), $key), $nonce, $keys, 'reject non-integer expiry');
googleReject(googleFixture($claims, $other), $nonce, $keys, 'reject wrong signature');
googleReject(googleFixture($claims, $key, ['alg' => 'HS256', 'kid' => 'fixture']), $nonce, $keys, 'reject algorithm confusion');
googleReject(googleFixture($claims, $key, ['alg' => 'RS256', 'kid' => 'unknown']), $nonce, $keys, 'reject unknown key');
googleReject('not.a.jwt', $nonce, $keys, 'reject malformed JWT');
googleReject($token, '', $keys, 'reject missing nonce');
googleReject(googleFixture(array_replace($claims, ['aud' => [$claims['aud'], 'second']]), $key), $nonce, $keys, 'multi-audience requires matching azp');
$multi = array_replace($claims, ['aud' => [$claims['aud'], 'second'], 'azp' => $claims['aud']]);
googleCheck(googleIdentity(googleFixture($multi, $key), $claims['aud'], $nonce, $keys, 1000) === $claims['sub'], 'authorized multi-audience token');

$cacheDir = sys_get_temp_dir() . '/google-certs-' . bin2hex(random_bytes(6));
mkdir($cacheDir, 0700);
try {
    file_put_contents($cacheDir . '/google-certificates.json', encode(['expires' => time() + 60, 'keys' => $keys]));
    $liveClaims = array_replace($claims, ['iat' => time(), 'exp' => time() + 3600]);
    googleCheck(googleSubject(['data_dir' => $cacheDir], ['client_id' => $claims['aud']],
        ['nonce' => $nonce], ['id_token' => googleFixture($liveClaims, $key)]) === $claims['sub'],
        'callback validates token using cached Google keys');
} finally {
    unlink($cacheDir . '/google-certificates.json');
    rmdir($cacheDir);
}

// A nonce is one-time flow state; tokens for another attempt must not link.
googleReject($token, str_repeat('b', 64), $keys, 'reject token from another login attempt');
$c = ['data_dir' => $cacheDir];
require_once dirname(__DIR__) . '/private/src/storage.php';
$c['mysql'] = require __DIR__ . '/mysql.php';
initializeStorage($c);
$db = database($c);
$identity = 'guest:' . str_repeat('c', 64);
$before = account($db, $identity);
query($db, 'UPDATE accounts SET profile=?, state=?, revision=2 WHERE auth_id=?',
    [encode(['name' => '瓢箪屋']), encode(['event' => '登山祭']), $identity]);
$subject = googleIdentity($token, $claims['aud'], $nonce, $keys, 1000);
$hash = hash_hmac('sha256', 'google:' . $subject, str_repeat('d', 64));
linkIdentity($db, $hash, 'google', $identity);
linkIdentity($db, $hash, 'google', $identity);
$after = one($db, 'SELECT * FROM accounts WHERE auth_id=?', [$identity]);
googleCheck($after['id'] === $before['id'] && $after['revision'] === 2 &&
    json_decode($after['profile'], true)['name'] === '瓢箪屋' &&
    json_decode($after['state'], true)['event'] === '登山祭', 'link keeps account, card and state');
$restored = one($db, 'SELECT auth_id FROM identities WHERE subject=?', [$hash]);
googleCheck($restored['auth_id'] === $identity, 'Google subject resolves to original guest account');
googleCheck(linkedProviders($db, $identity) === ['google'], 'Google linked status');
echo "Google checks passed; live OAuth requires configured Google Cloud credentials.\n";
