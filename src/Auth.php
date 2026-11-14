<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Users, login and sessions. Roles: owner (everything), reseller (own clients and their quotas), client (own sites only).
 * Passwords are hashed with Argon2id; five wrong passwords lock the account for 10 minutes; two-step codes are optional per user.
 */
final class Auth
{
    public const ROLES = ['owner', 'reseller', 'client'];
    public const SESSION_HOURS = 12;

    public static function create(string $name, string $password, string $role = 'client', ?int $parent = null, ?string $email = null): int
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $name)) {
            throw new PanelError('The user name needs 3 to 32 letters, digits, - or _ and must start with a letter.', 422);
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new PanelError('Unknown role.', 422);
        }
        self::checkPassword($password);
        $db = Db::pdo();
        $st = $db->prepare('SELECT 1 FROM users WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetchColumn()) {
            throw new PanelError('This user name is taken.', 422);
        }
        $db->prepare('INSERT INTO users (name, email, password, role, parent_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_ARGON2ID), $role, $parent, gmdate('c')]);

        return (int) $db->lastInsertId();
    }

    public static function checkPassword(string $p): void
    {
        if (strlen($p) < 10 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            throw new PanelError('The password needs at least 10 characters with letters and digits.', 422);
        }
        $level = (int) Db::setting('password_strength', '1'); // owner's choice (Webuzo "Password strength")
        if ($level >= 2 && (strlen($p) < 12 || !preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p))) {
            throw new PanelError('The password needs at least 12 characters with small and capital letters and digits.', 422);
        }
        if ($level >= 3 && (strlen($p) < 14 || !preg_match('/[^A-Za-z0-9]/', $p))) {
            throw new PanelError('The password needs at least 14 characters with small and capital letters, digits and a sign like ! or #.', 422);
        }
    }

    public static function user(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    /** @return array{token: string, csrf: string, user: array}|array{two_step: true} */
    public static function login(string $name, string $password, ?string $code = null, ?string $ip = null): array
    {
        $db = Db::pdo();
        $st = $db->prepare('SELECT * FROM users WHERE name = ?');
        $st->execute([$name]);
        $u = $st->fetch();
        if (!$u || !$u['active']) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$YWFhYWFhYWFhYWFhYQ$bm90YXJlYWxoYXNoYWFhYWFhYWFhYWFhYWFhYWFhYWE'); // same time for unknown users
            throw new PanelError('Wrong user name or password.', 401);
        }
        $allow = json_decode((string) Db::setting('panel_allow_ips', '[]'), true) ?: [];
        if ($allow && !PanelPlus::ipIn((string) $ip, $allow)) { // the owner's list of addresses that may sign in (other panels, 0.11.0)
            Audit::log((int) $u['id'], 'login.refused_ip', ['ip' => $ip]);
            throw new PanelError('This panel accepts sign-ins only from allowed addresses.', 403);
        }
        if ($u['role'] !== 'owner' && Sso::settings()['enabled'] && Sso::settings()['only']) { // "company sign-in only" (the owner keeps the password)
            throw new PanelError('This panel signs in with the company account. Use "Sign in with your company account".', 403);
        }
        if ((int) $u['locked_until'] > time()) {
            throw new PanelError('Too many wrong attempts. Try again in a few minutes.', 429);
        }
        if (!password_verify($password, $u['password'])) {
            $failed = (int) $u['failed'] + 1;
            $db->prepare('UPDATE users SET failed = ?, locked_until = ? WHERE id = ?')->execute([$failed >= 5 ? 0 : $failed, $failed >= 5 ? time() + 600 : 0, $u['id']]);
            Audit::log((int) $u['id'], 'login.failed');
            throw new PanelError('Wrong user name or password.', 401);
        }
        if ($u['totp_secret']) {
            if ($code === null || $code === '') {
                return ['two_step' => true];
            }
            if (!Totp::verify($u['totp_secret'], $code)) {
                throw new PanelError('That code is not right.', 401);
            }
        }
        $db->prepare('UPDATE users SET failed = 0, locked_until = 0 WHERE id = ?')->execute([$u['id']]);

        return self::startSession($u, $ip);
    }

    /** A new signed-in session for a checked user (password + two-step, or company sign-in). */
    public static function startSession(array $u, ?string $ip, string $how = 'login'): array
    {
        $db = Db::pdo();
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO sessions (token, user_id, csrf, ip, expires_at) VALUES (?, ?, ?, ?, ?)')->execute([hash('sha256', $token), $u['id'], $csrf, $ip, time() + self::hours() * 3600]);
        $db->exec('DELETE FROM sessions WHERE expires_at < ' . time());
        self::loginAlert($u, (string) $ip);
        Audit::log((int) $u['id'], $how);

        return ['token' => $token, 'csrf' => $csrf, 'user' => self::public($u)];
    }

    public static function hours(): int
    {
        return max(1, (int) Db::setting('session_hours', (string) self::SESSION_HOURS));
    }

    /** A mail to the user when they sign in from an address not seen in their last 50 sign-ins (aaPanel / Plesk login alerts). */
    private static function loginAlert(array $u, string $ip): void
    {
        if (Db::setting('login_alert', '0') !== '1' || empty($u['email']) || $ip === '') {
            return;
        }
        $st = Db::pdo()->prepare("SELECT ip FROM audit WHERE user_id = ? AND action = 'login' ORDER BY id DESC LIMIT 50");
        $st->execute([$u['id']]);
        $seen = array_column($st->fetchAll(), 'ip');
        if (!$seen || in_array($ip, $seen, true)) { // the very first sign-in is not news
            return;
        }
        $host = gethostname() ?: 'server';
        Exec::run(['sendmail', '-t'], "To: {$u['email']}\nSubject: [ZARS Panel] New sign-in on $host\nContent-Type: text/plain; charset=utf-8\n\n{$u['name']} signed in from $ip at " . date('Y-m-d H:i T') . ".\nIf this was not you, change your password and switch on two-step sign-in.\n");
    }

    /** Sign in as one of your clients (WHM, Plesk, DirectAdmin, HestiaCP "log in as user"); your own session waits to come back. */
    public static function impersonate(array $actor, int $targetId, ?string $ip): array
    {
        $t = self::user($targetId) ?? throw new PanelError('Unknown user.', 404);
        if ((int) $t['id'] === (int) $actor['id'] || !self::manages($actor, $t) || ($actor['role'] !== 'owner' && $t['role'] === 'owner')) {
            throw new PanelError('You cannot sign in as this user.', 403);
        }
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        Db::pdo()->prepare('INSERT INTO sessions (token, user_id, csrf, ip, expires_at, imp_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([hash('sha256', $token), $t['id'], $csrf, $ip, time() + 3600, $actor['id']]);
        Audit::log((int) $actor['id'], 'login.as', ['user' => $t['name']]);

        return ['token' => $token, 'csrf' => $csrf, 'user' => self::public($t)];
    }

    /** Who opened this session as someone else (null for a normal session). */
    public static function impersonatedBy(string $token): ?array
    {
        $st = Db::pdo()->prepare('SELECT imp_by FROM sessions WHERE token = ?');
        $st->execute([hash('sha256', $token)]);
        $by = $st->fetchColumn();

        return $by ? self::user((int) $by) : null;
    }

    public static function logout(string $token): void
    {
        Db::pdo()->prepare('DELETE FROM sessions WHERE token = ?')->execute([hash('sha256', $token)]);
    }

    /** @return array{user: array, csrf: ?string, via: string}|null */
    public static function fromToken(string $token): ?array
    {
        $st = Db::pdo()->prepare('SELECT s.csrf, u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND s.expires_at > ? AND u.active = 1');
        $st->execute([hash('sha256', $token), time()]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $csrf = $r['csrf'];
        unset($r['csrf']);

        return ['user' => $r, 'csrf' => $csrf, 'via' => 'session'];
    }

    /** An API token ("zp_..."): the same rights as its user, no CSRF. */
    public static function fromApiToken(string $token): ?array
    {
        $h = hash('sha256', $token);
        $st = Db::pdo()->prepare('SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.hash = ? AND u.active = 1');
        $st->execute([$h]);
        $u = $st->fetch();
        if (!$u) {
            return null;
        }
        Db::pdo()->prepare('UPDATE api_tokens SET last_used = ? WHERE hash = ?')->execute([time(), $h]);

        return ['user' => $u, 'csrf' => null, 'via' => 'token'];
    }

    public static function createApiToken(int $userId, string $name): string
    {
        $plain = 'zp_' . bin2hex(random_bytes(24));
        Db::pdo()->prepare('INSERT INTO api_tokens (user_id, name, hash, created_at) VALUES (?, ?, ?, ?)')->execute([$userId, mb_substr($name, 0, 60), hash('sha256', $plain), gmdate('c')]);

        return $plain; // shown once, only the hash is kept
    }

    public static function public(array $u): array
    {
        return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'demo' => !empty($u['demo']), 'parent_id' => $u['parent_id'] ? (int) $u['parent_id'] : null, 'two_step' => !empty($u['totp_secret']),
            'quota' => json_decode((string) $u['quota'], true) ?: new \stdClass(), 'brand' => json_decode((string) $u['brand'], true) ?: new \stdClass(), 'active' => (bool) $u['active'], 'plan_id' => isset($u['plan_id']) ? (int) $u['plan_id'] : null,
            'language' => (string) ($u['language'] ?? 'en')];
    }

    /** The panel languages (web/src/lang/<code>.json; English is built in). */
    public const LANGUAGES = ['en' => 'English', 'hi' => 'हिन्दी', 'mr' => 'मराठी', 'bn' => 'বাংলা', 'ta' => 'தமிழ்'];

    public static function setLanguage(int $userId, string $code): void
    {
        if (!isset(self::LANGUAGES[$code])) {
            throw new PanelError('Unknown language.', 422);
        }
        Db::pdo()->prepare('UPDATE users SET language = ? WHERE id = ?')->execute([$code, $userId]);
    }

    public static function changePassword(int $userId, string $new): void
    {
        self::checkPassword($new);
        Db::pdo()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_ARGON2ID), $userId]);
        Db::pdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    /** Is $actor allowed to manage $target (the owner: everybody; a reseller: their own clients; others: only themselves)? */
    public static function manages(array $actor, array $target): bool
    {
        return $actor['role'] === 'owner' || (int) $actor['id'] === (int) $target['id'] || ($actor['role'] === 'reseller' && (int) $target['parent_id'] === (int) $actor['id']);
    }
}
