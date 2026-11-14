<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Server mail (Postfix + Dovecot, virtual domains): domains, mailboxes (hashed passwords, quota) and forwards. The panel writes the
 * Postfix maps and the Dovecot password file, then reloads both; DKIM keys are made per domain with opendkim-genkey.
 */
final class Mail
{
    /** Where the mail of the mailboxes is stored (Dovecot mail_location). */
    public static function vmail(): string
    {
        return rtrim(getenv('ZARSPANEL_VMAIL') ?: '/var/vmail', '/');
    }

    private static function dir(): string
    {
        return Config::ensure(Config::etc() . '/zarspanel-mail');
    }

    public static function domain(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM mail_domains WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch() ?: throw new PanelError('Mail domain not found.', 404);
        $owner = Auth::user((int) $d['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Mail domain not found.', 404);
        }

        return $d;
    }

    public static function list(array $actor): array
    {
        $rows = $actor['role'] === 'owner' ? Db::pdo()->query('SELECT * FROM mail_domains ORDER BY domain')->fetchAll() : (function () use ($actor) {
            $st = Db::pdo()->prepare('SELECT * FROM mail_domains WHERE user_id = :a OR user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY domain');
            $st->execute([':a' => $actor['id']]);

            return $st->fetchAll();
        })();

        return array_map(function ($d) {
            $b = Db::pdo()->prepare('SELECT id, local, quota_mb, autoreply FROM mailboxes WHERE domain_id = ? ORDER BY local');
            $b->execute([$d['id']]);
            $a = Db::pdo()->prepare('SELECT id, local, target FROM mail_aliases WHERE domain_id = ? ORDER BY local');
            $a->execute([$d['id']]);

            return $d + ['mailboxes' => $b->fetchAll(), 'aliases' => $a->fetchAll(), 'dns' => self::dnsHints($d)];
        }, $rows);
    }

    /** What the customer puts in DNS: MX, SPF, DMARC and the DKIM key once it exists. */
    public static function dnsHints(array $d): array
    {
        $host = Db::setting('mail_host') ?: 'mail.' . $d['domain'];
        $h = [['MX', '@', "10 $host."], ['TXT', '@', 'v=spf1 mx ~all'], ['TXT', '_dmarc', 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $d['domain']]];
        if (!empty($d['dkim'])) {
            $h[] = ['TXT', 'zp._domainkey', $d['dkim']];
        }

        return $h;
    }

    public static function addDomain(array $actor, array $in): array
    {
        $dom = strtolower(trim((string) ($in['domain'] ?? '')));
        if (!Dns::validDomain($dom)) {
            throw new PanelError('Enter a domain like example.com.', 422);
        }
        $ex = Db::pdo()->prepare('SELECT 1 FROM mail_domains WHERE domain = ?');
        $ex->execute([$dom]);
        if ($ex->fetchColumn()) {
            throw new PanelError('This mail domain exists already.', 409);
        }
        $keyDir = Config::ensure(Config::etc() . '/opendkim/keys/' . $dom);
        Exec::run(['opendkim-genkey', '-b', '2048', '-d', $dom, '-s', 'zp', '-D', $keyDir]);
        $txt = is_file("$keyDir/zp.txt") ? trim(preg_replace('/\s+|"/', '', preg_replace('/^.*?\(|\).*$/s', '', (string) file_get_contents("$keyDir/zp.txt")))) : null;
        $ownerId = (int) $actor['id'];
        if (!empty($in['user_id']) && ($o = Auth::user((int) $in['user_id'])) && Auth::manages($actor, $o)) {
            $ownerId = (int) $o['id']; // made for a client (import of a cPanel account)
        }
        Db::pdo()->prepare('INSERT INTO mail_domains (user_id, domain, dkim, created_at) VALUES (?, ?, ?, ?)')->execute([$ownerId, $dom, $txt, gmdate('c')]);
        $newId = (int) Db::pdo()->lastInsertId();
        self::publish();
        Audit::log((int) $actor['id'], 'mail.domain_added', ['domain' => $dom]);

        $row = self::domain($actor, $newId);

        return $row + ['mailboxes' => [], 'aliases' => [], 'dns' => self::dnsHints($row)];
    }

