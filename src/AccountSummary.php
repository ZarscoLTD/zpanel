<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * The hosting account at a glance (the right column of a cPanel / Webuzo Enduser home): who, primary domain, IP, home folder, last
 * sign-in, and every resource with what is used and the plan's limit. Disk and bandwidth are measured at most every 10 minutes.
 */
final class AccountSummary
{
    public static function of(array $u): array
    {
        $pdo = Db::pdo();
        $id = (int) $u['id'];
        $count = function (string $sql) use ($pdo, $id): int {
            $st = $pdo->prepare($sql);
            $st->execute([$id]);

            return (int) $st->fetchColumn();
        };
        $sites = $pdo->prepare('SELECT domain, aliases FROM sites WHERE user_id = ? ORDER BY id');
        $sites->execute([$id]);
        $sites = $sites->fetchAll();
        $aliases = array_sum(array_map(fn ($s) => count(array_filter(preg_split('/[\s,]+/', (string) $s['aliases']))), $sites));
        $quota = json_decode((string) $u['quota'], true) ?: [];
        [$disk, $bw] = self::measured($u, $sites);
        $last = $pdo->prepare("SELECT ip, created_at FROM audit WHERE user_id = ? AND action LIKE 'login%' ORDER BY id DESC LIMIT 1 OFFSET 1");
        $last->execute([$id]);
        $last = $last->fetch() ?: null;
        $plan = null;
        if (!empty($u['plan_id'])) {
            $st = $pdo->prepare('SELECT name FROM plans WHERE id = ?');
            $st->execute([$u['plan_id']]);
            $plan = $st->fetchColumn() ?: null;
        }
        $limit = fn (string $k) => isset($quota[$k]) ? (int) $quota[$k] : null;
        $rows = [
            ['disk', 'Disk usage', $disk, isset($quota['disk_mb']) ? $quota['disk_mb'] * 1048576 : null, 'bytes'],
            ['bandwidth', 'Bandwidth (this month)', $bw, isset($quota['bandwidth_gb']) ? $quota['bandwidth_gb'] * 1073741824 : null, 'bytes'],
            ['sites', 'Domains (websites)', count($sites), $limit('sites'), 'count'],
            ['aliases', 'Aliases and subdomains', $aliases, null, 'count'],
            ['mailboxes', 'Email accounts', $count('SELECT COUNT(*) FROM mailboxes m JOIN mail_domains d ON d.id = m.domain_id WHERE d.user_id = ?'), null, 'count'],
            ['databases', 'Databases', $count('SELECT COUNT(*) FROM dbs WHERE user_id = ?'), $limit('databases'), 'count'],
            ['ftp', 'FTP accounts', $count('SELECT COUNT(*) FROM ftp_users WHERE user_id = ?'), null, 'count'],
            ['crons', 'Cron jobs', $count('SELECT COUNT(*) FROM crons WHERE user_id = ?'), $limit('crons'), 'count'],
            ['backups', 'Backups', $count('SELECT COUNT(*) FROM backups WHERE user_id = ?'), $limit('backups'), 'count'],
        ];

        return [
            'user' => $u['name'], 'role' => $u['role'], 'plan' => $plan, 'primary_domain' => $sites[0]['domain'] ?? null,
            'ip' => (string) (Db::setting('shared_ip') ?: (gethostbyname((string) gethostname()) ?: '')), 'home' => Config::www() . ($sites ? '/' . $sites[0]['domain'] : ''),
            'linux_user' => SysUser::nameFor($id), 'last_login' => $last ? ['ip' => $last['ip'], 'at' => $last['created_at']] : null, 'since' => $u['created_at'],
            'stats' => array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'used' => $r[2], 'limit' => $r[3], 'unit' => $r[4]], $rows),
        ];
    }

    /** [disk bytes, bandwidth bytes this month], kept 10 minutes (du and the access logs are slow on big accounts). */
    private static function measured(array $u, array $sites): array
    {
        $key = 'usage_cache_' . (int) $u['id'];
        $c = json_decode((string) Db::setting($key, '{}'), true) ?: [];
        if (($c['at'] ?? 0) > time() - 600) {
            return [(int) $c['disk'], (int) $c['bw']];
        }
        $disk = 0;
        foreach ($sites as $s) {
            $du = Exec::run(['du', '-sb', Config::www() . '/' . $s['domain']]);
            $disk += (int) (preg_match('/^(\d+)/', $du['out'], $m) ? $m[1] : 0);
        }
        $bw = (int) (Hosting::bandwidth()[(int) $u['id']] ?? 0);
        Db::put($key, json_encode(['at' => time(), 'disk' => $disk, 'bw' => $bw]));

        return [$disk, $bw];
    }
}
