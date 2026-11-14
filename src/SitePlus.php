<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Website features of the other control panels (0.11.0): WordPress manager (Plesk WP Toolkit, cPanel WP Toolkit), Laravel tools
 * (Plesk Laravel Toolkit), staging copy and push to live (Plesk, CyberPanel), recycle bin and share links in the file manager
 * (aaPanel), background programs / queue workers (aaPanel Supervisor, Plesk), uptime checks (Plesk 360, CloudPanel), page cache,
 * bad-bot blocking and a preview address before DNS (Plesk, CloudPanel). Every site runs as its account's own Linux user (SysUser).
 */
final class SitePlus
{
    public const ARTISAN = ['about', 'migrate --force', 'migrate:status', 'cache:clear', 'config:cache', 'config:clear', 'route:cache', 'route:clear', 'view:clear',
        'optimize', 'optimize:clear', 'queue:restart', 'storage:link', 'down', 'up', 'schedule:list'];
    public const BOTS = 'AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|PetalBot|DataForSeoBot|MegaIndex|serpstatbot|Bytespider|Barkrowler|ZoominfoBot|SeekportBot|sqlmap|nikto|masscan|zgrab';

    private static function top(array $s): string
    {
        return Config::www() . '/' . $s['domain'];
    }

    private static function www(array $s, array $argv, int $timeout = 300): array
    {
        return SysUser::run($s, $argv, $timeout);
    }

    // ---- WordPress manager ------------------------------------------------------------------------------------------------

    private static function wp(array $s, array $args, int $timeout = 600): array
    {
        return self::www($s, array_merge(['wp', '--path=' . $s['root'], '--skip-plugins', '--skip-themes'], $args), $timeout);
    }

    public static function wordpress(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        if (!is_file($s['root'] . '/wp-config.php') && !Config::dry()) {
            return ['wordpress' => false];
        }
        $j = fn (array $a) => json_decode(self::wp($s, $a)['out'], true) ?: [];
        $core = trim(self::wp($s, ['core', 'version'])['out']);
        $updates = $j(['core', 'check-update', '--format=json']);

        return ['wordpress' => true, 'version' => $core, 'core_update' => $updates[0]['version'] ?? null,
            'plugins' => $j(['plugin', 'list', '--format=json', '--fields=name,status,version,update_version,update']),
            'themes' => $j(['theme', 'list', '--format=json', '--fields=name,status,version,update_version,update']),
            'users' => $j(['user', 'list', '--role=administrator', '--format=json', '--fields=ID,user_login,user_email']),
            'maintenance_on' => self::wp($s, ['maintenance-mode', 'is-active'])['code'] === 0,
            'debug' => trim(self::wp($s, ['config', 'get', 'WP_DEBUG'])['out']) === '1'];
    }

    public static function wordpressAction(array $actor, int $id, array $in): array
    {
        $s = Sites::mine($actor, $id);
        $name = (string) ($in['name'] ?? '');
        if ($name !== '' && !preg_match('/^[a-z0-9._-]{1,100}$/i', $name)) {
            throw new PanelError('Unknown plugin or theme.', 422);
        }
        $do = match ((string) ($in['action'] ?? '')) {
            'update-core' => [['core', 'update'], ['core', 'update-db']],
            'update-plugins' => [array_merge(['plugin', 'update'], $name !== '' ? [$name] : ['--all'])],
            'update-themes' => [array_merge(['theme', 'update'], $name !== '' ? [$name] : ['--all'])],
            'update-all' => [['core', 'update'], ['core', 'update-db'], ['plugin', 'update', '--all'], ['theme', 'update', '--all']],
            'plugin-on' => [['plugin', 'activate', $name]],
            'plugin-off' => [['plugin', 'deactivate', $name]],
            'maintenance-on' => [['maintenance-mode', 'activate']],
            'maintenance-off' => [['maintenance-mode', 'deactivate']],
            'harden' => [['config', 'set', 'DISALLOW_FILE_EDIT', 'true', '--raw'], ['config', 'set', 'WP_DEBUG', 'false', '--raw'], ['option', 'update', 'default_comment_status', 'closed']],
            'cache-flush' => [['cache', 'flush'], ['transient', 'delete', '--all']],
            'check' => [['core', 'verify-checksums']],
            default => throw new PanelError('Unknown WordPress action.', 422),
        };
        $out = '';
        foreach ($do as $args) {
            $r = self::wp($s, $args, 900);
            $out .= trim($r['out']) . "\n";
            if ($r['code'] !== 0 && ($in['action'] ?? '') !== 'check') {
                throw new PanelError(mb_substr(trim($r['out']), 0, 400) ?: 'WordPress did not do it.', 500);
            }
        }
        if (($in['action'] ?? '') === 'harden') {
            Exec::run(['chmod', '600', $s['root'] . '/wp-config.php']);
        }
        Audit::log((int) $actor['id'], 'wp.' . $in['action'], ['site' => $s['domain'], 'name' => $name]);

        return ['output' => trim($out)] + self::wordpress($actor, $id);
    }

