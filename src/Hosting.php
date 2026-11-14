<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Hosting-wide settings and jobs of the owner: DNS template for new zones, skeleton folder copied into new sites, log
 * rotation, monthly bandwidth limits per user (sites are paused when the limit is passed), IP addresses of the server,
 * software installer (Tomcat, Perl / PEAR modules, Varnish ... from the system packages) and changes to many accounts at once.
 */
final class Hosting
{
    // ---------------- DNS template ----------------
    public const DEFAULT_TEMPLATE = "NS @ ns1.{domain}.\nNS @ ns2.{domain}.\nA @ {ip}\nA www {ip}\nA ns1 {ip}\nA ns2 {ip}\nA mail {ip}\nMX @ 10 mail.{domain}.\nTXT @ v=spf1 a mx ~all";

    public static function dnsTemplate(): string
    {
        return Db::setting('dns_template') ?? self::DEFAULT_TEMPLATE;
    }

    public static function saveDnsTemplate(string $t): void
    {
        foreach (self::templateRecords($t, 'example.com', '203.0.113.1') as [$type, $name, $value]) {
            if (!in_array($type, Dns::TYPES, true)) {
                throw new PanelError("Unknown record type $type in the template.", 422);
            }
        }
        Db::put('dns_template', trim($t));
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?int}> type, name, value, priority */
    public static function templateRecords(string $t, string $domain, string $ip): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = strtr(trim($line), ['{domain}' => $domain, '{ip}' => $ip]);
            if (!preg_match('/^([A-Z]+)\s+(\S+)\s+(.+)$/', $line, $m) || ($ip === '' && str_contains($line . ' ', '  '))) {
                continue;
            }
            $prio = null;
            if (in_array($m[1], ['MX', 'SRV'], true) && preg_match('/^(\d+)\s+(.+)$/', $m[3], $p)) {
                [$prio, $m[3]] = [(int) $p[1], $p[2]];
            }
            if ($ip === '' && in_array($m[1], ['A', 'AAAA'], true)) {
                continue; // no server address given: address records are left out
            }
            $out[] = [$m[1], $m[2], trim($m[3]), $prio];
        }

        return $out;
    }

    // ---------------- skeleton folder ----------------
    public static function skeleton(): string
    {
        return Config::home() . '/skeleton';
    }

    /** Files of the skeleton folder go into every new PHP / static site (existing files are not overwritten). */
    public static function copySkeleton(string $root): int
    {
        $src = self::skeleton();
        if (!is_dir($src)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isLink()) {
                continue;
            }
            $to = $root . '/' . substr($f->getPathname(), strlen($src) + 1);
            if ($f->isDir()) {
                Config::ensure($to);
            } elseif (!file_exists($to)) {
                copy($f->getPathname(), $to);
                $n++;
            }
        }

        return $n;
    }

    // ---------------- log rotation ----------------
    public static function logRotation(): array
    {
        return json_decode((string) Db::setting('logrotate', ''), true) ?: ['days' => 14, 'max_mb' => 100, 'compress' => true];
    }

    public static function saveLogRotation(array $in): array
    {
        $c = ['days' => max(1, min(365, (int) ($in['days'] ?? 14))), 'max_mb' => max(1, min(10240, (int) ($in['max_mb'] ?? 100))), 'compress' => (bool) ($in['compress'] ?? true)];
        Db::put('logrotate', json_encode($c));
        $conf = Nginx::logs() . "/*.log {\n    daily\n    rotate {$c['days']}\n    maxsize {$c['max_mb']}M\n    missingok\n    notifempty\n" . ($c['compress'] ? "    compress\n    delaycompress\n" : '')
            . "    sharedscripts\n    postrotate\n        [ -s /run/nginx.pid ] && kill -USR1 `cat /run/nginx.pid`\n    endscript\n}\n";
        Exec::write(Config::etc() . '/logrotate.d/zarspanel', $conf, 0644);

        return $c;
    }

    // ---------------- bandwidth ----------------
    /** Traffic of the current month per user, from the sites' access logs. */
    public static function bandwidth(bool $sinceReset = true): array
    {
        $month = gmdate('M/Y');
        $out = [];
        foreach (Db::pdo()->query('SELECT s.domain, s.user_id FROM sites s')->fetchAll() as $s) {
            $bytes = 0;
            $log = Nginx::logs() . '/' . $s['domain'] . '.access.log';
            if (is_file($log)) {
                $fh = fopen($log, 'rb');
                while (($line = fgets($fh)) !== false) {
                    if (str_contains($line, $month) && preg_match('#" \d{3} (\d+)#', $line, $m)) {
                        $bytes += (int) $m[1];
                    }
                }
                fclose($fh);
            }
            $out[(int) $s['user_id']] = ($out[(int) $s['user_id']] ?? 0) + $bytes;
        }
        if ($sinceReset) { // Server tools > Reset bandwidth: count from the reset on, in the month of the reset
            foreach (json_decode((string) Db::setting('bandwidth_base', '{}'), true) ?: [] as $uid => $b) {
                if (isset($out[(int) $uid]) && ($b['month'] ?? '') === $month) {
                    $out[(int) $uid] = max(0, $out[(int) $uid] - (int) $b['bytes']);
                }
            }
        }

        return $out;
    }

    /** Cron (zp bandwidth): pause the sites of users over their monthly limit (quota bandwidth_gb); start them again in a new month. */
    public static function enforceBandwidth(): array
    {
        $used = self::bandwidth();
        $notes = [];
        foreach (Db::pdo()->query("SELECT * FROM users WHERE role != 'owner'")->fetchAll() as $u) {
            $limit = (float) ((json_decode((string) $u['quota'], true) ?: [])['bandwidth_gb'] ?? 0);
            $over = $limit > 0 && ($used[(int) $u['id']] ?? 0) > $limit * 1073741824;
            $flag = 'bw_paused_' . $u['id'];
            $paused = Db::setting($flag) === '1';
            if ($over && !$paused) {
                foreach (self::sitesOf((int) $u['id']) as $s) {
                    if ($s['enabled']) {
                        Sites::toggle(Auth::user(1) ?? $u, (int) $s['id'], false);
                    }
                }
                Db::put($flag, '1');
                Alerts::raise('bandwidth', "{$u['name']} passed the monthly traffic limit of {$limit} GB: sites paused.");
                $notes[] = "paused {$u['name']}";
            } elseif (!$over && $paused) {
                foreach (self::sitesOf((int) $u['id']) as $s) {
                    Sites::toggle(Auth::user(1) ?? $u, (int) $s['id'], true);
                }
                Db::put($flag, '0');
                $notes[] = "started {$u['name']}";
            }
        }

        return $notes;
    }

    private static function sitesOf(int $userId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM sites WHERE user_id = ?');
        $st->execute([$userId]);

        return $st->fetchAll();
    }

    // ---------------- IP addresses ----------------
    public static function ips(): array
    {
        $r = Exec::run(['ip', '-j', 'addr', 'show']);
        $out = [];
        foreach (json_decode($r['out'], true) ?: [] as $if) {
            foreach ($if['addr_info'] ?? [] as $a) {
                if (($a['scope'] ?? '') === 'global') {
                    $out[] = ['interface' => $if['ifname'], 'ip' => $a['local'], 'prefix' => $a['prefixlen'], 'family' => $a['family'] === 'inet6' ? 'IPv6' : 'IPv4'];
                }
            }
        }

        return ['addresses' => $out, 'extra' => json_decode((string) Db::setting('extra_ips', '[]'), true) ?: []];
    }

    /** Add or remove an extra address on an interface (now and after a restart, through a netplan file of the panel). */
    public static function ip(string $action, string $iface, string $cidr): array
    {
        if (!preg_match('/^[a-z0-9]{2,15}$/', $iface) || !SiteTools::validCidr($cidr) || !str_contains($cidr, '/')) {
            throw new PanelError('Give the interface (eth0) and the address with its prefix, like 203.0.113.10/32.', 422);
        }
        $extra = json_decode((string) Db::setting('extra_ips', '[]'), true) ?: [];
        if ($action === 'add') {
            Exec::run(['ip', 'addr', 'add', $cidr, 'dev', $iface]);
            $extra[] = ['interface' => $iface, 'ip' => $cidr];
        } else {
            Exec::run(['ip', 'addr', 'del', $cidr, 'dev', $iface]);
            $extra = array_values(array_filter($extra, fn ($e) => !($e['ip'] === $cidr && $e['interface'] === $iface)));
        }
        Db::put('extra_ips', json_encode($extra));
        $by = [];
        foreach ($extra as $e) {
            $by[$e['interface']][] = $e['ip'];
        }
        $yaml = "# Managed by ZARS Panel: extra addresses\nnetwork:\n  version: 2\n  ethernets:\n";
        foreach ($by as $if => $list) {
            $yaml .= "    $if:\n      addresses:\n" . implode('', array_map(fn ($a) => "        - $a\n", $list));
        }
        Exec::write(Config::etc() . '/netplan/90-zarspanel.yaml', $by ? $yaml : "# Managed by ZARS Panel: no extra addresses\n", 0600);

        return self::ips();
    }

    /**
     * The server moved to a new address (WHM "IP migration"): every DNS zone on this server gets the new address in its A / AAAA
     * records and in SPF "ip4:" / "ip6:" parts, then the zones are published again. Websites listen on every address already.
     */
    public static function changeIp(array $actor, string $old, string $new): array
    {
        $v4 = filter_var($old, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($new, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $v6 = filter_var($old, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($new, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if ((!$v4 && !$v6) || $old === $new) {
            throw new PanelError('Give the old and the new address (both IPv4 or both IPv6, and different).', 422);
        }
        $pdo = Db::pdo();
        $zones = [];
        $records = 0;
        $st = $pdo->prepare('SELECT id, zone_id FROM dns_records WHERE type = ? AND value = ?');
        $st->execute([$v4 ? 'A' : 'AAAA', $old]);
        foreach ($st->fetchAll() as $r) {
            $pdo->prepare('UPDATE dns_records SET value = ? WHERE id = ?')->execute([$new, $r['id']]);
            $zones[(int) $r['zone_id']] = true;
            $records++;
        }
        $tag = ($v4 ? 'ip4:' : 'ip6:');
        $st = $pdo->prepare("SELECT id, zone_id, value FROM dns_records WHERE type = 'TXT' AND value LIKE ?");
        $st->execute(['%' . $tag . $old . '%']);
        foreach ($st->fetchAll() as $r) {
            $value = preg_replace('/(?<=' . preg_quote($tag, '/') . ')' . preg_quote($old, '/') . '(?=[\s\/]|$)/', $new, (string) $r['value']);
            if ($value !== $r['value']) {
                $pdo->prepare('UPDATE dns_records SET value = ? WHERE id = ?')->execute([$value, $r['id']]);
                $zones[(int) $r['zone_id']] = true;
                $records++;
            }
        }
        foreach (array_keys($zones) as $z) {
            Dns::publish($z);
        }
        Audit::log((int) $actor['id'], 'server.ip_change', ['from' => $old, 'to' => $new, 'records' => $records, 'zones' => count($zones)]);

        return ['records' => $records, 'zones' => count($zones)];
    }

    // ---------------- software ----------------
    public const SOFTWARE = ['tomcat10' => 'Apache Tomcat 10 (Java apps)', 'default-jdk' => 'Java (OpenJDK)', 'varnish' => 'Varnish cache', 'php-pear' => 'PHP PEAR',
        'cpanminus' => 'Perl modules (cpanm)', 'nodejs' => 'Node.js', 'python3-pip' => 'Python pip', 'memcached' => 'Memcached', 'imagemagick' => 'ImageMagick',
        'ffmpeg' => 'FFmpeg', 'git' => 'Git', 'composer' => 'Composer', 'supervisor' => 'Supervisor (queue workers)', 'mongodb-org' => 'MongoDB', 'elasticsearch' => 'Elasticsearch'];

    public static function software(): array
    {
        $out = [];
        foreach (self::SOFTWARE as $pkg => $label) {
            $r = Exec::run(['dpkg-query', '-W', '-f=${Status} ${Version}', $pkg]);
            $out[] = ['package' => $pkg, 'name' => $label, 'installed' => str_contains($r['out'], 'install ok installed'), 'version' => preg_match('/installed (\S+)/', $r['out'], $m) ? $m[1] : null];
        }

        return $out;
    }

    /** Install / remove one of the listed packages, or a Perl (cpanm) / PEAR module by name. */
    public static function installSoftware(string $kind, string $name, bool $install): array
    {
        $argv = match ($kind) {
            'package' => isset(self::SOFTWARE[$name]) ? ['env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-y', $install ? 'install' : 'remove', $name] : null,
            'perl' => preg_match('/^[A-Za-z][A-Za-z0-9:]{1,80}$/', $name) ? ($install ? ['cpanm', '--notest', $name] : null) : null,
            'pear' => preg_match('/^[A-Za-z][A-Za-z0-9_\/-]{1,80}$/', $name) ? ['pear', $install ? 'install' : 'uninstall', $name] : null,
            default => null,
        };
        if (!$argv) {
            throw new PanelError('Unknown package or module name.', 422);
        }
        $r = Exec::run($argv, null, 1800);

        return ['ok' => $r['code'] === 0, 'log' => mb_substr($r['out'], -4000)];
    }

    // ---------------- many accounts at once ----------------
    /** action: plan (plan_id), suspend, unsuspend, php (version) for the chosen users the actor manages. */
    public static function mass(array $actor, array $in): array
    {
        $ids = array_map('intval', (array) ($in['users'] ?? []));
        $action = (string) ($in['action'] ?? '');
        $done = [];
        foreach ($ids as $id) {
            $u = Auth::user($id);
            if (!$u || !Auth::manages($actor, $u) || $id === (int) $actor['id']) {
                continue;
            }
            switch ($action) {
                case 'plan':
                    Plans::assign($actor, $id, (int) ($in['plan_id'] ?? 0) ?: null);
                    break;
                case 'suspend':
                case 'unsuspend':
                    Db::pdo()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$action === 'unsuspend' ? 1 : 0, $id]);
                    foreach (self::sitesOf($id) as $s) {
                        Sites::toggle($actor, (int) $s['id'], $action === 'unsuspend');
                    }
                    if ($action === 'suspend') {
                        Db::pdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
                    }
                    break;
                case 'php':
                    $v = (string) ($in['php'] ?? '');
                    if (!in_array($v, Sites::phpVersions(), true)) {
                        throw new PanelError('That PHP version is not installed.', 422);
                    }
                    foreach (self::sitesOf($id) as $s) {
                        if ($s['type'] === 'php') {
                            Sites::update($actor, (int) $s['id'], ['php' => $v]);
                        }
                    }
                    break;
                default:
                    throw new PanelError('Unknown action.', 422);
            }
            $done[] = $u['name'];
        }
        Audit::log((int) $actor['id'], 'users.mass_' . $action, ['users' => $done]);

        return ['changed' => $done];
    }
}
