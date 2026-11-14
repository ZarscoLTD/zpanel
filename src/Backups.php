<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Backups of sites (tar.gz of the folder) and databases (gzip dump): local copies, optional upload to S3-compatible storage, retention and restore. */
final class Backups
{
    public static function dir(): string
    {
        return Config::ensure(Db::setting('backup_dir') ?: Config::home() . '/backups');
    }

    public static function keep(): int
    {
        return max(1, min(60, (int) (Db::setting('backup.keep') ?: 7)));
    }

    public static function list(array $actor): array
    {
        if ($actor['role'] === 'owner') {
            return Db::pdo()->query('SELECT b.*, u.name AS owner FROM backups b JOIN users u ON u.id = b.user_id ORDER BY b.id DESC LIMIT 300')->fetchAll();
        }
        $st = Db::pdo()->prepare('SELECT b.*, u.name AS owner FROM backups b JOIN users u ON u.id = b.user_id WHERE b.user_id = :a OR b.user_id IN (SELECT id FROM users WHERE parent_id = :a) ORDER BY b.id DESC LIMIT 300');
        $st->execute([':a' => $actor['id']]);

        return $st->fetchAll();
    }

    public static function mine(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM backups WHERE id = ?');
        $st->execute([$id]);
        $b = $st->fetch() ?: throw new PanelError('Backup not found.', 404);
        $owner = Auth::user((int) $b['user_id']);
        if (!$owner || !Auth::manages($actor, $owner)) {
            throw new PanelError('Backup not found.', 404);
        }

        return $b;
    }

    private static function record(array $actor, string $kind, string $subject, string $file, bool $upload): array
    {
        Quota::check($actor, 'backups');
        $remote = null;
        if ($upload && ($c = S3::config())) {
            S3::put($c, $file, basename($file));
            $remote = 's3://' . $c['bucket'] . '/' . trim(($c['prefix'] ?? '') . '/' . basename($file), '/');
        }
        if ($upload && PanelPlus::rcloneCopy($file)) { // remote storage of the other panels (Google Drive, Dropbox, SFTP ...)
            $remote = ($remote ? $remote . ' + ' : '') . 'rclone:' . (PanelPlus::rclone()['remote']['folder'] ?? '');
        }
        Db::pdo()->prepare('INSERT INTO backups (user_id, kind, subject, file, size, remote, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$actor['id'], $kind, $subject, $file, (int) @filesize($file), $remote, gmdate('c')]);
        $id = (int) Db::pdo()->lastInsertId();
        // keep the newest N per subject
        $st = Db::pdo()->prepare('SELECT id, file FROM backups WHERE kind = ? AND subject = ? ORDER BY id DESC');
        $st->execute([$kind, $subject]);
        foreach (array_slice($st->fetchAll(), self::keep()) as $old) {
            @unlink($old['file']);
            Db::pdo()->prepare('DELETE FROM backups WHERE id = ?')->execute([$old['id']]);
        }
        Audit::log((int) $actor['id'], 'backup.' . $kind, ['subject' => $subject]);

        return Db::pdo()->query('SELECT * FROM backups WHERE id = ' . $id)->fetch();
    }

    public static function site(array $actor, int $siteId, bool $upload = true): array
    {
        $s = Sites::mine($actor, $siteId);
        $dir = Config::www() . '/' . $s['domain'];
        if (!is_dir($dir)) {
            throw new PanelError('This site has no files to back up.', 422);
        }
        $file = self::dir() . '/site-' . $s['domain'] . '-' . gmdate('Ymd-His') . '.tar.gz';
        $r = Exec::run(['tar', '-czf', $file, '-C', Config::www(), $s['domain']], null, 1800);
        Config::dry() && !is_file($file) && touch($file); // a dry run records the command; the file stands in for the archive
        if ($r['code'] !== 0) {
            throw new PanelError('The backup could not be made: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }

        return self::record($actor, 'site', $s['domain'], $file, $upload);
    }

    public static function database(array $actor, int $dbId, bool $upload = true): array
    {
        $d = Databases::mine($actor, $dbId);
        $file = self::dir() . '/db-' . $d['name'] . '-' . gmdate('Ymd-His') . '.sql.gz';
        Databases::dumpTo($d, $file);

        return self::record($actor, 'db', $d['name'], $file, $upload);
    }

    public static function restore(array $actor, int $id): void
    {
        $b = self::mine($actor, $id);
        if (!is_file($b['file'])) {
            throw new PanelError('The backup file is no longer on this server.', 404);
        }
        if ($b['kind'] === 'site') {
            $site = Db::pdo()->prepare('SELECT * FROM sites WHERE domain = ?');
            $site->execute([$b['subject']]);
            Sites::mine($actor, (int) ($site->fetch()['id'] ?? 0)); // the actor must still manage that site
            $r = Exec::run(['tar', '-xzf', $b['file'], '-C', Config::www()], null, 1800);
        } else {
            $d = Db::pdo()->prepare('SELECT * FROM dbs WHERE name = ?');
            $d->execute([$b['subject']]);
            $row = $d->fetch() ?: throw new PanelError('The database no longer exists. Create it again first.', 422);
            Databases::mine($actor, (int) $row['id']);
            $r = Exec::run(['sh', '-c', 'gunzip -c ' . escapeshellarg($b['file']) . ' | mysql ' . escapeshellarg($b['subject'])], null, 1800);
        }
        if ($r['code'] !== 0) {
            throw new PanelError('The restore stopped: ' . mb_substr(trim($r['out']), 0, 200), 500);
        }
        Audit::log((int) $actor['id'], 'backup.restore', ['subject' => $b['subject']]);
    }

    public static function delete(array $actor, int $id): void
    {
        $b = self::mine($actor, $id);
        @unlink($b['file']);
        Db::pdo()->prepare('DELETE FROM backups WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'backup.delete', ['subject' => $b['subject']]);
    }
}