    /** A new password for a WordPress administrator, shown once. */
    public static function wordpressPassword(array $actor, int $id, string $login): array
    {
        $s = Sites::mine($actor, $id);
        if (!preg_match('/^[A-Za-z0-9._@ -]{1,60}$/', $login)) {
            throw new PanelError('Unknown WordPress user.', 422);
        }
        $pw = Secrets::password(16);
        $r = self::wp($s, ['user', 'update', $login, '--user_pass=' . $pw, '--skip-email']);
        if ($r['code'] !== 0) {
            throw new PanelError(mb_substr(trim($r['out']), 0, 300) ?: 'The password was not changed.', 500);
        }
        Audit::log((int) $actor['id'], 'wp.password', ['site' => $s['domain'], 'user' => $login]);

        return ['password' => $pw];
    }

    // ---- Laravel tools -----------------------------------------------------------------------------------------------------

    private static function appDir(array $s): string
    {
        return str_ends_with(rtrim($s['root'], '/'), '/public') ? dirname(rtrim($s['root'], '/')) : $s['root'];
    }

    public static function artisan(array $actor, int $id, string $command): array
    {
        $s = Sites::mine($actor, $id);
        if (!in_array($command, self::ARTISAN, true)) {
            throw new PanelError('Choose one of the listed commands.', 422);
        }
        $r = self::www($s, ['sh', '-c', 'cd ' . escapeshellarg(self::appDir($s)) . ' && php artisan ' . $command . ' --no-interaction'], 900);
        Audit::log((int) $actor['id'], 'laravel.artisan', ['site' => $s['domain'], 'command' => $command]);

        return ['ok' => $r['code'] === 0, 'output' => mb_substr($r['out'], 0, 20000)];
    }

    /** Laravel's scheduler as a cron job of the site (every minute), on or off. */
    public static function scheduler(array $actor, int $id, bool $on): array
    {
        $s = Sites::mine($actor, $id);
        $have = self::schedulerJob((int) $s['id']);
        if ($on && !$have) {
            $rel = trim(substr(self::appDir($s), strlen(self::top($s))), '/'); // cron jobs start in the site folder
            Cron::create($actor, ['kind' => 'shell', 'name' => 'Laravel scheduler', 'schedule' => '* * * * *', 'site_id' => $s['id'], 'command' => 'php ' . ($rel !== '' ? $rel . '/' : '') . 'artisan schedule:run']);
        } elseif (!$on && $have) {
            Cron::delete($actor, (int) $have);
        }

        return ['scheduler' => $on];
    }

    public static function schedulerJob(int $siteId): int
    {
        $st = Db::pdo()->prepare("SELECT id FROM crons WHERE kind = 'shell' AND name = 'Laravel scheduler' AND target LIKE ?");
        $st->execute(['{"site_id":' . $siteId . ',%']);

        return (int) $st->fetchColumn();
    }

    // ---- background programs: queue workers and any long-running command (Supervisor) ------------------------------------

    private static function workerConf(array $w, array $s): string
    {
        $dir = self::appDir($s);
        $log = Nginx::logs() . "/{$s['domain']}.worker-{$w['id']}.log";

        return "; ZARS Panel: {$s['domain']} - {$w['name']}\n[program:zp-worker-{$w['id']}]\ncommand=/bin/sh -c " . escapeshellarg($w['command']) . "\ndirectory=$dir\nuser=" . SysUser::of($s)
            . "\nnumprocs={$w['procs']}\nprocess_name=%(program_name)s_%(process_num)02d\nautostart=true\nautorestart=true\nstopwaitsecs=60\nstopasgroup=true\nkillasgroup=true\nredirect_stderr=true\nstdout_logfile=$log\nstdout_logfile_maxbytes=10MB\nstdout_logfile_backups=2\n";
    }

