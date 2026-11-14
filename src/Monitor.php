<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Server health from /proc: CPU, memory, disk, load, network, uptime. */
final class Monitor
{
    private static function cpu(): array
    {
        $l = (string) @file_get_contents('/proc/stat');
        if (!preg_match('/^cpu\s+(.+)$/m', $l, $m)) {
            return [0, 0];
        }
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $idle = ($v[3] ?? 0) + ($v[4] ?? 0);

        return [array_sum($v), $idle];
    }

    public static function snapshot(): array
    {
        [$t1, $i1] = self::cpu();
        usleep(150000);
        [$t2, $i2] = self::cpu();
        $cpu = $t2 > $t1 ? round((1 - ($i2 - $i1) / ($t2 - $t1)) * 100, 1) : 0.0;
        $mem = [];
        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $mem[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $total = $mem['MemTotal'] ?? 0;
        $avail = $mem['MemAvailable'] ?? 0;
        $load = array_map('floatval', explode(' ', (string) @file_get_contents('/proc/loadavg')));
        $up = (int) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        $disk = Config::www();
        while (!is_dir($disk) && $disk !== '/') {
            $disk = dirname($disk);
        }
        $dt = (int) @disk_total_space($disk);
        $df = (int) @disk_free_space($disk);
        $rx = $tx = 0;
        foreach (explode("\n", (string) @file_get_contents('/proc/net/dev')) as $line) {
            if (preg_match('/^\s*(\w+):\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $line, $m) && $m[1] !== 'lo') {
                $rx += (int) $m[2];
                $tx += (int) $m[3];
            }
        }

        return ['cpu' => $cpu, 'cores' => (int) trim((string) @shell_exec('nproc 2>/dev/null')) ?: 1, 'memory' => ['total' => $total, 'used' => $total - $avail, 'percent' => $total ? round(($total - $avail) / $total * 100, 1) : 0],
            'disk' => ['total' => $dt, 'used' => $dt - $df, 'percent' => $dt ? round(($dt - $df) / $dt * 100, 1) : 0], 'load' => array_slice($load, 0, 3), 'uptime' => $up, 'network' => ['rx' => $rx, 'tx' => $tx],
            'host' => gethostname(), 'os' => php_uname('s') . ' ' . php_uname('r')];
    }
}
