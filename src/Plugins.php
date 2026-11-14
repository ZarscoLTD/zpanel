<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Plugins (as Webuzo's): a zip with plugin.json {"key", "name", "version", "about"} and plugin.php, which returns
 * function (PluginApi $api): void. A plugin adds API routes under /api/plugins/<key>/..., a page shown in the panel (page.html),
 * and jobs run by the panel's cron. Owner only: a plugin runs with the panel's rights, so it is installed switched OFF and is switched
 * on by the owner, who confirms it. A plugin that throws while starting is switched off again and the error is kept.
 */
final class Plugins
{
    /** @var array<string, array<string, callable>> jobs of the plugins started by the last boot */
    public static array $jobs = [];

    public static function dir(): string
    {
        return Config::ensure(Config::home() . '/plugins');
    }

    private static function state(): array
    {
        return json_decode((string) Db::setting('plugins', '{}'), true) ?: [];
    }

    private static function save(array $s): void
    {
        Db::put('plugins', json_encode($s));
    }

    public static function list(): array
    {
        $state = self::state();
        $out = [];
        foreach (glob(self::dir() . '/*/plugin.json') ?: [] as $f) {
            $m = json_decode((string) file_get_contents($f), true) ?: [];
            $k = basename(dirname($f));
            $out[] = ['key' => $k, 'name' => (string) ($m['name'] ?? $k), 'version' => (string) ($m['version'] ?? ''), 'about' => (string) ($m['about'] ?? ''),
                'on' => !empty($state[$k]['on']), 'error' => $state[$k]['error'] ?? null, 'page' => is_file(dirname($f) . '/page.html')];
        }

        return $out;
    }

    /** Install (or update) from an uploaded zip. */
    public static function install(string $zipPath): array
    {
        $z = new \ZipArchive();
        if ($z->open($zipPath) !== true) {
            throw new PanelError('That is not a zip file.', 422);
        }
        $prefix = '';
        if ($z->locateName('plugin.json') === false) { // one top folder is fine
            $first = (string) $z->getNameIndex(0);
            $prefix = str_contains($first, '/') ? explode('/', $first)[0] . '/' : '';
        }
        $manifest = json_decode((string) $z->getFromName($prefix . 'plugin.json'), true);
        $key = (string) ($manifest['key'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $key) || $z->locateName($prefix . 'plugin.php') === false) {
            $z->close();
            throw new PanelError('A plugin needs plugin.json with a key (small letters) and plugin.php.', 422);
        }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string) $z->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, "\0") || ($prefix !== '' && !str_starts_with($n, $prefix))) {
                $z->close();
                throw new PanelError('The plugin contains unsafe paths and was not installed.', 422);
            }
        }
        $target = self::dir() . '/' . $key;
        $stage = self::dir() . '/.stage-' . bin2hex(random_bytes(4));
        Config::ensure($stage);
        $z->extractTo($stage);
        $z->close();
        if (is_dir($target)) {
            Files::rrmdir($target);
        }
        rename($prefix === '' ? $stage : $stage . '/' . rtrim($prefix, '/'), $target);
        if (is_dir($stage)) {
            Files::rrmdir($stage);
        }
        $state = self::state();
        $state[$key] = ['on' => false, 'error' => null]; // a new or updated plugin starts switched off
        self::save($state);

        return ['key' => $key, 'name' => (string) ($manifest['name'] ?? $key)];
    }

    public static function toggle(string $key, bool $on, string $confirm = ''): void
    {
        if (!is_file(self::dir() . "/$key/plugin.json")) {
            throw new PanelError('No such plugin.', 404);
        }
        if ($on && $confirm !== $key) {
            throw new PanelError('Type the plugin key to switch it on: it runs with the rights of the panel.', 422);
        }
        $state = self::state();
        $state[$key] = ['on' => $on, 'error' => null];
        self::save($state);
    }

    public static function remove(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $key) || !is_dir(self::dir() . "/$key")) {
            throw new PanelError('No such plugin.', 404);
        }
        Files::rrmdir(self::dir() . "/$key");
        $state = self::state();
        unset($state[$key]);
        self::save($state);
    }

    public static function page(string $key): string
    {
        $f = self::dir() . "/$key/page.html";
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $key) || !is_file($f) || empty(self::state()[$key]['on'])) {
            throw new PanelError('No page.', 404);
        }

        return (string) file_get_contents($f);
    }

    /** Start the switched-on plugins (on every request, after the panel's own routes) and collect their jobs. */
    public static function boot(App $app): array
    {
        $jobs = [];
        $state = self::state();
        foreach ($state as $key => $st) {
            if (empty($st['on']) || !is_file(self::dir() . "/$key/plugin.php")) {
                continue;
            }
            try {
                $fn = (static fn (string $f) => require $f)(self::dir() . "/$key/plugin.php");
                if (!is_callable($fn)) {
                    throw new \RuntimeException('plugin.php must return a function');
                }
                $api = new PluginApi($app, $key);
                $fn($api);
                $jobs[$key] = $api->jobs;
            } catch (\Throwable $e) {
                $state[$key] = ['on' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
                self::save($state);
            }
        }

        self::$jobs = $jobs;

        return $jobs;
    }
}