    public static function addMailbox(array $actor, int $domainId, array $in): array
    {
        $d = self::domain($actor, $domainId);
        $local = strtolower(trim((string) ($in['local'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $local)) {
            throw new PanelError('The address needs letters, numbers, . - or _ before the @.', 422);
        }
        $ex = Db::pdo()->prepare('SELECT 1 FROM mailboxes WHERE domain_id = ? AND local = ?');
        $ex->execute([$d['id'], $local]);
        $al = Db::pdo()->prepare('SELECT 1 FROM mail_aliases WHERE domain_id = ? AND local = ?');
        $al->execute([$d['id'], $local]);
        if ($ex->fetchColumn() || $al->fetchColumn()) {
            throw new PanelError('This address is taken.', 409);
        }
        $quota = max(50, min(51200, (int) ($in['quota_mb'] ?? 1024)));
        $password = (string) ($in['password'] ?? '') ?: bin2hex(random_bytes(8));
        if (strlen($password) < 8) {
            throw new PanelError('The password needs at least 8 characters.', 422);
        }
        $hash = trim(Exec::run(['doveadm', 'pw', '-s', 'SHA512-CRYPT', '-p', $password])['out']) ?: '{SHA512-CRYPT}dry-run';
        Db::pdo()->prepare('INSERT INTO mailboxes (domain_id, local, hash, quota_mb) VALUES (?, ?, ?, ?)')->execute([$d['id'], $local, $hash, $quota]);
        self::publish();
        Audit::log((int) $actor['id'], 'mail.mailbox_added', ['address' => "$local@{$d['domain']}"]);

        return ['address' => "$local@{$d['domain']}", 'password' => $password];
    }

    public static function addAlias(array $actor, int $domainId, array $in): array
    {
        $d = self::domain($actor, $domainId);
        $local = strtolower(trim((string) ($in['local'] ?? '')));
        $target = strtolower(trim((string) ($in['target'] ?? '')));
        if (!preg_match('/^([a-z0-9][a-z0-9._-]{0,63}|\*)$/', $local) || !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('Enter the forwarding address and the address it goes to.', 422);
        }
        Db::pdo()->prepare('INSERT INTO mail_aliases (domain_id, local, target) VALUES (?, ?, ?)')->execute([$d['id'], $local, $target]);
        self::publish();

        return ['ok' => true];
    }

    public static function removeMailbox(array $actor, int $domainId, int $id): void
    {
        $d = self::domain($actor, $domainId);
        Db::pdo()->prepare('DELETE FROM mailboxes WHERE id = ? AND domain_id = ?')->execute([$id, $d['id']]);
        self::publish();
    }

    public static function removeDomain(array $actor, int $id): void
    {
        $d = self::domain($actor, $id);
        Db::pdo()->prepare('DELETE FROM mailboxes WHERE domain_id = ?')->execute([$d['id']]);
        Db::pdo()->prepare('DELETE FROM mail_aliases WHERE domain_id = ?')->execute([$d['id']]);
        Db::pdo()->prepare('DELETE FROM mail_domains WHERE id = ?')->execute([$d['id']]);
        self::publish();
        Audit::log((int) $actor['id'], 'mail.domain_removed', ['domain' => $d['domain']]);
    }

    /** Write the Postfix maps and the Dovecot password file from the database, then reload both. */
    public static function publish(): void
    {
        $dir = self::dir();
        $domains = $mbox = $alias = $pass = '';
        foreach (Db::pdo()->query('SELECT * FROM mail_domains ORDER BY domain')->fetchAll() as $d) {
            $domains .= $d['domain'] . " OK\n";
            $b = Db::pdo()->prepare('SELECT * FROM mailboxes WHERE domain_id = ?');
            $b->execute([$d['id']]);
            foreach ($b->fetchAll() as $m) {
                $addr = $m['local'] . '@' . $d['domain'];
                $mbox .= "$addr {$d['domain']}/{$m['local']}/\n";
                $pass .= "$addr:{$m['hash']}::::::userdb_quota_rule=*:storage={$m['quota_mb']}M\n";
            }
            $a = Db::pdo()->prepare('SELECT * FROM mail_aliases WHERE domain_id = ?');
            $a->execute([$d['id']]);
            foreach ($a->fetchAll() as $x) {
                $alias .= ($x['local'] === '*' ? '@' . $d['domain'] : $x['local'] . '@' . $d['domain']) . ' ' . $x['target'] . "\n";
            }
        }
        Exec::write("$dir/virtual_domains", $domains, 0644);
        Exec::write("$dir/vmailbox", $mbox, 0644);
        Exec::write("$dir/virtual_aliases", $alias, 0644);
        Exec::write("$dir/dovecot-users", $pass, 0640);
        foreach (['vmailbox', 'virtual_aliases', 'virtual_domains'] as $f) {
            Exec::run(['postmap', "$dir/$f"]);
        }
        Exec::run(['systemctl', 'reload', 'postfix']);
        Exec::run(['systemctl', 'reload', 'dovecot']);
    }
}
