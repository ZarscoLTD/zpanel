<?php

declare(strict_types=1);

// Tiny test runner with no dependencies: php tests/run.php [filter]
$tmp = sys_get_temp_dir() . '/zarspanel-test-' . getmypid();
foreach (['home', 'www', 'etc', 'logs'] as $d) {
    @mkdir("$tmp/$d", 0777, true);
}
putenv("ZARSPANEL_HOME=$tmp/home");
putenv("ZARSPANEL_WWW=$tmp/www");
putenv("ZARSPANEL_ETC=$tmp/etc");
putenv("ZARSPANEL_LOGS=$tmp/logs");
putenv("ZARSPANEL_LSWS=$tmp/lsws");
putenv('ZARSPANEL_DRY=1');
require dirname(__DIR__) . '/src/autoload.php';

use ZarsPanel\{Db, Exec};

final class T
{
    public static int $pass = 0;
    public static array $fail = [];
    public static string $current = '';

    public static function ok(bool $cond, string $msg = ''): void
    {
        $cond ? self::$pass++ : self::$fail[] = self::$current . ': ' . ($msg ?: 'assertion failed') . ' (' . (debug_backtrace()[0]['line'] ?? '?') . ')';
    }

    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::ok($expected === $actual, ($msg ? "$msg: " : '') . 'expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }

    public static function contains(string $needle, string $hay, string $msg = ''): void
    {
        self::ok(str_contains($hay, $needle), ($msg ? "$msg: " : '') . "missing \"$needle\"");
    }

    public static function status(int $expected, array $res, string $msg = ''): void
    {
        self::ok($res['status'] === $expected, ($msg ? "$msg: " : '') . "status {$res['status']} (wanted $expected): " . mb_substr((string) $res['body'], 0, 160));
    }

    public static function json(array $res): array
    {
        return json_decode($res['body'], true) ?? [];
    }

    public static function fresh(): void
    {
        global $tmp;
        Db::reset();
        foreach (['home', 'www', 'etc', 'logs'] as $d) {
            if (is_dir("$tmp/$d")) {
                \ZarsPanel\Files::rrmdir("$tmp/$d");
            }
            mkdir("$tmp/$d", 0777, true);
        }
        mkdir("$tmp/etc/php/8.2/fpm", 0777, true);
        mkdir("$tmp/etc/php/8.3/fpm", 0777, true);
        Exec::reset();
    }
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($filter && !str_contains($file, $filter)) {
        continue;
    }
    require $file;
    $cls = basename($file, '.php');
    foreach (get_class_methods($cls) as $m) {
        if (str_starts_with($m, 'test')) {
            T::$current = "$cls::$m";
            T::fresh();
            try {
                (new $cls())->$m();
            } catch (\Throwable $e) {
                T::$fail[] = T::$current . ': exception ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
            }
        }
    }
}
\ZarsPanel\Files::rrmdir($tmp);
echo T::$pass . ' assertions passed, ' . count(T::$fail) . " failed\n";
foreach (T::$fail as $f) {
    echo "FAIL $f\n";
}
exit(T::$fail ? 1 : 0);
