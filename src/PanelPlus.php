<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Server features the other control panels have (0.11.0, owner 2026-11-08: "every feature of every panel"): compared with Plesk,
 * DirectAdmin, CyberPanel, HestiaCP, aaPanel, CloudPanel, ISPConfig and Virtualmin / Webmin. Own code and wording, patterns only.
 * System services and logs, time zone and time sync, swap, fail2ban jails, blocking whole countries, who may sign in to the panel,
 * history graphs, the advisor, remote backup storage (rclone: Google Drive, Dropbox, OneDrive, SFTP, FTP, B2 ...), DNS cluster,
 * Cloudflare DNS and moving a site from any server over SSH. Owner only; the routes write every change to the activity log.
 */
final class PanelPlus
{
    private const UNIT = '/^[a-zA-Z0-9@._-]{1,120}\.service$/';
    private const PROTECTED_UNITS = ['zarspanel.service', 'ssh.service', 'sshd.service', 'systemd-journald.service', 'dbus.service', 'systemd-logind.service'];
    public const RCLONE_TYPES = ['sftp', 'ftp', 'drive', 'dropbox', 'onedrive', 'b2', 'webdav', 'pcloud', 'mega'];

    private static function json(string $key, mixed $default = []): mixed
    {
        return json_decode((string) Db::setting($key, json_encode($default)), true) ?? $default;
    }

    // ---- system services (Webmin "Bootup and shutdown", aaPanel / Plesk "Services management") ------------------------------

