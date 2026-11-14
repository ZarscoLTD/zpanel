<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Per-site web server extras (stored in sites.extra, written into the vhost by Nginx::vhost): custom error pages, hotlink protection,
 * password-protected folders, blocked IPs, folder listing, PHP settings, extra MIME types and the site's disk and traffic figures.
 */
final class SiteTools
{
    public const PHP_KEYS = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_time', 'max_input_vars', 'display_errors', 'date.timezone', 'session.gc_maxlifetime', 'short_open_tag'];

    public static function extra(array $site): array
    {
        $x = json_decode((string) ($site['extra'] ?? '{}'), true) ?: [];

        return $x + ['error_pages' => [], 'hotlink' => ['on' => false, 'allow' => ''], 'protect' => [], 'deny_ips' => [], 'autoindex' => false, 'php' => [], 'mime' => '', 'modsec' => false, 'limit_rate_kb' => 0, 'optimize' => false, 'leech' => 0, 'fcache' => false, 'block_bots' => false, 'preview' => false, 'webserver' => 'nginx'];
    }

    /** What the screen shows: the settings without password hashes. */
    public static function show(array $actor, int $id): array
    {
        $s = Sites::mine($actor, $id);
        $x = self::extra($s);
        $x['protect'] = array_map(fn ($p) => ['path' => $p['path'], 'users' => array_keys($p['users'] ?? [])], $x['protect']);

        return ['tools' => $x, 'php_keys' => self::PHP_KEYS, 'usage' => self::usage($s), 'ols_installed' => is_file(Ols::root() . '/bin/lswsctrl') || Config::dry()];
    }

