<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Licence of the full ZARS Panel (owner 2026-11-11: paid per server, cheaper than every other panel; the Community edition has
 * no licence at all).
 *  - A new panel runs a free trial (TRIAL_DAYS) from its first start.
 *  - With a key (Settings > Licence, or `zp licence <key>`) it asks the ZARS licence server (the Insights address that serves the
 *    panel's updates) every day. The answer is signed with the licence server's Ed25519 key, pinned on the first check, and is
 *    trusted until its valid_until (about a week), so a short outage of the licence server changes nothing.
 *  - Without a valid licence the hosted websites, mail and databases keep running; only changes from the panel stop (read-only),
 *    except signing in / out and entering a key.
 *  - Starter allows a number of websites (sites in the answer, 0 = unlimited).
 */
final class Licence
{
    public const TRIAL_DAYS = 15;

    /** Paths that always work, also without a licence. */
    private const ALWAYS = ['#^/api/(login|logout|licence|licence/refresh|me/language)$#'];

    /** Test hook: fn(string $method, string $url, ?array $body): ?array instead of curl. */
    public static ?\Closure $http = null;

    public static function server(): string
    {
        $set = Db::setting('licence.server');
        if ($set) {
            return rtrim($set, '/');
        }
        $u = parse_url(Updates::feed());

        return 'https://' . (($u['host'] ?? '') ?: 'insights.zarsx.in');
    }

    /** A stable id of this server (machine id hashed; a random one kept when there is none). */
    public static function serverId(): string
    {
        $id = Db::setting('licence.server_id');
        if ($id) {
            return $id;
        }
        $mid = trim((string) @file_get_contents(Config::etc() . '/machine-id'));
        $id = substr(hash('sha256', 'zarspanel:' . ($mid !== '' ? $mid : bin2hex(random_bytes(16)))), 0, 40);
        Db::put('licence.server_id', $id);

        return $id;
    }

    private static function request(string $method, string $path, ?array $body = null): ?array
    {
        $url = self::server() . $path;
        if (self::$http) {
            return (self::$http)($method, $url, $body);
        }
        $cmd = ['curl', '-fsS', '--max-time', '20', '-H', 'Accept: application/json'];
        if ($body !== null) {
            $cmd = array_merge($cmd, ['-H', 'Content-Type: application/json', '-X', 'POST', '--data-binary', json_encode($body)]);
        }
        $r = Exec::run(array_merge($cmd, [$url]));
        $d = json_decode((string) ($r['out'] ?? ''), true);

        return is_array($d) ? $d : null;
    }

    private static function trialStart(): int
    {
        $t = (int) Db::setting('licence.trial_start', '0');
        if ($t <= 0) {
            $t = time();
            Db::put('licence.trial_start', (string) $t);
        }

        return $t;
    }

    /** The stored answer if it is genuine and meant for this server and key, else null. */
    private static function answer(): ?array
    {
        $payload = (string) Db::setting('licence.payload', '');
        $sig = base64_decode((string) Db::setting('licence.signature', ''), true);
        $pub = base64_decode((string) Db::setting('licence.public_key', ''), true);
        if ($payload === '' || $sig === false || $pub === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig, $payload, $pub)) {
            return null;
        }
        $d = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($d) || ($d['server_id'] ?? '') !== self::serverId() || strtoupper((string) ($d['key'] ?? '')) !== strtoupper((string) Db::setting('licence.key', ''))) {
            return null;
        }

        return $d;
    }

    /** Ask the licence server for the stored (or given) key; keeps the answer only when it is signed by the pinned key. */
    public static function refresh(?string $key = null): array
    {
        if (Edition::community()) {
            return self::status();
        }
        $key = strtoupper(trim((string) ($key ?? Db::setting('licence.key', ''))));
        if ($key === '') {
            return self::status();
        }
        if (!preg_match('/^ZP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key)) {
            throw new PanelError('That is not a ZARS Panel licence key (ZP-XXXX-XXXX-XXXX-XXXX).', 422);
        }
        if (!Db::setting('licence.public_key')) { // first contact: pin the licence server's key (over HTTPS)
            $pk = (string) (self::request('GET', '/api/panel-licence/public-key')['public_key'] ?? '');
            if (strlen((string) base64_decode($pk, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new PanelError('The licence server cannot be reached. Try again in a minute.', 502);
            }
            Db::put('licence.public_key', $pk);
        }
        $r = self::request('POST', '/api/panel-licence/check', ['key' => $key, 'server_id' => self::serverId(), 'host' => (string) gethostname(), 'version' => Config::version()]);
        Db::put('licence.checked_at', (string) time());
        if (!$r || empty($r['payload']) || empty($r['signature'])) {
            throw new PanelError('The licence server cannot be reached. The panel keeps its last answer.', 502);
        }
        $pub = base64_decode((string) Db::setting('licence.public_key'), true);
        $sig = base64_decode((string) $r['signature'], true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, (string) $r['payload'], (string) $pub)) {
            throw new PanelError('The licence answer is not signed by ZARSCO.', 502);
        }
        $d = json_decode((string) base64_decode((string) $r['payload'], true), true) ?: [];
        $old = Db::setting('licence.key', '');
        if (($d['status'] ?? '') === 'active' || $old === $key) { // a wrong new key never replaces a working one
            Db::put('licence.key', $key);
            Db::put('licence.payload', (string) $r['payload']);
            Db::put('licence.signature', (string) $r['signature']);
        }
        if (($d['status'] ?? '') !== 'active') {
            $why = ['unknown' => 'This licence key does not exist.', 'revoked' => 'This licence key was switched off. Contact ZARSCO.', 'expired' => 'This licence has ended. Renew it to make changes again.',
                'too_many_servers' => 'This key is already used on as many servers as it allows. Free a place or add a server to the licence.'][$d['status'] ?? ''] ?? 'The licence is not valid.';
            if ($old !== $key) {
                throw new PanelError($why, 422);
            }
        }

        return self::status();
    }

    public static function status(): array
    {
        if (Edition::community()) {
            return ['state' => 'community', 'writable' => true, 'message' => 'Community edition: free, no licence needed.'];
        }
        $a = self::answer();
        $base = ['key' => self::mask((string) Db::setting('licence.key', '')), 'server_id' => self::serverId(), 'checked_at' => (int) Db::setting('licence.checked_at', '0') ?: null];
        if ($a && ($a['status'] ?? '') === 'active' && strtotime((string) ($a['valid_until'] ?? '')) > time()) {
            return $base + ['state' => 'active', 'writable' => true, 'plan' => $a['plan_name'] ?? $a['plan'] ?? '', 'sites' => (int) ($a['sites'] ?? 0),
                'expires_at' => $a['expires_at'] ?? null, 'customer' => $a['customer'] ?? '', 'message' => 'Licence ' . ($a['plan_name'] ?? '') . ($a['expires_at'] ? ' until ' . substr((string) $a['expires_at'], 0, 10) : ' (lifetime)') . '.'];
        }
        $left = self::TRIAL_DAYS - intdiv(time() - self::trialStart(), 86400);
        if ($left > 0) {
            return $base + ['state' => 'trial', 'writable' => true, 'days_left' => $left, 'sites' => 0,
                'message' => "Free trial: $left day" . ($left === 1 ? '' : 's') . ' left. Enter your licence key in Settings > Licence.'];
        }
        $why = $a ? ($a['status'] ?? '') : '';

        return $base + ['state' => 'expired', 'writable' => false, 'reason' => $why ?: 'no_key',
            'message' => 'No valid licence' . ($why && $why !== 'active' ? ' (' . str_replace('_', ' ', $why) . ')' : '') . ': your websites keep running, but changes from the panel are stopped. Enter or renew your licence key.'];
    }

    private static function mask(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 8) . '-****-' . substr($key, -4);
    }

    /** Called for every signed-in change: stops it while there is no valid licence (sign in / out and the licence screen always work). */
    public static function guard(string $method, string $path): void
    {
        if ($method === 'GET' || Edition::community()) {
            return;
        }
        foreach (self::ALWAYS as $re) {
            if (preg_match($re, $path)) {
                return;
            }
        }
        $s = self::status();
        if (!$s['writable']) {
            throw new PanelError($s['message'], 402);
        }
        // Starter: a limited number of websites
        if ($method === 'POST' && $path === '/api/sites' && ($s['sites'] ?? 0) > 0) {
            $n = (int) Db::pdo()->query('SELECT COUNT(*) FROM sites')->fetchColumn();
            if ($n >= $s['sites']) {
                throw new PanelError("Your licence allows {$s['sites']} websites. Move to Pro for unlimited websites.", 402);
            }
        }
    }
}
