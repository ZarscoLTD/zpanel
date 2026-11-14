<?php

declare(strict_types=1);

namespace ZarsPanel;

/** FTP / FTPS users (Pure-FTPd virtual users), each locked into one site's folder. The password is shown once and never stored. */
final class Ftp
{
    public static function validName(string $n): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_.-]{2,31}$/', $n);
    }

    public static function list(array $actor): array
    {
        if ($actor['role'] === 'owner') {
            return Db::pdo()->query('SELECT f.*, s.domain FROM ftp_users f JOIN sites s ON s.id = f.site_id ORDER BY f.name')->fetchAll();
        }
        $st = Db::pdo()->prepare('SELECT f.*, s.domain FROM ftp_users f JOIN sites s ON s.id = f.site_id WHERE f.user_id = :a OR f.user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY f.name');
        $st->execute([':a' => $actor['id']]);

        return $st->fetchAll();
    }

    public static function create(array $actor, array $in): array
    {
        $site = Sites::mine($actor, (int) ($in['site_id'] ?? 0));
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        if (!self::validName($name)) {
            throw new PanelError('The user name needs 3 to 32 letters, numbers, . - or _ and must start with a letter.', 422);
        }
        $ex = Db::pdo()->prepare('SELECT 1 FROM ftp_users WHERE name = ?');
        $ex->execute([$name]);
        if ($ex->fetchColumn()) {
            throw new PanelError('This FTP user name is taken.', 409);
        }
        $sub = trim((string) ($in['folder'] ?? ''), '/');
        if ($sub !== '' && (str_contains($sub, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $sub))) {
            throw new PanelError('The folder must stay inside the site.', 422);
        }
        $dir = Config::www() . '/' . $site['domain'] . ($sub !== '' ? '/' . $sub : '');
        $password = bin2hex(random_bytes(9));
        $u = SysUser::of($site);
        $r = Exec::run(['pure-pw', 'useradd', $name, '-u', $u, '-g', $u, '-d', $dir, '-m'], $password . "\n" . $password . "\n");
        if ($r['code'] !== 0) {
            throw new PanelError('The FTP server refused the user: ' . mb_substr(trim($r['out']), 0, 160), 500);
        }
        Db::pdo()->prepare('INSERT INTO ftp_users (user_id, site_id, name, dir, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$site['user_id'], $site['id'], $name, $dir, gmdate('c')]);
        Audit::log((int) $actor['id'], 'ftp.created', ['user' => $name, 'site' => $site['domain']]);

        return ['name' => $name, 'dir' => $dir, 'password' => $password, 'note' => 'Shown once: save it now.'];
    }

    /** After a site moved to its account's own user: its FTP accounts write files as that user. */
    public static function reown(array $site): void
    {
        $u = SysUser::of($site);
        $st = Db::pdo()->prepare('SELECT name FROM ftp_users WHERE site_id = ?');
        $st->execute([$site['id']]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            Exec::run(['pure-pw', 'usermod', $name, '-u', $u, '-g', $u, '-m']);
        }
    }

    public static function mine(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM ftp_users WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch() ?: throw new PanelError('FTP user not found.', 404);
        $owner = Auth::user((int) $f['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('FTP user not found.', 404);
        }

        return $f;
    }

    public static function resetPassword(array $actor, int $id): array
    {
        $f = self::mine($actor, $id);
        $password = bin2hex(random_bytes(9));
        Exec::run(['pure-pw', 'passwd', $f['name'], '-m'], $password . "\n" . $password . "\n");
        Audit::log((int) $actor['id'], 'ftp.password_reset', ['user' => $f['name']]);

        return ['name' => $f['name'], 'password' => $password];
    }

    public static function delete(array $actor, int $id): void
    {
        $f = self::mine($actor, $id);
        Exec::run(['pure-pw', 'userdel', $f['name'], '-m']);
        Db::pdo()->prepare('DELETE FROM ftp_users WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'ftp.deleted', ['user' => $f['name']]);
    }
}
