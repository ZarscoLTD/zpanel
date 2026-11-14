<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * OpenLiteSpeed for chosen websites (owner 2026-11-14 "litespeed yes"): the CyberPanel / Webuzo web server with LSAPI PHP and
 * LSCache (the WordPress page cache). Nginx stays in front for every site - HTTPS, redirects, blocked IPs, hotlink protection,
 * error pages and the other site tools keep working - and passes the PHP of an OpenLiteSpeed site to OpenLiteSpeed on
 * 127.0.0.1:8098, where the site runs as its own account with lsphp and reads its .htaccess rewrites (like on LiteSpeed hosting).
 * The panel owns one marked block of httpd_config.conf and the vhost folders "zp-<domain>"; nothing else of OpenLiteSpeed is touched.
 */
final class Ols
{
    /** Internal port (OpenLiteSpeed's own example listener already uses 8088, its WebAdmin 7080). */
    public const PORT = 8098;
    private const START = '# ZARS Panel start - managed, do not edit';
    private const END = '# ZARS Panel end';

    public static function root(): string
    {
        return rtrim(getenv('ZARSPANEL_LSWS') ?: '/usr/local/lsws', '/');
    }

    public static function installed(): bool
    {
        return Config::dry() || is_file(self::root() . '/bin/lswsctrl');
    }

    /** @param array<string, mixed> $s a site row */
    public static function uses(array $s): bool
    {
        return ($s['type'] ?? '') === 'php' && (SiteTools::extra($s)['webserver'] ?? 'nginx') === 'ols';
    }

    public static function vhostDir(string $domain): string
    {
        return self::root() . '/conf/vhosts/zp-' . $domain;
    }

    public static function cacheDir(string $domain): string
    {
        return self::root() . '/cachedata/zp-' . $domain;
    }

    /** lsphp of the site's PHP version (lsphp83 for 8.3 ...). */
    public static function lsphp(array $s): string
    {
        return self::root() . '/lsphp' . str_replace('.', '', (string) ($s['php'] ?: '8.3')) . '/bin/lsphp';
    }

    /** The vhost of one site: its folder, its own account's lsphp, .htaccess rewrites, LSCache. Pure text (tested). */
    public static function vhconf(array $s): string
    {
        $d = $s['domain'];
        $u = SysUser::of($s);
        $proc = 'zp_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($u . '_' . str_replace('.', '', (string) ($s['php'] ?: '83'))));
        $log = Nginx::logs();
        $php = [];
        foreach (SiteTools::extra($s)['php'] as $k => $v) { // the site's PHP settings (Site tools > PHP settings) also for lsphp
            if (in_array($k, SiteTools::PHP_KEYS, true)) {
                $php[] = "  php_value $k " . preg_replace('/[^A-Za-z0-9_.\/+-]/', '', (string) $v);
            }
        }

        return "# Managed by ZARS Panel. Changes made here are overwritten; use the panel.\n"
            . "docRoot                   {$s['root']}\nvhDomain                  $d\nenableGzip                1\nenableBr                  1\n"
            . "index  {\n  useServer               0\n  indexFiles              index.php, index.html, index.htm\n}\n"
            . "errorlog $log/$d.ols-error.log {\n  useServer               0\n  logLevel                WARN\n  rollingSize             10M\n}\n"
            . "scripthandler  {\n  add                     lsapi:$proc php\n}\n"
            . "extprocessor $proc {\n  type                    lsapi\n  address                 uds://tmp/lshttpd/$proc.sock\n  maxConns                10\n"
            . "  env                     PHP_LSAPI_CHILDREN=10\n  env                     LSAPI_AVOID_FORK=200M\n  initTimeout             60\n  retryTimeout            0\n"
            . "  persistConn             1\n  respBuffer              0\n  autoStart               2\n  path                    " . self::lsphp($s) . "\n"
            . "  backlog                 100\n  instances               1\n  extUser                 $u\n  extGroup                $u\n  runOnStartUp            2\n"
            . "  priority                0\n  memSoftLimit            2047M\n  memHardLimit            2047M\n  procSoftLimit           400\n  procHardLimit           500\n}\n"
            . "rewrite  {\n  enable                  1\n  autoLoadHtaccess        1\n}\n"
            . ($php ? "phpIniOverride  {\n" . implode("\n", $php) . "\n}\n" : '')
            . "module cache {\n  storagePath             " . self::cacheDir($d) . "\n  ls_enabled              1\n  checkPrivateCache       1\n  checkPublicCache        1\n"
            . "  maxCacheObjSize         10000000\n  maxStaleAge             200\n  qsCache                 1\n  reqCookieCache          1\n  respCookieCache         1\n"
            . "  ignoreReqCacheCtrl      1\n  ignoreRespCacheCtrl     0\n  enableCache             0\n  expireInSeconds         3600\n  enablePrivateCache      0\n"
            . "  privateExpireInSeconds  3600\n}\n";
    }

