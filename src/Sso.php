<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Company sign-in for the panel (OpenID Connect: Microsoft Entra ID, Google Workspace, Okta, Keycloak ...), owner 2026-11-13.
 * The owner sets issuer, client id and secret (sealed with Secrets); a panel user whose e-mail matches the company account signs in
 * without the panel password. Authorization code + PKCE; the ID token's RS256 signature is checked against the issuer's keys with
 * openssl, plus issuer, audience, nonce and expiry. No new users are made by it: the owner adds people as usual.
 */
final class Sso
{
    /** @var null|\Closure(string $method, string $url, array $form): array{code: int, body: string} test hook */
    public static ?\Closure $http = null;

    public static function settings(): array
    {
        $s = json_decode((string) Db::setting('sso', '{}'), true) ?: [];

        return ['enabled' => !empty($s['enabled']), 'issuer' => (string) ($s['issuer'] ?? ''), 'client_id' => (string) ($s['client_id'] ?? ''),
            'has_secret' => !empty($s['secret']), 'only' => !empty($s['only']), 'redirect_uri' => ''];
    }

    public static function save(array $in, string $base): array
    {
        $old = json_decode((string) Db::setting('sso', '{}'), true) ?: [];
        $issuer = rtrim(trim((string) ($in['issuer'] ?? '')), '/');
        if (!empty($in['enabled']) && (!preg_match('#^https://[^\s]+$#', $issuer) || trim((string) ($in['client_id'] ?? '')) === '')) {
            throw new PanelError('Enter the issuer address (https://...) and the client ID.', 422);
        }
        $secret = trim((string) ($in['client_secret'] ?? ''));
        if (!empty($in['enabled']) && $secret === '' && empty($old['secret'])) {
            throw new PanelError('Enter the client secret.', 422);
        }
        if (!empty($in['enabled'])) {
            self::discovery($issuer); // a wrong address is refused now, not at the first sign-in
        }
        $new = ['enabled' => !empty($in['enabled']), 'issuer' => $issuer, 'client_id' => trim((string) ($in['client_id'] ?? '')), 'only' => !empty($in['only']),
            'secret' => $secret !== '' ? Secrets::seal($secret) : ($old['secret'] ?? '')];
        Db::put('sso', json_encode($new));

        return self::settings() + ['redirect_uri' => $base . '/api/sso/callback'];
    }

