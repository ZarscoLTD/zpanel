<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * The only door to the operating system. Commands are given as an argument list (never one shell string), every argument is escaped,
 * and in dry-run mode (tests, "plan" previews) nothing runs: the command is recorded so a test can read exactly what would have happened.
 */
final class Exec
{
    /** @var list<array{cmd: string, stdin: ?string}> */
    public static array $log = [];
    /** @var array<string, array{code: int, out: string}> canned answers for dry runs, keyed by the start of the command */
    public static array $canned = [];

    /** @return array{code: int, out: string} */
    public static function run(array $argv, ?string $stdin = null, int $timeout = 120): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        self::$log[] = ['cmd' => $cmd, 'stdin' => $stdin];
        if (Config::dry()) {
            foreach (self::$canned as $prefix => $answer) {
                if (str_starts_with($cmd, $prefix)) {
                    return $answer;
                }
            }

            return ['code' => 0, 'out' => ''];
        }
        $p = proc_open('timeout ' . (int) $timeout . ' ' . $cmd . ' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => 'cannot start'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($p), 'out' => (string) $out];
    }

    public static function ok(array $argv, ?string $stdin = null): bool
    {
        return self::run($argv, $stdin)['code'] === 0;
    }

    /** Write a file (atomically, mode 0640). In dry-run it is still written: tests point ZARSPANEL_ETC / WWW at a temporary folder. */
    public static function write(string $path, string $content, int $mode = 0640): void
    {
        Config::ensure(dirname($path));
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        file_put_contents($tmp, $content);
        @chmod($tmp, $mode);
        rename($tmp, $path);
    }

    public static function reset(): void
    {
        self::$log = [];
        self::$canned = [];
    }

    public static function commands(): array
    {
        return array_column(self::$log, 'cmd');
    }
}
