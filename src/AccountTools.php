<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Hosting account tools that cPanel / Webuzo Enduser have and the panel did not (Panel 2.0 part 2c):
 *  - network tools: DNS lookup, ping, trace route to a host
 *  - SSH keys of the account's own Linux user (~/.ssh/authorized_keys)
 *  - disk usage: how much each website, its mail and the backups take
 * Every system command goes through Exec (dry-run and the command log keep working).
 */
final class AccountTools
{
    private const KEY_TYPES = ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'];

    // ---------------------------------------------------------------- network tools

    public static function validHost(string $h): bool
    {
        return filter_var($h, FILTER_VALIDATE_IP) !== false
            || (strlen($h) <= 253 && (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $h));
    }

    /** @return array{tool: string, host: string, output: string} */
    public static function network(array $actor, string $tool, string $host): array
    {
        $host = strtolower(trim($host));
        if (!self::validHost($host)) {
            throw new PanelError('Type a domain name (example.com) or an IP address.', 422);
        }
        $out = match ($tool) {
            'dns' => self::dns($host),
            'ping' => self::cmd(['ping', '-c', '4', '-W', '2', $host], 20),
            'trace' => self::cmd(['traceroute', '-m', '20', '-w', '2', '-q', '1', $host], 60),
            default => throw new PanelError('Unknown tool.', 422),
        };
        Audit::log((int) $actor['id'], 'tools.network', ['tool' => $tool, 'host' => $host]);

        return ['tool' => $tool, 'host' => $host, 'output' => $out];
    }

