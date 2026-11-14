<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Every five minutes: disk, memory and services are checked; a problem is mailed and / or posted to a webhook, once an hour per problem. */
final class Alerts
{
    public static function settings(): array
    {
        return ['email' => (string) Db::setting('alerts.email', ''), 'webhook' => (string) Db::setting('alerts.webhook', ''), 'disk' => (int) (Db::setting('alerts.disk') ?: 90), 'memory' => (int) (Db::setting('alerts.memory') ?: 95),
            'telegram_chat' => (string) Db::setting('alerts.telegram_chat', ''), 'telegram_saved' => (string) Db::setting('alerts.telegram_token', '') !== ''];
    }

    public static function save(array $in): array
    {
        $email = trim((string) ($in['email'] ?? ''));
        $hook = trim((string) ($in['webhook'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PanelError('That e-mail address is not valid.', 422);
        }
        if ($hook !== '' && !preg_match('#^https://[^\s]+$#i', $hook)) {
            throw new PanelError('The webhook address must start with https://', 422);
        }
        $tg = trim((string) ($in['telegram_token'] ?? ''));
        $chat = trim((string) ($in['telegram_chat'] ?? ''));
        if ($tg !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $tg)) {
            throw new PanelError('That does not look like a Telegram bot token (from @BotFather, like 123456:ABC...).', 422);
        }
        if ($chat !== '' && !preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $chat)) {
            throw new PanelError('The Telegram chat id is a number (groups start with -100) or @channelname.', 422);
        }
        if ($tg !== '') {
            Db::put('alerts.telegram_token', Secrets::seal($tg));
        }
        if (!empty($in['telegram_off'])) {
            Db::put('alerts.telegram_token', '');
            $chat = '';
        }
        Db::put('alerts.telegram_chat', $chat);
        Db::put('alerts.email', $email);
        Db::put('alerts.webhook', $hook);
        Db::put('alerts.disk', (string) max(50, min(99, (int) ($in['disk'] ?? 90))));
        Db::put('alerts.memory', (string) max(50, min(99, (int) ($in['memory'] ?? 95))));

        return self::settings();
    }

    /** @return string[] the problems found now (new ones are announced) */
    public static function check(): array
    {
        $s = self::settings();
        $snap = Monitor::snapshot();
        $found = [];
        if ($snap['disk']['percent'] >= $s['disk']) {
            $found['disk'] = "Disk is {$snap['disk']['percent']}% full (limit {$s['disk']}%).";
        }
        if ($snap['memory']['percent'] >= $s['memory']) {
            $found['memory'] = "Memory use is {$snap['memory']['percent']}% (limit {$s['memory']}%).";
        }
        foreach (Services::status() as $svc) {
            if (!$svc['active'] && in_array($svc['name'], ['nginx', 'mariadb'], true)) {
                $found['service-' . $svc['name']] = "The service {$svc['name']} is not running.";
            }
        }
        foreach ($found as $kind => $msg) {
            $st = Db::pdo()->prepare('SELECT COUNT(*) FROM alerts WHERE kind = ? AND created_at > ?');
            $st->execute([$kind, gmdate('c', time() - 3600)]);
            if ((int) $st->fetchColumn() === 0) {
                Db::pdo()->prepare('INSERT INTO alerts (kind, message, created_at) VALUES (?, ?, ?)')->execute([$kind, $msg, gmdate('c')]);
                self::send($msg);
            }
        }

        return array_values($found);
    }

    /** An alert from another part of the panel (bandwidth limit ...): stored and sent like the checks above. */
    public static function raise(string $kind, string $msg): void
    {
        Db::pdo()->prepare('INSERT INTO alerts (kind, message, created_at) VALUES (?, ?, ?)')->execute([$kind, $msg, gmdate('c')]);
        self::send($msg);
    }

    private static function send(string $msg): void
    {
        $s = self::settings();
        $host = gethostname() ?: 'server';
        if ($s['email'] !== '') {
            Exec::run(['sendmail', '-t'], "To: {$s['email']}\nSubject: [ZARS Panel] Problem on $host\nContent-Type: text/plain; charset=utf-8\n\n$msg\n");
        }
        $tg = (string) Db::setting('alerts.telegram_token', '');
        if ($tg !== '' && $s['telegram_chat'] !== '') {
            $token = (string) Secrets::open($tg);
            Exec::run(['curl', '-fsS', '-m', '15', '-X', 'POST', '--data-urlencode', 'chat_id=' . $s['telegram_chat'], '--data-urlencode', "text=[ZARS Panel] $host: $msg",
                'https://api.telegram.org/bot' . $token . '/sendMessage']);
        }
        if ($s['webhook'] !== '') {
            Exec::run(['curl', '-fsS', '-m', '15', '-X', 'POST', '-H', 'Content-Type: application/json', '-d', json_encode(['text' => "[ZARS Panel] $host: $msg"]), $s['webhook']]);
        }
    }

    public static function recent(): array
    {
        return Db::pdo()->query('SELECT * FROM alerts ORDER BY id DESC LIMIT 50')->fetchAll();
    }
}
