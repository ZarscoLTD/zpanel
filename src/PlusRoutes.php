<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Routes of the features of the other control panels (0.11.0, see PanelPlus and SitePlus). Changes go to the activity log. */
final class PlusRoutes
{
    private static function b(array $r, string $k, mixed $d = null): mixed
    {
        return $r['body'][$k] ?? $d;
    }

    private static function cookie(string $name, string $value, int $maxAge, array $r): string
    {
        return "$name=$value; Path=/; HttpOnly; SameSite=Strict; Max-Age=$maxAge" . (($r['headers']['x-forwarded-proto'] ?? '') === 'https' ? '; Secure' : '');
    }

    public static function register(App $app): void
    {
        // server (owner): every POST is written to the activity log, passwords never
        $o = function (string $method, string $path, string $action, callable $fn) use ($app) {
            $app->route($method, '/api/plus/' . $path, 'owner', function ($r, $u, $p = []) use ($fn, $action, $method) {
                $out = $fn($r['body'] ?? [], $p, $u, $r);
                if ($method === 'POST') {
                    Audit::log((int) $u['id'], 'plus.' . $action, array_diff_key($r['body'] ?? [], ['password' => 1, 'token' => 1, 'remote_db_password' => 1, 'key' => 1]) + $p);
                }

                return $out ?? ['ok' => true];
            });
        };
        $o('GET', 'services', 'services', fn () => PanelPlus::services());
        $o('POST', 'services/{name}/{action}', 'service', fn ($b, $p) => PanelPlus::service((string) $p['name'], (string) $p['action']));
        $o('GET', 'journal', 'journal', fn ($b, $p, $u, $r) => PanelPlus::journal((string) ($r['query']['unit'] ?? ''), (int) ($r['query']['lines'] ?? 200), (string) ($r['query']['find'] ?? '')));
        $o('GET', 'time', 'time', fn () => PanelPlus::time());
        $o('POST', 'time', 'time', fn ($b) => PanelPlus::setTime($b));
        $o('POST', 'swap', 'swap', fn ($b) => PanelPlus::swap((int) ($b['mb'] ?? 0)));
        $o('GET', 'jails', 'jails', fn () => PanelPlus::jails());
        $o('POST', 'jails/settings', 'jail_settings', fn ($b) => PanelPlus::jailSettings($b));
        $o('POST', 'jails/{jail}/{action}', 'jail', fn ($b, $p) => PanelPlus::jailAction((string) $p['jail'], (string) $p['action'], (string) ($b['ip'] ?? '')));
        $o('GET', 'countries', 'countries', fn () => PanelPlus::countries());
        $o('POST', 'countries', 'countries', fn ($b, $p, $u, $r) => PanelPlus::setCountries(preg_split('/[\s,]+/', is_array($b['countries'] ?? null) ? implode(' ', $b['countries']) : (string) ($b['countries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [], (string) $r['ip']));
        $o('GET', 'access', 'access', fn ($b, $p, $u, $r) => PanelPlus::access() + ['your_ip' => $r['ip']]);
        $o('POST', 'access', 'access', fn ($b, $p, $u, $r) => PanelPlus::setAccess($b, (string) $r['ip']) + ['your_ip' => $r['ip']]);
        $o('GET', 'history', 'history', fn ($b, $p, $u, $r) => PanelPlus::history((string) ($r['query']['range'] ?? '24h')));
        $o('GET', 'advisor', 'advisor', fn ($b, $p, $u) => PanelPlus::advisor($u));
        $o('GET', 'rclone', 'rclone', fn () => PanelPlus::rclone());
        $o('POST', 'rclone', 'rclone', fn ($b) => PanelPlus::setRclone($b));
        $o('POST', 'rclone/test', 'rclone_test', fn () => PanelPlus::rcloneTest());
        $o('GET', 'imunify', 'imunify', fn () => Imunify::status());
        $o('POST', 'imunify/install', 'imunify_install', fn ($b) => Imunify::install((string) ($b['product'] ?? ''), (string) ($b['key'] ?? '')));
        $o('POST', 'imunify/scan', 'imunify_scan', fn ($b) => Imunify::scan((string) ($b['domain'] ?? '')));
        $o('GET', 'imunify/found', 'imunify_found', fn () => Imunify::found());
        $o('POST', 'imunify/{action}', 'imunify_act', fn ($b, $p) => Imunify::act((string) $p['action'], (array) ($b['ids'] ?? [])));
        // 0.13.0: one Linux user per hosting account
        $o('GET', 'site-users', 'site_users', fn () => SysUser::overview());
        $o('POST', 'site-users', 'site_users', function ($b) {
            Db::put('site_users', !empty($b['enabled']) ? '1' : '0');

            return SysUser::overview();
        });
        $o('POST', 'site-users/move', 'site_users_move', function ($b) {
            $ids = !empty($b['site_id']) ? [(int) $b['site_id']] : array_map(fn ($s) => (int) $s['id'], SysUser::legacySites());
            $moved = [];
            foreach ($ids as $id) {
                $moved[] = SysUser::move($id);
            }

            return ['moved' => $moved] + SysUser::overview();
        });
        $o('GET', 'dns-cluster', 'dns_cluster', fn () => PanelPlus::cluster() + PanelPlus::zonesForCluster());
        $o('POST', 'dns-cluster/secondaries', 'dns_secondaries', fn ($b) => PanelPlus::setSecondaries(preg_split('/[\s,]+/', (string) ($b['ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $o('POST', 'dns-cluster/primaries', 'dns_primaries', fn ($b) => PanelPlus::setPrimaries((array) ($b['primaries'] ?? [])));

        // any signed-in user: Cloudflare DNS, moving a site over SSH, uptime
        $app->route('POST', '/api/plus/cloudflare/token', 'any', fn ($r, $u) => PanelPlus::cloudflareToken($u, (string) self::b($r, 'token', '')));
        $app->route('GET', '/api/plus/cloudflare', 'any', fn ($r, $u) => ['connected' => (string) Db::setting('cf_token_' . $u['id'], '') !== '']);
        $app->route('POST', '/api/plus/cloudflare/sync/{zone}', 'any', function ($r, $u, $p) {
            Audit::log((int) $u['id'], 'plus.cloudflare_sync', ['zone' => $p['zone']]);

            return PanelPlus::cloudflareSync($u, (int) $p['zone']);
        });
        $app->route('POST', '/api/plus/migrate', 'any', function ($r, $u) {
            Audit::log((int) $u['id'], 'plus.migrate', ['host' => self::b($r, 'host'), 'site' => self::b($r, 'site_id')]);

            return PanelPlus::migrateStart($u, $r['body']);
        });
        $app->route('GET', '/api/plus/migrate/{id}', 'any', fn ($r, $u, $p) => PanelPlus::migrateLog((string) $p['id']));
        $app->route('GET', '/api/plus/uptime', 'any', fn ($r, $u) => SitePlus::uptime($u));
        $app->route('POST', '/api/plus/uptime/{id}', 'any', fn ($r, $u, $p) => SitePlus::setUptime($u, (int) $p['id'], (bool) self::b($r, 'on', false)));

        // websites
        $app->route('GET', '/api/sites/{id}/wordpress', 'any', fn ($r, $u, $p) => SitePlus::wordpress($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/wordpress', 'any', fn ($r, $u, $p) => SitePlus::wordpressAction($u, (int) $p['id'], $r['body']));
        $app->route('POST', '/api/sites/{id}/wordpress/password', 'any', fn ($r, $u, $p) => SitePlus::wordpressPassword($u, (int) $p['id'], (string) self::b($r, 'user', '')));
        $app->route('GET', '/api/sites/{id}/laravel', 'any', function ($r, $u, $p) {
            $s = Sites::mine($u, (int) $p['id']);

            return ['commands' => SitePlus::ARTISAN, 'scheduler' => SitePlus::schedulerJob((int) $s['id']) > 0];
        });
        $app->route('POST', '/api/sites/{id}/artisan', 'any', fn ($r, $u, $p) => SitePlus::artisan($u, (int) $p['id'], (string) self::b($r, 'command', '')));
        $app->route('POST', '/api/sites/{id}/scheduler', 'any', fn ($r, $u, $p) => SitePlus::scheduler($u, (int) $p['id'], (bool) self::b($r, 'on', false)));
        $app->route('GET', '/api/sites/{id}/workers', 'any', fn ($r, $u, $p) => SitePlus::workers($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/workers', 'any', fn ($r, $u, $p) => SitePlus::addWorker($u, (int) $p['id'], $r['body']));
        $app->route('POST', '/api/sites/{id}/workers/{w}/{action}', 'any', fn ($r, $u, $p) => SitePlus::workerAction($u, (int) $p['id'], (int) $p['w'], (string) $p['action']));
        $app->route('GET', '/api/sites/{id}/workers/{w}/log', 'any', fn ($r, $u, $p) => SitePlus::workerLog($u, (int) $p['id'], (int) $p['w']));
        $app->route('POST', '/api/sites/{id}/staging', 'any', fn ($r, $u, $p) => SitePlus::staging($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/push-live', 'any', fn ($r, $u, $p) => SitePlus::pushLive($u, (int) $p['id'], (bool) self::b($r, 'database', false)));
        $app->route('GET', '/api/sites/{id}/trash', 'any', fn ($r, $u, $p) => SitePlus::trash($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/trash/{key}/{action}', 'any', fn ($r, $u, $p) => SitePlus::trashAction($u, (int) $p['id'], (string) $p['key'], (string) $p['action']));
        $app->route('GET', '/api/sites/{id}/shares', 'any', fn ($r, $u, $p) => SitePlus::shares($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/shares', 'any', fn ($r, $u, $p) => SitePlus::share($u, (int) $p['id'], (string) self::b($r, 'path', ''), (int) self::b($r, 'hours', 24)));
        $app->route('POST', '/api/sites/{id}/shares/{share}/delete', 'any', fn ($r, $u, $p) => SitePlus::unshare($u, (int) $p['id'], (int) $p['share']));
        $app->route('GET', '/api/share/{token}', 'public', fn ($r, $u, $p) => SitePlus::download((string) $p['token']));
        $app->route('POST', '/api/sites/{id}/cache/purge', 'any', fn ($r, $u, $p) => SitePlus::purgeCache($u, (int) $p['id']));
        $app->route('GET', '/api/sites/{id}/preview', 'any', fn ($r, $u, $p) => ['name' => SitePlus::previewName(Sites::mine($u, (int) $p['id']))]);

        // billing systems (WHMCS, Blesta, own scripts): the owner or a reseller sells hosting accounts with an API token
        $bl = function (string $method, string $path, callable $fn) use ($app) {
            $app->route($method, '/api/billing/' . $path, 'reseller', fn ($r, $u, $p = []) => $fn($r['body'] ?? [], $p, $u, $r));
        };
        $bl('GET', 'test', fn ($b, $p, $u) => Billing::test($u));
        $bl('GET', 'plans', fn ($b, $p, $u) => Billing::plans($u));
        $bl('GET', 'usage', fn ($b, $p, $u, $r) => Billing::usage($u, isset($r['query']['username']) ? (string) $r['query']['username'] : null));
        $bl('POST', 'accounts', fn ($b, $p, $u) => ['status' => 201, 'json' => Billing::create($u, $b)]);
        $bl('POST', 'accounts/{name}/suspend', fn ($b, $p, $u) => Billing::suspend($u, (string) $p['name'], true, (string) ($b['reason'] ?? '')));
        $bl('POST', 'accounts/{name}/unsuspend', fn ($b, $p, $u) => Billing::suspend($u, (string) $p['name'], false));
        $bl('POST', 'accounts/{name}/terminate', fn ($b, $p, $u) => Billing::terminate($u, (string) $p['name']));
        $bl('POST', 'accounts/{name}/password', fn ($b, $p, $u) => Billing::password($u, (string) $p['name'], (string) ($b['password'] ?? '')));
        $bl('POST', 'accounts/{name}/plan', fn ($b, $p, $u) => Billing::changePlan($u, (string) $p['name'], $b['plan'] ?? null));
        $bl('POST', 'accounts/{name}/sso', fn ($b, $p, $u) => Billing::sso($u, (string) $p['name']));
        $app->route('GET', '/api/sso/{token}', 'public', function ($r, $u, $p) {
            $res = Billing::ssoLogin((string) $p['token'], $r['ip']);

            return ['status' => 302, 'headers' => ['Location' => '/', 'Set-Cookie' => self::cookie('zp_session', $res['token'], Auth::hours() * 3600, $r)], 'raw' => ''];
        });
        $app->route('POST', '/api/users/{id}/terminate', 'reseller', function ($r, $u, $p) {
            $t = Auth::user((int) $p['id']) ?? throw new PanelError('User not found.', 404);
            if ((string) self::b($r, 'confirm', '') !== $t['name']) {
                throw new PanelError('Type the user name to confirm.', 422);
            }

            return Billing::terminate($u, $t['name']);
        });
        $app->route('GET', '/api/billing-modules/{which}', 'reseller', function ($r, $u, $p) {
            $file = dirname(__DIR__) . '/integrations/dist/' . match ((string) $p['which']) { 'whmcs' => 'zarspanel-whmcs.zip', 'blesta' => 'zarspanel-blesta.zip', default => throw new PanelError('Unknown module.', 404) };
            if (!is_file($file)) {
                throw new PanelError('The module is not in this release.', 404);
            }

            return ['raw' => (string) file_get_contents($file), 'headers' => ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . basename($file) . '"']];
        });

        // the last Webuzo admin screens (0.12.0)
        $app->route('POST', '/api/hosting-admin/mail-all', 'reseller', fn ($r, $u) => HostingAdmin::mailAll($u, $r['body']));
        $app->route('GET', '/api/hosting-admin/news', 'reseller', fn ($r, $u) => HostingAdmin::news($u));
        $app->route('POST', '/api/hosting-admin/news', 'reseller', fn ($r, $u) => HostingAdmin::news($u, (string) self::b($r, 'text', '')));
        $app->route('GET', '/api/news', 'any', fn ($r, $u) => ['news' => HostingAdmin::newsFor($u)]);
        $o('GET', 'strength', 'strength', fn () => HostingAdmin::strength());
        $o('POST', 'strength', 'strength', fn ($b) => HostingAdmin::strength((int) ($b['level'] ?? 0)));
        $o('GET', 'fork-bomb', 'fork_bomb', fn () => HostingAdmin::forkBomb());
        $o('POST', 'fork-bomb', 'fork_bomb', fn ($b) => HostingAdmin::forkBomb((bool) ($b['on'] ?? false)));
        $app->route('POST', '/api/dns-ttl', 'any', fn ($r, $u) => HostingAdmin::setTtl($u, (int) self::b($r, 'ttl', 0), (array) self::b($r, 'zones', [])));
        $o('POST', 'databases/{id}/owner', 'db_owner', fn ($b, $p) => HostingAdmin::giveDatabase((int) $p['id'], (int) ($b['user_id'] ?? 0)));
        $app->route('GET', '/api/plan-features', 'any', fn () => ['features' => array_keys(Quota::FEATURES)]);

        // sign in as a client, and back
        $app->route('POST', '/api/users/{id}/login-as', 'reseller', function ($r, $u, $p) {
            $back = (string) ($r['cookies']['zp_session'] ?? '');
            $res = Auth::impersonate($u, (int) $p['id'], $r['ip']);

            return ['headers' => ['Set-Cookie' => [self::cookie('zp_session', $res['token'], 3600, $r), self::cookie('zp_back', $back, 3600, $r)]],
                'json' => ['user' => $res['user'], 'csrf' => $res['csrf']]];
        });
        $app->route('POST', '/api/login-back', 'any', function ($r, $u) {
            $back = (string) ($r['cookies']['zp_back'] ?? '');
            $mine = Auth::fromToken($back);
            if (!$mine) {
                throw new PanelError('Your own session has ended; sign in again.', 401);
            }
            Auth::logout((string) ($r['cookies']['zp_session'] ?? ''));

            return ['headers' => ['Set-Cookie' => [self::cookie('zp_session', $back, Auth::hours() * 3600, $r), self::cookie('zp_back', '', 0, $r)]],
                'json' => ['user' => Auth::public($mine['user']), 'csrf' => $mine['csrf']]];
        });
    }
}
