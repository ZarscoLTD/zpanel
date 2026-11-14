<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * The cPanel features that were still missing: phpMyAdmin and Roundcube webmail, Git deployment, single-file restore from a
 * backup, mailbox import from CSV, spam filter per mail domain, mailbox disk usage, visitor statistics (GoAccess), "optimize
 * website" (compression and browser cache), Node.js / Python apps kept running, dynamic DNS, leech protection, calendars and
 * contacts (Radicale) and mailing lists.
 */
final class CpanelParity
{
    // ---------------- phpMyAdmin / Roundcube / Radicale (owner installs once, everyone gets a link) ----------------
    public const TOOLS = ['phpmyadmin' => ['phpmyadmin', '/phpmyadmin/'], 'webmail' => ['roundcube roundcube-mysql', '/webmail/'], 'calendar' => ['radicale', '/dav/'], 'adminer' => ['adminer', '/adminer/']];

    public static function tools(): array
    {
        $out = [];
        foreach (self::TOOLS as $k => [$pkgs, $path]) {
            $pkg = explode(' ', $pkgs)[0];
            $out[$k] = ['installed' => str_contains(Exec::run(['dpkg-query', '-W', '-f=${Status}', $pkg])['out'], 'install ok installed') || Db::setting("tool_$k") === '1', 'url' => self::panelBase() . $path];
        }

        return $out;
    }

    private static function panelBase(): string
    {
        return rtrim(Db::setting('panel_url') ?: 'https://' . (gethostname() ?: 'localhost') . ':' . Firewall::panelPort(), '/');
    }

    public static function installTool(string $k): array
    {
        if (!isset(self::TOOLS[$k])) {
            throw new PanelError('Unknown tool.', 422);
        }
        [$pkgs, $path] = self::TOOLS[$k];
        $r = Exec::run(array_merge(['env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-y', 'install'], explode(' ', $pkgs)), null, 1800);
        $root = ['phpmyadmin' => '/usr/share/phpmyadmin', 'webmail' => '/var/lib/roundcube/public_html', 'calendar' => null, 'adminer' => '/usr/share/adminer'][$k];
        // served by the panel's own nginx server block (included file), PHP through the newest PHP-FPM
        $php = Sites::phpVersions()[count(Sites::phpVersions()) - 1];
        $conf = $root ? "location ^~ $path {\n    alias $root/;\n    index " . ($k === 'adminer' ? 'adminer.php index.php' : 'index.php') . ";\n    location ~ \\.php\$ {\n        include fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME \$request_filename;\n        fastcgi_pass unix:/run/php/php$php-fpm.sock;\n    }\n}\n"
            : "location ^~ $path {\n    proxy_pass http://127.0.0.1:5232/;\n    proxy_set_header X-Script-Name /dav;\n    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n    proxy_set_header Host \$host;\n}\n";
        Exec::write(Config::etc() . "/nginx/zarspanel-tools/$k.conf", $conf, 0644);
        if ($k === 'calendar') { // Radicale reads the same mailbox passwords (IMAP login through Dovecot)
            Exec::write(Config::etc() . '/radicale/config', "[server]\nhosts = 127.0.0.1:5232\n[auth]\ntype = htpasswd\nhtpasswd_filename = /etc/zarspanel-mail/radicale-users\nhtpasswd_encryption = autodetect\n[storage]\nfilesystem_folder = /var/lib/radicale/collections\n", 0644);
            Exec::run(['systemctl', 'enable', '--now', 'radicale']);
        }
        Exec::run(['systemctl', 'reload', 'nginx']);
        Db::put("tool_$k", $r['code'] === 0 ? '1' : '0');

        return ['ok' => $r['code'] === 0, 'log' => mb_substr($r['out'], -3000), 'tools' => self::tools()];
    }

