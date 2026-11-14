<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Writes the nginx virtual host of a site. Pure text generation (testable); applying it (nginx -t, reload) is Sites::apply(). */
final class Nginx
{
    public const REWRITES = [
        'none' => '',
        'laravel' => "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }\n",
        'wordpress' => "    location / { try_files \$uri \$uri/ /index.php?\$args; }\n",
        'spa' => "    location / { try_files \$uri /index.html; }\n",
        'codeigniter' => "    location / { try_files \$uri \$uri/ /index.php?/\$request_uri; }\n",
    ];

    public static function dir(): string
    {
        return Config::etc() . '/nginx/zarspanel';
    }

    /** Extra nginx lines of one site (Server tools > Extra configuration), included in its server block. */
    public static function extraFile(string $domain): string
    {
        return self::dir() . '/extra/' . $domain . '.inc';
    }

    public static function file(string $domain, bool $enabled = true): string
    {
        return self::dir() . '/' . $domain . '.conf' . ($enabled ? '' : '.off');
    }

    public static function logs(): string
    {
        return rtrim(getenv('ZARSPANEL_LOGS') ?: '/www/wwwlogs', '/');
    }

    public static function validDomain(string $d): bool
    {
        return (bool) preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d) && strlen($d) <= 253;
    }

    /** @param array<string, mixed> $s a site row */
    public static function vhost(array $s, ?bool $hasCert = null): string
    {
        $names = trim($s['domain'] . ' ' . str_replace(',', ' ', (string) $s['aliases']) . (!empty(SiteTools::extra($s)['preview']) ? ' ' . SitePlus::previewName($s) : ''));
        $d = $s['domain'];
        $cert = Config::etc() . '/letsencrypt/live/' . $d;
        $hasCert ??= !empty($s['ssl']);
        $log = self::logs();
        $common = "    access_log $log/$d.access.log;\n    error_log $log/$d.error.log;\n";
        $headers = "    add_header X-Content-Type-Options nosniff always;\n    add_header X-Frame-Options SAMEORIGIN always;\n    add_header Referrer-Policy strict-origin-when-cross-origin always;\n";
        $out = "# Managed by ZARS Panel. Changes made here are overwritten; use the panel.\n";

        $body = '';
        switch ($s['type']) {
            case 'redirect':
                $body = "    return 301 {$s['redirect_to']};\n";
                break;
            case 'proxy':
                $body = "    location / {\n        proxy_pass {$s['proxy_to']};\n        proxy_http_version 1.1;\n        proxy_set_header Host \$host;\n        proxy_set_header X-Real-IP \$remote_addr;\n        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n        proxy_set_header X-Forwarded-Proto \$scheme;\n        proxy_set_header Upgrade \$http_upgrade;\n        proxy_set_header Connection \$connection_upgrade;\n        proxy_read_timeout 300s;\n    }\n";
                break;
            default:
                $ols = Ols::uses($s); // OpenLiteSpeed site: rewrites come from its .htaccess, PHP runs in OpenLiteSpeed
                $rw = self::REWRITES[$s['rewrite'] ?: ($s['type'] === 'php' ? 'none' : 'none')] ?? '';
                $body = "    root {$s['root']};\n    index index.php index.html index.htm;\n" . ($ols ? '' : ($rw ?: "    location / { try_files \$uri \$uri/ " . ($s['type'] === 'php' ? '=404' : '=404') . "; }\n"));
                $body .= "    location ~ /\\.(?!well-known) { deny all; }\n    location ~* \\.(git|env|sql|log|bak|ini)\$ { deny all; }\n";
                $body .= SiteTools::nginx($s); // error pages, blocked IPs, hotlink, protected folders, MIME types
                if (is_file(self::extraFile($s['domain']))) {
                    $body .= '    include ' . self::extraFile($s['domain']) . ";\n";
                }
                if ($ols) {
                    $body .= Ols::nginxProxy();
                } elseif ($s['type'] === 'php') {
                    $sock = SysUser::socket($s); // the account's own PHP-FPM pool (or the shared one for sites made before 0.13.0)
                    $body .= "    location ~ \\.php\$ {\n        include snippets/fastcgi-php.conf;\n        fastcgi_pass unix:$sock;\n        fastcgi_read_timeout 300;\n" . SiteTools::phpValue($s) . SiteTools::fastcgiCache($s) . "    }\n";
                }
                $body .= "    location ~* \\.(jpg|jpeg|png|gif|ico|css|js|svg|webp|woff2?)\$ { expires 30d; access_log off; }\n";
        }

        $acme = "    location ^~ /.well-known/acme-challenge/ { root {$s['root']}; allow all; }\n";
        if ($hasCert) {
            // port 80: only redirect when HTTPS is forced, else serve the site too
            $out .= "server {\n    listen 80;\n    listen [::]:80;\n    server_name $names;\n$common$acme";
            $out .= !empty($s['force_https']) ? "    location / { return 301 https://\$host\$request_uri; }\n}\n" : $body . "}\n";
            $out .= "server {\n    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n    server_name $names;\n$common$headers    add_header Strict-Transport-Security \"max-age=31536000\" always;\n";
            $out .= "    ssl_certificate $cert/fullchain.pem;\n    ssl_certificate_key $cert/privkey.pem;\n    ssl_protocols TLSv1.2 TLSv1.3;\n    ssl_session_cache shared:SSL:10m;\n$body}\n";
        } else {
            $out .= "server {\n    listen 80;\n    listen [::]:80;\n    server_name $names;\n$common$headers$acme$body}\n";
        }

        return $out;
    }

    /** Needed once in nginx.conf: the WebSocket upgrade map used by proxy sites. */
    public static function mainInclude(): string
    {
        return "map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }\nlimit_req_zone \$binary_remote_addr zone=zp_leech:10m rate=60r/m;\n"
            . "fastcgi_cache_path " . SitePlus::cacheDir() . " levels=1:2 keys_zone=zp_cache:64m max_size=2g inactive=60m use_temp_path=off;\n"
            . "map \$http_cookie\$request_method \$zp_nocache { default 0; ~*(wordpress_logged_in|wp-postpass|comment_author|woocommerce_items_in_cart|laravel_session|XSRF-TOKEN|PHPSESSID) 1; ~POST\$ 1; }\n"
            . "include " . self::dir() . "/*.conf;\n";
    }
}
