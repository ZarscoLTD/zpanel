<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Own SSL certificates (bought from a certificate authority): make a key + CSR, install the certificate the authority sends back. */
final class Ssl
{
    public static function csr(array $actor, int $id, array $in): array
    {
        $s = Sites::mine($actor, $id);
        $dn = ['commonName' => $s['domain']];
        foreach (['countryName' => 'country', 'stateOrProvinceName' => 'state', 'localityName' => 'city', 'organizationName' => 'organization'] as $k => $f) {
            if (!empty($in[$f])) {
                $dn[$k] = mb_substr(preg_replace('/[^\p{L}\p{N} .,&-]/u', '', (string) $in[$f]), 0, $k === 'countryName' ? 2 : 64);
            }
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if (!$key || !$csr) {
            throw new PanelError('Could not make the request.', 500);
        }
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($key, $keyOut);
        Exec::write(self::dir($s['domain']) . '/pending.key', $keyOut, 0600); // kept until the certificate is installed
        Audit::log((int) $actor['id'], 'ssl.csr', ['site' => $s['domain']]);

        return ['csr' => $csrOut, 'note' => 'Send this request to your certificate authority. The private key stays on the server.'];
    }

    public static function install(array $actor, int $id, array $in): array
    {
        $s = Sites::mine($actor, $id);
        $cert = trim((string) ($in['certificate'] ?? ''));
        $chain = trim((string) ($in['chain'] ?? ''));
        $key = trim((string) ($in['key'] ?? '')) ?: (string) @file_get_contents(self::dir($s['domain']) . '/pending.key');
        $x = @openssl_x509_read($cert);
        if (!$x) {
            throw new PanelError('That is not a certificate (it should start with -----BEGIN CERTIFICATE-----).', 422);
        }
        if (!$key || !@openssl_x509_check_private_key($x, $key)) {
            throw new PanelError('The certificate does not belong to this private key.', 422);
        }
        $info = openssl_x509_parse($x);
        $names = array_map(fn ($n) => strtolower(trim(substr($n, 4))), array_filter(explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')), fn ($n) => str_starts_with(trim($n), 'DNS:')));
        $names[] = strtolower((string) ($info['subject']['CN'] ?? ''));
        $ok = false;
        foreach ($names as $n) {
            $n = trim($n);
            if ($n === $s['domain'] || (str_starts_with($n, '*.') && str_ends_with($s['domain'], substr($n, 1)))) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new PanelError('The certificate is for ' . implode(', ', array_filter($names)) . ', not ' . $s['domain'] . '.', 422);
        }
        if (($info['validTo_time_t'] ?? 0) < time()) {
            throw new PanelError('The certificate has expired.', 422);
        }
        $dir = self::dir($s['domain']);
        Exec::write("$dir/fullchain.pem", $cert . "\n" . ($chain !== '' ? $chain . "\n" : ''), 0644);
        Exec::write("$dir/privkey.pem", $key . "\n", 0600);
        @unlink("$dir/pending.key");
        Db::pdo()->prepare('UPDATE sites SET ssl = 1 WHERE id = ?')->execute([$id]);
        Sites::apply($id);
        Audit::log((int) $actor['id'], 'ssl.installed', ['site' => $s['domain'], 'until' => gmdate('Y-m-d', (int) $info['validTo_time_t'])]);

        return ['site' => Sites::find($id), 'valid_until' => gmdate('Y-m-d', (int) $info['validTo_time_t'])];
    }

    /** The same folder the vhost reads (Let's Encrypt layout), so both kinds of certificate work the same way. */
    private static function dir(string $domain): string
    {
        return Config::etc() . '/letsencrypt/live/' . $domain;
    }
}
