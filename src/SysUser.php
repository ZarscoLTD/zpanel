<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * One Linux user per hosting account (the security of shared hosting, as cPanel / DirectAdmin / CloudLinux do it): the
 * sites of an account belong to its own user "zpu<id>", PHP runs in that user's own PHP-FPM pool, and files, FTP, cron,
 * Git, Composer, WordPress / Laravel tools and background workers run as that user. A hacked site can no longer read the
 * files of another account. Nginx (www-data) is a member of each account's group, so it can read the files to serve them;
 * folders are 0750, so other accounts cannot.
 *
 * Every site records its user (sites.sys_user). Sites made before 0.13.0 keep www-data until they are moved
 * (Server tools > Account users > Move all sites), which this class does too.
 */
final class SysUser
{
    public const LEGACY = 'www-data';

    /** On by default; the owner can switch it off (new sites then run as www-data, like before). */
    public static function enabled(): bool
    {
        return Db::setting('site_users', '1') === '1';
    }

    public static function nameFor(int $userId): string
    {
        // an account made from a Linux user that was already on the server (adopt) keeps that user
        $st = Db::pdo()->prepare('SELECT sys_user FROM users WHERE id = ?');
        $st->execute([$userId]);
        $own = (string) ($st->fetchColumn() ?: '');

        return $own !== '' ? $own : 'zpu' . $userId;
    }

    /**
     * The people's Linux users already on this server (uid 1000+, a real login shell) - like julfekar-lt on a laptop or the users of a
     * server moved from elsewhere - with the panel account each one belongs to (null = not a panel account yet).
     * @return list<array{name: string, uid: int, home: string, account: ?string}>
     */
    public static function serverUsers(): array
    {
        $file = getenv('ZARSPANEL_PASSWD') ?: '/etc/passwd';
        $linked = [];
        foreach (Db::pdo()->query("SELECT name, sys_user FROM users")->fetchAll() as $r) {
            $linked[$r['sys_user'] !== '' ? $r['sys_user'] : $r['name']] = $r['name'];
        }
        $out = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $p = explode(':', $line);
            if (count($p) < 7 || (int) $p[2] < 1000 || (int) $p[2] >= 60000 || str_starts_with($p[0], 'zpu') || preg_match('#/(nologin|false)$#', $p[6])) {
                continue;
            }
            $out[] = ['name' => $p[0], 'uid' => (int) $p[2], 'home' => $p[5], 'account' => $linked[$p[0]] ?? null];
        }