    public static function services(): array
    {
        $enabled = [];
        foreach (explode("\n", Exec::run(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--plain'])['out']) as $l) {
            $p = preg_split('/\s+/', trim($l));
            if (count($p) >= 2) {
                $enabled[$p[0]] = $p[1];
            }
        }
        $out = [];
        foreach (explode("\n", Exec::run(['systemctl', 'list-units', '--type=service', '--all', '--no-legend', '--plain'])['out']) as $l) {
            $p = preg_split('/\s+/', trim($l), 5);
            if (count($p) < 4 || !preg_match(self::UNIT, $p[0])) {
                continue;
            }
            $out[] = ['name' => $p[0], 'load' => $p[1], 'active' => $p[2], 'sub' => $p[3], 'about' => $p[4] ?? '', 'boot' => $enabled[$p[0]] ?? '', 'locked' => in_array($p[0], self::PROTECTED_UNITS, true)];
        }

        return ['services' => $out];
    }

    public static function service(string $name, string $action): array
    {
        if (!preg_match(self::UNIT, $name) || !in_array($action, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
            throw new PanelError('Unknown service or action.', 422);
        }
        if (in_array($name, self::PROTECTED_UNITS, true) && in_array($action, ['stop', 'disable'], true)) {
            throw new PanelError("$name keeps the server reachable; it cannot be stopped from here.", 422);
        }
        $r = Exec::run(['systemctl', $action, $name], null, 90);
        if ($r['code'] !== 0) {
            throw new PanelError(trim($r['out']) ?: "systemctl $action $name did not work.", 500);
        }

        return self::services();
    }

    /** The system journal (Webmin "System logs", Plesk "Log browser"): one service or all, newest last. */
    public static function journal(string $unit, int $lines, string $find): array
    {
        $argv = ['journalctl', '--no-pager', '-o', 'short-iso', '-n', (string) max(20, min(2000, $lines))];
        if ($unit !== '') {
            if (!preg_match(self::UNIT, $unit) && !preg_match('/^[a-zA-Z0-9@._-]{1,120}$/', $unit)) {
                throw new PanelError('Unknown service.', 422);
            }
            array_push($argv, '-u', $unit);
        }
        if ($find !== '') {
            array_push($argv, '--grep', mb_substr($find, 0, 100), '--case-sensitive=false');
        }

        return ['log' => Exec::run($argv, null, 30)['out']];
    }

    // ---- time zone, time sync and swap (aaPanel, CloudPanel, Webmin) ---------------------------------------------------------

    public static function time(): array
    {
        $v = [];
        foreach (explode("\n", Exec::run(['timedatectl', 'show'])['out']) as $l) {
            if (str_contains($l, '=')) {
                [$k, $val] = explode('=', $l, 2);
                $v[$k] = $val;
            }
        }
        $swap = [];
        foreach (array_slice(explode("\n", trim((string) @file_get_contents('/proc/swaps'))), 1) as $l) {
            $p = preg_split('/\s+/', trim($l));
            if (count($p) >= 4) {
                $swap[] = ['file' => $p[0], 'size' => (int) $p[2] * 1024, 'used' => (int) $p[3] * 1024];
            }
        }

        return ['timezone' => $v['Timezone'] ?? date_default_timezone_get(), 'synced' => ($v['NTPSynchronized'] ?? '') === 'yes', 'ntp' => ($v['NTP'] ?? '') === 'yes',
            'now' => date('c'), 'zones' => \DateTimeZone::listIdentifiers(), 'swap' => $swap, 'swap_mb' => (int) Db::setting('swap_mb', '0')];
    }

    public static function setTime(array $in): array
    {
        if (isset($in['timezone'])) {
            $tz = (string) $in['timezone'];
            if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                throw new PanelError('Choose a time zone from the list.', 422);
            }
            Exec::run(['timedatectl', 'set-timezone', $tz]);
        }
        if (isset($in['ntp'])) {
            Exec::run(['timedatectl', 'set-ntp', $in['ntp'] ? 'true' : 'false']);
        }

        return self::time();
    }

    /** A swap file of the given size (0 = none), kept after a restart (fstab). */
    public static function swap(int $mb): array
    {
        if ($mb < 0 || $mb > 65536) {
            throw new PanelError('Give the swap size in MB (0 to remove it, at most 65536).', 422);
        }
        $file = '/swapfile';
        $fstab = Config::etc() . '/fstab';
        $lines = array_filter(explode("\n", (string) @file_get_contents($fstab)), fn ($l) => !str_starts_with(trim($l), $file . ' '));
        Exec::run(['swapoff', $file]);
        Exec::run(['rm', '-f', $file]);
        if ($mb > 0) {
            foreach ([['fallocate', '-l', $mb . 'M', $file], ['chmod', '600', $file], ['mkswap', $file], ['swapon', $file]] as $c) {
                $r = Exec::run($c, null, 600);
                if ($r['code'] !== 0) {
                    throw new PanelError('The swap file could not be made: ' . mb_substr(trim($r['out']), 0, 200), 500);
                }
            }
            $lines[] = "$file none swap sw 0 0";
        }
        Exec::write($fstab, rtrim(implode("\n", $lines)) . "\n", 0644);
        Db::put('swap_mb', (string) $mb);

        return self::time();
    }

    // ---- fail2ban jails (Plesk "IP address banning", DirectAdmin "Brute force monitor", HestiaCP) -----------------------------

    public static function jails(): array
    {
        $list = Exec::run(['fail2ban-client', 'status'])['out'];
        $names = preg_match('/Jail list:\s*(.*)$/m', $list, $m) ? array_filter(array_map('trim', explode(',', $m[1]))) : [];
        $jails = [];
        foreach ($names as $j) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/', $j)) {
                continue;
            }
            $o = Exec::run(['fail2ban-client', 'status', $j])['out'];
            $jails[] = ['name' => $j, 'failed' => preg_match('/Currently failed:\s*(\d+)/', $o, $a) ? (int) $a[1] : 0,
                'banned' => preg_match('/Currently banned:\s*(\d+)/', $o, $b) ? (int) $b[1] : 0, 'total' => preg_match('/Total banned:\s*(\d+)/', $o, $c) ? (int) $c[1] : 0,
                'ips' => preg_match('/Banned IP list:\s*(.*)$/m', $o, $d) ? array_values(array_filter(preg_split('/\s+/', trim($d[1])))) : []];
        }

        return ['jails' => $jails, 'settings' => self::json('f2b', ['bantime' => 600, 'findtime' => 600, 'maxretry' => 5, 'ignore' => ''])];
    }

