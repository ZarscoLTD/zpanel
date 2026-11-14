<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Who did what (never the secrets themselves). */
final class Audit
{
    public static function log(?int $userId, string $action, array $detail = []): void
    {
        unset($detail['password'], $detail['token'], $detail['secret'], $detail['code']);
        Db::pdo()->prepare('INSERT INTO audit (user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $action, $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null, $_SERVER['REMOTE_ADDR'] ?? null, gmdate('c')]);
    }

    /**
     * The activity log as CSV (owner 2026-11-13): the owner gets everything, a reseller their own and their clients' entries, a client
     * their own; up to 100,000 rows, newest first. Cells that Excel would run as a formula are kept as text.
     */
    public static function csv(array $u): string
    {
        $sql = 'SELECT a.*, u.name AS user FROM audit a LEFT JOIN users u ON u.id = a.user_id';
        $args = [];
        if ($u['role'] === 'reseller') {
            $sql .= ' WHERE a.user_id = ? OR a.user_id IN (SELECT id FROM users WHERE parent_id = ?)';
            $args = [$u['id'], $u['id']];
        } elseif ($u['role'] !== 'owner') {
            $sql .= ' WHERE a.user_id = ?';
            $args = [$u['id']];
        }
        $st = Db::pdo()->prepare($sql . ' ORDER BY a.id DESC LIMIT 100000');
        $st->execute($args);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date and time (UTC)', 'User', 'Action', 'Details', 'IP address'], ',', '"', '');
        while ($r = $st->fetch()) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, [$r['created_at'], $r['user'], $r['action'], $r['detail'], $r['ip']]), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public static function recent(int $limit = 100, ?int $userId = null): array
    {
        $st = Db::pdo()->prepare('SELECT a.*, u.name AS user FROM audit a LEFT JOIN users u ON u.id = a.user_id ' . ($userId ? 'WHERE a.user_id = :u ' : '') . 'ORDER BY a.id DESC LIMIT ' . (int) $limit);
        $userId ? $st->execute([':u' => $userId]) : $st->execute();

        return $st->fetchAll();
    }
}