    /** After a site moved to its account's own user: its background programs run as that user too. */
    public static function rewriteWorkers(array $s): void
    {
        $st = Db::pdo()->prepare('SELECT * FROM workers WHERE site_id = ?');
        $st->execute([$s['id']]);
        $rows = $st->fetchAll();
        foreach ($rows as $w) {
            Exec::write(self::supervisorFile((int) $w['id']), self::workerConf($w, $s), 0644);
        }
        if ($rows) {
            Exec::run(['supervisorctl', 'reread']);
            Exec::run(['supervisorctl', 'update']);
        }
    }

    private static function supervisorFile(int $workerId): string
    {
        return Config::etc() . "/supervisor/conf.d/zp-worker-$workerId.conf";
    }

    public static function workers(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        $st = Db::pdo()->prepare('SELECT * FROM workers WHERE site_id = ? ORDER BY id');
        $st->execute([$s['id']]);
        $status = Exec::run(['supervisorctl', 'status'])['out'];
        $rows = [];
        foreach ($st->fetchAll() as $w) {
            $state = preg_match('/^zp-worker-' . $w['id'] . '\S*\s+(\w+)/m', $status, $m) ? $m[1] : 'UNKNOWN';
            $rows[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'command' => $w['command'], 'procs' => (int) $w['procs'], 'state' => $state];
        }

        return ['workers' => $rows];
    }

    public static function addWorker(array $actor, int $id, array $in): array
    {
        $s = Sites::mine($actor, $id);
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60) ?: 'worker';
        $cmd = trim((string) ($in['command'] ?? ''));
        if ($cmd === '' || strlen($cmd) > 500 || preg_match('/[\r\n]/', $cmd)) {
            throw new PanelError('Give the command to keep running, for example: php artisan queue:work --sleep=3 --tries=3', 422);
        }
        $procs = max(1, min(8, (int) ($in['procs'] ?? 1)));
        $count = Db::pdo()->prepare('SELECT COUNT(*) FROM workers w JOIN sites s ON s.id = w.site_id WHERE s.user_id = ?');
        $count->execute([$s['user_id']]);
        if ($actor['role'] === 'client' && (int) $count->fetchColumn() >= 5) {
            throw new PanelError('At most 5 background programs per account.', 422);
        }
        Db::pdo()->prepare('INSERT INTO workers (site_id, name, command, procs, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$s['id'], $name, $cmd, $procs, gmdate('c')]);
        $wid = (int) Db::pdo()->lastInsertId();
        Exec::write(self::supervisorFile($wid), self::workerConf(['id' => $wid, 'name' => $name, 'command' => $cmd, 'procs' => $procs], $s), 0644);
        Exec::run(['supervisorctl', 'reread']);
        Exec::run(['supervisorctl', 'update']);
        Audit::log((int) $actor['id'], 'worker.add', ['site' => $s['domain'], 'name' => $name]);

        return self::workers($actor, $id);
    }

    private static function worker(array $actor, int $id, int $wid): array
    {
        $s = Sites::mine($actor, $id);
        $st = Db::pdo()->prepare('SELECT * FROM workers WHERE id = ? AND site_id = ?');
        $st->execute([$wid, $s['id']]);

        return [$s, $st->fetch() ?: throw new PanelError('Unknown background program.', 404)];
    }

    public static function workerAction(array $actor, int $id, int $wid, string $action): array
    {
        [$s, $w] = self::worker($actor, $id, $wid);
        if ($action === 'delete') {
            Exec::run(['supervisorctl', 'stop', "zp-worker-$wid:*"]);
            @unlink(self::supervisorFile($wid));
            Exec::run(['supervisorctl', 'reread']);
            Exec::run(['supervisorctl', 'update']);
            Db::pdo()->prepare('DELETE FROM workers WHERE id = ?')->execute([$wid]);
        } elseif (in_array($action, ['start', 'stop', 'restart'], true)) {
            Exec::run(['supervisorctl', $action, "zp-worker-$wid:*"]);
        } else {
            throw new PanelError('Unknown action.', 422);
        }
        Audit::log((int) $actor['id'], 'worker.' . $action, ['site' => $s['domain'], 'name' => $w['name']]);

        return self::workers($actor, $id);
    }

