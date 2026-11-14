<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Websites: PHP / static / reverse proxy / redirect, with aliases, rewrite presets, SSL and per-site PHP version. */
final class Sites
{
    public const TYPES = ['php', 'static', 'proxy', 'redirect'];

    public static function phpVersions(): array
    {
        $found = [];
        foreach (glob(Config::etc() . '/php/*/fpm', GLOB_ONLYDIR) ?: [] as $d) {
            $found[] = basename(dirname($d));
        }
        sort($found);

        return $found ?: ['8.2'];
    }

    public static function findByDomain(string $domain): ?array
    {
        $q = Db::pdo()->prepare('SELECT * FROM sites WHERE domain = ?');
        $q->execute([$domain]);

        return $q->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM sites WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public static function list(array $actor): array
    {
        $db = Db::pdo();
        if ($actor['role'] === 'owner') {
            return $db->query('SELECT s.*, u.name AS owner FROM sites s JOIN users u ON u.id = s.user_id ORDER BY s.domain')->fetchAll();
        }
        $st = $db->prepare("SELECT s.*, u.name AS owner FROM sites s JOIN users u ON u.id = s.user_id WHERE s.user_id = :a OR s.user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY s.domain");
        $st->execute([':a' => $actor['id']]);

        return $st->fetchAll();
    }

    public static function mine(array $actor, int $id): array
    {
        $s = self::find($id) ?? throw new PanelError('Site not found.', 404);
        $owner = Auth::user((int) $s['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Site not found.', 404);
        }

        return $s;
    }

    public static function create(array $actor, array $in): array
    {
        $domain = strtolower(trim((string) ($in['domain'] ?? '')));
        if (!Nginx::validDomain($domain)) {
            throw new PanelError('That is not a valid domain name.', 422);
        }
        $type = $in['type'] ?? 'php';
        if (!in_array($type, self::TYPES, true)) {
            throw new PanelError('Unknown site type.', 422);
        }
        $ownerId = (int) ($in['user_id'] ?? $actor['id']);
        $owner = Auth::user($ownerId) ?? throw new PanelError('Unknown owner.', 422);
        if (!Auth::manages($actor, $owner)) {
            throw new PanelError('You cannot add sites for this user.', 403);
        }
        Quota::check($owner, 'sites');
        $db = Db::pdo();
        $st = $db->prepare('SELECT 1 FROM sites WHERE domain = ?');
        $st->execute([$domain]);
        if ($st->fetchColumn()) {
            throw new PanelError('This domain already has a site.', 422);
        }
        $aliases = self::cleanAliases((string) ($in['aliases'] ?? ''));
        $php = $type === 'php' ? (string) ($in['php'] ?? self::phpVersions()[count(self::phpVersions()) - 1]) : null;
        if ($php !== null && !in_array($php, self::phpVersions(), true)) {
            throw new PanelError('That PHP version is not installed.', 422);
        }
        $proxy = $redirect = null;
        if ($type === 'proxy') {
            $proxy = rtrim((string) ($in['proxy_to'] ?? ''), '/');
            if (!preg_match('#^https?://(127\.0\.0\.1|localhost|[a-z0-9.-]+)(:\d{1,5})?$#i', $proxy)) {
                throw new PanelError('The proxy address must look like http://127.0.0.1:3000', 422);
            }
        }
        if ($type === 'redirect') {
            $redirect = (string) ($in['redirect_to'] ?? '');
            if (!preg_match('#^https?://[^\s;{}]+$#i', $redirect)) {
                throw new PanelError('The redirect address must start with http:// or https://', 422);
            }
        }
        $rewrite = (string) ($in['rewrite'] ?? 'none');
        if (!isset(Nginx::REWRITES[$rewrite])) {
            $rewrite = 'none';
        }
        $root = Config::www() . '/' . $domain . (!empty($in['public_dir']) ? '/public' : '');
        $sys = SysUser::enabled() ? SysUser::ensure($ownerId) : SysUser::LEGACY; // one Linux user per hosting account
        $row = ['sys_user' => $sys, 'user_id' => $ownerId, 'domain' => $domain, 'aliases' => $aliases, 'type' => $type, 'php' => $php, 'root' => $root, 'proxy_to' => $proxy, 'redirect_to' => $redirect, 'rewrite' => $rewrite,
            'ssl' => 0, 'force_https' => 0, 'enabled' => 1, 'app' => $in['app'] ?? null];
        if (in_array($type, ['php', 'static'], true)) {
            Config::ensure($root);
            Hosting::copySkeleton($root); // the owner's skeleton folder (Server tools > Templates)
            if (!is_file($root . '/index.html') && !is_file($root . '/index.php') && count(glob($root . '/*') ?: []) === 0) {
                file_put_contents($root . '/index.html', '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($domain) . '</title><h1>' . htmlspecialchars($domain) . '</h1><p>This site is ready. Upload your files with the file manager.</p>');
            }
            SysUser::own(['domain' => $domain, 'sys_user' => $sys]);
        }
        $db->prepare('INSERT INTO sites (sys_user, user_id, domain, aliases, type, php, root, proxy_to, redirect_to, rewrite, ssl, force_https, enabled, app, created_at) VALUES (:sys_user, :user_id, :domain, :aliases, :type, :php, :root, :proxy_to, :redirect_to, :rewrite, :ssl, :force_https, :enabled, :app, :c)')
            ->execute($row + ['c' => gmdate('c')]);
        $id = (int) $db->lastInsertId();
        SysUser::pool(self::find($id));
        try {
            self::apply($id);
        } catch (PanelError $e) {
            $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
            @unlink(Nginx::file($domain));
            throw $e;
        }
        Audit::log((int) $actor['id'], 'site.create', ['domain' => $domain, 'type' => $type]);

        return self::find($id);
    }

    public static function cleanAliases(string $raw): string
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
            if (!Nginx::validDomain($a)) {
                throw new PanelError("\"$a\" is not a valid domain name.", 422);
            }
            $out[] = $a;
        }

        return implode(',', array_unique($out));
    }

