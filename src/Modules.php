<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Routes of every feature area, in one place. */
final class Modules
{
    private static function b(array $r, string $k, mixed $d = null): mixed
    {
        return $r['body'][$k] ?? $d;
    }

    public static function register(App $app): void
    {
        $app->route('GET', '/api/overview', 'any', fn ($r, $u) => Monitor::snapshot() + ['services' => $u['role'] === 'owner' ? Services::status() : [], 'usage' => Quota::usage($u)]);
        $app->route('GET', '/api/services', 'owner', fn () => ['services' => Services::status()]);
        $app->route('POST', '/api/services/{name}/{action}', 'owner', function ($r, $u, $p) {
            Audit::log((int) $u['id'], 'service.' . $p['action'], ['name' => $p['name']]);

            return Services::control($p['name'], $p['action']);
        });

        // websites
        $app->route('GET', '/api/php-versions', 'any', fn () => ['versions' => Sites::phpVersions(), 'rewrites' => array_keys(Nginx::REWRITES), 'types' => Sites::TYPES]);
        $app->route('GET', '/api/sites', 'any', fn ($r, $u) => ['sites' => Sites::list($u)]);
        $app->route('POST', '/api/sites', 'any', fn ($r, $u) => ['status' => 201, 'json' => ['site' => Sites::create($u, $r['body'])]]);
        $app->route('GET', '/api/sites/{id}', 'any', fn ($r, $u, $p) => ['site' => Sites::mine($u, (int) $p['id'])]);
        $app->route('POST', '/api/sites/{id}', 'any', fn ($r, $u, $p) => ['site' => Sites::update($u, (int) $p['id'], $r['body'])]);
        $app->route('POST', '/api/sites/{id}/toggle', 'any', fn ($r, $u, $p) => ['site' => Sites::toggle($u, (int) $p['id'], (bool) self::b($r, 'on', false))]);
        $app->route('POST', '/api/sites/{id}/delete', 'any', function ($r, $u, $p) {
            Sites::delete($u, (int) $p['id'], (bool) self::b($r, 'files', false));

            return ['ok' => true];
        });
        $app->route('POST', '/api/sites/{id}/ssl', 'any', fn ($r, $u, $p) => ['site' => Sites::issueSsl($u, (int) $p['id'], (string) self::b($r, 'email', ''))]);

        // files of a site
        $root = fn ($u, $p) => Sites::mine($u, (int) $p['id'])['root'];
        $siteTop = fn ($u, $p) => Config::www() . '/' . Sites::mine($u, (int) $p['id'])['domain'];
        $app->route('GET', '/api/sites/{id}/files', 'any', fn ($r, $u, $p) => ['files' => Files::list($siteTop($u, $p), (string) ($r['query']['path'] ?? ''))]);
        $app->route('GET', '/api/sites/{id}/file', 'any', fn ($r, $u, $p) => Files::read($siteTop($u, $p), (string) ($r['query']['path'] ?? '')));
        $app->route('POST', '/api/sites/{id}/file', 'any', function ($r, $u, $p) use ($siteTop) {
            Files::write($siteTop($u, $p), (string) self::b($r, 'path', ''), (string) self::b($r, 'content', ''));
            Audit::log((int) $u['id'], 'file.save', ['site' => $p['id'], 'path' => self::b($r, 'path')]);

            return ['ok' => true];
        });
        $app->route('POST', '/api/sites/{id}/files/{op}', 'any', function ($r, $u, $p) use ($siteTop) {
            $top = $siteTop($u, $p);
            $path = (string) self::b($r, 'path', '');
            match ($p['op']) {
                'mkdir' => Files::mkdir($top, $path),
                'delete' => self::b($r, 'forever') ? Files::delete($top, $path) : SitePlus::toTrash(Sites::mine($u, (int) $p['id']), $path),
                'rename' => Files::rename($top, $path, (string) self::b($r, 'to', '')),
                'chmod' => Files::chmod($top, $path, (string) self::b($r, 'mode', '')),
                'zip' => Files::zip($top, $path, (string) self::b($r, 'to', $path . '.zip')),
                'unzip' => Files::unzip($top, $path, (string) self::b($r, 'to', dirname($path) === '.' ? '' : dirname($path))),
                default => throw new PanelError('Unknown file action.', 422),
            };
            Audit::log((int) $u['id'], 'file.' . $p['op'], ['site' => $p['id'], 'path' => $path]);

            return ['ok' => true];
        });
        $app->route('POST', '/api/sites/{id}/upload', 'any', function ($r, $u, $p) use ($siteTop) {
            $top = $siteTop($u, $p);
            $dir = (string) ($r['query']['path'] ?? '');
            $n = 0;
            foreach ($r['files'] as $f) {
                $name = basename((string) ($f['name'] ?? ''));
                if ($name === '' || str_contains($name, "\0") || ($f['error'] ?? 0) !== 0) {
                    continue;
                }
                $dest = Files::resolve($top, trim($dir, '/') . '/' . $name);
                is_uploaded_file($f['tmp_name']) || Config::dry() ? copy($f['tmp_name'], $dest) : move_uploaded_file($f['tmp_name'], $dest);
                @chmod($dest, 0644);
                Files::adopt($top, $dest);
                $n++;
            }

            return ['uploaded' => $n];
        });

        // databases
        $app->route('GET', '/api/databases', 'any', fn ($r, $u) => ['databases' => Databases::list($u)]);
        $app->route('POST', '/api/databases', 'any', fn ($r, $u) => ['status' => 201, 'json' => Databases::create($u, $r['body']) + ['note' => 'Copy the password now, it is not shown again.']]);
        $app->route('POST', '/api/databases/{id}/password', 'any', fn ($r, $u, $p) => ['password' => Databases::resetPassword($u, (int) $p['id']), 'note' => 'Copy the password now, it is not shown again.']);
        $app->route('POST', '/api/databases/{id}/delete', 'any', function ($r, $u, $p) {
            Databases::delete($u, (int) $p['id']);

            return ['ok' => true];
        });
        $app->route('POST', '/api/databases/{id}/import', 'any', function ($r, $u, $p) {
            $site = Sites::mine($u, (int) self::b($r, 'site_id', 0));
            Databases::import($u, (int) $p['id'], Config::www() . '/' . $site['domain'], (string) self::b($r, 'path', ''));

            return ['ok' => true];
        });

        // scheduled jobs
        $app->route('GET', '/api/cron', 'any', fn ($r, $u) => ['jobs' => Cron::list($u)]);
        $app->route('POST', '/api/cron', 'any', fn ($r, $u) => ['status' => 201, 'json' => ['job' => Cron::create($u, $r['body'])]]);
        $app->route('POST', '/api/cron/{id}/toggle', 'any', function ($r, $u, $p) {
            Cron::toggle($u, (int) $p['id'], (bool) self::b($r, 'on', false));

            return ['ok' => true];
        });
        $app->route('POST', '/api/cron/{id}/delete', 'any', function ($r, $u, $p) {
            Cron::delete($u, (int) $p['id']);

            return ['ok' => true];
        });

        // backups
        $app->route('GET', '/api/backups', 'any', fn ($r, $u) => ['backups' => Backups::list($u), 'keep' => Backups::keep(), 'cloud' => S3::config() !== null]);
        $app->route('POST', '/api/backups/site/{id}', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => ['backup' => Backups::site($u, (int) $p['id'], (bool) self::b($r, 'cloud', true))]]);
        $app->route('POST', '/api/backups/database/{id}', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => ['backup' => Backups::database($u, (int) $p['id'], (bool) self::b($r, 'cloud', true))]]);
        $app->route('POST', '/api/backups/{id}/restore', 'any', function ($r, $u, $p) {
            Backups::restore($u, (int) $p['id']);

            return ['ok' => true];
        });
        $app->route('POST', '/api/backups/{id}/delete', 'any', function ($r, $u, $p) {
            Backups::delete($u, (int) $p['id']);

            return ['ok' => true];
        });
        $app->route('GET', '/api/backups/{id}/download', 'any', function ($r, $u, $p) {
            $b = Backups::mine($u, (int) $p['id']);
            if (!is_file($b['file'])) {
                throw new PanelError('The file is no longer on this server.', 404);
            }

            return ['raw' => (string) file_get_contents($b['file']), 'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . basename($b['file']) . '"']];
        });
        $app->route('POST', '/api/settings/backups', 'owner', function ($r, $u) {
            Db::put('backup.keep', (string) max(1, min(60, (int) self::b($r, 'keep', 7))));
            if (is_array(self::b($r, 'cloud'))) {
                S3::save($r['body']['cloud']);
            }
            Audit::log((int) $u['id'], 'settings.backups');

            return ['keep' => Backups::keep(), 'cloud' => S3::config() !== null];
        });

        // firewall and SSH (owner)
        $app->route('GET', '/api/firewall', 'owner', fn () => Firewall::status());
        $app->route('POST', '/api/firewall/allow', 'owner', function ($r, $u) {
            Firewall::allow((int) self::b($r, 'port', 0), (string) self::b($r, 'proto', 'tcp'), self::b($r, 'from') ?: null);
            Audit::log((int) $u['id'], 'firewall.allow', ['port' => self::b($r, 'port')]);

            return Firewall::status();
        });
        $app->route('POST', '/api/firewall/deny', 'owner', function ($r, $u) {
            Firewall::deny((int) self::b($r, 'port', 0), (string) self::b($r, 'proto', 'tcp'));
            Audit::log((int) $u['id'], 'firewall.deny', ['port' => self::b($r, 'port')]);

            return Firewall::status();
        });
        $app->route('POST', '/api/firewall/block-ip', 'owner', function ($r, $u) {
            Firewall::blockIp((string) self::b($r, 'ip', ''));
            Audit::log((int) $u['id'], 'firewall.block', ['ip' => self::b($r, 'ip')]);

            return Firewall::status();
        });
        $app->route('POST', '/api/firewall/rules/{n}/delete', 'owner', function ($r, $u, $p) {
            Firewall::deleteRule((int) $p['n']);
            Audit::log((int) $u['id'], 'firewall.delete', ['rule' => $p['n']]);

            return Firewall::status();
        });
        $app->route('POST', '/api/firewall/enable', 'owner', function ($r, $u) {
            Firewall::enable((bool) self::b($r, 'on', true));
            Audit::log((int) $u['id'], 'firewall.' . (self::b($r, 'on', true) ? 'on' : 'off'));

            return Firewall::status();
        });
        $app->route('POST', '/api/firewall/ssh', 'owner', function ($r, $u) {
            Firewall::ssh(self::b($r, 'port') !== null ? (int) $r['body']['port'] : null, self::b($r, 'root_login') !== null ? (bool) $r['body']['root_login'] : null);
            Audit::log((int) $u['id'], 'firewall.ssh', ['port' => self::b($r, 'port')]);

            return Firewall::status();
        });

        // app store
        $app->route('GET', '/api/apps', 'any', fn ($r, $u) => ['apps' => Apps::describe()]);
        $app->route('POST', '/api/apps/{key}/install', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => Apps::install($u, $p['key'], $r['body'])]);
        $app->route('POST', '/api/apps/{key}/source', 'owner', function ($r, $u, $p) {
            Apps::saveSource($p['key'], (string) self::b($r, 'url', ''), self::b($r, 'token'));
            Audit::log((int) $u['id'], 'apps.source', ['app' => $p['key']]);

            return ['apps' => Apps::describe()];
        });

        // DNS zone editor
        $app->route('GET', '/api/dns', 'any', fn ($r, $u) => ['zones' => Dns::list($u), 'types' => Dns::TYPES]);
        $app->route('POST', '/api/dns', 'any', fn ($r, $u) => ['status' => 201, 'json' => ['zone' => Dns::create($u, $r['body'])]]);
        $app->route('POST', '/api/dns/{id}/records', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => ['records' => Dns::addRecord($u, (int) $p['id'], $r['body'])]]);
        $app->route('POST', '/api/dns/{id}/records/{rid}/delete', 'any', fn ($r, $u, $p) => ['records' => Dns::deleteRecord($u, (int) $p['id'], (int) $p['rid'])]);
        $app->route('POST', '/api/dns/{id}/delete', 'any', function ($r, $u, $p) {
            Dns::deleteZone($u, (int) $p['id']);

            return ['ok' => true];
        });

        // FTP users
        $app->route('GET', '/api/ftp', 'any', fn ($r, $u) => ['users' => Ftp::list($u)]);
        $app->route('POST', '/api/ftp', 'any', fn ($r, $u) => ['status' => 201, 'json' => Ftp::create($u, $r['body'])]);
        $app->route('POST', '/api/ftp/{id}/password', 'any', fn ($r, $u, $p) => Ftp::resetPassword($u, (int) $p['id']));
        $app->route('POST', '/api/ftp/{id}/delete', 'any', function ($r, $u, $p) {
            Ftp::delete($u, (int) $p['id']);

            return ['ok' => true];
        });

        // Redis instances
        $app->route('GET', '/api/redis', 'any', fn ($r, $u) => ['instances' => RedisInst::list($u)]);
        $app->route('POST', '/api/redis', 'any', fn ($r, $u) => ['status' => 201, 'json' => RedisInst::create($u, $r['body'])]);
        $app->route('POST', '/api/redis/{id}/{action}', 'any', fn ($r, $u, $p) => $p['action'] === 'delete' ? (function () use ($u, $p) {
            RedisInst::delete($u, (int) $p['id']);

            return ['ok' => true];
        })() : RedisInst::control($u, (int) $p['id'], $p['action']));

        // Docker (owner)
        $app->route('GET', '/api/docker', 'owner', fn () => ['available' => Docker::available(), 'containers' => Docker::containers(), 'templates' => array_map(fn ($t) => $t[3], Docker::TEMPLATES)]);
        $app->route('POST', '/api/docker/run', 'owner', fn ($r, $u) => ['status' => 201, 'json' => Docker::run($r['body'])]);
        $app->route('GET', '/api/docker/{name}/logs', 'owner', fn ($r, $u, $p) => ['logs' => Docker::logs($p['name'])]);
        $app->route('POST', '/api/docker/{name}/{action}', 'owner', function ($r, $u, $p) {
            Audit::log((int) $u['id'], 'docker.' . $p['action'], ['name' => $p['name']]);

            return Docker::control($p['name'], $p['action']);
        });

        // server mail (Postfix / Dovecot)
        $app->route('GET', '/api/mail', 'any', fn ($r, $u) => ['domains' => Mail::list($u)]);
        $app->route('POST', '/api/mail', 'any', fn ($r, $u) => ['status' => 201, 'json' => ['domain' => Mail::addDomain($u, $r['body'])]]);
        $app->route('POST', '/api/mail/{id}/mailboxes', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => Mail::addMailbox($u, (int) $p['id'], $r['body'])]);
        $app->route('POST', '/api/mail/{id}/aliases', 'any', fn ($r, $u, $p) => ['status' => 201, 'json' => Mail::addAlias($u, (int) $p['id'], $r['body'])]);
        $app->route('POST', '/api/mail/{id}/mailboxes/{mid}/delete', 'any', function ($r, $u, $p) {
            Mail::removeMailbox($u, (int) $p['id'], (int) $p['mid']);

            return ['ok' => true];
        });
        $app->route('POST', '/api/mail/{id}/delete', 'any', function ($r, $u, $p) {
            Mail::removeDomain($u, (int) $p['id']);

            return ['ok' => true];
        });

        // virus scan of a website (ClamAV)
        $app->route('POST', '/api/sites/{id}/scan', 'any', fn ($r, $u, $p) => Scan::site($u, (int) $p['id']));
        $app->route('POST', '/api/scan/signatures', 'owner', fn () => Scan::updateSignatures());

        // web terminal (owner)
        $app->route('POST', '/api/terminal', 'owner', fn ($r, $u) => Terminal::run($u, (string) self::b($r, 'command', ''), (string) ($r['ip'] ?? '')));

        // logs, alerts, updates
        $app->route('POST', '/api/updates/pin', 'owner', fn ($r) => ['pin' => Updates::pin((string) self::b($r, 'version', ''))]);
        $app->route('GET', '/api/sites/{id}/logs', 'any', fn ($r, $u, $p) => Logs::site(Sites::mine($u, (int) $p['id']), (string) ($r['query']['type'] ?? 'error'), (int) ($r['query']['lines'] ?? 200)));
        $app->route('GET', '/api/alerts', 'owner', fn () => ['settings' => Alerts::settings(), 'recent' => Alerts::recent()]);
        $app->route('POST', '/api/alerts', 'owner', fn ($r) => ['settings' => Alerts::save($r['body'])]);
        $app->route('POST', '/api/updates/auto', 'owner', function ($r, $u) { // the daily update check installs new releases by itself (default on)
            Db::put('auto_update', self::b($r, 'on', true) ? '1' : '0');
            Audit::log((int) $u['id'], 'updates.auto', ['on' => (bool) self::b($r, 'on', true)]);

            return ['auto' => Db::setting('auto_update', '1') === '1'];
        });
        $app->route('GET', '/api/updates', 'owner', fn () => Updates::check());
        $app->route('GET', '/api/offer', 'owner', fn () => ['offer' => Offer::current()]); // the running ZARS offer (Insights > Offers), for the Overview banner
        $app->route('POST', '/api/updates/apply', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'panel.update.start');

            return Updates::apply();
        });

        // people
        $app->route('GET', '/api/users', 'reseller', function ($r, $u) {
            $st = Db::pdo()->prepare($u['role'] === 'owner' ? 'SELECT * FROM users ORDER BY name' : 'SELECT * FROM users WHERE parent_id = ? OR id = ? ORDER BY name');
            $u['role'] === 'owner' ? $st->execute() : $st->execute([$u['id'], $u['id']]);

            return ['users' => array_map(fn ($x) => Auth::public($x) + ['usage' => Quota::usage($x)], $st->fetchAll())];
        });
        $app->route('POST', '/api/users', 'reseller', function ($r, $u) {
            $role = (string) self::b($r, 'role', 'client');
            if ($u['role'] === 'reseller' && $role !== 'client') {
                throw new PanelError('Resellers can only add clients.', 403);
            }
            Quota::checkAccounts($u);
            $quotaIn = Quota::capFor($u, (array) self::b($r, 'quota', []));
            // like WHM "Create a New Account": the account, its package (plan) and its main website in one step
            $domain = strtolower(trim((string) self::b($r, 'domain', '')));
            if ($domain !== '' && !Nginx::validDomain($domain)) {
                throw new PanelError('That is not a valid domain name.', 422);
            }
            $planId = (int) self::b($r, 'plan_id', 0);
            if ($planId) {
                Plans::mine($u, $planId); // the plan must be one of the creator's own
            }
            $id = Auth::create(strtolower(trim((string) self::b($r, 'name', ''))), (string) self::b($r, 'password', ''), $role, $u['role'] === 'owner' ? ((int) self::b($r, 'parent_id') ?: null) : (int) $u['id'], self::b($r, 'email'));
            Db::pdo()->prepare('UPDATE users SET quota = ? WHERE id = ?')->execute([json_encode($quotaIn), $id]);
            if ($planId) {
                Plans::assign($u, $id, $planId);
            }
            $site = null;
            if ($domain !== '') {
                $site = Sites::create($u, ['domain' => $domain, 'type' => 'php', 'user_id' => $id]);
            }
            Audit::log((int) $u['id'], 'user.create', ['name' => self::b($r, 'name'), 'role' => $role, 'domain' => $domain ?: null, 'plan' => $planId ?: null]);

            return ['status' => 201, 'json' => ['user' => Auth::public(Auth::user($id)), 'site' => $site]];
        });
        // Linux users already on this server (julfekar-lt on a laptop, users of a moved server) and making them panel accounts
        $app->route('GET', '/api/server-users', 'owner', fn ($r, $u) => ['users' => SysUser::serverUsers()]);
        $app->route('POST', '/api/server-users/adopt', 'owner', function ($r, $u) {
            $role = (string) self::b($r, 'role', 'client');
            if (!in_array($role, ['client', 'reseller'], true)) {
                throw new PanelError('Choose client or reseller.', 422);
            }
            $id = SysUser::adopt($u, (string) self::b($r, 'name', ''), $role, (string) self::b($r, 'password', ''), self::b($r, 'email'));

            return ['status' => 201, 'json' => ['user' => Auth::public(Auth::user($id)), 'users' => SysUser::serverUsers()]];
        });
        // WHM "Modify account ownership": move a client to another reseller (or to the owner)
        $app->route('POST', '/api/users/{id}/owner', 'owner', function ($r, $u, $p) {
            $t = Auth::user((int) $p['id']) ?? throw new PanelError('User not found.', 404);
            $to = Auth::user((int) self::b($r, 'parent_id', 0)) ?? throw new PanelError('Choose the new owner.', 422);
            if ($t['role'] !== 'client' || !in_array($to['role'], ['reseller', 'owner'], true)) {
                throw new PanelError('Only a client account can move, and only to a reseller or to you.', 422);
            }
            Db::pdo()->prepare('UPDATE users SET parent_id = ? WHERE id = ?')->execute([$to['role'] === 'owner' ? null : (int) $to['id'], $t['id']]);
            Audit::log((int) $u['id'], 'user.owner', ['name' => $t['name'], 'to' => $to['name']]);

            return ['user' => Auth::public(Auth::user((int) $t['id']))];
        });
        // WHM "View bandwidth / resource usage": every account with its use against its plan
        $app->route('GET', '/api/users-usage', 'reseller', function ($r, $u) {
            $rows = [];
            $st = Db::pdo()->prepare($u['role'] === 'owner' ? 'SELECT * FROM users ORDER BY name' : 'SELECT * FROM users WHERE parent_id = ? ORDER BY name');
            $u['role'] === 'owner' ? $st->execute() : $st->execute([$u['id']]);
            foreach ($st->fetchAll() as $t) {
                if (!in_array($t['role'], ['client', 'reseller'], true)) {
                    continue;
                }
                $sum = AccountSummary::of($t);
                $rows[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'role' => $t['role'], 'plan' => $sum['plan'] ?? null, 'active' => (bool) ($t['active'] ?? 1),
                    'stats' => array_map(fn ($x) => ['key' => $x['key'], 'label' => $x['label'], 'used' => $x['used'], 'limit' => $x['limit'], 'unit' => $x['unit']], $sum['stats'])];
            }

            return ['accounts' => $rows];
        });
        $app->route('POST', '/api/users/{id}', 'reseller', function ($r, $u, $p) {
            $t = Auth::user((int) $p['id']) ?? throw new PanelError('User not found.', 404);
            if (!Auth::manages($u, $t) || ((int) $t['id'] === (int) $u['id'] && $u['role'] !== 'owner' && (self::b($r, 'quota') !== null))) {
                throw new PanelError('You cannot change this user.', 403);
            }
            $db = Db::pdo();
            if (self::b($r, 'quota') !== null) {
                $db->prepare('UPDATE users SET quota = ? WHERE id = ?')->execute([json_encode((int) $t['id'] === (int) $u['id'] ? Quota::clean((array) $r['body']['quota']) : Quota::capFor($u, (array) $r['body']['quota'])), $t['id']]);
            }
            if (self::b($r, 'active') !== null && (int) $t['id'] !== (int) $u['id']) {
                $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([self::b($r, 'active') ? 1 : 0, $t['id']]);
                if (!self::b($r, 'active')) {
                    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$t['id']]);
                }
            }
            if (self::b($r, 'password')) {
                Auth::changePassword((int) $t['id'], (string) $r['body']['password']);
            }
            if (is_array(self::b($r, 'brand')) && in_array($t['role'], ['reseller', 'owner'], true)) {
                $db->prepare('UPDATE users SET brand = ? WHERE id = ?')->execute([json_encode(Brand::clean($r['body']['brand'])), $t['id']]);
            }
            Audit::log((int) $u['id'], 'user.update', ['name' => $t['name']]);

            return ['user' => Auth::public(Auth::user((int) $t['id']))];
        });

        // my account: password, two-step login, API tokens
        $app->route('POST', '/api/account/password', 'any', function ($r, $u) {
            if (!password_verify((string) self::b($r, 'current', ''), $u['password'])) {
                throw new PanelError('The current password is not right.', 422);
            }
            Auth::changePassword((int) $u['id'], (string) self::b($r, 'password', ''));
            Audit::log((int) $u['id'], 'account.password');

            return ['ok' => true, 'message' => 'Password changed. Please sign in again.'];
        });
        $app->route('POST', '/api/account/two-step/start', 'any', function ($r, $u) {
            $secret = Totp::secret();
            Db::put('totp.pending.' . $u['id'], $secret);

            return ['secret' => $secret, 'uri' => Totp::uri($secret, $u['name'], Brand::for($u)['name'])];
        });
        $app->route('POST', '/api/account/two-step/confirm', 'any', function ($r, $u) {
            $secret = Db::setting('totp.pending.' . $u['id']);
            if (!$secret || !Totp::verify($secret, (string) self::b($r, 'code', ''))) {
                throw new PanelError('That code is not right. Try the next one.', 422);
            }
            Db::pdo()->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$secret, $u['id']]);
            Db::pdo()->prepare('DELETE FROM settings WHERE k = ?')->execute(['totp.pending.' . $u['id']]);
            Audit::log((int) $u['id'], 'account.two_step_on');

            return ['ok' => true];
        });
        $app->route('POST', '/api/account/two-step/off', 'any', function ($r, $u) {
            if (!password_verify((string) self::b($r, 'password', ''), $u['password'])) {
                throw new PanelError('The password is not right.', 422);
            }
            Db::pdo()->prepare('UPDATE users SET totp_secret = NULL WHERE id = ?')->execute([$u['id']]);
            Audit::log((int) $u['id'], 'account.two_step_off');

            return ['ok' => true];
        });
        $app->route('GET', '/api/account/tokens', 'any', function ($r, $u) {
            $st = Db::pdo()->prepare('SELECT id, name, last_used, created_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC');
            $st->execute([$u['id']]);

            return ['tokens' => $st->fetchAll()];
        });
        $app->route('POST', '/api/account/tokens', 'any', function ($r, $u) {
            $name = trim((string) self::b($r, 'name', ''));
            if ($name === '') {
                throw new PanelError('Give the token a name.', 422);
            }
            Audit::log((int) $u['id'], 'token.create', ['name' => $name]);

            return ['status' => 201, 'json' => ['token' => Auth::createApiToken((int) $u['id'], $name), 'note' => 'Copy it now, it is not shown again.']];
        });
        // Panel 2.0 part 2c - hosting account tools of cPanel / Webuzo Enduser (AccountTools)
        $app->route('POST', '/api/account/network', 'any', fn ($r, $u) => ['json' => AccountTools::network($u, (string) ($r['body']['tool'] ?? ''), (string) ($r['body']['host'] ?? ''))]);
        $app->route('GET', '/api/account/ssh-keys', 'any', fn ($r, $u) => ['json' => AccountTools::sshKeys($u)]);
        $app->route('POST', '/api/account/ssh-keys', 'any', fn ($r, $u) => ['json' => AccountTools::addKey($u, (string) ($r['body']['key'] ?? ''))]);
        $app->route('POST', '/api/account/ssh-keys/{id}/delete', 'any', fn ($r, $u, $p) => ['json' => AccountTools::removeKey($u, (string) $p['id'])]);
        $app->route('GET', '/api/account/disk', 'any', fn ($r, $u) => ['json' => AccountTools::diskUsage($u)]);
        $app->route('POST', '/api/sites/{id}/rename', 'any', fn ($r, $u, $p) => ['json' => ['site' => Sites::rename($u, (int) $p['id'], (string) ($r['body']['domain'] ?? ''))]]);
        $app->route('GET', '/api/account/bandwidth', 'any', fn ($r, $u) => ['json' => AccountTools::bandwidth($u, isset($r['query']['month']) ? (string) $r['query']['month'] : null)]);
        $app->route('POST', '/api/account/tokens/{id}/delete', 'any', function ($r, $u, $p) {
            Db::pdo()->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')->execute([(int) $p['id'], $u['id']]);

            return ['ok' => true];
        });

        // owner: brand, audit
        $app->route('POST', '/api/settings/brand', 'owner', function ($r, $u) {
            Db::put('brand', json_encode(Brand::clean($r['body'])));
            Audit::log((int) $u['id'], 'settings.brand');

            return ['brand' => Brand::owner()];
        });
        // site extras (Webuzo class): error pages, hotlink, protected folders, blocked IPs, PHP settings, MIME types, disk and traffic; own SSL
        $app->route('GET', '/api/sites/{id}/tools', 'any', fn ($r, $u, $p) => SiteTools::show($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/tools', 'any', fn ($r, $u, $p) => SiteTools::save($u, (int) $p['id'], $r['body']));
        $app->route('POST', '/api/sites/{id}/lscache-purge', 'any', fn ($r, $u, $p) => Ols::purge($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/ssl/wildcard', 'any', fn ($r, $u, $p) => ['site' => SslPlus::wildcard($u, (int) $p['id'], (string) self::b($r, 'email', ''))]);
        $app->route('GET', '/api/server/acme', 'owner', fn () => SslPlus::ca());
        $app->route('POST', '/api/server/acme', 'owner', fn ($r) => SslPlus::saveCa($r['body']));
        $app->route('GET', '/api/server/ptr', 'owner', fn () => SslPlus::ptr());
        $app->route('GET', '/api/dns/{id}/dnssec', 'any', fn ($r, $u, $p) => DnsMailPlus::dnssecStatus($u, (int) $p['id']));
        $app->route('POST', '/api/dns/{id}/dnssec', 'any', fn ($r, $u, $p) => DnsMailPlus::dnssec($u, (int) $p['id'], (bool) self::b($r, 'on', false)));
        $app->route('POST', '/api/mail/{id}/mta-sts', 'any', fn ($r, $u, $p) => DnsMailPlus::mtaSts($u, (int) $p['id'], (string) self::b($r, 'mode', 'testing'), trim((string) self::b($r, 'report', ''))));
        $app->route('POST', '/api/mail/{id}/mta-sts/cert', 'any', fn ($r, $u, $p) => DnsMailPlus::mtaStsCert($u, (int) $p['id'], (string) self::b($r, 'email', '')));
        $app->route('GET', '/api/server/brotli', 'owner', fn () => DnsMailPlus::brotli());
        $app->route('POST', '/api/server/brotli', 'owner', fn ($r, $u) => DnsMailPlus::setBrotli($u, (bool) self::b($r, 'on', false)));
        $app->route('POST', '/api/server/ip-change', 'owner', fn ($r, $u) => Hosting::changeIp($u, trim((string) self::b($r, 'old', '')), trim((string) self::b($r, 'new', ''))));
        $app->route('POST', '/api/sites/{id}/ssl/csr', 'any', fn ($r, $u, $p) => Ssl::csr($u, (int) $p['id'], $r['body']));
        $app->route('POST', '/api/sites/{id}/ssl/install', 'any', fn ($r, $u, $p) => Ssl::install($u, (int) $p['id'], $r['body']));

        // hosting plans
        $app->route('GET', '/api/plans', 'reseller', fn ($r, $u) => ['plans' => Plans::list($u)]);
        $app->route('POST', '/api/plans', 'reseller', fn ($r, $u) => Plans::save($u, $r['body']));
        $app->route('POST', '/api/plans/{id}', 'reseller', fn ($r, $u, $p) => Plans::save($u, $r['body'], (int) $p['id']));
        $app->route('POST', '/api/plans/{id}/delete', 'reseller', function ($r, $u, $p) {
            Plans::delete($u, (int) $p['id']);

            return ['plans' => Plans::list($u)];
        });
        $app->route('POST', '/api/users/{id}/plan', 'reseller', function ($r, $u, $p) {
            Plans::assign($u, (int) $p['id'], self::b($r, 'plan_id') ? (int) self::b($r, 'plan_id') : null);

            return ['ok' => true];
        });

        // database extras
        $app->route('POST', '/api/databases/{id}/remote', 'any', fn ($r, $u, $p) => DbTools::allowHost($u, (int) $p['id'], (string) self::b($r, 'host', '')));
        $app->route('POST', '/api/databases/{id}/remote/delete', 'any', fn ($r, $u, $p) => DbTools::removeHost($u, (int) $p['id'], (string) self::b($r, 'host', '')));
        $app->route('POST', '/api/databases/{id}/maintain', 'any', fn ($r, $u, $p) => DbTools::maintain($u, (int) $p['id'], (string) self::b($r, 'op', '')));
        $app->route('GET', '/api/databases-processes', 'owner', fn () => ['processes' => DbTools::processes()]);
        $app->route('POST', '/api/databases-processes/{id}/kill', 'owner', function ($r, $u, $p) {
            DbTools::killQuery((int) $p['id']);
            Audit::log((int) $u['id'], 'db.query_killed', ['id' => $p['id']]);

            return ['processes' => DbTools::processes()];
        });

        // mail extras
        $app->route('POST', '/api/mail/{id}/mailboxes/{mid}/autoreply', 'any', fn ($r, $u, $p) => MailTools::autoreply($u, (int) $p['id'], (int) $p['mid'], $r['body']));
        $app->route('GET', '/api/mail/{id}/check', 'any', fn ($r, $u, $p) => MailTools::check($u, (int) $p['id']));
        $app->route('GET', '/api/mail-queue', 'owner', fn () => ['queue' => MailTools::queue()]);
        $app->route('POST', '/api/mail-queue/{action}', 'owner', function ($r, $u, $p) {
            MailTools::queueAction($p['action'], self::b($r, 'id'));
            Audit::log((int) $u['id'], 'mail.queue_' . $p['action']);

            return ['queue' => MailTools::queue()];
        });

        // server tools (owner)
        $app->route('GET', '/api/server', 'owner', fn () => ServerTools::info() + ['password_login' => ServerTools::passwordLogin(), 'keys' => ServerTools::keys()]);
        $app->route('GET', '/api/server/processes', 'owner', fn () => ['processes' => ServerTools::processes()]);
        $app->route('POST', '/api/server/processes/{pid}/kill', 'owner', function ($r, $u, $p) {
            ServerTools::kill((int) $p['pid'], (string) self::b($r, 'signal', 'TERM'));
            Audit::log((int) $u['id'], 'server.kill', ['pid' => $p['pid']]);

            return ['processes' => ServerTools::processes()];
        });
        $app->route('POST', '/api/server/hostname', 'owner', function ($r, $u) {
            ServerTools::setHostname((string) self::b($r, 'hostname', ''));
            Audit::log((int) $u['id'], 'server.hostname', ['name' => self::b($r, 'hostname')]);

            return ServerTools::info();
        });
        $app->route('GET', '/api/server/service-tls', 'owner', fn () => ['json' => ServiceTls::status()]);
        $app->route('POST', '/api/server/service-tls', 'owner', function ($r, $u) {
            $s = ServiceTls::issue((string) self::b($r, 'email', ''));
            Audit::log((int) $u['id'], 'server.service_tls', ['host' => $s['hostname']]);

            return ['json' => $s];
        });
        $app->route('GET', '/api/server/timezones', 'owner', fn () => ['json' => ['current' => ServerTools::timezone(), 'zones' => \DateTimeZone::listIdentifiers()]]);
        $app->route('POST', '/api/server/timezone', 'owner', function ($r, $u) {
            ServerTools::setTimezone((string) self::b($r, 'timezone', ''));
            Audit::log((int) $u['id'], 'server.timezone', ['zone' => self::b($r, 'timezone')]);

            return ServerTools::info();
        });
        $app->route('POST', '/api/server/resolvers', 'owner', function ($r, $u) {
            ServerTools::setResolvers((array) self::b($r, 'resolvers', []));
            Audit::log((int) $u['id'], 'server.resolvers');

            return ServerTools::info();
        });
        $app->route('POST', '/api/server/reboot', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'server.reboot');
            ServerTools::reboot((string) self::b($r, 'confirm', ''));

            return ['ok' => true, 'note' => 'The server is restarting. Sign in again in a minute or two.'];
        });
        $app->route('GET', '/api/server/updates', 'owner', fn () => ['updates' => ServerTools::updates()]);
        $app->route('POST', '/api/server/updates', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'server.upgrade', ['security_only' => (bool) self::b($r, 'security_only', false)]);

            return ServerTools::upgrade((bool) self::b($r, 'security_only', false));
        });
        $app->route('POST', '/api/server/network', 'owner', fn ($r) => ServerTools::network((string) self::b($r, 'tool', ''), (string) self::b($r, 'host', '')));
        // server software catalogue (Webuzo's Apps list): install / update / remove, official repositories for the newest versions
        $app->route('GET', '/api/server/software', 'owner', fn () => ['software' => Software::status()]);
        $app->route('POST', '/api/server/software', 'owner', function ($r, $u) {
            $key = (string) self::b($r, 'key', '');
            $action = (string) self::b($r, 'action', '');
            Audit::log((int) $u['id'], 'server.software', ['key' => $key, 'action' => $action]);

            return Software::act($key, $action) + ['software' => Software::status()];
        });
        $app->route('GET', '/api/server/php-extensions', 'owner', fn () => ['extensions' => ServerTools::phpExtensions()]);
        $app->route('POST', '/api/server/php-extensions', 'owner', function ($r, $u) {
            ServerTools::phpExtension((string) self::b($r, 'version', ''), (string) self::b($r, 'extension', ''), (bool) self::b($r, 'install', true));
            Audit::log((int) $u['id'], 'server.php_extension', ['v' => self::b($r, 'version'), 'ext' => self::b($r, 'extension'), 'install' => self::b($r, 'install')]);

            return ['extensions' => ServerTools::phpExtensions()];
        });
        $app->route('POST', '/api/server/ssh-keys', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'server.ssh_key_added');
            if (self::b($r, 'generate')) {
                return ServerTools::generateKey((string) self::b($r, 'comment', 'zarspanel')) + ['keys' => ServerTools::keys(), 'note' => 'Save the private key now, it is not shown again.'];
            }
            ServerTools::addKey((string) self::b($r, 'key', ''));

            return ['keys' => ServerTools::keys()];
        });
        $app->route('POST', '/api/server/ssh-keys/{n}/delete', 'owner', function ($r, $u, $p) {
            ServerTools::removeKey((int) $p['n']);
            Audit::log((int) $u['id'], 'server.ssh_key_removed');

            return ['keys' => ServerTools::keys()];
        });
        $app->route('POST', '/api/server/ssh-password', 'owner', function ($r, $u) {
            ServerTools::setPasswordLogin((bool) self::b($r, 'on', true));
            Audit::log((int) $u['id'], 'server.ssh_password', ['on' => (bool) self::b($r, 'on', true)]);

            return ['password_login' => ServerTools::passwordLogin()];
        });
        // the rest of Webuzo's admin screens (ServerExtras), owner only; every change is in the activity log
        $x = function (string $method, string $path, string $action, callable $fn) use ($app) {
            $app->route($method, '/api/extras/' . $path, 'owner', function ($r, $u, $p = []) use ($fn, $action, $method) {
                $out = $fn($r['body'] ?? [], $p, $u, $r);
                if ($method === 'POST') {
                    Audit::log((int) $u['id'], 'extras.' . $action, array_diff_key($r['body'] ?? [], ['password' => 1]) + $p);
                }

                return $out ?? ['ok' => true];
            });
        };
        $x('GET', 'mail', 'mail', fn () => ['relay' => json_decode((string) Db::setting('mail_relay', '{}'), true) ?: null, 'remote' => json_decode((string) Db::setting('mail_remote_domains', '[]'), true),
            'blocks' => json_decode((string) Db::setting('mail_blocks', '{"senders":[],"countries":[]}'), true), 'smtp_restricted' => Db::setting('smtp_restricted', '0') === '1',
            'system_mail' => Db::setting('system_mail'), 'service_tls' => Db::setting('service_tls')]);
        $x('POST', 'mail/relay', 'relay', fn ($b) => ServerExtras::relay($b));
        $x('POST', 'mail/routing', 'routing', fn ($b) => ServerExtras::routing((string) ($b['domain'] ?? ''), (string) ($b['mode'] ?? '')));
        $x('POST', 'mail/blocks', 'blocks', fn ($b) => ServerExtras::senderBlock($b));
        $x('POST', 'mail/smtp-restrict', 'smtp_restrict', fn ($b) => ServerExtras::smtpRestrict((bool) ($b['on'] ?? false)));
        $x('POST', 'mail/system', 'system_mail', fn ($b) => ServerExtras::systemMail((string) ($b['email'] ?? '')));
        $x('POST', 'mail/tls', 'service_tls', fn ($b) => ServerExtras::serviceTls((string) ($b['domain'] ?? '')));
        $x('GET', 'mail/log', 'mail_log', fn ($b, $p, $u, $r) => ServerExtras::mailLog((string) ($r['query']['find'] ?? '')));
        $x('GET', 'ftp-sessions', 'ftp', fn () => ['sessions' => ServerExtras::ftpSessions()]);
        $x('POST', 'ftp-sessions/{pid}/close', 'ftp_close', function ($b, $p) {
            ServerExtras::ftpKill((int) $p['pid']);

            return ['sessions' => ServerExtras::ftpSessions()];
        });
        $x('GET', 'processes', 'processes', fn () => ServerExtras::processKiller() + ['log' => ServerExtras::processLog()]);
        $x('POST', 'processes/killer', 'process_killer', fn ($b) => ServerExtras::processKiller(array_map('strval', (array) ($b['names'] ?? []))));
        $x('GET', 'php-fpm/{version}', 'fpm', fn ($b, $p) => ServerExtras::phpFpm((string) $p['version']));
        $x('POST', 'php-fpm/{version}', 'fpm', fn ($b, $p) => ServerExtras::phpFpm((string) $p['version'], (array) ($b['settings'] ?? [])));
        $x('GET', 'mysql', 'mysql', fn () => ServerExtras::mysqlConf());
        $x('POST', 'mysql', 'mysql_conf', fn ($b) => ServerExtras::mysqlConf((array) ($b['settings'] ?? [])));
        $x('POST', 'mysql/root-password', 'mysql_root', fn ($b) => ServerExtras::mysqlRootPassword((string) ($b['password'] ?? '')));
        $x('GET', 'apache', 'apache', fn () => ServerExtras::apache());
        $x('POST', 'apache', 'apache', fn ($b) => ServerExtras::apache((array) ($b['settings'] ?? [])));
        $x('GET', 'users', 'users', fn () => ServerExtras::wheel() + ServerExtras::shells() + ServerExtras::compilers());
        $x('POST', 'users/sudo', 'sudo', fn ($b) => ServerExtras::wheel((string) ($b['user'] ?? ''), (bool) ($b['add'] ?? false)));
        $x('POST', 'users/shell', 'shell', fn ($b) => ServerExtras::shells((string) ($b['user'] ?? ''), (bool) ($b['on'] ?? false)));
        $x('POST', 'compilers', 'compilers', fn ($b) => ServerExtras::compilers((bool) ($b['open'] ?? false)));
        $x('POST', 'packages/repair', 'packages_repair', fn () => ServerExtras::repairPackages());
        $x('POST', 'ip-migration', 'ip_migration', fn ($b) => ServerExtras::ipMigration((string) ($b['old'] ?? ''), (string) ($b['new'] ?? '')));
        $x('GET', 'shared-ip', 'shared_ip', fn () => ServerExtras::sharedIp() + ['ips' => Hosting::ips()['addresses']]);
        $x('POST', 'shared-ip', 'shared_ip', fn ($b) => ServerExtras::sharedIp((string) ($b['ip'] ?? '')));
        $x('POST', 'sites/{id}/owner', 'change_owner', fn ($b, $p) => ServerExtras::changeOwner((int) $p['id'], (int) ($b['user_id'] ?? 0)));
        $x('GET', 'sites/{id}/extra-conf', 'extra_conf', fn ($b, $p) => ServerExtras::extraConf((int) $p['id']));
        $x('POST', 'sites/{id}/extra-conf', 'extra_conf', fn ($b, $p) => ServerExtras::extraConf((int) $p['id'], (string) ($b['text'] ?? '')));
        $x('POST', 'sites/{id}/move', 'move_site', fn ($b, $p) => ServerExtras::moveSite((int) $p['id'], (string) ($b['to'] ?? '')));
        $x('GET', 'dns-cleanup', 'dns_cleanup', fn () => ServerExtras::orphanZones());
        $x('POST', 'dns-cleanup', 'dns_cleanup', fn ($b, $p, $u) => ServerExtras::orphanZones(true, $u));
        $x('POST', 'bandwidth/{user}/reset', 'bandwidth_reset', fn ($b, $p) => ServerExtras::resetBandwidth((int) $p['user']));
        $x('GET', 'export', 'export', fn () => ServerExtras::export());
        $x('POST', 'import', 'import', fn ($b, $p, $u) => ServerExtras::import((array) ($b['data'] ?? []), (int) $u['id']));
        $x('POST', 'tomcat/password', 'tomcat_password', fn ($b) => ServerExtras::tomcatPassword((string) ($b['password'] ?? '')));
        $x('GET', 'mail-limits', 'mail_limits', fn () => ['on' => Db::setting('mail_limits', '0') === '1', 'users' => MailPolicy::usage()]);
        $x('POST', 'mail-limits/on', 'mail_limits_on', function () {
            MailPolicy::enable();

            return ['on' => true, 'users' => MailPolicy::usage()];
        });
        $x('POST', 'mail-limits/{user}', 'mail_limit', function ($b, $p) {
            $u = Auth::user((int) $p['user']) ?? throw new PanelError('Unknown user.', 404);
            $q = json_decode((string) $u['quota'], true) ?: [];
            $v = $b['per_hour'] ?? '';
            if ($v === '' || $v === null) {
                unset($q['mail_per_hour']);
            } elseif (is_numeric($v) && (int) $v >= 0 && (int) $v <= 100000) {
                $q['mail_per_hour'] = (int) $v;
            } else {
                throw new PanelError('Give a number of messages an hour, or leave it empty for no limit.', 422);
            }
            Db::pdo()->prepare('UPDATE users SET quota = ? WHERE id = ?')->execute([json_encode($q), $u['id']]);

            return ['users' => MailPolicy::usage()];
        });
        $x('GET', 'plugins', 'plugins', fn () => ['plugins' => Plugins::list()]);
        $x('POST', 'plugins/upload', 'plugin_install', function ($b, $p, $u, $r) {
            $f = $r['files'][0] ?? null;
            if (!$f || ($f['error'] ?? 0) !== 0) {
                throw new PanelError('Choose the plugin zip.', 422);
            }

            return Plugins::install((string) $f['tmp_name']) + ['plugins' => Plugins::list()];
        });
        $x('POST', 'plugins/{key}/on', 'plugin_on', function ($b, $p) {
            Plugins::toggle((string) $p['key'], true, (string) ($b['confirm'] ?? ''));

            return ['plugins' => Plugins::list()];
        });
        $x('POST', 'plugins/{key}/off', 'plugin_off', function ($b, $p) {
            Plugins::toggle((string) $p['key'], false);

            return ['plugins' => Plugins::list()];
        });
        $x('POST', 'plugins/{key}/delete', 'plugin_delete', function ($b, $p) {
            Plugins::remove((string) $p['key']);

            return ['plugins' => Plugins::list()];
        });
        $app->route('GET', '/api/extras/plugins/{key}/page', 'owner', fn ($r, $u, $p) => ['raw' => Plugins::page((string) $p['key']), 'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self' 'unsafe-inline' data:; frame-ancestors 'self'"]]);
        $x('GET', 'storage', 'storage', fn () => ServerExtras::storage());
        $x('POST', 'storage', 'storage', fn ($b) => ServerExtras::storage((string) ($b['backup_dir'] ?? '')));

        $app->route('GET', '/api/login-log', 'any', fn ($r, $u) => ['logins' => array_values(array_filter(Audit::recent(500, $u['role'] === 'owner' ? null : (int) $u['id']), fn ($a) => str_starts_with((string) $a['action'], 'auth.') || str_starts_with((string) $a['action'], 'login')))]);

        // hosting: DNS template, skeleton, log rotation, bandwidth, IPs, software, many accounts at once (owner unless noted)
        $app->route('GET', '/api/hosting', 'owner', fn () => ['dns_template' => Hosting::dnsTemplate(), 'skeleton' => Hosting::skeleton(), 'logrotate' => Hosting::logRotation(),
            'bandwidth' => Hosting::bandwidth(), 'greylisting' => MailTools::greylisting()]);
        $app->route('POST', '/api/hosting/dns-template', 'owner', function ($r, $u) {
            Hosting::saveDnsTemplate((string) self::b($r, 'template', ''));
            Audit::log((int) $u['id'], 'hosting.dns_template');

            return ['dns_template' => Hosting::dnsTemplate()];
        });
        $app->route('POST', '/api/hosting/logrotate', 'owner', fn ($r) => ['logrotate' => Hosting::saveLogRotation($r['body'])]);
        $app->route('POST', '/api/hosting/greylisting', 'owner', function ($r, $u) {
            MailTools::setGreylisting((bool) self::b($r, 'on', false));
            Audit::log((int) $u['id'], 'mail.greylisting', ['on' => (bool) self::b($r, 'on', false)]);

            return ['greylisting' => MailTools::greylisting()];
        });
        $app->route('GET', '/api/ips', 'owner', fn () => Hosting::ips());
        $app->route('POST', '/api/ips', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'server.ip_' . self::b($r, 'action', 'add'), ['ip' => self::b($r, 'ip')]);

            return Hosting::ip((string) self::b($r, 'action', 'add') === 'remove' ? 'remove' : 'add', (string) self::b($r, 'interface', ''), (string) self::b($r, 'ip', ''));
        });
        $app->route('GET', '/api/software', 'owner', fn () => ['software' => Hosting::software()]);
        $app->route('POST', '/api/software', 'owner', function ($r, $u) {
            Audit::log((int) $u['id'], 'server.software', ['kind' => self::b($r, 'kind'), 'name' => self::b($r, 'name'), 'install' => self::b($r, 'install', true)]);

            return Hosting::installSoftware((string) self::b($r, 'kind', 'package'), (string) self::b($r, 'name', ''), (bool) self::b($r, 'install', true));
        });
        $app->route('POST', '/api/users-mass', 'reseller', fn ($r, $u) => Hosting::mass($u, $r['body']));

        // mail filters (any) - greylisting above (owner)
        $app->route('POST', '/api/mail/{id}/mailboxes/{mid}/filters', 'any', fn ($r, $u, $p) => MailTools::filters($u, (int) $p['id'], (int) $p['mid'], (array) self::b($r, 'filters', [])));

        // other servers (owner)
        $app->route('GET', '/api/remote', 'owner', fn () => ['servers' => Remote::health()]);
        $app->route('POST', '/api/remote', 'owner', fn ($r) => ['servers' => Remote::add($r['body'])]);
        $app->route('POST', '/api/remote/{id}/delete', 'owner', fn ($r, $u, $p) => ['servers' => Remote::remove($p['id'])]);

        // support tickets (everyone: clients to their reseller, resellers to the owner)
        $app->route('GET', '/api/tickets', 'any', fn ($r, $u) => ['tickets' => Tickets::list($u)]);
        $app->route('POST', '/api/tickets', 'any', fn ($r, $u) => ['status' => 201, 'json' => ['ticket' => Tickets::open($u, $r['body'])]]);
        $app->route('GET', '/api/tickets/{id}', 'any', fn ($r, $u, $p) => ['ticket' => Tickets::mine($u, (int) $p['id'])]);
        $app->route('POST', '/api/tickets/{id}', 'any', fn ($r, $u, $p) => ['ticket' => Tickets::reply($u, (int) $p['id'], $r['body'])]);

        // move accounts in from cPanel (owner / reseller)
        $app->route('GET', '/api/import', 'reseller', fn () => ['folder' => Import::folder(), 'archives' => Import::archives()]);
        $app->route('POST', '/api/import', 'reseller', fn ($r, $u) => Import::run($u, (string) self::b($r, 'file', ''), $r['body']));

        // cPanel parity: phpMyAdmin / webmail / calendars, Git deploy, file restore, mail import / spam / usage / lists, stats, apps, dynamic DNS
        $app->route('GET', '/api/tools-web', 'any', fn () => ['tools' => CpanelParity::tools()]);
        $app->route('POST', '/api/tools-web/{k}', 'owner', function ($r, $u, $p) {
            Audit::log((int) $u['id'], 'server.tool_install', ['tool' => $p['k']]);

            return CpanelParity::installTool($p['k']);
        });
        $app->route('POST', '/api/databases/{id}/phpmyadmin', 'any', fn ($r, $u, $p) => CpanelParity::phpMyAdminLogin($u, (int) $p['id']));
        $app->route('POST', '/api/sites/{id}/git', 'any', fn ($r, $u, $p) => CpanelParity::gitDeploy($u, (int) $p['id'], $r['body']) + ['hook' => '/hook/git/' . $p['id'] . '/' . CpanelParity::hookSecret((int) $p['id'])]);
        $app->route('POST', '/hook/git/{id}/{secret}', 'public', function ($r, $u, $p) {
            if (!hash_equals(CpanelParity::hookSecret((int) $p['id']), (string) $p['secret'])) {
                throw new PanelError('Not found.', 404);
            }
            $cfg = json_decode((string) Db::setting('git_' . (int) $p['id'], ''), true) ?: throw new PanelError('No deployment set up.', 404);
            $site = Sites::find((int) $p['id']) ?? throw new PanelError('Not found.', 404);

            return CpanelParity::gitDeploy(Auth::user((int) $site['user_id']), (int) $p['id'], $cfg);
        });
        $app->route('GET', '/api/backups/{id}/files', 'any', fn ($r, $u, $p) => ['files' => CpanelParity::backupFiles($u, (int) $p['id'])]);
        $app->route('POST', '/api/backups/{id}/restore-file', 'any', function ($r, $u, $p) {
            CpanelParity::restoreFile($u, (int) $p['id'], (string) self::b($r, 'path', ''));

            return ['ok' => true];
        });
        $app->route('POST', '/api/mail/{id}/import', 'any', fn ($r, $u, $p) => CpanelParity::importMailboxes($u, (int) $p['id'], (string) self::b($r, 'csv', '')));
        $app->route('POST', '/api/mail/{id}/spam', 'any', fn ($r, $u, $p) => CpanelParity::spamFilter($u, (int) $p['id'], $r['body']));
        $app->route('GET', '/api/mail/{id}/usage', 'any', fn ($r, $u, $p) => ['usage' => CpanelParity::mailUsage($u, (int) $p['id'])]);
        $app->route('POST', '/api/mail/{id}/lists', 'any', fn ($r, $u, $p) => CpanelParity::mailingList($u, (int) $p['id'], (string) self::b($r, 'local', ''), (array) self::b($r, 'members', [])));
        $app->route('GET', '/api/sites/{id}/stats', 'any', fn ($r, $u, $p) => ['raw' => CpanelParity::stats($u, (int) $p['id'])['html'], 'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self' 'unsafe-inline' data:"]]);
        $app->route('POST', '/api/sites/{id}/app', 'any', fn ($r, $u, $p) => CpanelParity::app($u, (int) $p['id'], $r['body']));
        $app->route('POST', '/api/sites/{id}/app/{action}', 'any', fn ($r, $u, $p) => CpanelParity::appControl($u, (int) $p['id'], $p['action']));
        $app->route('POST', '/api/dns/{id}/ddns', 'any', fn ($r, $u, $p) => CpanelParity::ddnsCreate($u, (int) $p['id'], (string) self::b($r, 'name', '')));
        $app->route('GET', '/ddns/{token}', 'public', fn ($r, $u, $p) => CpanelParity::ddnsUpdate($p['token'], (string) ($r['query']['ip'] ?? $r['headers']['x-real-ip'] ?? $r['ip'] ?? '')));

        $app->route('GET', '/api/audit', 'reseller', fn ($r, $u) => ['audit' => Audit::recent(200, $u['role'] === 'owner' ? null : (int) $u['id'])]);
    }
}