    public static function workerLog(array $actor, int $id, int $wid): array
    {
        [$s] = self::worker($actor, $id, $wid);
        $f = Nginx::logs() . "/{$s['domain']}.worker-$wid.log";

        return ['log' => is_file($f) ? (string) shell_exec('tail -n 300 ' . escapeshellarg($f)) : ''];
    }

    // ---- staging copy and push to live ----------------------------------------------------------------------------------------

    /** staging.<domain> with a copy of the files and of the site's databases; WordPress and Laravel settings follow the copy. */
    public static function staging(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        if (str_starts_with($s['domain'], 'staging.')) {
            throw new PanelError('This is already a staging copy.', 422);
        }
        $domain = 'staging.' . $s['domain'];
        if (Sites::findByDomain($domain)) {
            throw new PanelError("$domain exists already. Push it to live, or remove it first.", 422);
        }
        $new = Sites::create($actor, ['domain' => $domain, 'type' => $s['type'], 'php' => $s['php'], 'user_id' => $s['user_id'], 'rewrite' => $s['rewrite'], 'proxy_to' => $s['proxy_to']]);
        $r = Exec::run(['rsync', '-a', '--delete', '--exclude', '.zp-trash', self::top($s) . '/', self::top($new) . '/'], null, 3600);
        if ($r['code'] !== 0) {
            throw new PanelError('The files were not copied: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
        Db::pdo()->prepare('UPDATE sites SET root = ? WHERE id = ?')->execute([str_replace(self::top($s), self::top($new), $s['root']), $new['id']]);
        $new = Sites::find((int) $new['id']);
        $dbs = [];
        $st = Db::pdo()->prepare("SELECT * FROM dbs WHERE site_id = ? AND engine = 'mysql'");
        $st->execute([$s['id']]);
        foreach ($st->fetchAll() as $d) {
            $name = substr($d['name'], 0, 26) . '_stg';
            $made = Databases::create($actor, ['name' => $name, 'user_id' => $s['user_id'], 'site_id' => $new['id']]);
            $c = Exec::run(['sh', '-c', 'mysqldump --single-transaction --quick ' . escapeshellarg($d['name']) . ' | mysql ' . escapeshellarg($name)], null, 3600);
            if ($c['code'] !== 0) {
                throw new PanelError('The database was not copied: ' . mb_substr(trim($c['out']), 0, 200), 500);
            }
            self::pointConfig($new, $d['name'], $name, $made['password']);
            $dbs[] = $name;
        }
        if (is_file($new['root'] . '/wp-config.php') || Config::dry()) {
            self::wp($new, ['search-replace', '//' . $s['domain'], '//' . $domain, '--all-tables', '--skip-columns=guid'], 1800);
            self::wp($new, ['option', 'update', 'blog_public', '0']); // search engines stay away from the copy
        }
        Sites::apply((int) $new['id']);
        Audit::log((int) $actor['id'], 'site.staging', ['site' => $s['domain']]);

        return ['site' => $new, 'databases' => $dbs];
    }

    /** wp-config.php / .env of the copy use the copied database. */
    private static function pointConfig(array $site, string $oldDb, string $newDb, string $password): void
    {
        $wp = $site['root'] . '/wp-config.php';
        if (is_file($wp)) {
            $t = (string) file_get_contents($wp);
            $t = preg_replace("/define\(\s*'DB_NAME',\s*'[^']*'\s*\)/", "define('DB_NAME', '$newDb')", $t);
            $t = preg_replace("/define\(\s*'DB_USER',\s*'[^']*'\s*\)/", "define('DB_USER', '$newDb')", $t);
            $t = preg_replace("/define\(\s*'DB_PASSWORD',\s*'[^']*'\s*\)/", "define('DB_PASSWORD', '$password')", $t);
            Exec::write($wp, $t, 0640);
        }
        $env = self::appDir($site) . '/.env';
        if (is_file($env)) {
            $t = (string) file_get_contents($env);
            $t = preg_replace('/^DB_DATABASE=.*$/m', "DB_DATABASE=$newDb", $t);
            $t = preg_replace('/^DB_USERNAME=.*$/m', "DB_USERNAME=$newDb", $t);
            $t = preg_replace('/^DB_PASSWORD=.*$/m', "DB_PASSWORD=$password", $t);
            $t = preg_replace('/^APP_URL=.*$/m', 'APP_URL=https://' . $site['domain'], $t);
            Exec::write($env, $t, 0640);
        }
    }

    /** The staging files (without the configuration files) and, when asked, its database go to the live site. */
    public static function pushLive(array $actor, int $stagingId, bool $withDb): array
    {
        $st = Sites::mine($actor, $stagingId);
        if (!str_starts_with($st['domain'], 'staging.')) {
            throw new PanelError('Open the staging copy (staging.<domain>) to push it.', 422);
        }
        $live = Sites::findByDomain(substr($st['domain'], 8)) ?? throw new PanelError('The live site is gone.', 404);
        Sites::mine($actor, (int) $live['id']);
        Backups::site($actor, (int) $live['id'], false); // a backup of the live site first
        $r = Exec::run(['rsync', '-a', '--delete', '--exclude', 'wp-config.php', '--exclude', '.env', '--exclude', '.zp-trash', '--exclude', 'storage/logs', self::top($st) . '/', self::top($live) . '/'], null, 3600);
        if ($r['code'] !== 0) {
            throw new PanelError('The files were not pushed: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
        $pushed = [];
        if ($withDb) {
            $q = Db::pdo()->prepare("SELECT name FROM dbs WHERE site_id = ? AND engine = 'mysql'");
            $q->execute([$st['id']]);
            foreach ($q->fetchAll() as $d) {
                $liveDb = preg_replace('/_stg$/', '', $d['name']);
                $ld = Db::pdo()->prepare('SELECT * FROM dbs WHERE name = ?');
                $ld->execute([$liveDb]);
                if (!$ld->fetch()) {
                    continue;
                }
                Exec::run(['sh', '-c', 'mysqldump --single-transaction --quick ' . escapeshellarg($d['name']) . ' | mysql ' . escapeshellarg($liveDb)], null, 3600);
                $pushed[] = $liveDb;
            }
            if (is_file($live['root'] . '/wp-config.php') || Config::dry()) {
                self::wp($live, ['search-replace', '//' . $st['domain'], '//' . $live['domain'], '--all-tables', '--skip-columns=guid'], 1800);
                self::wp($live, ['option', 'update', 'blog_public', '1']);
            }
        }
        Audit::log((int) $actor['id'], 'site.push_live', ['site' => $live['domain'], 'database' => $withDb]);

        return ['live' => $live['domain'], 'databases' => $pushed];
    }

    // ---- recycle bin of the file manager ----------------------------------------------------------------------------------

    public static function trashDir(array $s): string
    {
        return self::top($s) . '/.zp-trash';
    }

    /** Files::delete goes here instead: the file waits 30 days in the recycle bin. */
    public static function toTrash(array $s, string $rel): void
    {
        if (trim($rel, '/') === '') {
            throw new PanelError('Choose a file or folder.', 422);
        }
        $from = Files::resolve(self::top($s), $rel);
        if (!file_exists($from)) {
            throw new PanelError('That file does not exist.', 404);
        }
        $top = rtrim(realpath(self::top($s)) ?: self::top($s), '/');
        if (str_starts_with($from, $top . '/.zp-trash')) {
            is_dir($from) && !is_link($from) ? Files::rrmdir($from) : @unlink($from);

            return;
        }
        $dir = Config::ensure($top . '/.zp-trash');
        $key = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        rename($from, "$dir/$key");
        file_put_contents("$dir/$key.json", json_encode(['path' => ltrim($rel, '/'), 'at' => time(), 'size' => Files::size("$dir/$key")]));
    }

    public static function trash(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        $out = [];
        foreach (glob(self::trashDir($s) . '/*.json') ?: [] as $j) {
            $m = json_decode((string) file_get_contents($j), true) ?: [];
            $out[] = ['key' => basename($j, '.json'), 'path' => $m['path'] ?? '?', 'at' => $m['at'] ?? 0, 'size' => $m['size'] ?? 0];
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return ['trash' => $out];
    }

    public static function trashAction(array $actor, int $id, string $key, string $action): array
    {
        $s = Sites::mine($actor, $id);
        $dir = self::trashDir($s);
        if ($key === '*' && $action === 'empty') {
            Files::rrmdir($dir);
        } else {
            if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $key) || !is_file("$dir/$key.json")) {
                throw new PanelError('Not in the recycle bin.', 404);
            }
            $m = json_decode((string) file_get_contents("$dir/$key.json"), true);
            if ($action === 'restore') {
                $to = self::top($s) . '/' . $m['path'];
                if (file_exists($to)) {
                    $to .= '.restored-' . date('His');
                }
                Files::resolve(self::top($s), ltrim(substr($to, strlen(self::top($s))), '/')); // still inside the site
                Config::ensure(dirname($to));
                rename("$dir/$key", $to);
            } elseif ($action === 'delete') {
                is_dir("$dir/$key") ? Files::rrmdir("$dir/$key") : @unlink("$dir/$key");
            } else {
                throw new PanelError('Unknown action.', 422);
            }
            @unlink("$dir/$key.json");
        }
        Audit::log((int) $actor['id'], 'trash.' . $action, ['site' => $s['domain'], 'key' => $key]);

        return self::trash($actor, $id);
    }

    /** Nightly: older than 30 days goes for good. */
    public static function cleanTrash(): int
    {
        $n = 0;
        foreach (glob(Config::www() . '/*/.zp-trash/*.json') ?: [] as $j) {
            $m = json_decode((string) file_get_contents($j), true) ?: [];
            if (($m['at'] ?? 0) < time() - 30 * 86400) {
                $item = substr($j, 0, -5);
                is_dir($item) ? Files::rrmdir($item) : @unlink($item);
                @unlink($j);
                $n++;
            }
        }

        return $n;
    }

    // ---- share links: a file anyone can download until the link ends ------------------------------------------------------

    public static function share(array $actor, int $id, string $rel, int $hours): array
    {
        $s = Sites::mine($actor, $id);
        $path = Files::resolve(self::top($s), $rel);
        if (!is_file($path)) {
            throw new PanelError('Only a file can be shared.', 422);
        }
        $token = bin2hex(random_bytes(20));
        Db::pdo()->prepare('INSERT INTO shares (site_id, user_id, path, hash, expires_at) VALUES (?, ?, ?, ?, ?)')->execute([$s['id'], $actor['id'], ltrim($rel, '/'), hash('sha256', $token), time() + max(1, min(720, $hours)) * 3600]);
        Audit::log((int) $actor['id'], 'file.share', ['site' => $s['domain'], 'path' => $rel]);

        return ['url' => '/api/share/' . $token, 'expires_at' => time() + max(1, min(720, $hours)) * 3600] + self::shares($actor, $id);
    }

    public static function shares(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        $st = Db::pdo()->prepare('SELECT id, path, expires_at, downloads FROM shares WHERE site_id = ? AND expires_at > ? ORDER BY id DESC');
        $st->execute([$s['id'], time()]);

        return ['shares' => $st->fetchAll()];
    }

    public static function unshare(array $actor, int $id, int $shareId): array
    {
        $s = Sites::mine($actor, $id);
        Db::pdo()->prepare('DELETE FROM shares WHERE id = ? AND site_id = ?')->execute([$shareId, $s['id']]);

        return self::shares($actor, $id);
    }

    /** The public download (no sign-in): the file, if the link is still valid. */
    public static function download(string $token): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM shares WHERE hash = ? AND expires_at > ?');
        $st->execute([hash('sha256', $token), time()]);
        $sh = $st->fetch() ?: throw new PanelError('This link has ended.', 404);
        $site = Sites::find((int) $sh['site_id']) ?? throw new PanelError('This link has ended.', 404);
        $file = Files::resolve(self::top($site), $sh['path']);
        if (!is_file($file)) {
            throw new PanelError('The file is no longer there.', 404);
        }
        Db::pdo()->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ?')->execute([$sh['id']]);

        return ['raw' => (string) file_get_contents($file), 'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', basename($file)) . '"']];
    }

