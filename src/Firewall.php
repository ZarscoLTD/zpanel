<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Firewall (ufw) and SSH settings. Owner only. It refuses rules that would cut off the panel or SSH. */
final class Firewall
{
    public static function panelPort(): int
    {
        return (int) (Db::setting('panel.port') ?: getenv('ZARSPANEL_PORT') ?: 8443);
    }

    public static function sshPort(): int
    {
        $f = Config::etc() . '/ssh/sshd_config.d/zarspanel.conf';
        if (is_file($f) && preg_match('/^Port\s+(\d+)/m', (string) file_get_contents($f), $m)) {
            return (int) $m[1];
        }

        return 22;
    }

    public static function status(): array
    {
        $r = Exec::run(['ufw', 'status', 'numbered']);
        $rules = [];
        foreach (explode("\n", $r['out']) as $line) {
            if (preg_match('/^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?: IN| OUT)?\s+(.+)$/', trim($line), $m)) {
                $rules[] = ['number' => (int) $m[1], 'to' => trim($m[2]), 'action' => $m[3], 'from' => trim($m[4])];
            }
        }
        $f2b = Exec::run(['fail2ban-client', 'status', 'sshd']);

        return ['active' => str_contains($r['out'], 'Status: active'), 'rules' => $rules, 'panel_port' => self::panelPort(), 'ssh_port' => self::sshPort(),
            'root_login' => self::rootLogin(), 'fail2ban' => ['running' => $f2b['code'] === 0, 'banned' => preg_match('/Currently banned:\s+(\d+)/', $f2b['out'], $m) ? (int) $m[1] : 0]];
    }

    public static function rootLogin(): bool
    {
        $f = Config::etc() . '/ssh/sshd_config.d/zarspanel.conf';

        return !is_file($f) || !preg_match('/^PermitRootLogin\s+(no|prohibit-password)/mi', (string) file_get_contents($f));
    }

    public static function allow(int $port, string $proto, ?string $from = null): void
    {
        $proto = self::proto($proto);
        self::port($port);
        $argv = ['ufw', 'allow'];
        if ($from) {
            array_push($argv, 'from', self::ip($from), 'to', 'any', 'port', (string) $port, 'proto', $proto);
        } else {
            $argv[] = $port . '/' . $proto;
        }
        Exec::run($argv);
    }

    public static function deny(int $port, string $proto): void
    {
        $proto = self::proto($proto);
        self::port($port);
        if (in_array($port, [self::panelPort(), self::sshPort()], true)) {
            throw new PanelError('That port is used by the panel or SSH. Blocking it would lock you out.', 422);
        }
        Exec::run(['ufw', 'deny', $port . '/' . $proto]);
    }

    public static function blockIp(string $ip): void
    {
        Exec::run(['ufw', 'insert', '1', 'deny', 'from', self::ip($ip)]);
    }

    public static function deleteRule(int $number): void
    {
        $rule = null;
        foreach (self::status()['rules'] as $r) {
            if ($r['number'] === $number) {
                $rule = $r;
            }
        }
        if ($rule === null && !Config::dry()) {
            throw new PanelError('That rule does not exist.', 404);
        }
        if ($rule && $rule['action'] === 'ALLOW' && preg_match('#^(\d+)(/\w+)?(\s|$)#', $rule['to'], $m) && in_array((int) $m[1], [self::panelPort(), self::sshPort()], true)) {
            throw new PanelError('That rule keeps the panel or SSH reachable. Delete it from the server console if you really mean it.', 422);
        }
        Exec::run(['ufw', '--force', 'delete', (string) $number]);
    }

    public static function enable(bool $on): void
    {
        if ($on) { // never switch on without the doors the owner needs
            foreach ([self::sshPort(), self::panelPort(), 80, 443] as $p) {
                Exec::run(['ufw', 'allow', $p . '/tcp']);
            }
            Exec::run(['ufw', '--force', 'enable']);
        } else {
            Exec::run(['ufw', 'disable']);
        }
    }

    /** New SSH port and / or root login off. The new port is opened in the firewall first; sshd is tested before it is reloaded. */
    public static function ssh(?int $port, ?bool $rootLogin): void
    {
        $file = Config::etc() . '/ssh/sshd_config.d/zarspanel.conf';
        $port ??= self::sshPort();
        $root = $rootLogin ?? self::rootLogin();
        if ($port < 1024 && $port !== 22 || $port > 65535) {
            throw new PanelError('Use port 22 or a port between 1024 and 65535.', 422);
        }
        $previous = is_file($file) ? file_get_contents($file) : null;
        Exec::run(['ufw', 'allow', $port . '/tcp']);
        Exec::write($file, "# Managed by ZARS Panel\nPort $port\nPermitRootLogin " . ($root ? 'yes' : 'prohibit-password') . "\n", 0644);
        if (!Exec::ok(['sshd', '-t'])) {
            $previous !== null ? Exec::write($file, $previous, 0644) : @unlink($file);
            throw new PanelError('The SSH settings were not accepted by sshd, nothing was changed.', 500);
        }
        Exec::run(['systemctl', 'reload', 'ssh']);
    }

    private static function proto(string $p): string
    {
        $p = strtolower($p);
        if (!in_array($p, ['tcp', 'udp'], true)) {
            throw new PanelError('The protocol must be tcp or udp.', 422);
        }

        return $p;
    }

    private static function port(int $p): void
    {
        if ($p < 1 || $p > 65535) {
            throw new PanelError('The port must be between 1 and 65535.', 422);
        }
    }

    private static function ip(string $ip): string
    {
        $base = explode('/', $ip)[0];
        $mask = explode('/', $ip)[1] ?? null;
        if (!filter_var($base, FILTER_VALIDATE_IP) || ($mask !== null && (!ctype_digit($mask) || (int) $mask > (str_contains($base, ':') ? 128 : 32)))) {
            throw new PanelError('That is not a valid IP address.', 422);
        }

        return $ip;
    }
}