        return $out;
    }

    /** Make a panel account (client, or reseller) for a Linux user that is already on the server; its sites run as that user. */
    public static function adopt(array $actor, string $linuxName, string $role, string $password, ?string $email): int
    {
        $u = null;
        foreach (self::serverUsers() as $s) {
            if ($s['name'] === $linuxName) {
                $u = $s;
            }
        }
        if (!$u) {
            throw new PanelError('There is no such user on this server.', 404);
        }
        if ($u['account'] !== null) {
            throw new PanelError("$linuxName is already the panel account " . $u['account'] . '.', 422);
        }
        $id = Auth::create(strtolower($linuxName), $password, $role, null, $email);
        Db::pdo()->prepare('UPDATE users SET sys_user = ? WHERE id = ?')->execute([$linuxName, $id]);
        Exec::run(['usermod', '-aG', $linuxName, 'www-data']); // Nginx reads its sites, as for every account
        Audit::log((int) $actor['id'], 'user.adopt', ['linux' => $linuxName, 'role' => $role]);

        return $id;
    }

    /** The Linux user a site runs as. */
    public static function of(array $site): string
    {
        $u = (string) ($site['sys_user'] ?? '');

        return $u !== '' ? $u : self::LEGACY;
    }

    /** The user of the site a folder belongs to (www/<domain>/...); www-data outside the sites. */
    public static function ofPath(string $path): string
    {
        $www = rtrim(Config::www(), '/') . '/';
        if (!str_starts_with($path, $www)) {
            return self::LEGACY;
        }
        $st = Db::pdo()->prepare('SELECT * FROM sites WHERE domain = ?');
        $st->execute([explode('/', substr($path, strlen($www)))[0]]);
        $s = $st->fetch();

        return $s ? self::of($s) : self::LEGACY;
    }

    /** chown -R to the user of the site the folder belongs to. */
    public static function ownPath(string $path, int $timeout = 1800): void
    {
        $u = self::ofPath($path);
        Exec::run(['chown', '-R', "$u:$u", $path], null, $timeout);
    }

    /** Make the account's Linux user when it is missing (no login shell, no home of its own) and let Nginx read its files. */
    public static function ensure(int $userId): string
    {
        $name = self::nameFor($userId);
        if (!self::exists($name)) {
            Exec::run(['useradd', '--no-create-home', '--home-dir', Config::www(), '--shell', '/usr/sbin/nologin', '--user-group', '--comment', 'ZARS Panel account ' . $userId, $name]);
        }
        Exec::run(['usermod', '-aG', $name, 'www-data']);

        return $name;
    }

    public static function exists(string $name): bool
    {
        if (Config::dry()) {
            return false;
        }

        return function_exists('posix_getpwnam') ? posix_getpwnam($name) !== false : Exec::ok(['id', '-u', $name]);
    }

    /** [uid, gid] of a user (pure-ftpd virtual users need the numbers). */
    public static function ids(string $name): array
    {
        if (!Config::dry() && function_exists('posix_getpwnam') && ($p = posix_getpwnam($name))) {
            return [(int) $p['uid'], (int) $p['gid']];
        }

        return $name === self::LEGACY ? [33, 33] : [0, 0];
    }

    /** Run a command as the site's user. */
    public static function run(array $site, array $argv, int $timeout = 300): array
    {
        return Exec::run(array_merge(['runuser', '-u', self::of($site), '--'], $argv), null, $timeout);
    }

    /** Give a site's folder to its user (and group); the top folder 0750 so other accounts cannot look in. */
    public static function own(array $site, ?string $path = null): void
    {
        $u = self::of($site);
        $top = Config::www() . '/' . $site['domain'];
        Exec::run(['chown', '-R', "$u:$u", $path ?? $top]);
        if ($u !== self::LEGACY && ($path === null || $path === $top)) {
            Exec::run(['chmod', '0750', $top]);
        }
    }

    // ---- PHP-FPM pools -----------------------------------------------------------------------------------------------------

    public static function socket(array $site): string
    {
        $php = (string) ($site['php'] ?: '8.2');
        $u = self::of($site);

        return $u === self::LEGACY ? "/run/php/php$php-fpm.sock" : "/run/php/zp-$u-$php.sock";
    }

    public static function poolFile(string $user, string $php): string
    {
        return Config::etc() . "/php/$php/fpm/pool.d/zp-$user.conf";
    }

    /** The account's own pool for this PHP version (on demand: no idle processes), then reload that PHP-FPM. */
    public static function pool(array $site): void
    {
        $u = self::of($site);
        $php = (string) ($site['php'] ?? '');
        if ($u === self::LEGACY || $php === '') {
            return;
        }
        $owner = Auth::user((int) $site['user_id']);
        $q = $owner ? (json_decode((string) ($owner['quota'] ?? ''), true) ?: []) : [];
        $children = max(2, min(50, (int) ($q['php_workers'] ?? 5)));
        Exec::write(self::poolFile($u, $php), "; ZARS Panel: PHP $php for account $u\n[zp-$u]\nuser = $u\ngroup = $u\nlisten = " . self::socket($site)
            . "\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\npm = ondemand\npm.max_children = $children\npm.process_idle_timeout = 20s\npm.max_requests = 500\n"
            . "php_admin_value[upload_tmp_dir] = /tmp\nphp_admin_value[session.save_path] = /var/lib/php/sessions/$u\nsecurity.limit_extensions = .php\n", 0644);
        Exec::run(['install', '-d', '-m', '0700', '-o', $u, '-g', $u, "/var/lib/php/sessions/$u"]);
        Exec::run(['systemctl', 'reload', "php$php-fpm"]);
    }

    /** Pools nobody uses any more (the account's last site of that PHP version is gone) are removed. */
    public static function prune(string $user): void
    {
        if ($user === self::LEGACY) {
            return;
        }
        $st = Db::pdo()->prepare('SELECT DISTINCT php FROM sites WHERE sys_user = ? AND php IS NOT NULL');
        $st->execute([$user]);
        $used = $st->fetchAll(\PDO::FETCH_COLUMN);
        foreach (glob(Config::etc() . "/php/*/fpm/pool.d/zp-$user.conf") ?: [] as $f) {
            $php = basename(dirname($f, 3));
            if (!in_array($php, $used, true)) {
                @unlink($f);
                Exec::run(['systemctl', 'reload', "php$php-fpm"]);
            }
        }
    }

    // ---- moving sites made before 0.13.0 -----------------------------------------------------------------------------------

    /** Sites still running as www-data. */
    public static function legacySites(): array
    {
        return Db::pdo()->query("SELECT * FROM sites WHERE sys_user = '' OR sys_user = 'www-data' ORDER BY id")->fetchAll();
    }

    /** Move one site to its account's own user: files, PHP pool, Nginx, FTP accounts, cron, workers, app services. */
    public static function move(int $siteId): array
    {
        $s = Sites::find($siteId) ?? throw new PanelError('Unknown site.', 404);
        $u = self::ensure((int) $s['user_id']);
        Db::pdo()->prepare('UPDATE sites SET sys_user = ? WHERE id = ?')->execute([$u, $siteId]);
        $s = Sites::find($siteId);
        self::own($s);
        self::pool($s);
        Sites::apply($siteId);
        Ftp::reown($s);
        Cron::render();
        SitePlus::rewriteWorkers($s);
        CpanelParity::rewriteServices($s);

        return ['site' => $s['domain'], 'user' => $u];
    }

    /** Owner: the account users and what still runs as www-data. */
    public static function overview(): array
    {
        $rows = Db::pdo()->query("SELECT s.id, s.domain, s.sys_user, s.user_id, u.name AS username FROM sites s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.domain")->fetchAll();

        return ['enabled' => self::enabled(), 'sites' => array_map(fn ($r) => ['id' => (int) $r['id'], 'domain' => $r['domain'], 'account' => $r['username'], 'user' => self::of($r),
            'own' => self::of($r) !== self::LEGACY], $rows), 'legacy' => count(array_filter($rows, fn ($r) => self::of($r) === self::LEGACY))];
    }
}