    // ---- uptime checks -----------------------------------------------------------------------------------------------------

    public static function uptime(array $actor): array
    {
        $out = [];
        foreach (Sites::list($actor) as $s) {
            $st = Db::pdo()->prepare('SELECT * FROM uptime WHERE site_id = ?');
            $st->execute([$s['id']]);
            $u = $st->fetch();
            $day = Db::pdo()->prepare('SELECT COUNT(*) AS n, SUM(up) AS ok FROM uptime_log WHERE site_id = ? AND t > ?');
            $day->execute([$s['id'], time() - 30 * 86400]);
            $d = $day->fetch();
            $out[] = ['id' => (int) $s['id'], 'domain' => $s['domain'], 'on' => (bool) $u, 'up' => $u ? (bool) $u['up'] : null, 'code' => $u['code'] ?? null, 'ms' => $u['ms'] ?? null,
                'checked_at' => $u['checked_at'] ?? null, 'down_since' => $u['down_since'] ?? null, 'percent' => $d && $d['n'] ? round($d['ok'] * 100 / $d['n'], 2) : null];
        }

        return ['sites' => $out];
    }

    public static function setUptime(array $actor, int $id, bool $on): array
    {
        $s = Sites::mine($actor, $id);
        if ($on) {
            Db::pdo()->prepare('INSERT OR IGNORE INTO uptime (site_id, up, checked_at) VALUES (?, 1, 0)')->execute([$s['id']]);
        } else {
            Db::pdo()->prepare('DELETE FROM uptime WHERE site_id = ?')->execute([$s['id']]);
        }

        return self::uptime($actor);
    }

