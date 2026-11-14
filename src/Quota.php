<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Limits of a client set by the owner or their reseller: {"sites": 5, "databases": 5, "crons": 10, "backups": 20}. Empty = no limit. */
final class Quota
{
    private const TABLES = ['sites' => 'sites', 'databases' => 'dbs', 'crons' => 'crons', 'backups' => 'backups'];

    public static function check(array $user, string $what): void
    {
        $q = json_decode((string) $user['quota'], true) ?: [];
        if (!isset($q[$what]) || $q[$what] === '' || $q[$what] === null) {
            return;
        }
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM ' . self::TABLES[$what] . ' WHERE user_id = ?');
        $st->execute([$user['id']]);
        if ((int) $st->fetchColumn() >= (int) $q[$what]) {
            throw new PanelError("The limit of {$q[$what]} $what is reached. Ask your provider for more.", 403);
        }
    }

    public static function usage(array $user): array
    {
        $out = [];
        foreach (self::TABLES as $k => $t) {
            $st = Db::pdo()->prepare("SELECT COUNT(*) FROM $t WHERE user_id = ?");
            $st->execute([$user['id']]);
            $out[$k] = (int) $st->fetchColumn();
        }

        return $out;
    }

    /**
     * Feature sets of a hosting plan (Webuzo feature sets / ACL, cPanel feature lists): what a client of the plan may use.
     * key => [the requests that belong to it (method, path pattern)]. Missing from "off" = allowed.
     */
    public const FEATURES = [
        'add_domains' => [['POST', '#^/api/sites$#']],
        'email' => [['*', '#^/api/mail#']],
        'dns' => [['*', '#^/api/dns#'], ['*', '#^/api/plus/cloudflare#']],
        'databases' => [['*', '#^/api/databases#']],
        'ftp' => [['*', '#^/api/ftp#']],
        'cron' => [['*', '#^/api/cron#']],
        'apps' => [['*', '#^/api/apps#'], ['*', '#^/api/sites/\\d+/app#']],
        'backups' => [['*', '#^/api/backups#']],
        'redis' => [['*', '#^/api/redis#']],
        'files' => [['*', '#^/api/sites/\\d+/(files?|upload|trash|shares)#']],
        'ssl' => [['*', '#^/api/sites/\\d+/ssl#']],
        'git' => [['*', '#^/api/sites/\\d+/git#']],
        'wordpress' => [['*', '#^/api/sites/\\d+/wordpress#']],
        'developer' => [['*', '#^/api/sites/\\d+/(artisan|scheduler|laravel|workers)#']],
        'staging' => [['*', '#^/api/sites/\\d+/(staging|push-live)#']],
        'migrate' => [['*', '#^/api/plus/migrate#'], ['*', '#^/api/import#']],
        'uptime' => [['*', '#^/api/plus/uptime#']],
        'statistics' => [['*', '#^/api/sites/\\d+/(stats|logs)#']],
    ];

    /** The feature a request needs when the user's plan switched it off (null = allowed). The owner is never limited. */
    public static function blocked(array $user, string $method, string $path): ?string
    {
        if ($user['role'] === 'owner') {
            return null;
        }
        $off = (json_decode((string) $user['quota'], true) ?: [])['off'] ?? [];
        foreach ($off as $key) {
            foreach (self::FEATURES[$key] ?? [] as [$m, $re]) {
                if (($m === '*' || $m === $method) && preg_match($re, $path)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function clean(array $in): array
    {
        $out = [];
        if (!empty($in['off']) && is_array($in['off'])) {
            $out['off'] = array_values(array_intersect(array_keys(self::FEATURES), array_map('strval', $in['off'])));
        }
        foreach ([...array_keys(self::TABLES), 'bandwidth_gb', 'mail_per_hour', 'disk_mb', 'accounts', 'inodes'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k]) && (int) $in[$k] >= 0) {
                $out[$k] = (int) $in[$k];
            }
        }

        return $out;
    }

    /**
     * No overselling (WHM reseller limits): a reseller can give a client / put in a plan at most its own limit for each value it has
     * a limit for; "no limit" there becomes the reseller's limit. The owner is never limited.
     */
    public static function capFor(array $actor, array $q): array
    {
        $q = self::clean($q);
        if ($actor['role'] !== 'reseller') {
            return $q;
        }
        $mine = json_decode((string) ($actor['quota'] ?? '{}'), true) ?: [];
        foreach ($mine as $k => $limit) {
            if ($k === 'off' || $k === 'accounts' || !is_numeric($limit)) {
                continue;
            }
            if (!isset($q[$k])) {
                $q[$k] = (int) $limit;
            } elseif ($q[$k] > (int) $limit) {
                throw new PanelError("Your reseller limit for $k is $limit - give at most that.", 422);
            }
        }
        unset($q['accounts']);

        return $q;
    }

    /** WHM "Limit the number of accounts": a reseller with an "accounts" limit cannot make more clients. */
    public static function checkAccounts(array $actor): void
    {
        if ($actor['role'] !== 'reseller') {
            return;
        }
        $max = (json_decode((string) ($actor['quota'] ?? '{}'), true) ?: [])['accounts'] ?? null;
        if ($max === null) {
            return;
        }
        $st = Db::pdo()->prepare("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'client'");
        $st->execute([$actor['id']]);
        if ((int) $st->fetchColumn() >= (int) $max) {
            throw new PanelError("Your reseller limit is $max accounts - ask the server owner for more.", 422);
        }
    }
}