    /** One-time sign-in to phpMyAdmin for one of the actor's databases (a short-lived MariaDB user that can see only that database). */
    public static function phpMyAdminLogin(array $actor, int $dbId): array
    {
        $d = Databases::mine($actor, $dbId);
        if (($d['engine'] ?? 'mysql') !== 'mysql') {
            throw new PanelError('phpMyAdmin opens MariaDB databases only.', 422);
        }
        $user = 'pma_' . bin2hex(random_bytes(4));
        $pw = Secrets::password();
        $r = Exec::run(['mysql', '--batch'], "CREATE USER '$user'@'localhost' IDENTIFIED BY '$pw';\nGRANT ALL PRIVILEGES ON `{$d['name']}`.* TO '$user'@'localhost';\nFLUSH PRIVILEGES;\n"
            . "CREATE EVENT IF NOT EXISTS `drop_$user` ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 2 HOUR DO DROP USER '$user'@'localhost';");
        if ($r['code'] !== 0) {
            throw new PanelError('Could not make the sign-in.', 500);
        }
        Audit::log((int) $actor['id'], 'db.phpmyadmin', ['name' => $d['name']]);

        return ['url' => self::panelBase() . '/phpmyadmin/', 'user' => $user, 'password' => $pw, 'note' => 'Valid for 2 hours, only for ' . $d['name'] . '.'];
    }

    // ---------------- Git deployment ----------------
    public static function gitDeploy(array $actor, int $siteId, array $in): array
    {
        $s = Sites::mine($actor, $siteId);
        $repo = trim((string) ($in['repo'] ?? ''));
        $branch = trim((string) ($in['branch'] ?? 'main'));
        if (!preg_match('#^(https://[A-Za-z0-9.-]+/[A-Za-z0-9._/-]+?(\.git)?|git@[A-Za-z0-9.-]+:[A-Za-z0-9._/-]+?(\.git)?)$#', $repo) || !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) {
            throw new PanelError('Give an https:// or git@ repository address and a branch name.', 422);
        }
        $dir = Config::www() . '/' . $s['domain'];
        $git = is_dir("$dir/.git")
            ? Exec::run(['runuser', '-u', SysUser::of($s), '--', 'git', '-C', $dir, 'pull', '--ff-only', 'origin', $branch], null, 900)
            : Exec::run(['runuser', '-u', SysUser::of($s), '--', 'git', 'clone', '--branch', $branch, '--depth', '50', $repo, "$dir/.deploy-tmp"], null, 900);
        if (!is_dir("$dir/.git") && $git['code'] === 0) {
            Exec::run(['sh', '-c', 'cp -a ' . escapeshellarg("$dir/.deploy-tmp/.") . ' ' . escapeshellarg($dir) . ' && rm -rf ' . escapeshellarg("$dir/.deploy-tmp")]);
        }
        $after = [];
        if (!empty($in['composer']) && is_file("$dir/composer.json")) {
            $after[] = Exec::run(['runuser', '-u', SysUser::of($s), '--', 'composer', 'install', '--no-dev', '--no-interaction', '-d', $dir], null, 1200)['out'];
        }
        if (!empty($in['npm']) && is_file("$dir/package.json")) {
            $after[] = Exec::run(['runuser', '-u', SysUser::of($s), '--', 'sh', '-c', 'cd ' . escapeshellarg($dir) . ' && npm ci && npm run build'], null, 1800)['out'];
        }
        Db::put('git_' . $siteId, json_encode(['repo' => $repo, 'branch' => $branch, 'at' => gmdate('c'), 'ok' => $git['code'] === 0]));
        Audit::log((int) $actor['id'], 'site.git_deploy', ['site' => $s['domain'], 'branch' => $branch]);

        return ['ok' => $git['code'] === 0, 'log' => mb_substr($git['out'] . "\n" . implode("\n", $after), -6000)];
    }

    /** The deploy hook: POST /hook/git/{site id}/{secret} from GitHub / GitLab pulls the site again. */
    public static function hookSecret(int $siteId): string
    {
        return hash_hmac('sha256', 'git-hook-' . $siteId, Secrets::key());
    }

