<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * DNS zone editor: zones and records live in the panel database and are written as BIND zone files (etc/bind/zones/db.<domain>) that
 * named.conf.zarspanel includes. Every value is checked for its record type, so nothing a client types can break a zone file.
 */
final class Dns
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    public static function validDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d);
    }

    private static function host(string $h): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.?$/i', $h);
    }

    public static function zone(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM dns_zones WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch() ?: throw new PanelError('Zone not found.', 404);
        $owner = Auth::user((int) $z['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Zone not found.', 404);
        }

        return $z;
    }

    public static function list(array $actor): array
    {
        $rows = $actor['role'] === 'owner' ? Db::pdo()->query('SELECT * FROM dns_zones ORDER BY domain')->fetchAll() : (function () use ($actor) {
            $st = Db::pdo()->prepare('SELECT * FROM dns_zones WHERE user_id = :a OR user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY domain');
            $st->execute([':a' => $actor['id']]);

            return $st->fetchAll();
        })();

        return array_map(fn ($z) => $z + ['records' => self::records((int) $z['id'])], $rows);
    }

    public static function records(int $zoneId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM dns_records WHERE zone_id = ? ORDER BY type, name, id');
        $st->execute([$zoneId]);

        return $st->fetchAll();
    }

    public static function create(array $actor, array $in): array
    {
        $d = strtolower(trim((string) ($in['domain'] ?? '')));
        if (!self::validDomain($d)) {
            throw new PanelError('Enter a domain like example.com.', 422);
        }
        $ex = Db::pdo()->prepare('SELECT 1 FROM dns_zones WHERE domain = ?');
        $ex->execute([$d]);
        if ($ex->fetchColumn()) {
            throw new PanelError('This domain already has a zone.', 409);
        }
        $ip = trim((string) ($in['ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new PanelError('The server address is not a valid IP.', 422);
        }
        Db::pdo()->prepare('INSERT INTO dns_zones (user_id, domain, serial, created_at) VALUES (?, ?, ?, ?)')->execute([$actor['id'], $d, (int) date('Ymd') * 100, gmdate('c')]);
        $id = (int) Db::pdo()->lastInsertId();
        // the starter records come from the owner's DNS template (Server tools > Templates)
        foreach (Hosting::templateRecords(Hosting::dnsTemplate(), $d, $ip) as [$t, $n, $v, $prio]) {
            self::insert($id, $t === 'A' && str_contains($v, ':') ? 'AAAA' : $t, $n, $v, 3600, $prio);
        }
        self::publish($id);
        Audit::log((int) $actor['id'], 'dns.zone_created', ['domain' => $d]);

        return self::zone($actor, $id) + ['records' => self::records($id)];
    }

    private static function insert(int $zone, string $type, string $name, string $value, int $ttl, ?int $prio): void
    {
        Db::pdo()->prepare('INSERT INTO dns_records (zone_id, type, name, value, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)')->execute([$zone, $type, $name, $value, $ttl, $prio]);
    }

    public static function addRecord(array $actor, int $zoneId, array $in): array
    {
        $z = self::zone($actor, $zoneId);
        $type = strtoupper(trim((string) ($in['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new PanelError('Choose a record type.', 422);
        }
        $name = strtolower(trim((string) ($in['name'] ?? '@'))) ?: '@';
        if ($name !== '@' && !preg_match('/^([a-z0-9_*]([a-z0-9_-]{0,61}[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $name)) {
            throw new PanelError('The name may have letters, numbers, - and _ only (use @ for the domain itself).', 422);
        }
        $value = trim((string) ($in['value'] ?? ''));
        $ttl = max(60, min(86400, (int) ($in['ttl'] ?? 3600)));
        $prio = isset($in['prio']) && $in['prio'] !== '' ? max(0, min(65535, (int) $in['prio'])) : null;
        $ok = match ($type) {
            'A' => (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
            'AAAA' => (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
            'CNAME', 'NS' => self::host($value),
            'MX' => self::host($value) && $prio !== null,
            'TXT' => $value !== '' && strlen($value) <= 2000 && !preg_match('/[\x00-\x1f"\\\\]/', $value),
            'SRV' => (bool) preg_match('/^\d{1,5} \d{1,5} [a-z0-9.-]+\.?$/i', $value) && $prio !== null,
            'CAA' => (bool) preg_match('/^\d (issue|issuewild|iodef) "[^"\s;]+"$/', $value),
        };
        if (!$ok) {
            throw new PanelError("The value does not fit a $type record" . ($type === 'MX' || $type === 'SRV' ? ' (it needs a priority too).' : '.'), 422);
        }
        if ($type === 'CNAME') { // a CNAME cannot share a name with another record
            $st = Db::pdo()->prepare('SELECT 1 FROM dns_records WHERE zone_id = ? AND name = ?');
            $st->execute([$z['id'], $name]);
            if ($name === '@' || $st->fetchColumn()) {
                throw new PanelError('A CNAME cannot share its name with another record.', 422);
            }
        }
        self::insert((int) $z['id'], $type, $name, $value, $ttl, in_array($type, ['MX', 'SRV'], true) ? $prio : null);
        self::publish((int) $z['id']);

        return self::records((int) $z['id']);
    }

    public static function deleteRecord(array $actor, int $zoneId, int $recordId): array
    {
        $z = self::zone($actor, $zoneId);
        Db::pdo()->prepare('DELETE FROM dns_records WHERE id = ? AND zone_id = ?')->execute([$recordId, $z['id']]);
        self::publish((int) $z['id']);

        return self::records((int) $z['id']);
    }

    public static function deleteZone(array $actor, int $id): void
    {
        $z = self::zone($actor, $id);
        Db::pdo()->prepare('DELETE FROM dns_records WHERE zone_id = ?')->execute([$z['id']]);
        Db::pdo()->prepare('DELETE FROM dns_zones WHERE id = ?')->execute([$z['id']]);
        @unlink(self::dir() . '/db.' . $z['domain']);
        self::conf();
        Exec::run(['rndc', 'reload']);
        Audit::log((int) $actor['id'], 'dns.zone_deleted', ['domain' => $z['domain']]);
    }

    private static function dir(): string
    {
        return Config::ensure(Config::etc() . '/bind/zones');
    }

    /** The zone file text of a zone. */
    public static function render(array $z): string
    {
        $o = "\$TTL 3600\n@ IN SOA ns1.{$z['domain']}. hostmaster.{$z['domain']}. ({$z['serial']} 7200 3600 1209600 3600)\n";
        foreach (self::records((int) $z['id']) as $r) {
            $v = $r['value'];
            $line = match ($r['type']) {
                'MX' => $r['prio'] . ' ' . rtrim($v, '.') . '.',
                'SRV' => $r['prio'] . ' ' . $v,
                'TXT' => '"' . $v . '"',
                'CNAME', 'NS' => rtrim($v, '.') . '.',
                default => $v,
            };
            $o .= sprintf("%s %d IN %s %s\n", $r['name'], $r['ttl'], $r['type'], $line);
        }

        return $o;
    }

    public static function publish(int $zoneId): void
    {
        $st = Db::pdo()->prepare('SELECT * FROM dns_zones WHERE id = ?');
        $st->execute([$zoneId]);
        $z = $st->fetch();
        $serial = max((int) $z['serial'] + 1, (int) date('Ymd') * 100);
        Db::pdo()->prepare('UPDATE dns_zones SET serial = ? WHERE id = ?')->execute([$serial, $zoneId]);
        $z['serial'] = $serial;
        $file = self::dir() . '/db.' . $z['domain'];
        Exec::write($file, self::render($z), 0644);
        if (!Exec::ok(['named-checkzone', $z['domain'], $file])) { // a zone that does not pass the check is never loaded
            throw new PanelError('The zone did not pass the DNS check; nothing was changed on the server.', 500);
        }
        self::conf();
        Exec::run(['rndc', 'reload', $z['domain']]);
    }

    public static function conf(): void
    {
        $o = '';
        $sec = json_decode((string) Db::setting('dns_secondaries', '[]'), true) ?: []; // DNS cluster: these servers copy every zone
        $cluster = $sec ? ' allow-transfer { ' . implode('; ', $sec) . '; }; also-notify { ' . implode('; ', $sec) . '; }; notify yes;' : '';
        foreach (Db::pdo()->query('SELECT domain FROM dns_zones ORDER BY domain')->fetchAll() as $z) {
            $o .= 'zone "' . $z['domain'] . '" { type master; file "' . self::dir() . '/db.' . $z['domain'] . "\";$cluster" . DnsMailPlus::zoneOptions($z['domain']) . " };\n";
        }
        Exec::write(Config::etc() . '/bind/named.conf.zarspanel', $o, 0644);
    }
}
