<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * The HTTP layer without HTTP: handle() takes a request as an array and returns [status, headers, body], so every route is testable
 * without a web server. public/index.php only translates PHP's request into this array. All answers are JSON.
 */
final class App
{
    /** @var list<array{0: string, 1: string, 2: string, 3: callable}> */
    private array $routes = [];

    public function __construct()
    {
        Routes::register($this);
    }

    public function route(string $method, string $pattern, string $access, callable $handler): void
    {
        $this->routes[] = [$method, '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#', $access, $handler];
    }

    /** @param array{method: string, path: string, query?: array, body?: array, headers?: array, cookies?: array, files?: array, ip?: string} $req */
    public function handle(array $req): array
    {
        $req += ['query' => [], 'body' => [], 'headers' => [], 'cookies' => [], 'files' => [], 'ip' => '127.0.0.1'];
        $_SERVER['REMOTE_ADDR'] = $req['ip'];
        $req['headers'] = array_change_key_case($req['headers']);
        $out = ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY'];
        try {
            if (!Edition::allows($req['path'])) { // Community edition: only the basic features (Edition::COMMUNITY_API)
                throw new PanelError('This is part of the full ZARS Panel, not the free Community edition.', 404);
            }
            foreach ($this->routes as [$method, $re, $access, $handler]) {
                if ($method !== $req['method'] || !preg_match($re, $req['path'], $m)) {
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $actor = null;
                if ($access !== 'public') {
                    $session = $this->session($req);
                    if (!$session) {
                        throw new PanelError('Please sign in.', 401);
                    }
                    $actor = $session['user'];
                    if ($session['via'] === 'session' && $req['method'] !== 'GET' && !hash_equals((string) $session['csrf'], (string) ($req['headers']['x-csrf-token'] ?? ''))) {
                        throw new PanelError('The page is out of date, reload it.', 419);
                    }
                    if (($access === 'owner' && $actor['role'] !== 'owner') || ($access === 'reseller' && !in_array($actor['role'], ['owner', 'reseller'], true))) {
                        throw new PanelError('You are not allowed to do this.', 403);
                    }
                }
                // demo login (zp demo-account): sees the panel, never changes anything on the server
                if ($actor && !empty($actor['demo']) && $req['method'] !== 'GET' && $req['path'] !== '/api/logout') {
                    throw new PanelError('This is a demo login: you can look at everything, but nothing is changed.', 403);
                }
                if ($actor) { // full panel without a valid licence: read-only (websites keep running), Starter: website limit
                    Licence::guard($req['method'], $req['path']);
                }
                if ($actor && ($feature = Quota::blocked($actor, $req['method'], $req['path']))) {
                    throw new PanelError('Your hosting plan does not include this (' . str_replace('_', ' ', $feature) . '). Ask your provider.', 403);
                }
                $res = $handler($req, $actor, $params);
                $headers = $res['headers'] ?? [];
                if (isset($res['raw'])) {
                    return ['status' => $res['status'] ?? 200, 'headers' => $headers + ['X-Content-Type-Options' => 'nosniff'], 'body' => $res['raw']];
                }

                return ['status' => $res['status'] ?? 200, 'headers' => $headers + $out, 'body' => json_encode($res['json'] ?? $res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)];
            }
            throw new PanelError('Not found.', 404);
        } catch (PanelError $e) {
            return ['status' => $e->status, 'headers' => $out, 'body' => json_encode(['message' => $e->getMessage()])];
        } catch (\Throwable $e) {
            error_log('[zarspanel] ' . $e);

            return ['status' => 500, 'headers' => $out, 'body' => json_encode(['message' => 'Something went wrong on the server. See the panel log.'])];
        }
    }

    private function session(array $req): ?array
    {
        $auth = (string) ($req['headers']['authorization'] ?? '');
        if (preg_match('/^Bearer\s+(zp_[a-f0-9]{48})$/', $auth, $m)) {
            return Auth::fromApiToken($m[1]);
        }
        $c = (string) ($req['cookies']['zp_session'] ?? '');

        return $c !== '' ? Auth::fromToken($c) : null;
    }
}
