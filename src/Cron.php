<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Scheduled jobs. They live in the panel database and are written to one cron file (/etc/cron.d/zarspanel). Kinds: shell (run in a
 * site folder as the web user), url (fetch an address), backup_site, backup_db. A job can only touch what its owner owns.
 */
final class Cron
{
    public const KINDS = ['shell', 'url', 'backup_site', 'backup_db'];

    public static function file(): string
    {
        return Config::etc() . '/cron.d/zarspanel';
    }

    public static function validSchedule(string $s): bool
    {
        $f = preg_split('/\s+/', trim($s));
        if (count($f) !== 5) {
            return false;
        }
        $range = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach ($f as $i => $field) {
            foreach (explode(',', $field) as $part) {
                if (!preg_match('#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$#', $part, $m)) {
                    return false;
                }
                [$lo, $hi] = $range[$i];
                foreach ([$m[2] ?? '', $m[3] ?? ''] as $n) {
                    if ($n !== '' && ((int) $n < $lo || (int) $n > $hi)) {
                        return false;
                    }
                }
                if (($m[3] ?? '') !== '' && (int) $m[3] < (int) $m[2]) {
                    return false;
                }
                if (isset($m[4]) && (int) $m[4] < 1) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function list(array $actor): array
    {
        if ($actor['role'] === 'owner') {
            return Db::pdo()->query('SELECT c.*, u.name AS owner FROM crons c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC')->fetchAll();
        }
        $st = Db::pdo()->prepare('SELECT c.*, u.name AS owner FROM crons c JOIN users u ON u.id = c.user_id WHERE c.user_id = :a OR c.user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY c.id DESC');
        $st->execute([':a' => $actor['id']]);

        return $st->fetchAll();
    }

    public static function mine(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM crons WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch() ?: throw new PanelError('Job not found.', 404);
        $owner = Auth::user((int) $c['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Job not found.', 404);
        }

        return $c;
    }

    public static function create(array $actor, array $in): array
    {
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new PanelError('Choose what the job does.', 422);
        }
        $schedule = trim(preg_replace('/\s+/', ' ', (string) ($in['schedule'] ?? '')));
        if (!self::validSchedule($schedule)) {
            throw new PanelError('The schedule must be five parts like */5 * * * * (minute hour day month weekday).', 422);
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80) ?: $kind;
        $target = trim((string) ($in['target'] ?? ''));
        Quota::check($actor, 'crons');
        switch ($kind) {
            case 'shell':
                $site = Sites::mine($actor, (int) ($in['site_id'] ?? 0));
                $cmd = trim((string) ($in['command'] ?? ''));
                if ($cmd === '' || preg_match('/[\r\n\0%]/', $cmd) || strlen($cmd) > 600) {
                    throw new PanelError('Enter one command (no line breaks, no % sign).', 422);
                }
                $target = json_encode(['site_id' => (int) $site['id'], 'command' => $cmd]);
                break;
            case 'url':
                if (!preg_match('#^https?://[^\s\'"`$;&|<>\\\\%]+$#i', $target)) {
                    throw new PanelError('Enter a web address starting with http:// or https://', 422);
                }
                break;
            case 'backup_site':
                $target = (string) Sites::mine($actor, (int) ($in['site_id'] ?? 0))['id'];
                break;
            case 'backup_db':
                $target = (string) Databases::mine($actor, (int) ($in['db_id'] ?? 0))['id'];
                break;
        }
        Db::pdo()->prepare('INSERT INTO crons (user_id, name, kind, schedule, target, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$actor['id'], $name, $kind, $schedule, $target, gmdate('c')]);
        $id = (int) Db::pdo()->lastInsertId();
        self::render();
        Audit::log((int) $actor['id'], 'cron.create', ['name' => $name, 'kind' => $kind]);

        return self::mine($actor, $id);
    }

    public static function toggle(array $actor, int $id, bool $on): void
    {
        self::mine($actor, $id);
        Db::pdo()->prepare('UPDATE crons SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
        self::render();
    }

    public static function delete(array $actor, int $id): void
    {
        self::mine($actor, $id);
        Db::pdo()->prepare('DELETE FROM crons WHERE id = ?')->execute([$id]);
        self::render();
        Audit::log((int) $actor['id'], 'cron.delete', ['id' => $id]);
    }

    /** The command line cron runs for a job, as ['user', 'command']. */
    public static function line(array $c): array
    {
        $zp = dirname(__DIR__) . '/bin/zp';
        switch ($c['kind']) {
            case 'shell':
                $t = json_decode($c['target'], true);
                $site = Sites::find((int) $t['site_id']);
                $dir = $site ? Config::www() . '/' . $site['domain'] : Config::www();

                return [$site ? SysUser::of($site) : 'www-data', 'cd ' . escapeshellarg($dir) . ' && ' . $t['command'] . ' >> ' . escapeshellarg(Nginx::logs() . '/cron-' . $c['id'] . '.log') . ' 2>&1'];
            case 'url':
                return ['www-data', 'curl -fsS -m 60 -o /dev/null ' . escapeshellarg($c['target'])];
            case 'backup_site':
                return ['root', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($zp) . ' backup-site ' . (int) $c['target'] . ' ' . (int) $c['user_id']];
            default:
                return ['root', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($zp) . ' backup-db ' . (int) $c['target'] . ' ' . (int) $c['user_id']];
        }
    }

    public static function render(): void
    {
        $zp = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/zp');
        $out = "# Managed by ZARS Panel. Do not edit; use the panel.\nSHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n";
        // the server's own jobs (the Webuzo set): SSL renewal, alerts, bandwidth, service check, daily update check and clean-up
        $out .= "17 3 * * * root certbot renew --quiet --deploy-hook 'systemctl reload nginx'\n*/5 * * * * root $zp alerts\n7 * * * * root $zp bandwidth\n"
            . "*/5 * * * * root $zp service-check\n*/5 * * * * root $zp process-killer\n*/5 * * * * root $zp plugin-jobs\n11 * * * * root $zp process-log\n" . sprintf('%d 4 * * * root %s update-check', crc32((string) gethostname()) % 60, $zp) . "\n37 4 * * * root $zp cleanup\n" . sprintf('%d 3 * * * root %s licence-check', crc32((string) gethostname()) % 60, $zp) . "\n@reboot root $zp country-block\n23 5 * * * root $zp country-block\n";
        foreach (Db::pdo()->query('SELECT * FROM crons WHERE enabled = 1 ORDER BY id')->fetchAll() as $c) {
            [$user, $cmd] = self::line($c);
            $out .= "{$c['schedule']} $user $cmd\n";
            if ($c['kind'] === 'shell' && $user !== 'root') { // the job appends to its log as its own user
                $log = Nginx::logs() . '/cron-' . $c['id'] . '.log';
                Exec::run(['touch', $log]);
                Exec::run(['chown', "$user:$user", $log]);
            }
        }
        Exec::write(self::file(), $out, 0644);
    }
}
