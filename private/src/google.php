<?php
declare(strict_types=1);

// Only Google's fixed HTTPS endpoint may supply signing certificates.
function googleCertificates(array $config, string $kid): array
{
    $path = $config['data_dir'] . '/google-certificates.json';
    $cached = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time() &&
        is_array($cached['keys'] ?? null) && isset($cached['keys'][$kid])) {
        return $cached['keys'];
    }
    $keys = remoteJson('https://www.googleapis.com/oauth2/v1/certs');
    foreach ($keys as $id => $certificate) {
        if (!is_string($id) || !is_string($certificate) ||
            !str_starts_with($certificate, '-----BEGIN CERTIFICATE-----')) {
            fail('Googleの認証鍵を確認できませんでした', 503);
        }
    }
    if (!$keys) {
        fail('Googleの認証鍵を確認できませんでした', 503);
    }
    // Certificates only; no tokens or user profile data are cached.
    $temporary = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, encode(['expires' => time() + 300, 'keys' => $keys])) !== false) {
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
        }
    }
    return $keys;
}

function googleBase64(string $value): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        fail('Googleの認証トークンが不正です');
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        fail('Googleの認証トークンが不正です');
    }
    return $decoded;
}

function googleTokenParts(string $token): array
{
    if (strlen($token) > 16384 || count($parts = explode('.', $token)) !== 3) {
        fail('Googleの認証トークンが不正です');
    }
    $header = json_decode(googleBase64($parts[0]), true);
    $claims = json_decode(googleBase64($parts[1]), true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' ||
        !is_string($header['kid'] ?? null) || $header['kid'] === '' || isset($header['crit'])) {
        fail('Googleの認証トークンが不正です');
    }
    return [$parts, $header, $claims];
}

function googleIdentity(string $token, string $clientId, string $nonce, array $certificates, ?int $now = null): string
{
    [$parts, $header, $claims] = googleTokenParts($token);
    $certificate = $certificates[$header['kid']] ?? null;
    if (!is_string($certificate) || !extension_loaded('openssl')) {
        fail('Googleの認証鍵を確認できませんでした');
    }
    if (openssl_verify($parts[0] . '.' . $parts[1], googleBase64($parts[2]), $certificate, OPENSSL_ALGO_SHA256) !== 1) {
        fail('Googleの認証署名を確認できませんでした');
    }
    $now ??= time();
    $audience = $claims['aud'] ?? null;
    $audiences = is_string($audience) ? [$audience] : $audience;
    if (!is_array($audiences) || !in_array($clientId, $audiences, true) ||
        (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId) ||
        (isset($claims['azp']) && $claims['azp'] !== $clientId) ||
        !in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true) ||
        !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 60 ||
        !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 ||
        $claims['exp'] <= $claims['iat'] ||
        !is_string($claims['nonce'] ?? null) || $nonce === '' || !hash_equals($nonce, $claims['nonce']) ||
        !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
        fail('Googleの認証内容を確認できませんでした。もう一度連携してください');
    }
    return $claims['sub'];
}

function googleSubject(array $config, array $provider, array $flow, array $token): string
{
    if (!is_string($token['id_token'] ?? null) || !is_string($flow['nonce'] ?? null)) {
        fail('Googleの認証トークンを確認できませんでした');
    }
    [, $header] = googleTokenParts($token['id_token']);
    return googleIdentity(
        $token['id_token'],
        $provider['client_id'],
        $flow['nonce'],
        googleCertificates($config, $header['kid'])
    );
}
