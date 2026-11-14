<?php

declare(strict_types=1);

namespace ZarsPanel;

/** The route table. Handlers get ($request, $actor, $params) and return an array (JSON) or ['status' => ..., 'json' => ...]. */
final class Routes
{
    private static function body(array $r, string $k, mixed $d = null): mixed
    {
        return $r['body'][$k] ?? $d;
    }

    public static function register(App $app): void
    {
        // the sign-in page shows the server owner's brand (name, colour, logo) before anybody is signed in
        $app->route('GET', '/api/brand', 'public', fn ($r) => ['json' => array_intersect_key(Brand::owner(), array_flip(['name', 'color', 'logo']))]);
        // licence of the full panel (owner 2026-11-11): status for everybody signed in (banner), key only by the owner
        $app->route('GET', '/api/licence', 'any', fn ($r, $u) => ['json' => Licence::status() + ['owner' => $u['role'] === 'owner']]);
        $app->route('POST', '/api/licence', 'owner', function ($r, $u) {
            $s = Licence::refresh((string) self::body($r, 'key', ''));
            Audit::log((int) $u['id'], 'licence.key', ['state' => $s['state']]);

            return ['json' => $s];
        });
        $app->route('POST', '/api/licence/refresh', 'owner', fn ($r) => ['json' => Licence::refresh()]);
        $app->route('POST', '/api/login', 'public', function ($r) {
            $name = strtolower(trim((string) self::body($r, 'name', '')));
            $res = Auth::login($name, (string) self::body($r, 'password', ''), self::body($r, 'code'), $r['ip']);
            if (isset($res['two_step'])) {
                return ['status' => 202, 'json' => ['two_step' => true]];
            }

            return ['headers' => ['Set-Cookie' => 'zp_session=' . $res['token'] . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . Auth::SESSION_HOURS * 3600 . (($r['headers']['x-forwarded-proto'] ?? '') === 'https' ? '; Secure' : '')],
                'json' => ['user' => $res['user'], 'csrf' => $res['csrf']]];
        });
        // company sign-in (OpenID Connect) - Sso
        $base = fn ($r) => ((($r['headers']['x-forwarded-proto'] ?? '') === 'https' || ($r['headers']['https'] ?? '') === 'on') ? 'https' : 'http') . '://' . preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string) ($r['headers']['host'] ?? 'localhost'));
        $app->route('GET', '/api/sso/info', 'public', fn ($r) => ['json' => ['enabled' => Sso::settings()['enabled']]]);
        $app->route('GET', '/api/sso/start', 'public', function ($r) use ($base) {
            $s = Sso::start($base($r));

            return ['status' => 302, 'headers' => ['Location' => $s['url'], 'Set-Cookie' => 'zp_sso=' . rawurlencode($s['cookie']) . '; Path=/api/sso; HttpOnly; SameSite=Lax; Max-Age=900; Secure'], 'raw' => ''];
        });
        $app->route('GET', '/api/sso/callback', 'public', function ($r) use ($base) {
            try {
                $u = Sso::finish($r['query'], rawurldecode((string) ($r['cookies']['zp_sso'] ?? '')), $base($r));
                $allow = json_decode((string) Db::setting('panel_allow_ips', '[]'), true) ?: [];
                if ($allow && !PanelPlus::ipIn((string) $r['ip'], $allow)) {
                    throw new PanelError('This panel accepts sign-ins only from allowed addresses.', 403);
                }
                $res = Auth::startSession($u, $r['ip'], 'login.sso');
            } catch (PanelError $e) {
                return ['status' => 302, 'headers' => ['Location' => '/?sso_error=' . rawurlencode($e->getMessage())], 'raw' => ''];
            }

            return ['status' => 302, 'headers' => ['Location' => '/', 'Set-Cookie' => 'zp_session=' . $res['token'] . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . Auth::hours() * 3600 . '; Secure'], 'raw' => ''];
        });
        $app->route('GET', '/api/audit.csv', 'any', fn ($r, $u) => ['headers' => ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="panel-activity-' . gmdate('Y-m-d') . '.csv"'], 'raw' => Audit::csv($u)]);
        $app->route('GET', '/api/settings/sso', 'owner', fn ($r) => ['json' => array_merge(Sso::settings(), ['redirect_uri' => $base($r) . '/api/sso/callback'])]);
        $app->route('POST', '/api/settings/sso', 'owner', function ($r, $u) use ($base) {
            $s = Sso::save($r['body'], $base($r));
            Audit::log((int) $u['id'], 'settings.sso', ['enabled' => $s['enabled'], 'issuer' => $s['issuer'], 'only' => $s['only']]);

            return ['json' => $s];
        });
        $app->route('POST', '/api/logout', 'any', function ($r) {
            Auth::logout((string) ($r['cookies']['zp_session'] ?? ''));

            return ['headers' => ['Set-Cookie' => 'zp_session=; Path=/; HttpOnly; Max-Age=0'], 'json' => ['ok' => true]];
        });
        // the hosting account at a glance (cPanel-style home: general information and statistics), own or a managed account
        $app->route('GET', '/api/account/summary', 'any', function ($r, $u) {
            $id = (int) ($r['query']['user'] ?? $u['id']);
            $t = $id === (int) $u['id'] ? $u : (Auth::user($id) ?? throw new PanelError('User not found.', 404));
            if ((int) $t['id'] !== (int) $u['id'] && !Auth::manages($u, $t)) {
                throw new PanelError('You cannot see this account.', 403);
            }

            return ['json' => AccountSummary::of($t)];
        });
        $app->route('GET', '/api/me', 'any', function ($r, $u) {
            $session = Auth::fromToken((string) ($r['cookies']['zp_session'] ?? ''));

            $by = Auth::impersonatedBy((string) ($r['cookies']['zp_session'] ?? ''));

            return ['user' => Auth::public($u), 'csrf' => $session['csrf'] ?? null, 'version' => Config::version(), 'brand' => Brand::for($u), 'languages' => Auth::LANGUAGES, 'edition' => Edition::name(), 'community_pages' => Edition::COMMUNITY_PAGES,
                'signed_in_as_by' => $by ? $by['name'] : null];
        });
        // cPanel "Change language": every user picks the language of their own panel
        $app->route('POST', '/api/me/language', 'any', function ($r, $u) {
            Auth::setLanguage((int) $u['id'], (string) ($r['body']['language'] ?? ''));
            Audit::log((int) $u['id'], 'language', ['language' => (string) ($r['body']['language'] ?? '')]);

            return ['ok' => true, 'languages' => Auth::LANGUAGES];
        });
        Modules::register($app);
        PlusRoutes::register($app); // features of the other control panels (0.11.0)
        Plugins::boot($app); // switched-on plugins add their routes after the panel's own
    }
}
