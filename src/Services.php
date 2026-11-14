<?php

declare(strict_types=1);

namespace ZarsPanel;

/** The services a server needs, with status and start / stop / restart. Only names from this list can be touched. */
final class Services
{
    public static function names(): array
    {
        $php = array_map(fn ($v) => "php$v-fpm", Sites::phpVersions());

        return array_merge(['nginx', 'mariadb', 'cron', 'fail2ban', 'ufw'], is_file(Ols::root() . '/bin/lswsctrl') ? ['lsws'] : [], $php);
    }

    public static function status(): array
    {
        $out = [];
        foreach (self::names() as $n) {
            $r = Exec::run(['systemctl', 'is-active', $n]);
            $out[] = ['name' => $n, 'active' => trim($r['out']) === 'active', 'state' => trim($r['out']) ?: 'unknown'];
        }

        return $out;
    }

    public static function control(string $name, string $action): array
    {
        if (!in_array($name, self::names(), true) || !in_array($action, ['start', 'stop', 'restart', 'reload'], true)) {
            throw new PanelError('Unknown service or action.', 422);
        }
        if ($action === 'stop' && in_array($name, ['nginx'], true) && !Config::dry()) {
            throw new PanelError('Stopping nginx would switch every website off. Use restart or reload.', 422);
        }
        $r = Exec::run(['systemctl', $action, $name]);
        if ($r['code'] !== 0) {
            throw new PanelError("Could not $action $name: " . mb_substr(trim($r['out']), 0, 200), 500);
        }

        return ['name' => $name, 'action' => $action];
    }
}
