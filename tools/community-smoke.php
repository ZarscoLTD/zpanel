<?php

declare(strict_types=1);

// Smoke check of a Community build (bin/make-community.sh runs it on the cut-down copy): the basic features work end to end
// in dry mode, and a feature of the full panel answers "full ZARS Panel". Usage: php tools/community-smoke.php <community folder>
$dir = rtrim((string) ($argv[1] ?? ''), '/');
if (!is_file("$dir/src/autoload.php") || trim((string) @file_get_contents("$dir/EDITION")) !== 'community') {
    fwrite(STDERR, "Not a Community build: $dir\n");
    exit(2);
}
$tmp = sys_get_temp_dir() . '/zp-community-smoke-' . getmypid();
foreach (['home', 'www', 'etc', 'logs'] as $d) {
    @mkdir("$tmp/$d", 0777, true);
}
putenv("ZARSPANEL_HOME=$tmp/home");
putenv("ZARSPANEL_WWW=$tmp/www");
putenv("ZARSPANEL_ETC=$tmp/etc");
putenv("ZARSPANEL_LOGS=$tmp/logs");
putenv('ZARSPANEL_DRY=1');
require "$dir/src/autoload.php";

$app = new \ZarsPanel\App();
\ZarsPanel\Routes::register($app);
\ZarsPanel\Auth::create('admin', 'Passw0rd-long', 'owner');
$res = $app->handle(['method' => 'POST', 'path' => '/api/login', 'body' => ['name' => 'admin', 'password' => 'Passw0rd-long']]);
preg_match('/zp_session=([a-f0-9]+)/', $res['headers']['Set-Cookie'] ?? '', $m);
$csrf = json_decode($res['body'], true)['csrf'] ?? '';
$call = fn (string $method, string $path, array $body = []) => $app->handle(['method' => $method, 'path' => $path, 'body' => $body,
    'cookies' => ['zp_session' => $m[1] ?? ''], 'headers' => ['X-CSRF-Token' => $csrf]]);
$fail = [];
$want = function (int $status, array $r, string $what) use (&$fail) {
    if ($r['status'] !== $status) {
        $fail[] = "$what: status {$r['status']} (wanted $status) " . substr((string) $r['body'], 0, 200);
    }

    return json_decode((string) $r['body'], true) ?: [];
};
$me = $want(200, $call('GET', '/api/me'), 'me');
if (($me['edition'] ?? '') !== 'community') {
    $fail[] = 'me: edition is not community';
}
$site = $want(201, $call('POST', '/api/sites', ['domain' => 'smoke.example.com', 'type' => 'php', 'php' => '8.2']), 'new website')['site'] ?? ['id' => 0];
$want(200, $call('GET', '/api/sites/' . $site['id'] . '/files'), 'file manager');
$want(201, $call('POST', '/api/databases', ['name' => 'smokedb']), 'new database');
$want(201, $call('POST', '/api/cron', ['kind' => 'url', 'schedule' => '*/5 * * * *', 'target' => 'https://smoke.example.com/ping']), 'new scheduled job');
$want(201, $call('POST', '/api/backups/site/' . $site['id'], ['cloud' => false]), 'website backup');
$want(200, $call('GET', '/api/firewall'), 'firewall');
$want(200, $call('GET', '/api/overview'), 'server status');
$r = $want(404, $call('GET', '/api/apps'), 'app store is not in the Community edition');
if (!str_contains((string) ($r['message'] ?? ''), 'full ZARS Panel')) {
    $fail[] = 'app store: no "full ZARS Panel" message';
}
$want(404, $call('GET', '/api/billing-modules'), 'selling hosting is not in the Community edition');

exec('rm -rf ' . escapeshellarg($tmp));
if ($fail) {
    fwrite(STDERR, "Community smoke check FAILED:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}
echo "Community smoke check OK\n";