    public static function save(array $actor, int $id, array $in): array
    {
        $s = Sites::mine($actor, $id);
        $x = self::extra($s);
        if (isset($in['webserver'])) { // OpenLiteSpeed (LSAPI + LSCache) or nginx with PHP-FPM
            $w = (string) $in['webserver'];
            if (!in_array($w, ['nginx', 'ols'], true)) {
                throw new PanelError('Web server must be nginx or ols.', 422);
            }
            if ($w === 'ols' && $s['type'] !== 'php') {
                throw new PanelError('OpenLiteSpeed runs PHP websites; this one is a ' . $s['type'] . ' site.', 422);
            }
            if ($w === 'ols' && !Ols::installed()) {
                throw new PanelError('Install OpenLiteSpeed first: Server > Software > OpenLiteSpeed.', 422);
            }
            if ($w === 'ols' && !Config::dry() && !is_file(Ols::lsphp($s))) {
                throw new PanelError('OpenLiteSpeed has no PHP ' . $s['php'] . ' (lsphp' . str_replace('.', '', (string) $s['php']) . '). Choose PHP 8.2, 8.3 or 8.4 for this site, or install that lsphp.', 422);
            }
                        $x['webserver'] = $w;
        }
                if (isset($in['error_pages'])) {
            $x['error_pages'] = [];
            foreach ((array) $in['error_pages'] as $code => $path) {
                if ($path === '' || $path === null) {
                    continue;
                }
                if (!in_array((int) $code, [400, 401, 403, 404, 500, 502, 503, 504], true) || !preg_match('#^/[A-Za-z0-9._/-]{1,200}$#', (string) $path) || str_contains((string) $path, '..')) {
                    throw new PanelError("Error page for $code must be a path inside the site, like /errors/$code.html", 422);
                }
                $x['error_pages'][(int) $code] = (string) $path;
            }
        }
        if (isset($in['hotlink'])) {
            $allow = [];
            foreach (preg_split('/[\s,]+/', strtolower((string) ($in['hotlink']['allow'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
                if (!Nginx::validDomain(ltrim($d, '*.')) && !Nginx::validDomain($d)) {
                    throw new PanelError("\"$d\" is not a domain name.", 422);
                }
                $allow[] = $d;
            }
            $x['hotlink'] = ['on' => !empty($in['hotlink']['on']), 'allow' => implode(' ', $allow)];
        }
        if (isset($in['deny_ips'])) {
            $x['deny_ips'] = [];
            foreach (preg_split('/[\s,]+/', (string) (is_array($in['deny_ips']) ? implode(' ', $in['deny_ips']) : $in['deny_ips']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
                if (!self::validCidr($ip)) {
                    throw new PanelError("\"$ip\" is not an IP address or range.", 422);
                }
                $x['deny_ips'][] = $ip;
            }
        }
        if (array_key_exists('modsec', $in)) { // web application firewall (ModSecurity + OWASP core rules)
            $x['modsec'] = (bool) $in['modsec'];
        }
        if (array_key_exists('limit_rate_kb', $in)) { // speed limit per visitor connection, 0 = none
            $x['limit_rate_kb'] = max(0, min(1048576, (int) $in['limit_rate_kb']));
        }
        if (array_key_exists('optimize', $in)) { // compression + long browser cache for static files
            $x['optimize'] = (bool) $in['optimize'];
        }
        if (array_key_exists('leech', $in)) { // leech protection: most requests per minute from one address to protected folders
            $x['leech'] = max(0, min(600, (int) $in['leech']));
        }
        if (array_key_exists('autoindex', $in)) {
            $x['autoindex'] = (bool) $in['autoindex'];
        }
        foreach (['fcache', 'block_bots', 'preview'] as $k) { // page cache, bad-bot blocking, preview address (other panels, 0.11.0)
            if (array_key_exists($k, $in)) {
                $x[$k] = (bool) $in[$k];
            }
        }
        if (isset($in['php'])) {
            $x['php'] = [];
            foreach ((array) $in['php'] as $k => $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                if (!in_array($k, self::PHP_KEYS, true) || !preg_match('#^[A-Za-z0-9_./+-]{1,40}$#', (string) $v)) {
                    throw new PanelError("PHP setting $k: only plain values like 256M, 300 or On.", 422);
                }
                $x['php'][$k] = (string) $v;
            }
        }
        if (isset($in['mime'])) {
            $lines = [];
            foreach (preg_split('/\R/', (string) $in['mime'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                if (!preg_match('#^([a-z0-9.+-]+/[a-z0-9.+-]+)\s+((?:[a-z0-9]{1,10}\s*)+)$#i', trim($line), $m)) {
                    throw new PanelError("MIME line \"$line\" must look like: application/manifest+json webmanifest", 422);
                }
                $lines[] = strtolower($m[1]) . ' ' . strtolower(preg_replace('/\s+/', ' ', trim($m[2])));
            }
            $x['mime'] = implode("\n", $lines);
        }
        // password-protected folders: {path, user, password} adds a user, {path, remove_user} / {path, remove: true} take away
        if (isset($in['protect'])) {
            $p = $in['protect'];
            $path = '/' . trim((string) ($p['path'] ?? ''), '/');
            if (!preg_match('#^/[A-Za-z0-9._/-]{0,200}$#', $path) || str_contains($path, '..')) {
                throw new PanelError('The folder must be a path inside the site, like /admin', 422);
            }
            $i = array_search($path, array_column($x['protect'], 'path'), true);
            if (!empty($p['remove'])) {
                if ($i !== false) {
                    array_splice($x['protect'], $i, 1);
                }
            } elseif (!empty($p['remove_user'])) {
                if ($i !== false) {
                    unset($x['protect'][$i]['users'][(string) $p['remove_user']]);
                }
            } else {
                $user = (string) ($p['user'] ?? '');
                $pw = (string) ($p['password'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $user) || strlen($pw) < 8) {
                    throw new PanelError('User name: 2 to 32 letters or numbers. Password: at least 8 characters.', 422);
                }
                $hash = crypt($pw, '$6$' . bin2hex(random_bytes(8)) . '$');
                if ($i === false) {
                    $x['protect'][] = ['path' => $path, 'users' => [$user => $hash]];
                } else {
                    $x['protect'][$i]['users'][$user] = $hash;
                }
            }
        }
        Db::pdo()->prepare('UPDATE sites SET extra = ? WHERE id = ?')->execute([json_encode($x), $id]);
        self::writeHtpasswd($s['domain'], $x);
        Sites::apply($id);
        Audit::log((int) $actor['id'], 'site.tools', ['site' => $s['domain'], 'changed' => array_keys($in)]);

        return self::show($actor, $id);
    }

    public static function validCidr(string $ip): bool
    {
        [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
        if (!filter_var($addr, FILTER_VALIDATE_IP)) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= (str_contains($addr, ':') ? 128 : 32));
    }

    public static function htpasswdFile(string $domain, int $n): string
    {
        return Nginx::dir() . '/htpasswd/' . $domain . '-' . $n;
    }

    private static function writeHtpasswd(string $domain, array $x): void
    {
        foreach (glob(Nginx::dir() . '/htpasswd/' . $domain . '-*') ?: [] as $old) {
            @unlink($old);
        }
        foreach ($x['protect'] as $n => $p) {
            $body = '';
            foreach ($p['users'] ?? [] as $u => $h) {
                $body .= "$u:$h\n";
            }
            Exec::write(self::htpasswdFile($domain, $n), $body, 0640);
        }
    }

    /** nginx lines for the site's server block (php and static sites). */
    public static function nginx(array $s): string
    {
        $x = self::extra($s);
        $out = '';
        foreach ($x['error_pages'] as $code => $path) {
            $out .= "    error_page $code $path;\n";
        }
        foreach ($x['deny_ips'] as $ip) {
            $out .= "    deny $ip;\n";
        }
        if ($x['autoindex']) {
            $out .= "    autoindex on;\n";
        }
        if ($x['modsec']) {
            $out .= "    modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;\n";
        }
        if ($x['optimize']) {
            $out .= "    gzip on;\n    gzip_comp_level 5;\n    gzip_min_length 512;\n    gzip_types text/plain text/css application/javascript application/json image/svg+xml application/xml;\n    location ~* \\.(css|js|woff2?|svg|png|jpe?g|webp|gif|ico)\$ { expires 365d; add_header Cache-Control \"public, immutable\"; access_log off; }\n";
        }
        if ($x['block_bots']) {
            $out .= "    if (\$http_user_agent ~* (" . SitePlus::BOTS . ")) { return 403; }\n";
        }
        if ($x['limit_rate_kb'] > 0) {
            $out .= "    limit_rate {$x['limit_rate_kb']}k;\n";
        }
        if ($x['mime'] !== '') {
            $out .= "    types {\n" . implode('', array_map(fn ($l) => "        $l;\n", explode("\n", $x['mime']))) . "    }\n";
        }
        if ($x['hotlink']['on']) {
            $out .= "    location ~* \\.(jpg|jpeg|png|gif|webp|svg|mp4|webm|mp3|pdf)\$ {\n        valid_referers none blocked server_names {$x['hotlink']['allow']};\n        if (\$invalid_referer) { return 403; }\n    }\n";
        }
        foreach ($x['protect'] as $n => $p) {
            if (!empty($p['users'])) {
                $out .= "    location ^~ {$p['path']} {\n" . ($x['leech'] > 0 ? "        limit_req zone=zp_leech burst=" . max(1, intdiv($x['leech'], 6)) . " nodelay;\n" : '') . "        auth_basic \"Restricted\";\n        auth_basic_user_file " . self::htpasswdFile($s['domain'], $n) . ";\n"
                    . ($s['type'] === 'php' ? "        location ~ \\.php\$ {\n            include snippets/fastcgi-php.conf;\n            fastcgi_pass unix:/run/php/php" . ($s['php'] ?: '8.2') . "-fpm.sock;\n        }\n" : '') . "    }\n";
            }
        }

        return $out;
    }

    /** Page cache lines for the PHP block: pages are kept 10 minutes, never for signed-in visitors, forms or carts. */
    public static function fastcgiCache(array $s): string
    {
        if (!self::extra($s)['fcache']) {
            return '';
        }

        return "        fastcgi_cache zp_cache;\n        fastcgi_cache_key \$scheme\$request_method\$host\$request_uri;\n        fastcgi_cache_valid 200 301 302 10m;\n"
            . "        fastcgi_cache_bypass \$zp_nocache;\n        fastcgi_no_cache \$zp_nocache;\n        fastcgi_cache_use_stale error timeout updating;\n        add_header X-Cache \$upstream_cache_status;\n";
    }

    /** PHP_VALUE line for the fastcgi block. */
    public static function phpValue(array $s): string
    {
        $x = self::extra($s);
        if (!$x['php']) {
            return '';
        }

        return '        fastcgi_param PHP_VALUE "' . implode('\n', array_map(fn ($k, $v) => "$k=$v", array_keys($x['php']), $x['php'])) . "\";\n";
    }

    /** Disk used by the site folder and the traffic of the last days from its access log. */
    public static function usage(array $s): array
    {
        $du = Exec::run(['du', '-sb', Config::www() . '/' . $s['domain']]);
        $bytes = (int) (preg_match('/^(\d+)/', $du['out'], $m) ? $m[1] : 0);
        $days = [];
        $log = Nginx::logs() . '/' . $s['domain'] . '.access.log';
        if (is_file($log)) {
            $fh = fopen($log, 'rb');
            $size = filesize($log);
            fseek($fh, max(0, $size - 20 * 1024 * 1024)); // the last 20 MB is enough for the recent days
            while (($line = fgets($fh)) !== false) {
                if (preg_match('#\[(\d{2}/\w{3}/\d{4}):[^\]]*\] "[^"]*" (\d{3}) (\d+)#', $line, $m)) {
                    $d = \DateTime::createFromFormat('d/M/Y', $m[1]);
                    $k = $d ? $d->format('Y-m-d') : $m[1];
                    $days[$k] ??= ['requests' => 0, 'bytes' => 0];
                    $days[$k]['requests']++;
                    $days[$k]['bytes'] += (int) $m[3];
                }
            }
            fclose($fh);
        }
        krsort($days);

        return ['disk_bytes' => $bytes, 'traffic' => array_slice($days, 0, 14, true)];
    }
}