    private static function dns(string $host): string
    {
        $lines = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'NS' => DNS_NS, 'TXT' => DNS_TXT, 'CAA' => DNS_CAA] as $name => $type) {
            $rows = @dns_get_record($host, $type) ?: [];
            foreach ($rows as $r) {
                $v = $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? (isset($r['value']) ? ($r['flags'] ?? '') . ' ' . ($r['tag'] ?? '') . ' ' . $r['value'] : '');
                $lines[] = str_pad($name, 6) . ' ' . (isset($r['pri']) ? $r['pri'] . ' ' : '') . $v . '   (TTL ' . ($r['ttl'] ?? '?') . ')';
            }
        }

        return $lines ? implode("\n", $lines) : 'No DNS records found for ' . $host . '.';
    }

    private static function cmd(array $argv, int $timeout): string
    {
        $r = Exec::run($argv, null, $timeout);

        return trim($r['out']) !== '' ? mb_substr(trim($r['out']), 0, 8000) : ($r['code'] === 0 ? '(no answer)' : 'The command could not run here (' . $argv[0] . ').');
    }

    // ---------------------------------------------------------------- SSH keys

    /** The account's Linux user and its home folder. */
    private static function linux(array $actor): array
    {
        $name = SysUser::nameFor((int) $actor['id']);
        if (Config::dry()) { // tests and the laptop copy: nothing outside the panel's own folders
            return [$name, Config::home() . '/users/' . $name];
        }
        $pw = function_exists('posix_getpwnam') ? @posix_getpwnam($name) : false;
        $home = $pw['dir'] ?? ('/home/' . $name);

        return [$name, rtrim($home, '/')];
    }

    public static function sshKeys(array $actor): array
    {
        [$name, $home] = self::linux($actor);
        $file = $home . '/.ssh/authorized_keys';
        $keys = [];
        $raw = is_readable($file) ? (string) @file_get_contents($file) : (string) (Db::setting('ssh_keys_' . $actor['id']) ?? '');
        foreach (preg_split('/\R/', $raw) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = preg_split('/\s+/', $line, 3);
            $keys[] = ['id' => substr(hash('sha256', $line), 0, 16), 'type' => $p[0] ?? '', 'comment' => $p[2] ?? '', 'fingerprint' => self::fingerprint($p[1] ?? '')];
        }

        return ['linux_user' => $name, 'keys' => $keys, 'shell' => Db::setting('shell_' . $actor['id']) ?? 'none'];
    }

    public static function addKey(array $actor, string $key): array
    {
        $key = trim(preg_replace('/\s+/', ' ', $key));
        $p = explode(' ', $key, 3);
        if (count($p) < 2 || !in_array($p[0], self::KEY_TYPES, true) || base64_decode($p[1], true) === false || strlen($key) > 16384) {
            throw new PanelError('Paste one public key (it starts with ssh-ed25519, ssh-rsa or ecdsa-...). Never paste a private key.', 422);
        }
        if (str_contains($key, 'PRIVATE KEY')) {
            throw new PanelError('That is a private key - keep it secret. Paste the .pub file instead.', 422);
        }
        $lines = self::rawKeys($actor);
        foreach ($lines as $l) {
            if ((preg_split('/\s+/', $l)[1] ?? '') === $p[1]) {
                throw new PanelError('This key is already there.', 409);
            }
        }
        $lines[] = $key;
        self::saveKeys($actor, $lines);
        Audit::log((int) $actor['id'], 'ssh.key_added', ['type' => $p[0], 'fingerprint' => self::fingerprint($p[1])]);

        return self::sshKeys($actor);
    }

    public static function removeKey(array $actor, string $id): array
    {
        $lines = array_values(array_filter(self::rawKeys($actor), fn ($l) => substr(hash('sha256', trim($l)), 0, 16) !== $id));
        self::saveKeys($actor, $lines);
        Audit::log((int) $actor['id'], 'ssh.key_removed', ['id' => $id]);

        return self::sshKeys($actor);
    }

    private static function rawKeys(array $actor): array
    {
        [, $home] = self::linux($actor);
        $file = $home . '/.ssh/authorized_keys';
        $raw = is_readable($file) ? (string) @file_get_contents($file) : (string) (Db::setting('ssh_keys_' . $actor['id']) ?? '');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), fn ($l) => $l !== '' && $l[0] !== '#'));
    }

    private static function saveKeys(array $actor, array $lines): void
    {
        [$name, $home] = self::linux($actor);
        $content = $lines ? implode("\n", $lines) . "\n" : '';
        Db::put('ssh_keys_' . $actor['id'], $content); // the panel's copy (and the only one in dry-run mode)
        Exec::run(['mkdir', '-p', $home . '/.ssh']);
        Exec::write($home . '/.ssh/authorized_keys', $content, 0600);
        Exec::run(['chmod', '700', $home . '/.ssh']);
        Exec::run(['chown', '-R', $name . ':' . $name, $home . '/.ssh']);
    }

    private static function fingerprint(string $b64): string
    {
        $bin = base64_decode($b64, true);

        return $bin === false ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $bin, true)), '=');
    }

    // ---------------------------------------------------------------- disk usage

    /** Size of every website of the account (and of its own backups), largest first. */
    public static function diskUsage(array $actor): array
    {
        $sites = Sites::list($actor);
        $rows = [];
        $total = 0;
        $files = 0;
        foreach ($sites as $s) {
            $dir = Config::www() . '/' . $s['domain'];
            $kb = self::du($dir);
            $n = self::inodes($dir);
            $rows[] = ['what' => $s['domain'], 'kind' => 'Website files', 'bytes' => $kb * 1024, 'files' => $n];
            $total += $kb * 1024;
            $files += $n;
        }
        $mail = Config::home() . '/mail';
        foreach ($sites as $s) {
            $d = $mail . '/' . $s['domain'];
            if (is_dir($d)) {
                $b = self::du($d) * 1024;
                $n = self::inodes($d);
                $rows[] = ['what' => $s['domain'], 'kind' => 'Mail', 'bytes' => $b, 'files' => $n];
                $total += $b;
                $files += $n;
            }
        }
        usort($rows, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        $q = is_array($actor['quota'] ?? null) ? $actor['quota'] : (json_decode((string) ($actor['quota'] ?? '{}'), true) ?: []);
        $limitMb = (int) ($q['disk_mb'] ?? $q['disk'] ?? 0);

        $inodeLimit = (int) ($q['inodes'] ?? 0);

        return ['rows' => $rows, 'total' => $total, 'limit' => $limitMb > 0 ? $limitMb * 1024 * 1024 : null,
            'files' => $files, 'files_limit' => $inodeLimit > 0 ? $inodeLimit : null]; // files and folders = inodes (cPanel "File usage")
    }

    /** Files and folders under a folder (inodes). */
    private static function inodes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $r = Exec::run(['du', '--inodes', '-s', $dir], null, 60);
        if ($r['code'] === 0 && preg_match('/^(\d+)/', trim($r['out']), $m)) {
            return (int) $m[1];
        }
        $n = 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $n++;
        }

        return $n;
    }

    private static function du(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $r = Exec::run(['du', '-sk', $dir], null, 60);
        if ($r['code'] === 0 && preg_match('/^(\d+)/', trim($r['out']), $m)) {
            return (int) $m[1];
        }
        // dry-run / no du: count it ourselves
        $kb = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $kb += (int) ceil($f->getSize() / 1024);
        }

        return $kb;
    }

    // ---------------------------------------------------------------- bandwidth (cPanel "Bandwidth")

    /** This month per website: data sent, page hits and different visitors, from its nginx access log (newest 50 MB). */
    public static function bandwidth(array $actor, ?string $month = null): array
    {
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : gmdate('Y-m');
        $tag = '/' . date('M/Y', (int) strtotime($month . '-01')) . ':'; // "/Nov/2026:" as nginx writes it
        $rows = [];
        $total = 0;
        foreach (Sites::list($actor) as $s) {
            $r = self::logTotals(Nginx::logs() . '/' . $s['domain'] . '.access.log', $tag);
            $rows[] = ['domain' => $s['domain']] + $r;
            $total += $r['bytes'];
        }
        usort($rows, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return ['month' => $month, 'rows' => $rows, 'total' => $total];
    }

    /** @return array{bytes: int, hits: int, visitors: int} */
    public static function logTotals(string $file, string $tag): array
    {
        $bytes = 0; $hits = 0; $ips = [];
        if (!is_readable($file)) {
            return ['bytes' => 0, 'hits' => 0, 'visitors' => 0];
        }
        $fh = fopen($file, 'r');
        $size = filesize($file) ?: 0;
        if ($size > 50 * 1048576) {
            fseek($fh, $size - 50 * 1048576);
            fgets($fh); // skip the cut line
        }
        while (($line = fgets($fh)) !== false) {
            if (!str_contains($line, $tag)) {
                continue;
            }
            // combined format: IP - user [time] "request" status bytes "referer" "agent"
            if (preg_match('/^(\S+) \S+ \S+ \[[^\]]+\] "[^"]*" (\d{3}) (\d+|-)/', $line, $m)) {
                $hits++;
                $bytes += $m[3] === '-' ? 0 : (int) $m[3];
                $ips[$m[1]] = true;
            }
        }
        fclose($fh);

        return ['bytes' => $bytes, 'hits' => $hits, 'visitors' => count($ips)];
    }
}

