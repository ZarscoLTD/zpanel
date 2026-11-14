<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Demo login of the panel (owner 2026-11-10, P2 demo world): a client account flagged "demo" that can open every screen a
 * client sees but never changes anything (App::handle refuses every write). It owns no sites, so no server config is ever
 * written for it. "zp demo-account" makes it or gives it a new password and mails the login to the alert address.
 */
final class Demo
{
    public const NAME = 'demo';

    /** @return array{name: string, password: string} */
    public static function account(): array
    {
        $db = Db::pdo();
        $password = getenv('ZP_DEMO_PASSWORD') ?: 'Demo@zarsx'; // owner 2026-11-11: one fixed demo password
        $st = $db->prepare('SELECT id, demo FROM users WHERE name = ?');
        $st->execute([self::NAME]);
        $row = $st->fetch();
        if ($row && empty($row['demo'])) {
            throw new PanelError('A real user is already called "demo"; the demo login was not made.', 422);
        }
        if (!$row) { // made with a strong random password first (the password rule), then given the shared demo password below
            $id = Auth::create(self::NAME, 'Demo' . bin2hex(random_bytes(8)) . '7', 'client');
        } else {
            $id = (int) $row['id'];
        }
        $db->prepare('UPDATE users SET password = ?, active = 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_ARGON2ID), $id]);
        $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        $db->prepare('UPDATE users SET demo = 1 WHERE id = ?')->execute([$id]);
        Audit::log(null, 'demo.account', ['name' => self::NAME]);

        return ['name' => self::NAME, 'password' => $password];
    }

    /** The login to the alert e-mail address (Settings > Alerts), when one is set. */
    public static function mail(array $login): bool
    {
        $to = (string) (Alerts::settings()['email'] ?? '');
        if ($to === '') {
            return false;
        }
        $host = gethostname() ?: 'server';
        Exec::run(['sendmail', '-t'], "To: $to\nSubject: [ZARS Panel] Demo login on $host\nContent-Type: text/plain; charset=utf-8\n\n"
            . "Demo login of ZARS Panel on $host (it can look at everything, it changes nothing):\nUser: {$login['name']}\nPassword: {$login['password']}\n");

        return true;
    }
}