    // ---------------- restore one file from a site backup ----------------
    public static function backupFiles(array $actor, int $backupId): array
    {
        $b = Backups::mine($actor, $backupId);
        if ($b['kind'] !== 'site') {
            throw new PanelError('Only website backups hold files.', 422);
        }
        $r = Exec::run(['tar', '-tzf', $b['file']], null, 300);

        return array_values(array_filter(array_slice(preg_split('/\R/', $r['out']) ?: [], 0, 5000), fn ($f) => $f !== '' && !str_ends_with($f, '/')));
    }

    public static function restoreFile(array $actor, int $backupId, string $path): void
    {
        $b = Backups::mine($actor, $backupId);
        if ($b['kind'] !== 'site' || str_contains($path, '..') || !str_starts_with($path, $b['subject'] . '/')) {
            throw new PanelError('Pick a file of this backup.', 422);
        }
        Sites::mine($actor, (int) (Db::pdo()->query('SELECT id FROM sites WHERE domain = ' . Db::pdo()->quote($b['subject']))->fetchColumn() ?: 0));
        $r = Exec::run(['tar', '-xzf', $b['file'], '-C', Config::www(), $path], null, 600);
        if ($r['code'] !== 0) {
            throw new PanelError('The file could not be restored: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
        Audit::log((int) $actor['id'], 'backup.restore_file', ['path' => $path]);
    }

    // ---------------- mail ----------------
    /** Mailboxes from CSV lines "address,password,quota MB" (cPanel's address importer). */
    public static function importMailboxes(array $actor, int $domainId, string $csv): array
    {
        $d = Mail::domain($actor, $domainId);
        $done = $failed = [];
        foreach (array_slice(preg_split('/\R/', trim($csv), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 500) as $line) {
            [$addr, $pw, $quota] = array_pad(array_map('trim', str_getcsv($line)), 3, '');
            $local = strtolower(str_contains($addr, '@') ? strstr($addr, '@', true) : $addr);
            try {
                Mail::addMailbox($actor, (int) $d['id'], ['local' => $local, 'password' => $pw, 'quota_mb' => (int) $quota ?: 1024]);
                $done[] = "$local@{$d['domain']}";
            } catch (PanelError $e) {
                $failed[] = "$addr: " . $e->getMessage();
            }
        }

        return ['added' => $done, 'failed' => $failed];
    }

    /** Spam filter of a mail domain (rspamd): off, or the score from which mail goes to Junk / is rejected. */
    public static function spamFilter(array $actor, int $domainId, array $in): array
    {
        $d = Mail::domain($actor, $domainId);
        $c = ['on' => (bool) ($in['on'] ?? true), 'junk' => max(1.0, min(30.0, (float) ($in['junk'] ?? 6))), 'reject' => max(5.0, min(50.0, (float) ($in['reject'] ?? 15)))];
        Db::put('spam_' . $d['domain'], json_encode($c));
        $all = '';
        foreach (Db::pdo()->query('SELECT domain FROM mail_domains')->fetchAll() as $row) {
            $x = json_decode((string) Db::setting('spam_' . $row['domain'], ''), true);
            if ($x) {
                $all .= "  \"{$row['domain']}\" {\n    priority = high;\n    rcpt = \"@{$row['domain']}\";\n" . ($x['on'] ? "    actions { add_header = {$x['junk']}; reject = {$x['reject']}; }\n" : "    want_spam = yes;\n") . "  }\n";
            }
        }
        Exec::write(Config::etc() . '/rspamd/local.d/settings.conf', "# Managed by ZARS Panel\n" . $all, 0644);
        Exec::run(['systemctl', 'reload', 'rspamd']);
        Audit::log((int) $actor['id'], 'mail.spam', ['domain' => $d['domain']] + $c);

        return $c;
    }

    public static function mailUsage(array $actor, int $domainId): array
    {
        $d = Mail::domain($actor, $domainId);
        $out = [];
        $st = Db::pdo()->prepare('SELECT local, quota_mb FROM mailboxes WHERE domain_id = ?');
        $st->execute([$d['id']]);
        foreach ($st->fetchAll() as $m) {
            $du = Exec::run(['du', '-sb', Mail::vmail() . '/' . $d['domain'] . '/' . $m['local']]);
            $out[] = ['address' => $m['local'] . '@' . $d['domain'], 'bytes' => (int) (preg_match('/^(\d+)/', $du['out'], $x) ? $x[1] : 0), 'quota_mb' => (int) $m['quota_mb']];
        }

        return $out;
    }

    /** Mailing list: one address that sends to all members (a forward with many targets). */
    public static function mailingList(array $actor, int $domainId, string $local, array $members): array
    {
        $d = Mail::domain($actor, $domainId);
        $local = strtolower(trim($local));
        $members = array_values(array_unique(array_filter(array_map('trim', $members), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL))));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $local) || !$members || count($members) > 500) {
            throw new PanelError('Give the list address and one to 500 member addresses.', 422);
        }
        Db::pdo()->prepare('DELETE FROM mail_aliases WHERE domain_id = ? AND local = ?')->execute([$d['id'], $local]);
        Db::pdo()->prepare('INSERT INTO mail_aliases (domain_id, local, target) VALUES (?, ?, ?)')->execute([$d['id'], $local, implode(',', $members)]);
        Mail::publish();

        return ['list' => "$local@{$d['domain']}", 'members' => $members];
    }