    /** Write the vhost and reload nginx; when nginx refuses the new file, the old one is put back. */
    public static function apply(int $id): void
    {
        $s = self::find($id) ?? throw new PanelError('Site not found.', 404);
        $file = Nginx::file($s['domain'], (bool) $s['enabled']);
        $off = Nginx::file($s['domain'], !$s['enabled']);
        $previous = is_file($file) ? file_get_contents($file) : null;
        @unlink($off);
        $cert = Config::etc() . '/letsencrypt/live/' . $s['domain'] . '/fullchain.pem';
        Exec::write($file, Nginx::vhost($s, (bool) $s['ssl'] && (is_file($cert) || Config::dry())));
        if ($s['enabled']) {
            if (!Exec::ok(['nginx', '-t'])) {
                $previous !== null ? Exec::write($file, $previous) : @unlink($file);
                throw new PanelError('nginx did not accept this configuration, nothing was changed.', 500);
            }
            Exec::run(['systemctl', 'reload', 'nginx']);
        }
        if (Ols::uses($s) || is_dir(Ols::vhostDir($s['domain']))) {
            Ols::sync();
        }
    }

    public static function update(array $actor, int $id, array $in): array
    {
        $s = self::mine($actor, $id);
        $set = [];
        foreach (['aliases' => fn ($v) => self::cleanAliases((string) $v), 'force_https' => fn ($v) => $v ? 1 : 0, 'rewrite' => fn ($v) => isset(Nginx::REWRITES[$v]) ? $v : 'none',
            'php' => fn ($v) => in_array($v, self::phpVersions(), true) ? $v : throw new PanelError('That PHP version is not installed.', 422)] as $k => $f) {
            if (array_key_exists($k, $in) && ($k !== 'php' || $s['type'] === 'php')) {
                $set[$k] = $f($in[$k]);
            }
        }
        if (isset($set['force_https']) && $set['force_https'] && !$s['ssl']) {
            throw new PanelError('Get an SSL certificate first, then force HTTPS.', 422);
        }
        if ($set) {
            Db::pdo()->prepare('UPDATE sites SET ' . implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($set))) . ' WHERE id = :id')->execute($set + ['id' => $id]);
            if (isset($set['php'])) { // the account's pool for the new PHP version; the old one goes when nothing uses it
                SysUser::pool(self::find($id));
            }
            self::apply($id);
            isset($set['php']) && SysUser::prune(SysUser::of($s));
            Audit::log((int) $actor['id'], 'site.update', ['domain' => $s['domain']] + $set);
        }

        return self::find($id);
    }

    public static function toggle(array $actor, int $id, bool $on): array
    {
        $s = self::mine($actor, $id);
        Db::pdo()->prepare('UPDATE sites SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
        $file = Nginx::file($s['domain'], (bool) $s['enabled']);
        if (is_file($file) && $s['enabled'] != $on) {
            rename($file, Nginx::file($s['domain'], $on));
        }
        self::apply($id);
        if (!$on) {
            Exec::run(['systemctl', 'reload', 'nginx']);
        }
        Audit::log((int) $actor['id'], $on ? 'site.enable' : 'site.disable', ['domain' => $s['domain']]);

        return self::find($id);
    }

    public static function delete(array $actor, int $id, bool $withFiles): void
    {
        $s = self::mine($actor, $id);
        @unlink(Nginx::file($s['domain']));
        @unlink(Nginx::file($s['domain'], false));
        Db::pdo()->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
        SysUser::prune(SysUser::of($s));
        Exec::run(['systemctl', 'reload', 'nginx']);
        if (is_dir(Ols::vhostDir($s['domain']))) {
            Ols::sync();
        }
        if ($withFiles && in_array($s['type'], ['php', 'static'], true) && str_starts_with($s['root'], Config::www() . '/')) {
            $top = Config::www() . '/' . $s['domain'];
            if (is_dir($top)) {
                Files::rrmdir($top);
            }
        }
        Audit::log((int) $actor['id'], 'site.delete', ['domain' => $s['domain'], 'files' => $withFiles]);
    }

    /**
     * Change a website's main domain (WHM / Webuzo "change domain"): its folder moves to the new name, the web server config is
     * written again and HTTPS is switched off until a certificate for the new name is issued. Mail of the old domain is not moved.
     */
    public static function rename(array $actor, int $id, string $domain): array
    {
        $s = self::mine($actor, $id);
        $domain = strtolower(trim($domain));
        if (!Nginx::validDomain($domain)) {
            throw new PanelError('That is not a valid domain name.', 422);
        }
        if ($domain === $s['domain']) {
            return $s;
        }
        if (self::findByDomain($domain)) {
            throw new PanelError('Another website already uses this domain.', 409);
        }
        $oldTop = Config::www() . '/' . $s['domain'];
        $newTop = Config::www() . '/' . $domain;
        if (is_dir($newTop)) {
            throw new PanelError('A folder with the new name is already there.', 409);
        }
        $root = $s['root'];
        if (in_array($s['type'], ['php', 'static'], true) && str_starts_with($root, $oldTop)) {
            if (is_dir($oldTop) && !@rename($oldTop, $newTop) && Exec::run(['mv', $oldTop, $newTop])['code'] !== 0) {
                throw new PanelError('The website folder could not be moved.', 500);
            }
            $root = $newTop . substr($root, strlen($oldTop));
        }
        @unlink(Nginx::file($s['domain']));
        @unlink(Nginx::file($s['domain'], false));
        Db::pdo()->prepare('UPDATE sites SET domain = ?, root = ?, ssl = 0, force_https = 0 WHERE id = ?')->execute([$domain, $root, $id]);
        self::apply($id);
        Audit::log((int) $actor['id'], 'site.rename', ['from' => $s['domain'], 'to' => $domain]);

        return self::find($id);
    }

    /** Free certificate from Let's Encrypt by the HTTP challenge, then switch the site to HTTPS. */
    public static function issueSsl(array $actor, int $id, string $email): array
    {
        $s = self::mine($actor, $id);
        if (!in_array($s['type'], ['php', 'static'], true)) {
            throw new PanelError('Certificates by file challenge need a PHP or static site.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('Enter an e-mail address for the certificate notices.', 422);
        }
        $argv = ['certbot', 'certonly', '--webroot', '-w', $s['root'], '--non-interactive', '--agree-tos', '-m', $email, '--cert-name', $s['domain'], '-d', $s['domain']];
        foreach (array_filter(explode(',', (string) $s['aliases'])) as $a) {
            $argv[] = '-d';
            $argv[] = $a;
        }
        $r = Exec::run(array_merge($argv, SslPlus::caArgs()), null, 240);
        if ($r['code'] !== 0) {
            throw new PanelError('The certificate was not issued. Check that the domain points to this server. ' . mb_substr(trim($r['out']), -300), 422);
        }
        Db::pdo()->prepare('UPDATE sites SET ssl = 1 WHERE id = ?')->execute([$id]);
        self::apply($id);
        Audit::log((int) $actor['id'], 'site.ssl', ['domain' => $s['domain']]);

        return self::find($id);
    }
}
