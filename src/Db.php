<?php

declare(strict_types=1);

namespace ZarsPanel;

use PDO;

/** The panel's own SQLite database (users, sessions, tokens, sites, backups, audit log). Created on first use. */
final class Db
{
    private static ?PDO $pdo = null;
    private static ?string $path = null;

    public static function pdo(): PDO
    {
        $path = Config::ensure(Config::home()) . '/panel.db';
        if (self::$pdo === null || self::$path !== $path) {
            self::$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            self::$pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
            self::$path = $path;
            self::migrate(self::$pdo);
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$path = null;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, email TEXT, password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'client',
    parent_id INTEGER, totp_secret TEXT, active INTEGER NOT NULL DEFAULT 1, quota TEXT NOT NULL DEFAULT '{}', brand TEXT NOT NULL DEFAULT '{}',
    failed INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, csrf TEXT NOT NULL, ip TEXT, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS api_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, hash TEXT NOT NULL UNIQUE, last_used INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, domain TEXT NOT NULL UNIQUE, aliases TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'php',
    php TEXT, root TEXT NOT NULL, proxy_to TEXT, redirect_to TEXT, rewrite TEXT, ssl INTEGER NOT NULL DEFAULT 0, force_https INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1, app TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS dbs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL UNIQUE, db_user TEXT NOT NULL, site_id INTEGER, created_at TEXT NOT NULL, engine TEXT NOT NULL DEFAULT 'mysql');
CREATE TABLE IF NOT EXISTS crons (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, kind TEXT NOT NULL, schedule TEXT NOT NULL, target TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, last_run TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS backups (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL, subject TEXT NOT NULL, file TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, remote TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, detail TEXT, ip TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS dns_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, domain TEXT NOT NULL UNIQUE, serial INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS dns_records (id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INTEGER NOT NULL, type TEXT NOT NULL, name TEXT NOT NULL, value TEXT NOT NULL, ttl INTEGER NOT NULL DEFAULT 3600, prio INTEGER);
CREATE TABLE IF NOT EXISTS ftp_users (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, site_id INTEGER NOT NULL, name TEXT NOT NULL UNIQUE, dir TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS redis_inst (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL UNIQUE, port INTEGER NOT NULL, maxmem_mb INTEGER NOT NULL DEFAULT 128, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS mail_domains (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, domain TEXT NOT NULL UNIQUE, dkim TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS mailboxes (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER NOT NULL, local TEXT NOT NULL, hash TEXT NOT NULL, quota_mb INTEGER NOT NULL DEFAULT 1024);
CREATE TABLE IF NOT EXISTS mail_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER NOT NULL, local TEXT NOT NULL, target TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL);
SQL);
        // older installs: the engine column (mysql | pgsql) of databases
        $cols = array_column($db->query('PRAGMA table_info(dbs)')->fetchAll(), 'name');
        if (!in_array('engine', $cols, true)) {
            $db->exec("ALTER TABLE dbs ADD COLUMN engine TEXT NOT NULL DEFAULT 'mysql'");
        }
        if (!in_array('remote_hosts', $cols, true)) {
            $db->exec("ALTER TABLE dbs ADD COLUMN remote_hosts TEXT NOT NULL DEFAULT ''");
        }
        foreach (['sites' => ['extra' => "TEXT NOT NULL DEFAULT '{}'", 'sys_user' => "TEXT NOT NULL DEFAULT ''"], 'users' => ['plan_id' => 'INTEGER', 'language' => "TEXT NOT NULL DEFAULT 'en'", 'demo' => 'INTEGER NOT NULL DEFAULT 0', 'sys_user' => "TEXT NOT NULL DEFAULT ''"], 'mailboxes' => ['autoreply' => 'TEXT']] as $table => $add) {
            $have = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            foreach ($add as $col => $def) {
                if (!in_array($col, $have, true)) {
                    $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
                }
            }
        }
        // 0.11.0: features of the other control panels (Plesk, DirectAdmin, CyberPanel, HestiaCP, aaPanel, CloudPanel, ISPConfig, Virtualmin)
        $have = array_column($db->query('PRAGMA table_info(sessions)')->fetchAll(), 'name');
        if (!in_array('imp_by', $have, true)) {
            $db->exec('ALTER TABLE sessions ADD COLUMN imp_by INTEGER');
        }
        $db->exec('CREATE TABLE IF NOT EXISTS metrics (t INTEGER PRIMARY KEY, cpu REAL, mem REAL, disk REAL, load1 REAL, rx INTEGER, tx INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS uptime (site_id INTEGER PRIMARY KEY, up INTEGER, code INTEGER, ms INTEGER, checked_at INTEGER, down_since INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS uptime_log (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, up INTEGER NOT NULL, code INTEGER, t INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS shares (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, user_id INTEGER NOT NULL, path TEXT NOT NULL, hash TEXT NOT NULL UNIQUE, expires_at INTEGER NOT NULL, downloads INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS workers (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, name TEXT NOT NULL, command TEXT NOT NULL, procs INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS plans (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INTEGER NOT NULL, name TEXT NOT NULL, quota TEXT NOT NULL DEFAULT \'{}\', created_at TEXT NOT NULL)');
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $st = self::pdo()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);

        return ($v = $st->fetchColumn()) === false ? $default : (string) $v;
    }

    public static function put(string $key, string $value): void
    {
        self::pdo()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$key, $value]);
    }
}