    /** Every 5 minutes (zp alerts): open each watched site; a change from up to down (or back) is an alert. */
    public static function checkUptime(): array
    {
        $said = [];
        foreach (Db::pdo()->query('SELECT u.*, s.domain, s.ssl FROM uptime u JOIN sites s ON s.id = u.site_id')->fetchAll() as $u) {
            $url = ($u['ssl'] ? 'https://' : 'http://') . $u['domain'] . '/';
            $r = Exec::run(['curl', '-s', '-o', '/dev/null', '-m', '15', '-L', '--max-redirs', '3', '-w', '%{http_code} %{time_total}', $url], null, 20);
            [$code, $time] = array_pad(explode(' ', trim($r['out'])), 2, '0');
            $up = (int) $code >= 200 && (int) $code < 400;
            $was = (bool) $u['up'];
            $downSince = $up ? null : ($u['down_since'] ?: time());
            Db::pdo()->prepare('UPDATE uptime SET up = ?, code = ?, ms = ?, checked_at = ?, down_since = ? WHERE site_id = ?')->execute([(int) $up, (int) $code, (int) ((float) $time * 1000), time(), $downSince, $u['site_id']]);
            Db::pdo()->prepare('INSERT INTO uptime_log (site_id, up, code, t) VALUES (?, ?, ?, ?)')->execute([$u['site_id'], (int) $up, (int) $code, time()]);
            if ($was !== $up && $u['checked_at']) {
                $msg = $up ? "{$u['domain']} is up again." : "{$u['domain']} is down (answer: " . ((int) $code ?: 'none') . ').';
                Alerts::raise('uptime', $msg);
                $said[] = $msg;
            }
        }
        Db::pdo()->exec('DELETE FROM uptime_log WHERE t < ' . (time() - 31 * 86400));

        return $said;
    }

    // ---- page cache, bad bots, preview address --------------------------------------------------------------------------------

    public static function cacheDir(): string
    {
        return '/var/cache/nginx/zp';
    }

    public static function purgeCache(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        Exec::run(['sh', '-c', 'rm -rf ' . escapeshellarg(self::cacheDir()) . '/*']);
        Audit::log((int) $actor['id'], 'site.cache_purge', ['site' => $s['domain']]);

        return ['ok' => true];
    }

    /** <domain-with-dashes>.<server ip>.sslip.io answers before the domain points here (Plesk "website preview"). */
    public static function previewName(array $s): string
    {
        $ip = (string) (Db::setting('shared_ip') ?: (Hosting::ips()['addresses'][0]['ip'] ?? '127.0.0.1'));
        $ip = str_contains($ip, '/') ? explode('/', $ip)[0] : $ip;

        return str_replace('.', '-', $s['domain']) . '.' . (str_contains($ip, ':') ? str_replace(':', '-', $ip) : $ip) . '.sslip.io';
    }
}