    private static function http(string $method, string $url, array $form = []): array
    {
        if (self::$http) {
            return (self::$http)($method, $url, $form);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json']]
            + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)] : []));
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => $body];
    }

    public static function discovery(string $issuer): array
    {
        $r = self::http('GET', rtrim($issuer, '/') . '/.well-known/openid-configuration');
        $d = json_decode($r['body'], true);
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (!is_array($d) || empty($d[$k]) || !is_string($d[$k])) {
                throw new PanelError('The sign-in service did not answer correctly.', 422);
            }
        }

        return $d;
    }

    /** @return array{url: string, cookie: string} the sign-in address and the cookie that remembers state, nonce and PKCE verifier */
    public static function start(string $base): array
    {
        $raw = json_decode((string) Db::setting('sso', '{}'), true) ?: [];
        if (empty($raw['enabled'])) {
            throw new PanelError('Company sign-in is not switched on.', 404);
        }
        $d = self::discovery($raw['issuer']);
        $st = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 'verifier' => bin2hex(random_bytes(32)), 'at' => time()];
        $q = http_build_query(['response_type' => 'code', 'client_id' => $raw['client_id'], 'redirect_uri' => $base . '/api/sso/callback', 'scope' => 'openid email profile',
            'state' => $st['state'], 'nonce' => $st['nonce'], 'code_challenge' => self::b64(hash('sha256', $st['verifier'], true)), 'code_challenge_method' => 'S256']);

        return ['url' => $d['authorization_endpoint'] . (str_contains($d['authorization_endpoint'], '?') ? '&' : '?') . $q, 'cookie' => Secrets::seal(json_encode($st))];
    }

    /** Checks the answer; returns the panel user (array row) whose e-mail the company account has. */
    public static function finish(array $query, string $cookie, string $base): array
    {
        $raw = json_decode((string) Db::setting('sso', '{}'), true) ?: [];
        $st = json_decode((string) (Secrets::open($cookie) ?? ''), true);
        if (empty($raw['enabled']) || !is_array($st) || !hash_equals((string) $st['state'], (string) ($query['state'] ?? '')) || time() - (int) $st['at'] > 900) {
            throw new PanelError('This sign-in has expired. Please start again.', 401);
        }
        if (!empty($query['error'])) {
            throw new PanelError('Company sign-in was not completed: ' . mb_substr((string) ($query['error_description'] ?? $query['error']), 0, 150), 401);
        }
        $d = self::discovery($raw['issuer']);
        $r = self::http('POST', $d['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $base . '/api/sso/callback',
            'client_id' => $raw['client_id'], 'client_secret' => (string) Secrets::open((string) $raw['secret']), 'code_verifier' => $st['verifier']]);
        $idt = (string) (json_decode($r['body'], true)['id_token'] ?? '');
        if ($r['code'] !== 200 || $idt === '') {
            throw new PanelError('The sign-in service refused the sign-in.', 401);
        }
        $c = self::verify($idt, json_decode(self::http('GET', $d['jwks_uri'])['body'], true) ?: []);
        $want = rtrim(str_replace('{tenantid}', (string) ($c['tid'] ?? ''), (string) $d['issuer']), '/');
        $aud = (array) ($c['aud'] ?? []);
        if (rtrim((string) ($c['iss'] ?? ''), '/') !== $want || !in_array($raw['client_id'], $aud, true) || !hash_equals((string) $st['nonce'], (string) ($c['nonce'] ?? ''))
            || (int) ($c['exp'] ?? 0) < time() - 120) {
            throw new PanelError('The sign-in answer is not meant for this panel.', 401);
        }
        $email = strtolower(trim((string) ($c['email'] ?? $c['preferred_username'] ?? $c['upn'] ?? '')));
        $st2 = Db::pdo()->prepare('SELECT * FROM users WHERE lower(email) = ? AND active = 1');
        $st2->execute([$email]);
        $u = $st2->fetch();
        if ($email === '' || !$u) {
            throw new PanelError('No panel user has the e-mail ' . ($email ?: '?') . '. Ask the panel owner to add it to your user.', 403);
        }

        return $u;
    }

    /** RS256 JWT check with the issuer's JSON Web Key Set; returns the claims. */
    public static function verify(string $jwt, array $jwks): array
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) {
            throw new PanelError('Bad sign-in answer.', 401);
        }
        $h = json_decode(self::unb64($p[0]), true) ?: [];
        if (($h['alg'] ?? '') !== 'RS256') {
            throw new PanelError('Bad sign-in answer (algorithm).', 401);
        }
        foreach ((array) ($jwks['keys'] ?? []) as $k) {
            if (($k['kty'] ?? '') !== 'RSA' || (isset($h['kid'], $k['kid']) && $h['kid'] !== $k['kid'])) {
                continue;
            }
            if (openssl_verify($p[0] . '.' . $p[1], self::unb64($p[2]), self::pem(self::unb64($k['n']), self::unb64($k['e'])), OPENSSL_ALGO_SHA256) === 1) {
                return json_decode(self::unb64($p[1]), true) ?: [];
            }
        }
        throw new PanelError('The sign-in answer could not be verified.', 401);
    }

    /** PEM public key from an RSA modulus and exponent (DER SubjectPublicKeyInfo). */
    private static function pem(string $n, string $e): string
    {
        $int = fn (string $x) => "\x02" . self::len(strlen($x = (ord($x[0]) > 0x7f ? "\x00" . $x : $x))) . $x;
        $seq = fn (string $x) => "\x30" . self::len(strlen($x)) . $x;
        $rsa = $seq($int($n) . $int($e));
        $spki = $seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . "\x03" . self::len(strlen($rsa) + 1) . "\x00" . $rsa);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $l): string
    {
        if ($l < 128) {
            return chr($l);
        }
        $b = ltrim(pack('N', $l), "\x00");

        return chr(0x80 | strlen($b)) . $b;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
