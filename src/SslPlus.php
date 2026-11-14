<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * More certificates (Panel 2.0 part 6, like Plesk / Webuzo / HestiaCP): ZeroSSL as the certificate authority instead of Let's Encrypt
 * (owner setting with ZeroSSL's EAB keys), wildcard certificates (*.example.com) by the DNS challenge for zones hosted on this server,
 * and the reverse DNS (PTR) check of the server's address for mail.
 */
final class SslPlus
{
    public const ZEROSSL = 'https://acme.zerossl.com/v2/DV90';

    /** The CA setting without secrets, for the settings screen. */
    public static function ca(): array
    {
        $c = json_decode((string) Db::setting('acme_ca', '{}'), true) ?: [];

        return ['ca' => ($c['ca'] ?? '') === 'zerossl' ? 'zerossl' : 'letsencrypt', 'eab_saved' => !empty($c['kid'])];
    }

    public static function saveCa(array $in): array
    {
        $ca = ($in['ca'] ?? '') === 'zerossl' ? 'zerossl' : 'letsencrypt';
        $old = json_decode((string) Db::setting('acme_ca', '{}'), true) ?: [];
        $kid = trim((string) ($in['kid'] ?? '')) ?: (string) ($old['kid'] ?? '');
        $hmac = trim((string) ($in['hmac'] ?? '')) !== '' ? Secrets::seal(trim((string) $in['hmac'])) : (string) ($old['hmac'] ?? '');
        if ($ca === 'zerossl' && (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $kid) || $hmac === '')) {
            throw new PanelError('ZeroSSL needs its EAB key id and HMAC key (ZeroSSL dashboard > Developer > EAB credentials).', 422);
        }
        Db::put('acme_ca', json_encode(['ca' => $ca, 'kid' => $kid, 'hmac' => $hmac]));

        return self::ca();
    }

    /** Extra certbot arguments for the chosen authority. */
    public static function caArgs(): array
    {
        $c = json_decode((string) Db::setting('acme_ca', '{}'), true) ?: [];
        if (($c['ca'] ?? '') !== 'zerossl' || empty($c['kid']) || empty($c['hmac'])) {
            return [];
        }

        return ['--server', self::ZEROSSL, '--eab-kid', (string) $c['kid'], '--eab-hmac-key', (string) Secrets::open((string) $c['hmac'])];
    }

    /** The zone on this server that holds a domain (example.com for shop.example.com), or null. */
    public static function zoneFor(string $domain): ?array
    {
        $parts = explode('.', strtolower($domain));
        while (count($parts) >= 2) {
            $st = Db::pdo()->prepare('SELECT * FROM dns_zones WHERE domain = ?');
            $st->execute([implode('.', $parts)]);
            if ($z = $st->fetch()) {
                return $z;
            }
            array_shift($parts);
        }

        return null;
    }

    /** example.com + *.example.com by the DNS challenge (the zone must be on this server and the domain's name servers point here). */
    public static function wildcard(array $actor, int $id, string $email): array
    {
        $s = Sites::mine($actor, $id);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('Enter an e-mail address for the certificate notices.', 422);
        }
        $z = self::zoneFor($s['domain']);
        if (!$z) {
            throw new PanelError('A wildcard certificate needs the DNS zone of ' . $s['domain'] . ' on this server (DNS zones), with the domain\'s name servers pointing here.', 422);
        }
        $zp = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/zp');
        $r = Exec::run(array_merge(['certbot', 'certonly', '--manual', '--preferred-challenges', 'dns', '--non-interactive', '--agree-tos', '-m', $email,
            '--manual-auth-hook', "$zp acme-dns add", '--manual-cleanup-hook', "$zp acme-dns del", '--cert-name', $s['domain'],
            '-d', $s['domain'], '-d', '*.' . $s['domain']], self::caArgs()), null, 600);
        if ($r['code'] !== 0) {
            throw new PanelError('The wildcard certificate was not issued: ' . mb_substr(trim($r['out']), -300), 422);
        }
        Db::pdo()->prepare('UPDATE sites SET ssl = 1 WHERE id = ?')->execute([$id]);
        Sites::apply($id);
        Audit::log((int) $actor['id'], 'site.ssl_wildcard', ['domain' => $s['domain']]);

        return Sites::find($id);
    }

    /** certbot hook: put / remove the _acme-challenge TXT record (CERTBOT_DOMAIN, CERTBOT_VALIDATION), then wait for the reload. */
    public static function hook(string $op, string $domain, string $value): void
    {
        $z = self::zoneFor($domain) ?? throw new \RuntimeException("no zone for $domain");
        $rel = '_acme-challenge' . ($domain === $z['domain'] ? '' : '.' . substr($domain, 0, -strlen($z['domain']) - 1));
        if ($op === 'add') {
            if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/', $value)) {
                throw new \RuntimeException('bad validation value');
            }
            Db::pdo()->prepare('INSERT INTO dns_records (zone_id, type, name, value, ttl, prio) VALUES (?, ?, ?, ?, ?, NULL)')->execute([$z['id'], 'TXT', $rel, $value, 60]);
        } else {
            Db::pdo()->prepare("DELETE FROM dns_records WHERE zone_id = ? AND type = 'TXT' AND name = ?")->execute([$z['id'], $rel]);
        }
        Dns::publish((int) $z['id']);
        if ($op === 'add' && !Config::dry()) {
            sleep(20); // the name servers pick up the new serial
        }
    }

    /** Reverse DNS of the server's public address should name the host (mail servers check it). */
    public static function ptr(?string $ip = null, ?string $host = null): array
    {
        $ip ??= trim((string) (Exec::run(['hostname', '-I'])['out'] ?? '')) ?: '';
        $ip = explode(' ', $ip)[0];
        $host ??= ServerTools::info()['hostname'];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ip' => $ip, 'ptr' => null, 'host' => $host, 'ok' => false, 'hint' => 'The server address could not be read.'];
        }
        $ptr = Config::dry() ? null : (@gethostbyaddr($ip) ?: null);
        $ptr = $ptr === $ip ? null : $ptr;
        $ok = $ptr !== null && strcasecmp(rtrim($ptr, '.'), $host) === 0;

        return ['ip' => $ip, 'ptr' => $ptr, 'host' => $host, 'ok' => $ok,
            'hint' => $ok ? 'Reverse DNS matches the host name.' : 'Ask your server provider to set the reverse DNS (PTR) of ' . $ip . ' to ' . $host . ' - many mail servers refuse mail otherwise.'];
    }
}
