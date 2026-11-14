<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Hosting plans: named sets of limits (sites, databases, jobs, backups) that the owner or a reseller gives to clients in one step. */
final class Plans
{
    public static function list(array $actor): array
    {
        $st = $actor['role'] === 'owner' ? Db::pdo()->query('SELECT * FROM plans ORDER BY name') : Db::pdo()->prepare('SELECT * FROM plans WHERE owner_id = ? ORDER BY name');
        if ($actor['role'] !== 'owner') {
            $st->execute([$actor['id']]);
        }

        return array_map(fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'quota' => json_decode((string) $p['quota'], true) ?: [], 'users' => self::count((int) $p['id'])], $st->fetchAll());
    }

    private static function count(int $id): int
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM users WHERE plan_id = ?');
        $st->execute([$id]);

        return (int) $st->fetchColumn();
    }

    public static function mine(array $actor, int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM plans WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch() ?: throw new PanelError('Plan not found.', 404);
        if ($actor['role'] !== 'owner' && (int) $p['owner_id'] !== (int) $actor['id']) {
            throw new PanelError('Plan not found.', 404);
        }

        return $p;
    }

    public static function save(array $actor, array $in, ?int $id = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new PanelError('Give the plan a name (up to 60 characters).', 422);
        }
        $quota = json_encode(Quota::capFor($actor, (array) ($in['quota'] ?? [])));
        if ($id) {
            self::mine($actor, $id);
            Db::pdo()->prepare('UPDATE plans SET name = ?, quota = ? WHERE id = ?')->execute([$name, $quota, $id]);
            Db::pdo()->prepare('UPDATE users SET quota = ? WHERE plan_id = ?')->execute([$quota, $id]); // clients on the plan follow it
        } else {
            Db::pdo()->prepare('INSERT INTO plans (owner_id, name, quota, created_at) VALUES (?, ?, ?, ?)')->execute([$actor['id'], $name, $quota, gmdate('c')]);
            $id = (int) Db::pdo()->lastInsertId();
        }
        Audit::log((int) $actor['id'], 'plan.saved', ['plan' => $name]);

        return ['plans' => self::list($actor)];
    }

    public static function delete(array $actor, int $id): void
    {
        $p = self::mine($actor, $id);
        if (self::count($id) > 0) {
            throw new PanelError('Move the users on this plan to another plan first.', 422);
        }
        Db::pdo()->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'plan.deleted', ['plan' => $p['name']]);
    }

    /** Put a client on a plan: their limits become the plan's. */
    public static function assign(array $actor, int $userId, ?int $planId): void
    {
        $t = Auth::user($userId) ?? throw new PanelError('User not found.', 404);
        if (!Auth::manages($actor, $t) || (int) $t['id'] === (int) $actor['id']) {
            throw new PanelError('You cannot change this user.', 403);
        }
        if ($planId) {
            $p = self::mine($actor, $planId);
            Db::pdo()->prepare('UPDATE users SET plan_id = ?, quota = ? WHERE id = ?')->execute([$planId, $p['quota'], $userId]);
        } else {
            Db::pdo()->prepare('UPDATE users SET plan_id = NULL WHERE id = ?')->execute([$userId]);
        }
        Audit::log((int) $actor['id'], 'plan.assigned', ['user' => $t['name'], 'plan' => $planId]);
    }
}
