<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Panel 2.0 part 7 (HestiaCP / Plesk / aaPanel extras): DNSSEC signing of zones hosted here (BIND inline signing with the default key
 * policy; the DS record is shown for the registrar), MTA-STS + TLS reporting for mail domains (policy served at
 * https://mta-sts.<domain>/.well-known/mta-sts.txt, DNS records added when the zone is here), and Brotli compression for all websites.
 */
final class DnsMailPlus
{
    // ---------------- DNSSEC ----------------
    public static function dnssecZones(): array
    {
        return json_decode((string) Db::setting('dnssec_zones', '[]'), true) ?: [];
    }

    public static function keyDir(): string
    {
        return Config::ensure(Config::etc() . '/bind/keys');
    }

    /** Switch signing on or off for one zone (the zone's owner, or the server owner). */
    public static function dnssec(array $actor, int $zoneId, bool $on): array
    {
        $z = Dns::zone($actor, $zoneId);
        $list = array_values(array_diff(self::dnssecZones(), [$z['domain']]));
        if ($on) {
            $list[] = $z['domain'];
        }
        Db::put('dnssec_zones', json_encode($list));
        Dns::conf();
        Exec::run(['rndc', 'reconfig']);
        Audit::log((int) $actor['id'], $on ? 'dns.dnssec_on' : 'dns.dnssec_off', ['zone' => $z['domain']]);

        return self::dnssecStatus($actor, $zoneId);
    }

    /** On / off and the DS record(s) to give the registrar (empty until BIND made the keys, a few seconds after switching on). */
    public static function dnssecStatus(array $actor, int $zoneId): array
    {
        $z = Dns::zone($actor, $zoneId);
        $on = in_array($z['domain'], self::dnssecZones(), true);
        $ds = [];
        if ($on) {
            $r = Exec::run(['sh', '-c', 'dig @127.0.0.1 +noall +answer DNSKEY ' . escapeshellarg($z['domain']) . ' | dnssec-dsfromkey -2 -f - ' . escapeshellarg($z['domain'])]);
            foreach (preg_split('/\R/', trim((string) $r['out'])) ?: [] as $line) {
                if (preg_match('/\sIN\s+DS\s+(\d+\s+\d+\s+\d+\s+[0-9A-F]+)$/i', trim($line), $m)) {
                    $ds[] = $m[1];
                }
            }
        }

        return ['zone' => $z['domain'], 'dnssec' => $on, 'ds' => $ds];
    }

    /** The extra options of a signed zone in named.conf. */
    public static function zoneOptions(string $domain): string
    {
        return in_array($domain, self::dnssecZones(), true) ? ' dnssec-policy default; inline-signing yes; key-directory "' . self::keyDir() . '";' : '';
    }

    // ---------------- MTA-STS ----------------
    /** Publish an MTA-STS policy (mode testing or enforce) for a mail domain of the actor. */
    public static function mtaSts(array $actor, int $mailDomainId, string $mode, string $report): array
    {
        $d = Mail::domain($actor, $mailDomainId);
        if (!in_array($mode, ['testing', 'enforce', 'none'], true)) {
            throw new PanelError('Choose testing, enforce or none.', 422);
        }
        if ($report !== '' && !filter_var($report, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('The report address is not a valid e-mail address.', 422);
        }
        $domain = $d['domain'];
        $zone = SslPlus::zoneFor($domain);
        $mxs = [];
        if ($zone && $zone['domain'] === $domain) { // the domain's own MX records
            $st = Db::pdo()->prepare("SELECT value FROM dns_records WHERE zone_id = ? AND type = 'MX' AND name = '@'");
            $st->execute([$zone['id']]);
            $mxs = array_map(fn ($v) => rtrim((string) $v, '.'), $st->fetchAll(\PDO::FETCH_COLUMN));
        }
        $mxs = $mxs ?: [trim((string) Exec::run(['hostname', '-f'])['out']) ?: (gethostname() ?: $domain)];
        $policy = "version: STSv1\nmode: $mode\n" . implode('', array_map(fn ($m) => "mx: $m\n", $mxs)) . "max_age: " . ($mode === 'enforce' ? 604800 : 86400) . "\n";
        $root = Config::ensure(Config::home() . "/mta-sts/$domain/.well-known");
        Exec::write("$root/mta-sts.txt", $policy, 0644);
        $host = "mta-sts.$domain";
        $web = dirname($root);
        Exec::write(Nginx::dir() . "/mta-sts-$domain.conf", "server {\n    listen 80;\n    listen [::]:80;\n    server_name $host;\n    root $web;\n"
            . "    location = /.well-known/mta-sts.txt { default_type text/plain; }\n    location ^~ /.well-known/acme-challenge/ { allow all; }\n    location / { return 404; }\n}\n"
            . (is_file(Config::etc() . "/letsencrypt/live/$host/fullchain.pem") ? "server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name $host;\n    root $web;\n"
                . '    ssl_certificate ' . Config::etc() . "/letsencrypt/live/$host/fullchain.pem;\n    ssl_certificate_key " . Config::etc() . "/letsencrypt/live/$host/privkey.pem;\n"
                . "    location = /.well-known/mta-sts.txt { default_type text/plain; }\n    location / { return 404; }\n}\n" : ''), 0644);
        if (Exec::ok(['nginx', '-t'])) {
            Exec::run(['systemctl', 'reload', 'nginx']);
        }
        $id = gmdate('YmdHis');
        $records = [['TXT', '_mta-sts', "v=STSv1; id=$id"]];
        if ($report !== '') {
            $records[] = ['TXT', '_smtp._tls', "v=TLSRPTv1; rua=mailto:$report"];
        }
        $added = false;
        if ($zone && $zone['domain'] === $domain) {
            $pdo = Db::pdo();
            $pdo->prepare("DELETE FROM dns_records WHERE zone_id = ? AND type = 'TXT' AND name IN ('_mta-sts', '_smtp._tls')")->execute([$zone['id']]);
            $ip = $pdo->prepare("SELECT value FROM dns_records WHERE zone_id = ? AND type = 'A' AND name = '@' LIMIT 1");
            $ip->execute([$zone['id']]);
            $has = $pdo->prepare("SELECT 1 FROM dns_records WHERE zone_id = ? AND name = 'mta-sts'");
            $has->execute([$zone['id']]);
            if (($a = $ip->fetchColumn()) && !$has->fetchColumn()) {
                $records[] = ['A', 'mta-sts', (string) $a];
            }
            foreach ($records as [$t, $n, $v]) {
                $pdo->prepare('INSERT INTO dns_records (zone_id, type, name, value, ttl, prio) VALUES (?, ?, ?, ?, 3600, NULL)')->execute([$zone['id'], $t, $n, $v]);
            }
            Dns::publish((int) $zone['id']);
            $added = true;
        }
        Audit::log((int) $actor['id'], 'mail.mta_sts', ['domain' => $domain, 'mode' => $mode]);

        return ['domain' => $domain, 'policy' => $policy, 'dns_added' => $added,
            'records' => array_map(fn ($r) => ['type' => $r[0], 'name' => $r[1] . '.' . $domain, 'value' => $r[2]], $records),
            'next' => 'Get a certificate for ' . $host . ' (it must answer over HTTPS): point it to this server, then press "Certificate for mta-sts".'];
    }

    /** The HTTPS certificate of mta-sts.<domain>, by the file challenge into its own folder. */
    public static function mtaStsCert(array $actor, int $mailDomainId, string $email): array
    {
        $d = Mail::domain($actor, $mailDomainId);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('Enter an e-mail address for the certificate notices.', 422);
        }
        $host = 'mta-sts.' . $d['domain'];
        $web = Config::home() . '/mta-sts/' . $d['domain'];
        if (!is_dir($web)) {
            throw new PanelError('Publish the MTA-STS policy first.', 422);
        }
        $r = Exec::run(array_merge(['certbot', 'certonly', '--webroot', '-w', $web, '--non-interactive', '--agree-tos', '-m', $email, '--cert-name', $host, '-d', $host], SslPlus::caArgs()), null, 240);
        if ($r['code'] !== 0) {
            throw new PanelError('The certificate was not issued. Check that ' . $host . ' points to this server. ' . mb_substr(trim($r['out']), -200), 422);
        }

        return ['ok' => true];
    }

    // ---------------- Brotli ----------------
    public static function brotli(): array
    {
        return ['on' => is_file(Config::etc() . '/nginx/conf.d/zarspanel-brotli.conf')];
    }

    public static function setBrotli(array $actor, bool $on): array
    {
        $f = Config::etc() . '/nginx/conf.d/zarspanel-brotli.conf';
        if ($on) {
            $r = Exec::run(['env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-y', 'install', 'libnginx-mod-http-brotli-filter', 'libnginx-mod-http-brotli-static'], null, 900);
            if ($r['code'] !== 0) {
                throw new PanelError('The Brotli module could not be installed: ' . mb_substr(trim($r['out']), -200), 500);
            }
            Exec::write($f, "# ZARS Panel: Brotli compression (next to gzip)\nbrotli on;\nbrotli_comp_level 5;\nbrotli_static on;\nbrotli_min_length 256;\n"
                . "brotli_types text/plain text/css text/xml application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf application/wasm;\n", 0644);
        } else {
            @unlink($f);
        }
        if (!Exec::ok(['nginx', '-t'])) {
            @unlink($f);
            throw new PanelError('The web server did not accept the Brotli settings; they were taken back.', 500);
        }
        Exec::run(['systemctl', 'reload', 'nginx']);
        Audit::log((int) $actor['id'], $on ? 'server.brotli_on' : 'server.brotli_off');

        return self::brotli();
    }
}
