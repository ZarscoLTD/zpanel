<?php

declare(strict_types=1);

use ZarsPanel\{App, Auth};

/** Small helpers for the HTTP-level tests. */
trait Helpers
{
    protected function app(): App
    {
        return new App();
    }

    /** Create a user and sign in; returns the cookie + csrf header to use. */
    protected function login(string $name = 'admin', string $role = 'owner', ?int $parent = null, string $pw = 'Passw0rd-long'): array
    {
        $existing = Db()->prepare('SELECT id FROM users WHERE name = ?');
        $existing->execute([$name]);
        $id = (int) $existing->fetchColumn() ?: Auth::create($name, $pw, $role, $parent);
        $res = $this->app()->handle(['method' => 'POST', 'path' => '/api/login', 'body' => ['name' => $name, 'password' => $pw]]);
        $json = json_decode($res['body'], true);
        preg_match('/zp_session=([a-f0-9]+)/', $res['headers']['Set-Cookie'] ?? '', $m);

        return ['id' => $id, 'cookie' => $m[1] ?? '', 'csrf' => $json['csrf'] ?? ''];
    }

    protected function call(array $s, string $method, string $path, array $body = [], array $query = [], array $files = []): array
    {
        return $this->app()->handle(['method' => $method, 'path' => $path, 'body' => $body, 'query' => $query, 'files' => $files, 'cookies' => ['zp_session' => $s['cookie']], 'headers' => ['X-CSRF-Token' => $s['csrf']]]);
    }
}

function Db(): PDO
{
    return \ZarsPanel\Db::pdo();
}