    public static function jailAction(string $jail, string $action, string $ip): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/', $jail) || !in_array($action, ['ban', 'unban'], true) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new PanelError('Give the jail and a valid IP address.', 422);
        }
        Exec::run(['fail2ban-client', 'set', $jail, $action === 'ban' ? 'banip' : 'unbanip', $ip]);

        return self::jails();
    }

    public static function jailSettings(array $in): array
    {
        $ignore = [];
        foreach (preg_split('/[\s,]+/', (string) ($in['ignore'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            if (!SiteTools::validCidr($ip)) {
                throw new PanelError("\"$ip\" is not an IP address or range.", 422);
            }
            $ignore[] = $ip;
        }
        $s = ['bantime' => max(60, min(31536000, (int) ($in['bantime'] ?? 600))), 'findtime' => max(60, min(86400, (int) ($in['findtime'] ?? 600))),
            'maxretry' => max(1, min(100, (int) ($in['maxretry'] ?? 5))), 'ignore' => implode(' ', $ignore)];
        Exec::write(Config::etc() . '/fail2ban/jail.d/zarspanel.local', "# ZARS Panel\n[DEFAULT]\nbantime = {$s['bantime']}\nfindtime = {$s['findtime']}\nmaxretry = {$s['maxretry']}\nignoreip = 127.0.0.1/8 ::1 {$s['ignore']}\n", 0644);
        Exec::run(['fail2ban-client', 'reload']);
        Db::put('f2b', json_encode($s));

        return self::jails();
    }

    // ---- block whole countries (HestiaCP ipset lists, aaPanel, CSF) -----------------------------------------------------------

    public static function countries(): array
    {
        return ['countries' => self::json('blocked_countries'), 'allow' => self::json('country_allow')];
    }

    /** Drop every connection from these countries (lists from ipdeny.com, refreshed nightly); the owner's own address stays allowed. */
    public static function setCountries(array $codes, string $ownIp): array
    {
        $clean = [];
        foreach ($codes as $c) {
            $c = strtolower(trim((string) $c));
            if ($c === '') {
                continue;
            }
            if (!preg_match('/^[a-z]{2}$/', $c)) {
                throw new PanelError("\"$c\" is not a two-letter country code.", 422);
            }
            $clean[$c] = true;
        }
        $allow = self::json('country_allow');
        if ($clean && filter_var($ownIp, FILTER_VALIDATE_IP) && !in_array($ownIp, $allow, true)) {
            $allow[] = $ownIp; // never lock the owner out
        }
        Db::put('blocked_countries', json_encode(array_keys($clean)));
        Db::put('country_allow', json_encode($allow));
        self::applyCountries();

        return self::countries();
    }

    /** Builds the ipset and the firewall rules (also at boot and every night: zp country-block). */
    public static function applyCountries(): void
    {
        $codes = self::json('blocked_countries');
        Exec::run(['ipset', 'create', 'zp-countries', 'hash:net', 'maxelem', '1000000', '-exist']);
        Exec::run(['ipset', 'flush', 'zp-countries']);
        $add = '';
        foreach ($codes as $c) {
            $r = Exec::run(['curl', '-fsS', '-m', '60', "https://www.ipdeny.com/ipblocks/data/aggregated/$c-aggregated.zone"], null, 90);
            foreach (explode("\n", $r['out']) as $net) {
                $net = trim($net);
                if ($net !== '' && SiteTools::validCidr($net)) {
                    $add .= "add zp-countries $net -exist\n";
                }
            }
        }
        if ($add !== '') {
            Exec::run(['ipset', 'restore'], $add, 300);
        }
        foreach (self::json('country_allow') as $ip) {
            if (!Exec::ok(['iptables', '-C', 'INPUT', '-s', $ip, '-j', 'ACCEPT'])) {
                Exec::run(['iptables', '-I', 'INPUT', '1', '-s', $ip, '-j', 'ACCEPT']);
            }
        }
        $rule = ['INPUT', '-m', 'set', '--match-set', 'zp-countries', 'src', '-j', 'DROP'];
        $has = Exec::ok(array_merge(['iptables', '-C'], $rule));
        if ($codes && !$has) {
            Exec::run(array_merge(['iptables', '-A'], $rule));
        } elseif (!$codes && $has) {
            Exec::run(array_merge(['iptables', '-D'], $rule));
        }
    }

    // ---- who may sign in to the panel (aaPanel "authorized IPs", Plesk "restrict administrative access") --------------------

    public static function access(): array
    {
        return ['allow_ips' => self::json('panel_allow_ips'), 'login_alert' => Db::setting('login_alert', '0') === '1', 'session_hours' => (int) Db::setting('session_hours', (string) Auth::SESSION_HOURS)];
    }

    public static function setAccess(array $in, string $ownIp): array
    {
        if (isset($in['allow_ips'])) {
            $ips = [];
            foreach (preg_split('/[\s,]+/', is_array($in['allow_ips']) ? implode(' ', $in['allow_ips']) : (string) $in['allow_ips'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
                if (!SiteTools::validCidr($ip)) {
                    throw new PanelError("\"$ip\" is not an IP address or range.", 422);
                }
                $ips[] = $ip;
            }
            if ($ips && !self::ipIn($ownIp, $ips)) {
                throw new PanelError("Your own address ($ownIp) is not in the list, so you would be locked out. Add it first.", 422);
            }
            Db::put('panel_allow_ips', json_encode($ips));
        }
        if (isset($in['login_alert'])) {
            Db::put('login_alert', $in['login_alert'] ? '1' : '0');
        }
        if (isset($in['session_hours'])) {
            Db::put('session_hours', (string) max(1, min(720, (int) $in['session_hours'])));
        }

        return self::access();
    }

    public static function ipIn(string $ip, array $list): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach ($list as $net) {
            [$addr, $bits] = array_pad(explode('/', (string) $net, 2), 2, null);
            $a = @inet_pton($ip);
            $b = @inet_pton($addr);
            if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                continue;
            }
            $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest) & 0xFF)) === 0) {
                return true;
            }
        }

        return false;
    }

    // ---- history graphs (aaPanel monitor, Plesk "Monitoring", CyberPanel) -------------------------------------------------------

    /** Every 5 minutes from the alerts job: one row of numbers; kept 30 days. */
    public static function record(?array $snap = null): void
    {
        $s = $snap ?? Monitor::snapshot();
        Db::pdo()->prepare('INSERT OR REPLACE INTO metrics (t, cpu, mem, disk, load1, rx, tx) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([intdiv(time(), 300) * 300, $s['cpu'], $s['memory']['percent'], $s['disk']['percent'], $s['load'][0] ?? 0, $s['network']['rx'] ?? 0, $s['network']['tx'] ?? 0]);
        Db::pdo()->exec('DELETE FROM metrics WHERE t < ' . (time() - 30 * 86400));
    }

    public static function history(string $range): array
    {
        $secs = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400][$range] ?? 86400;
        $step = ['24h' => 300, '7d' => 3600, '30d' => 4 * 3600][$range] ?? 300;
        $st = Db::pdo()->prepare("SELECT (t / $step) * $step AS t, ROUND(AVG(cpu), 1) AS cpu, ROUND(AVG(mem), 1) AS mem, ROUND(AVG(disk), 1) AS disk, ROUND(AVG(load1), 2) AS load1, MAX(rx) AS rx, MAX(tx) AS tx FROM metrics WHERE t >= ? GROUP BY t / $step ORDER BY t");
        $st->execute([time() - $secs]);
        $rows = $st->fetchAll();
        // network as bytes per second between points (the counters restart at boot)
        for ($i = count($rows) - 1; $i > 0; $i--) {
            $dt = max(1, $rows[$i]['t'] - $rows[$i - 1]['t']);
            $rows[$i]['rx_s'] = max(0, (int) (($rows[$i]['rx'] - $rows[$i - 1]['rx']) / $dt));
            $rows[$i]['tx_s'] = max(0, (int) (($rows[$i]['tx'] - $rows[$i - 1]['tx']) / $dt));
        }

        return ['range' => $range, 'points' => array_map(fn ($r) => ['t' => (int) $r['t'], 'cpu' => (float) $r['cpu'], 'mem' => (float) $r['mem'], 'disk' => (float) $r['disk'], 'load' => (float) $r['load1'],
            'rx' => $r['rx_s'] ?? 0, 'tx' => $r['tx_s'] ?? 0], $rows)];
    }

    // ---- the advisor (Plesk Advisor, cPanel Security Advisor): what to fix, with the page that fixes it ---------------------

    public static function advisor(array $owner): array
    {
        $c = [];
        $add = function (string $key, bool $ok, string $good, string $bad, string $to) use (&$c) {
            $c[] = ['key' => $key, 'ok' => $ok, 'text' => $ok ? $good : $bad, 'to' => $to];
        };
        $fw = Firewall::status();
        $add('firewall', !empty($fw['active']), 'The firewall is on', 'The firewall is off', 'firewall');
        $add('two_step', !empty($owner['totp_secret']), 'Your account uses two-step sign-in', 'Your account has no two-step sign-in', 'security');
        $add('root_password', !Firewall::rootLogin(), 'root cannot sign in over SSH with a password', 'root can sign in over SSH with a password', 'firewall');
        $f2b = trim(Exec::run(['systemctl', 'is-active', 'fail2ban'])['out']) === 'active';
        $add('fail2ban', $f2b, 'Fail2ban blocks addresses that guess passwords', 'Fail2ban is not running', 'tools/jails');
        $noSsl = (int) Db::pdo()->query("SELECT COUNT(*) FROM sites WHERE ssl = 0 AND type != 'redirect'")->fetchColumn();
        $add('ssl', $noSsl === 0, 'Every website has an SSL certificate', "$noSsl websites have no SSL certificate", 'sites');
        $old = (int) Db::pdo()->query("SELECT COUNT(*) FROM sites WHERE type = 'php' AND php IS NOT NULL AND php < '8.1'")->fetchColumn();
        $add('php', $old === 0, 'Every website uses a supported PHP version', "$old websites use a PHP version without security fixes (older than 8.1)", 'sites');
        $nightly = (int) Db::pdo()->query("SELECT COUNT(*) FROM crons WHERE kind IN ('backup_site', 'backup_db')")->fetchColumn() > 0;
        $add('backups', $nightly, 'Backups run by themselves', 'No backup runs by itself (add a backup job)', 'cron');
        $add('offsite', S3::config() !== null || Db::setting('rclone', '') !== '', 'Backups are also copied off this server', 'Backups stay only on this server', 'tools/rclone');
        $al = Alerts::settings();
        $add('alerts', $al['email'] !== '' || $al['webhook'] !== '', 'Someone is told when the server has a problem', 'Nobody is told when the server has a problem', 'settings/alerts');
        $snap = Monitor::snapshot();
        $add('disk', $snap['disk']['percent'] < 85, 'The disk is ' . $snap['disk']['percent'] . '% full', 'The disk is ' . $snap['disk']['percent'] . '% full', 'overview');
        $t = self::time();
        $add('swap', (bool) $t['swap'], 'The server has swap space for busy moments', 'The server has no swap space', 'tools/time');
        $add('ntp', $t['synced'], 'The server clock is synchronised', 'The server clock is not synchronised', 'tools/time');
        try {
            $u = Updates::check();
            $add('updates', !$u['update'], 'The panel is up to date', 'A new panel version is waiting: ' . $u['latest'], 'settings/updates');
        } catch (\Throwable $e) {
            $add('updates', false, '', 'The update server could not be asked', 'settings/updates');
        }
        $good = count(array_filter($c, fn ($x) => $x['ok']));

        return ['score' => count($c) ? (int) round($good * 100 / count($c)) : 100, 'checks' => $c];
    }

    // ---- remote backup storage with rclone (CloudPanel, HestiaCP, Plesk: Google Drive, Dropbox, OneDrive, SFTP, FTP ...) ----

    public static function rcloneFile(): string
    {
        return Config::ensure(Config::home() . '/rclone') . '/rclone.conf';
    }

    public static function rclone(): array
    {
        $r = json_decode((string) Db::setting('rclone', ''), true) ?: null;

        return ['remote' => $r ? ['type' => $r['type'], 'host' => $r['host'] ?? '', 'user' => $r['user'] ?? '', 'folder' => $r['folder'] ?? ''] : null, 'types' => self::RCLONE_TYPES];
    }

    /** Saves the remote as [zp] in the panel's own rclone.conf (0600). Cloud drives take the token from "rclone authorize <type>". */
    public static function setRclone(array $in): array
    {
        $type = (string) ($in['type'] ?? '');
        if ($type === '') {
            @unlink(self::rcloneFile());
            Db::put('rclone', '');

            return self::rclone();
        }
        if (!in_array($type, self::RCLONE_TYPES, true)) {
            throw new PanelError('Choose where the backups go.', 422);
        }
        $folder = trim((string) ($in['folder'] ?? 'zarspanel-backups'), '/');
        if (!preg_match('#^[A-Za-z0-9._/ -]{1,120}$#', $folder) || str_contains($folder, '..')) {
            throw new PanelError('The folder name may have letters, digits, . _ - / and spaces.', 422);
        }
        $conf = "[zp]\ntype = $type\n";
        $keep = ['type' => $type, 'folder' => $folder];
        $clean = fn ($v) => str_replace(["\n", "\r"], '', trim((string) $v));
        if (in_array($type, ['sftp', 'ftp', 'webdav'], true)) {
            $host = $clean($in['host'] ?? '');
            $user = $clean($in['user'] ?? '');
            if ($host === '' || $user === '') {
                throw new PanelError('Give the server address and the user.', 422);
            }
            $pass = Exec::run(['rclone', 'obscure', (string) ($in['password'] ?? '')])['out'];
            $conf .= ($type === 'webdav' ? "url = $host\nvendor = other\n" : "host = $host\nport = " . (int) ($in['port'] ?? ($type === 'sftp' ? 22 : 21)) . "\n") . "user = $user\npass = " . trim($pass) . "\n";
            $keep += ['host' => $host, 'user' => $user];
        } elseif ($type === 'b2') {
            $conf .= 'account = ' . $clean($in['user'] ?? '') . "\nkey = " . $clean($in['password'] ?? '') . "\n";
            $keep['user'] = $clean($in['user'] ?? '');
        } elseif ($type === 'mega') {
            $conf .= 'user = ' . $clean($in['user'] ?? '') . "\npass = " . trim(Exec::run(['rclone', 'obscure', (string) ($in['password'] ?? '')])['out']) . "\n";
            $keep['user'] = $clean($in['user'] ?? '');
        } else {
            $token = $clean($in['token'] ?? '');
            if ($token === '' || json_decode($token) === null) {
                throw new PanelError('Paste the token that "rclone authorize ' . $type . '" printed on your computer.', 422);
            }
            $conf .= "token = $token\n" . ($type === 'onedrive' ? "drive_type = personal\n" : '');
        }
        Exec::write(self::rcloneFile(), $conf, 0600);
        Db::put('rclone', json_encode($keep));

        return self::rclone();
    }

    public static function rcloneTest(): array
    {
        $r = Exec::run(['rclone', '--config', self::rcloneFile(), 'mkdir', 'zp:' . (self::rclone()['remote']['folder'] ?? 'zarspanel-backups')], null, 60);

        return ['ok' => $r['code'] === 0, 'message' => $r['code'] === 0 ? 'The storage answers and the folder is there.' : mb_substr(trim($r['out']), 0, 300)];
    }

    /** After every backup (Backups::record): copy the file to the remote storage too. */
    public static function rcloneCopy(string $file): bool
    {
        $r = self::rclone()['remote'];
        if (!$r) {
            return false;
        }

        return Exec::run(['rclone', '--config', self::rcloneFile(), 'copy', $file, 'zp:' . $r['folder']], null, 3600)['code'] === 0;
    }

    // ---- DNS cluster (cPanel / DirectAdmin / HestiaCP / ISPConfig): other ZARS Panel servers answer for our zones too ----------

    public static function cluster(): array
    {
        return ['secondaries' => self::json('dns_secondaries'), 'primaries' => self::json('dns_primaries')];
    }

    /** This server is the primary: these servers may copy every zone and are told about every change. */
    public static function setSecondaries(array $ips): array
    {
        $clean = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new PanelError("\"$ip\" is not an IP address.", 422);
            }
            if ($ip !== '') {
                $clean[] = $ip;
            }
        }
        Db::put('dns_secondaries', json_encode(array_values(array_unique($clean))));
        self::writeClusterConf();

        return self::cluster();
    }

    /** This server is a secondary of these primaries (IP + the zone names they send, read from their panel with an API token). */
    public static function setPrimaries(array $list): array
    {
        $clean = [];
        foreach ($list as $p) {
            $ip = trim((string) ($p['ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new PanelError('Give the IP address of the primary server.', 422);
            }
            $zones = array_values(array_filter(array_map('strtolower', array_map('trim', (array) ($p['zones'] ?? []))), fn ($z) => Dns::validDomain($z)));
            $clean[] = ['ip' => $ip, 'zones' => $zones];
        }
        Db::put('dns_primaries', json_encode($clean));
        self::writeClusterConf();

        return self::cluster();
    }

    public static function zonesForCluster(): array
    {
        return ['zones' => array_column(Db::pdo()->query('SELECT domain FROM dns_zones ORDER BY domain')->fetchAll(), 'domain')];
    }

    private static function writeClusterConf(): void
    {
        Dns::conf(); // the zones of this server, with allow-transfer / also-notify for the secondaries
        $o = "// ZARS Panel DNS cluster: zones this server copies from its primaries\n";
        foreach (self::json('dns_primaries') as $p) {
            foreach ($p['zones'] as $z) {
                $o .= "zone \"$z\" { type slave; masters { {$p['ip']}; }; file \"/var/cache/bind/zp-slave-$z\"; };\n";
            }
        }
        Exec::write(Config::etc() . '/bind/named.conf.zp-cluster', $o, 0644);
        Exec::run(['rndc', 'reconfig']);
    }

    // ---- Cloudflare DNS (CloudPanel, cPanel / Plesk extensions): send a zone's records to Cloudflare --------------------------

    private static function cf(string $method, string $path, string $token, ?array $body = null): array
    {
        $argv = ['curl', '-sS', '-m', '20', '-X', $method, '-H', 'Authorization: Bearer ' . $token, '-H', 'Content-Type: application/json'];
        if ($body !== null) {
            array_push($argv, '--data', json_encode($body));
        }
        $argv[] = 'https://api.cloudflare.com/client/v4' . $path;
        $j = json_decode(Exec::run($argv, null, 30)['out'], true);
        if (!is_array($j) || empty($j['success'])) {
            throw new PanelError('Cloudflare said: ' . ($j['errors'][0]['message'] ?? 'no answer'), 502);
        }

        return $j;
    }

    public static function cloudflareToken(array $actor, string $token): array
    {
        $token = trim($token);
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{30,80}$/', $token)) {
            throw new PanelError('Paste the API token made in Cloudflare (My Profile > API Tokens, "Edit zone DNS").', 422);
        }
        if ($token !== '') {
            self::cf('GET', '/user/tokens/verify', $token);
        }
        Db::put('cf_token_' . $actor['id'], $token === '' ? '' : Secrets::seal($token));

        return ['connected' => $token !== ''];
    }

    /** Creates or updates every record of the zone at Cloudflare (records only at Cloudflare are left alone). */
    public static function cloudflareSync(array $actor, int $zoneId): array
    {
        $z = Dns::zone($actor, $zoneId);
        $sealed = (string) Db::setting('cf_token_' . $actor['id'], '');
        $token = $sealed !== '' ? (string) Secrets::open($sealed) : '';
        if ($token === '') {
            throw new PanelError('Connect Cloudflare first (paste an API token).', 422);
        }
        $zones = self::cf('GET', '/zones?name=' . rawurlencode($z['domain']), $token)['result'] ?? [];
        $cfZone = $zones[0]['id'] ?? throw new PanelError($z['domain'] . ' is not in this Cloudflare account. Add the site in Cloudflare first.', 422);
        $there = [];
        foreach (self::cf('GET', "/zones/$cfZone/dns_records?per_page=500", $token)['result'] ?? [] as $r) {
            $there[$r['type'] . ' ' . $r['name']][] = $r;
        }
        $made = $updated = 0;
        foreach (Dns::records($zoneId) as $r) {
            if (!in_array($r['type'], ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'], true)) {
                continue;
            }
            $name = $r['name'] === '@' ? $z['domain'] : rtrim($r['name'], '.') . (str_ends_with($r['name'], '.') ? '' : '.' . $z['domain']);
            $body = ['type' => $r['type'], 'name' => $name, 'content' => rtrim((string) $r['value'], '.'), 'ttl' => (int) $r['ttl'] < 120 ? 1 : (int) $r['ttl']];
            if ($r['type'] === 'MX') {
                $body['priority'] = (int) $r['prio'];
            }
            $same = null;
            foreach ($there[$r['type'] . ' ' . $name] ?? [] as $old) {
                if (in_array($r['type'], ['A', 'AAAA', 'CNAME'], true) || $old['content'] === $body['content']) {
                    $same = $old;
                    break;
                }
            }
            if ($same) {
                if ($same['content'] !== $body['content']) {
                    self::cf('PUT', "/zones/$cfZone/dns_records/{$same['id']}", $token, $body);
                    $updated++;
                }
            } else {
                self::cf('POST', "/zones/$cfZone/dns_records", $token, $body);
                $made++;
            }
        }

        return ['made' => $made, 'updated' => $updated];
    }

    // ---- move a site from any server over SSH (CloudPanel / CyberPanel / Plesk Migrator): files with rsync, database dump ----

    public static function migrateStart(array $actor, array $in): array
    {
        $host = trim((string) ($in['host'] ?? ''));
        $user = trim((string) ($in['user'] ?? ''));
        $path = trim((string) ($in['path'] ?? ''));
        $port = (int) ($in['port'] ?? 22);
        if (!(filter_var($host, FILTER_VALIDATE_IP) || Dns::validDomain($host)) || !preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $user) || !preg_match('#^/[A-Za-z0-9._/ -]{1,250}$#', $path) || str_contains($path, '..') || $port < 1 || $port > 65535) {
            throw new PanelError('Give the old server (address, SSH port, user) and the folder of the website there, like /home/shop/public_html.', 422);
        }
        $site = Sites::mine($actor, (int) ($in['site_id'] ?? 0));
        $db = null;
        if (!empty($in['db_id'])) {
            $db = Databases::mine($actor, (int) $in['db_id']);
            if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', (string) ($in['remote_db'] ?? '')) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) ($in['remote_db_user'] ?? ''))) {
                throw new PanelError('Give the database name and user on the old server.', 422);
            }
        }
        $id = bin2hex(random_bytes(6));
        $job = ['id' => $id, 'site' => $site['id'], 'root' => $site['root'], 'host' => $host, 'port' => $port, 'user' => $user, 'path' => rtrim($path, '/') . '/',
            'password' => Secrets::seal((string) ($in['password'] ?? '')), 'db' => $db['name'] ?? null, 'remote_db' => $in['remote_db'] ?? null, 'remote_db_user' => $in['remote_db_user'] ?? null,
            'remote_db_password' => Secrets::seal((string) ($in['remote_db_password'] ?? '')), 'by' => $actor['id']];
        $dir = Config::ensure(Config::home() . '/migrations');
        Exec::write("$dir/$id.json", json_encode($job), 0600);
        Exec::write("$dir/$id.log", "Waiting to start...\n", 0640);
        Exec::run(['sh', '-c', 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/zp') . ' migrate ' . escapeshellarg($id) . ' >> ' . escapeshellarg("$dir/$id.log") . ' 2>&1 &']);

        return ['job' => $id];
    }

    public static function migrateLog(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
            throw new PanelError('Unknown move.', 404);
        }
        $f = Config::home() . "/migrations/$id.log";
        $log = (string) @file_get_contents($f);

        return ['log' => $log, 'done' => str_contains($log, 'FINISHED') || str_contains($log, 'STOPPED')];
    }

    /** Runs in the background (zp migrate <id>): rsync of the files, then the database through SSH, then the owner of the files. */
    public static function migrateRun(string $id): void
    {
        $f = Config::home() . "/migrations/$id.json";
        $j = json_decode((string) @file_get_contents($f), true) ?: throw new \RuntimeException('no job');
        @unlink($f); // the passwords are not kept after the start
        $pw = (string) Secrets::open($j['password']);
        $ssh = 'ssh -p ' . (int) $j['port'] . ' -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20';
        $pre = $pw !== '' ? ['sshpass', '-e'] : [];
        $env = $pw !== '' ? 'SSHPASS=' . escapeshellarg($pw) . ' ' : '';
        echo date('H:i:s') . " Copying the files from {$j['host']}:{$j['path']} ...\n";
        $r = Exec::run(['sh', '-c', $env . implode(' ', array_map('escapeshellarg', array_merge($pre, ['rsync', '-az', '--delete', '-e', $ssh, $j['user'] . '@' . $j['host'] . ':' . $j['path'], rtrim($j['root'], '/') . '/'])))], null, 7200);
        echo $r['out'];
        if ($r['code'] !== 0) {
            echo "STOPPED: the files could not be copied (code {$r['code']}).\n";

            return;
        }
        if ($j['db']) {
            echo date('H:i:s') . " Copying the database {$j['remote_db']} into {$j['db']} ...\n";
            $dbpw = (string) Secrets::open($j['remote_db_password']);
            $dump = 'MYSQL_PWD=' . escapeshellarg($dbpw) . ' mysqldump --single-transaction --quick -u ' . escapeshellarg($j['remote_db_user']) . ' ' . escapeshellarg($j['remote_db']);
            $cmd = $env . implode(' ', array_map('escapeshellarg', array_merge($pre, ['ssh', '-p', (string) (int) $j['port'], '-o', 'StrictHostKeyChecking=accept-new', $j['user'] . '@' . $j['host'], $dump]))) . ' | mysql ' . escapeshellarg($j['db']);
            $r = Exec::run(['sh', '-c', $cmd], null, 7200);
            echo $r['out'];
            if ($r['code'] !== 0) {
                echo "STOPPED: the database could not be copied (code {$r['code']}).\n";

                return;
            }
        }
        SysUser::ownPath($j['root']);
        Audit::log((int) $j['by'], 'migrate.ssh', ['site' => $j['site'], 'from' => $j['host']]);
        echo date('H:i:s') . " FINISHED. Update the database password in the site's configuration (wp-config.php, .env) if it changed, then point the domain here.\n";
    }
}