    // ---------------- websites ----------------
    /** Visitor statistics page (GoAccess) from the site's access log, refreshed when asked. */
    public static function stats(array $actor, int $siteId): array
    {
        $s = Sites::mine($actor, $siteId);
        $out = Config::ensure(Config::home() . '/stats') . '/' . $s['domain'] . '.html';
        $r = Exec::run(['goaccess', Nginx::logs() . '/' . $s['domain'] . '.access.log', '--log-format=COMBINED', '-o', $out], null, 300);
        Config::dry() && !is_file($out) && file_put_contents($out, '<html><body>stats</body></html>');
        if (!is_file($out)) {
            throw new PanelError('The report could not be made: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }

        return ['html' => (string) file_get_contents($out)];
    }

    /** Node.js / Python app of a site: kept running by systemd, the site becomes a proxy to its port. */
    public static function app(array $actor, int $siteId, array $in): array
    {
        $s = Sites::mine($actor, $siteId);
        $kind = (string) ($in['kind'] ?? 'node');
        $port = (int) ($in['port'] ?? 0);
        $start = trim((string) ($in['start'] ?? ''));
        if (!in_array($kind, ['node', 'python'], true) || $port < 1024 || $port > 65000 || !preg_match('#^[A-Za-z0-9 ._/:=-]{1,200}$#', $start)) {
            throw new PanelError('Choose Node.js or Python, a port above 1024 and the start command (for example: npm start, or gunicorn app:app).', 422);
        }
        $dir = Config::www() . '/' . $s['domain'];
        $unit = 'zp-app-' . preg_replace('/[^a-z0-9]/', '-', $s['domain']);
        $env = "Environment=PORT=$port\nEnvironment=NODE_ENV=production\n";
        $cmd = $kind === 'python' && !str_starts_with($start, '/') ? "/bin/sh -c 'cd $dir && . venv/bin/activate 2>/dev/null; exec $start'" : "/bin/sh -c 'cd $dir && exec $start'";
        Exec::write(Config::etc() . "/systemd/system/$unit.service", self::unitFile($s, $dir, $env, $cmd), 0644);
        Db::put('app_unit_' . $siteId, json_encode(['unit' => $unit, 'dir' => $dir, 'env' => $env, 'cmd' => $cmd]));
        Exec::run(['systemctl', 'daemon-reload']);
        Exec::run(['systemctl', 'enable', '--now', $unit]);
        Exec::run(['systemctl', 'restart', $unit]);
        Db::pdo()->prepare("UPDATE sites SET type = 'proxy', proxy_to = ? WHERE id = ?")->execute(["http://127.0.0.1:$port", $siteId]);
        Sites::apply($siteId);
        Audit::log((int) $actor['id'], 'site.app', ['site' => $s['domain'], 'kind' => $kind, 'port' => $port]);

        return ['unit' => $unit, 'state' => trim(Exec::run(['systemctl', 'is-active', $unit])['out']) ?: 'starting'];
    }

    public static function appControl(array $actor, int $siteId, string $action): array
    {
        $s = Sites::mine($actor, $siteId);
        if (!in_array($action, ['restart', 'stop', 'start', 'logs'], true)) {
            throw new PanelError('Unknown action.', 422);
        }
        $unit = 'zp-app-' . preg_replace('/[^a-z0-9]/', '-', $s['domain']);
        if ($action === 'logs') {
            return ['log' => Exec::run(['journalctl', '-u', $unit, '-n', '200', '--no-pager'])['out']];
        }
        Exec::run(['systemctl', $action, $unit]);

        return ['state' => trim(Exec::run(['systemctl', 'is-active', $unit])['out'])];
    }

    // ---------------- dynamic DNS ----------------
    /** A secret address that sets an A record to the caller's IP (for a home or office line without a fixed IP). */
    public static function ddnsCreate(array $actor, int $zoneId, string $name): array
    {
        $z = Dns::zone($actor, $zoneId);
        if (!preg_match('/^[a-z0-9-]{1,63}$/', $name)) {
            throw new PanelError('Give a name like office (office.example.com).', 422);
        }
        $token = bin2hex(random_bytes(16));
        $all = json_decode((string) Db::setting('ddns', '{}'), true) ?: [];
        $all[hash('sha256', $token)] = ['zone' => (int) $z['id'], 'name' => $name, 'user' => (int) $actor['id']];
        Db::put('ddns', json_encode($all));

        return ['url' => self::panelBase() . '/ddns/' . $token, 'host' => "$name.{$z['domain']}", 'note' => 'Open this address from the device (router, cron with curl) and the record follows its IP. Shown once.'];
    }

    public static function ddnsUpdate(string $token, string $ip): array
    {
        $all = json_decode((string) Db::setting('ddns', '{}'), true) ?: [];
        $e = $all[hash('sha256', $token)] ?? throw new PanelError('Unknown address.', 404);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new PanelError('No IP.', 422);
        }
        $type = str_contains($ip, ':') ? 'AAAA' : 'A';
        Db::pdo()->prepare('DELETE FROM dns_records WHERE zone_id = ? AND name = ? AND type = ?')->execute([$e['zone'], $e['name'], $type]);
        Db::pdo()->prepare('INSERT INTO dns_records (zone_id, type, name, value, ttl, prio) VALUES (?, ?, ?, ?, 300, NULL)')->execute([$e['zone'], $type, $e['name'], $ip]);
        Dns::publish($e['zone']);

        return ['ok' => true, 'ip' => $ip];
    }

    private static function unitFile(array $s, string $dir, string $env, string $cmd): string
    {
        return "[Unit]\nDescription=App of {$s['domain']} (ZARS Panel)\nAfter=network.target\n[Service]\nUser=" . SysUser::of($s) . "\nWorkingDirectory=$dir\n$env" . "ExecStart=$cmd\nRestart=always\nRestartSec=3\n[Install]\nWantedBy=multi-user.target\n";
    }

    /** After a site moved to its account's own user: its Node.js / Python app service runs as that user too. */
    public static function rewriteServices(array $s): void
    {
        $u = json_decode((string) Db::setting('app_unit_' . $s['id'], ''), true);
        if (!$u) {
            return;
        }
        Exec::write(Config::etc() . "/systemd/system/{$u['unit']}.service", self::unitFile($s, $u['dir'], $u['env'], $u['cmd']), 0644);
        Exec::run(['systemctl', 'daemon-reload']);
        Exec::run(['systemctl', 'restart', $u['unit']]);
    }
}
