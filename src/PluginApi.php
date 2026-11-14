<?php

declare(strict_types=1);

namespace ZarsPanel;

/** What a plugin may use: routes in its own address space, jobs, settings in its own name. */
final class PluginApi
{
    /** @var array<string, callable> */
    public array $jobs = [];

    public function __construct(private App $app, public readonly string $key)
    {
    }

    /** $access: owner, reseller or any (signed-in). The handler gets ($request, $user, $params) and returns an array (JSON). */
    public function route(string $method, string $path, string $access, callable $handler): void
    {
        if (!in_array($access, ['owner', 'reseller', 'any'], true)) {
            throw new \InvalidArgumentException('access must be owner, reseller or any');
        }
        $this->app->route($method, '/api/plugins/' . $this->key . '/' . ltrim($path, '/'), $access, $handler);
    }

    /** A job the panel's cron runs every 5 minutes (zp plugin-jobs). */
    public function job(string $name, callable $fn): void
    {
        $this->jobs[$name] = $fn;
    }

    public function setting(string $k, ?string $default = null): ?string
    {
        return Db::setting("plugin.{$this->key}.$k", $default);
    }

    public function put(string $k, string $v): void
    {
        Db::put("plugin.{$this->key}.$k", $v);
    }
}
