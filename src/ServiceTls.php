<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Service certificates (WHM "Manage service SSL certificates", Webuzo "panel SSL"): a free Let's Encrypt certificate for the server's
 * host name, used by the panel itself and by mail (Postfix / Dovecot), renewed by certbot and copied in again by its deploy hook.
 * Until then the panel runs on the self-signed certificate the installer made.
 */
final class ServiceTls
{
    /** Folder of the panel's own certificate (installer: /opt/zarspanel/ssl). */
    public static function dir(): string
    {
        return dirname(Config::home()) . '/ssl';
    }

    public static function status(): array
    {
        $crt = self::dir() . '/panel.crt';
        $info = is_readable($crt) ? @openssl_x509_parse((string) file_get_contents($crt)) : false;
        $host = ServerTools::info()['hostname'];

        return [
            'hostname' => $host,
            'subject' => $info['subject']['CN'] ?? null,
            'issuer' => $info ? ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? null) : null,
            'expires' => $info ? gmdate('c', (int) $info['validTo_time_t']) : null,
            'self_signed' => $info ? (($info['issuer'] ?? []) == ($info['subject'] ?? [])) : true,
            'mail' => (bool) Db::setting('service_tls_mail'),
        ];
    }

    /** Issue (or renew) the certificate for the host name and put it in place for the panel and mail. */
    public static function issue(string $email): array
    {
        $host = ServerTools::info()['hostname'];
        if (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            throw new PanelError('Give the server a full host name first (Server, host name and time zone), like server1.example.com, and point it to this server in DNS.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('Enter an e-mail address for the certificate notices.', 422);
        }
        // a small port-80 block answers the Let's Encrypt challenge for the host name
        $acme = Config::ensure(Config::home() . '/acme');
        Exec::write(Nginx::dir() . '/00-service-tls.conf', "server {\n    listen 80;\n    listen [::]:80;\n    server_name $host;\n"
            . "    location ^~ /.well-known/acme-challenge/ { root $acme; allow all; }\n    location / { return 404; }\n}\n", 0644);
        Exec::run(['systemctl', 'reload', 'nginx']);
        $hook = self::dir() . '/deploy-hook.sh';
        Exec::write($hook, self::hook($host), 0750);
        $r = Exec::run(['certbot', 'certonly', '--webroot', '-w', $acme, '--non-interactive', '--agree-tos', '-m', $email, '--cert-name', 'zarspanel-host',
            '-d', $host, '--deploy-hook', $hook, ...SslPlus::caArgs()], null, 300);
        if ($r['code'] !== 0) {
            throw new PanelError('Let\'s Encrypt did not give the certificate: ' . mb_substr(trim($r['out']), 0, 240) . ' - check that ' . $host . ' points to this server.', 500);
        }
        Exec::run(['sh', $hook]); // first time: copy now (renewals run the hook themselves)
        Db::put('service_tls_mail', '1');

        return self::status();
    }

    /** Copies the certificate to the panel and mail, then reloads them. Runs on every renewal. */
    private static function hook(string $host): string
    {
        $live = Config::etc() . '/letsencrypt/live/zarspanel-host';
        $dir = self::dir();

        return "#!/bin/sh\n# ZARS Panel: put the renewed host certificate in place for the panel and mail\nset -e\n"
            . "cp $live/fullchain.pem $dir/panel.crt\ncp $live/privkey.pem $dir/panel.key\nchmod 600 $dir/panel.key\n"
            . "if command -v postconf >/dev/null; then postconf -e 'smtpd_tls_cert_file=$live/fullchain.pem' 'smtpd_tls_key_file=$live/privkey.pem'; systemctl reload postfix || true; fi\n"
            . "if [ -d /etc/dovecot/conf.d ]; then printf 'ssl_cert = <$live/fullchain.pem\\nssl_key = <$live/privkey.pem\\n' > /etc/dovecot/conf.d/99-zarspanel-ssl.conf; systemctl reload dovecot || true; fi\n"
            . "systemctl reload nginx\n";
    }
}