    /** The marked block of httpd_config.conf: one internal listener, every OpenLiteSpeed site as a virtual host. */
    public static function block(array $sites): string
    {
        $out = self::START . "\n";
        $map = '';
        foreach ($sites as $s) {
            $d = $s['domain'];
            $names = implode(', ', array_filter(array_merge([$d], explode(',', (string) $s['aliases']))));
            $out .= "virtualhost zp-$d {\n  vhRoot                  " . self::vhostDir($d) . "\n  configFile              \$VH_ROOT/vhconf.conf\n"
                . "  allowSymbolLink         1\n  enableScript            1\n  restrained              1\n  setUIDMode              2\n}\n";
            $map .= "  map                     zp-$d $names\n";
        }
        if ($sites) {
            $out .= "listener zarspanel {\n  address                 127.0.0.1:" . self::PORT . "\n  secure                  0\n$map}\n";
        }

        return $out . self::END . "\n";
    }

    /** httpd_config.conf with our block put in (or replaced), and the client's address taken from nginx's header. */
    public static function merge(string $config, string $block): string
    {
        $config = preg_replace('/\n?' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . "\n?/s", "\n", $config) ?? $config;
        // nginx in front: the visitor's IP comes in X-Forwarded-For from 127.0.0.1 (trusted)
        $config = preg_match('/^\s*useIpInProxyHeader\s+\S+/m', $config) ? preg_replace('/^(\s*)useIpInProxyHeader\s+\S+/m', '${1}useIpInProxyHeader        2', $config) : "useIpInProxyHeader        2\n" . $config;

        return rtrim((string) $config, "\n") . "\n\n" . $block;
    }

    /** The nginx part of an OpenLiteSpeed site: files straight from disk, everything else (PHP, rewrites) to OpenLiteSpeed. */
    public static function nginxProxy(): string
    {
        $p = "proxy_pass http://127.0.0.1:" . self::PORT . ";\n        proxy_http_version 1.1;\n        proxy_set_header Host \$host;\n        proxy_set_header X-Real-IP \$remote_addr;\n"
            . "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n        proxy_set_header X-Forwarded-Proto \$scheme;\n        proxy_read_timeout 300s;\n";

        return "    location / { try_files \$uri \$uri/index.html @ols; }\n    location ~ \\.php\$ {\n        $p    }\n    location @ols {\n        $p    }\n";
    }

    /** Write every OpenLiteSpeed site and restart it gracefully (only when OpenLiteSpeed is installed). */
    public static function sync(): void
    {
        if (!self::installed()) {
            return;
        }
        $sites = array_values(array_filter(Db::pdo()->query("SELECT * FROM sites WHERE type = 'php' AND enabled = 1 ORDER BY id")->fetchAll(), fn ($s) => self::uses($s)));
        $keep = [];
        foreach ($sites as $s) {
            Exec::write(self::vhostDir($s['domain']) . '/vhconf.conf', self::vhconf($s));
            $keep[] = 'zp-' . $s['domain'];
        }
        foreach (glob(self::root() . '/conf/vhosts/zp-*', GLOB_ONLYDIR) ?: [] as $dir) { // sites that left OpenLiteSpeed
            if (!in_array(basename($dir), $keep, true)) {
                @unlink($dir . '/vhconf.conf');
                @rmdir($dir);
            }
        }
        $file = self::root() . '/conf/httpd_config.conf';
        $now = is_file($file) ? (string) file_get_contents($file) : '';
        $new = self::merge($now, self::block($sites));
        if ($new !== $now) {
            Exec::write($file, $new);
            Exec::run([self::root() . '/bin/lswsctrl', 'restart']); // graceful: running requests finish
        }
    }

    /** Empty one site's LSCache. */
    public static function purge(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        if (!self::uses($s)) {
            throw new PanelError('This website does not run on OpenLiteSpeed.', 422);
        }
        $dir = self::cacheDir($s['domain']);
        if (is_dir($dir)) {
            Files::rrmdir($dir);
        }
        Audit::log((int) $actor['id'], 'site.lscache_purge', ['domain' => $s['domain']]);

        return ['ok' => true, 'message' => 'LSCache of ' . $s['domain'] . ' emptied.'];
    }
}
