<?php

declare(strict_types=1);

namespace ZarsPanel;

/** MariaDB / MySQL databases with their own user. The password is shown once at creation (or reset) and never stored. */
final class Databases
{
    public static function validName(string $n): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{2,31}$/', $n);
    }

    private static function sql(string $sql, string $engine = 'mysql'): void
    {
        $r = $engine === 'pgsql' ? Exec::run(['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1', '-q'], $sql) : Exec::run(['mysql', '--batch', '--skip-column-names'], $sql);
        if ($r['code'] !== 0) {
            throw new PanelError('The database server said: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM dbs WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public static function mine(array $actor, int $id): array
    {
        $d = self::find($id) ?? throw new PanelError('Database not found.', 404);
        $owner = Auth::user((int) $d['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Database not found.', 404);
        }

        return $d;
    }

    public static function list(array $actor): array
    {
        if ($actor['role'] === 'owner') {
            return Db::pdo()->query('SELECT d.*, u.name AS owner FROM dbs d JOIN users u ON u.id = d.user_id ORDER BY d.name')->fetchAll();
        }
        $st = Db::pdo()->prepare('SELECT d.*, u.name AS owner FROM dbs d JOIN users u ON u.id = d.user_id WHERE d.user_id = :a OR d.user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY d.name');
        $st->execute([':a' => $actor['id']]);

        return $st->fetchAll();
    }

    /** @return array{database: array, password: string} */
    public static function create(array $actor, array $in): array
    {
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        if (!self::validName($name)) {
            throw new PanelError('The database name needs 3 to 32 letters, digits or _ and must start with a letter.', 422);
        }
        $ownerId = (int) ($in['user_id'] ?? $actor['id']);
        $owner = Auth::user($ownerId) ?? throw new PanelError('Unknown owner.', 422);
        if (!Auth::manages($actor, $owner)) {
            throw new PanelError('You cannot add databases for this user.', 403);
        }
        Quota::check($owner, 'databases');
        $st = Db::pdo()->prepare('SELECT 1 FROM dbs WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetchColumn()) {
            throw new PanelError('A database with this name exists.', 422);
        }
        $user = $name; // the database user has the database's name
        $pw = Secrets::password();
        $engine = ($in['engine'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql';
        if ($engine === 'pgsql') { // PostgreSQL: a login role and a database it owns (the name is checked above, the password has only letters and digits)
            self::sql("CREATE ROLE \"$user\" LOGIN PASSWORD '$pw';\nCREATE DATABASE \"$name\" OWNER \"$user\" ENCODING 'UTF8' TEMPLATE template0;", 'pgsql');
            Db::pdo()->prepare('INSERT INTO dbs (user_id, name, db_user, site_id, created_at, engine) VALUES (?, ?, ?, ?, ?, ?)')->execute([$ownerId, $name, $user, $in['site_id'] ?? null, gmdate('c'), 'pgsql']);
            $id = (int) Db::pdo()->lastInsertId();
            Audit::log((int) $actor['id'], 'db.create', ['name' => $name, 'engine' => 'pgsql']);

            return ['database' => self::find($id), 'password' => $pw];
        }
        self::sql("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER '$user'@'localhost' IDENTIFIED BY '$pw';\nGRANT ALL PRIVILEGES ON `$name`.* TO '$user'@'localhost';\nFLUSH PRIVILEGES;");
        Db::pdo()->prepare('INSERT INTO dbs (user_id, name, db_user, site_id, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ownerId, $name, $user, $in['site_id'] ?? null, gmdate('c')]);
        $id = (int) Db::pdo()->lastInsertId();
        Audit::log((int) $actor['id'], 'db.create', ['name' => $name]);

        return ['database' => self::find($id), 'password' => $pw];
    }

    public static function resetPassword(array $actor, int $id): string
    {
        $d = self::mine($actor, $id);
        $pw = Secrets::password();
        if (($d['engine'] ?? 'mysql') === 'pgsql') {
            self::sql("ALTER ROLE \"{$d['db_user']}\" PASSWORD '$pw';", 'pgsql');
        } else {
            self::sql("ALTER USER '{$d['db_user']}'@'localhost' IDENTIFIED BY '$pw';\nFLUSH PRIVILEGES;");
        }
        Audit::log((int) $actor['id'], 'db.password', ['name' => $d['name']]);

        return $pw;
    }

    public static function delete(array $actor, int $id): void
    {
        $d = self::mine($actor, $id);
        if (($d['engine'] ?? 'mysql') === 'pgsql') {
            self::sql("DROP DATABASE IF EXISTS \"{$d['name']}\";\nDROP ROLE IF EXISTS \"{$d['db_user']}\";", 'pgsql');
        } else {
            self::sql("DROP DATABASE IF EXISTS `{$d['name']}`;\nDROP USER IF EXISTS '{$d['db_user']}'@'localhost';\nFLUSH PRIVILEGES;");
        }
        Db::pdo()->prepare('DELETE FROM dbs WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'db.delete', ['name' => $d['name']]);
    }

    /** Import an .sql file from a site's folder (the file is read here and given to the mysql client). */
    public static function import(array $actor, int $id, string $siteRoot, string $rel): void
    {
        $d = self::mine($actor, $id);
        $p = Files::resolve($siteRoot, $rel);
        if (!is_file($p) || !preg_match('/\.sql$/i', $p)) {
            throw new PanelError('Choose an .sql file inside the site folder.', 422);
        }
        if (filesize($p) > 200 * 1048576) {
            throw new PanelError('This file is over 200 MB, import it from the command line.', 422);
        }
        $r = ($d['engine'] ?? 'mysql') === 'pgsql'
            ? Exec::run(['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1', '-q', '-d', $d['name']], (string) file_get_contents($p), 600)
            : Exec::run(['mysql', $d['name']], (string) file_get_contents($p), 600);
        if ($r['code'] !== 0) {
            throw new PanelError('The import stopped: ' . mb_substr(trim($r['out']), 0, 200), 422);
        }
        Audit::log((int) $actor['id'], 'db.import', ['name' => $d['name']]);
    }

    public static function dumpTo(array $d, string $file): void
    {
        $dump = ($d['engine'] ?? 'mysql') === 'pgsql' ? 'runuser -u postgres -- pg_dump ' . escapeshellarg($d['name']) : 'mysqldump --single-transaction --quick ' . escapeshellarg($d['name']);
        $r = Exec::run(['sh', '-c', $dump . ' | gzip > ' . escapeshellarg($file)], null, 900);
        Config::dry() && !is_file($file) && touch($file);
        if ($r['code'] !== 0) {
            throw new PanelError('The database dump failed: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
    }
}
