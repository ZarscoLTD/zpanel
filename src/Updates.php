<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Panel updates from a signed release feed. The feed lists the newest version, the zip address, its SHA-256 and a detached Ed25519
 * signature of that hash made with ZARSCO's release key. The panel only installs a package whose hash and signature both check out.
 */
final class Updates
{
    /** Public half of the release key (the private half never leaves the release machine). */
    public const PUBLIC_KEY = 'l4DDRrmSkVfc1HPjKqRAU0YLTJlBDt927V5OEkx44Lw=';
    public const DEFAULT_FEED = 'https://insights.zarsx.in/updates/zarspanel/latest.json'; // served by ZARS Insights (Control center > System > Product updates)

    public static function publicKey(): string
    {
        return getenv('ZARSPANEL_UPDATE_PUBKEY') ?: self::PUBLIC_KEY;
    }

    public static function feed(): string
    {
        return Db::setting('update.feed') ?: self::DEFAULT_FEED;
    }

    public static function verify(string $sha256, string $signatureB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pub = base64_decode(self::publicKey(), true);

        return $sig !== false && $pub !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($sig, $sha256, $pub);
    }

    public static function fetchFeed(): array
    {
        $f = self::feed();
        $json = str_starts_with($f, '/') ? (string) @file_get_contents($f) : Exec::run(['curl', '-fsSL', '--max-time', '20', $f])['out'];
        $d = json_decode($json, true);
        if (!is_array($d) || empty($d['version']) || empty($d['url']) || empty($d['sha256']) || empty($d['signature'])) {
            throw new PanelError('The update server did not answer with a valid release.', 502);
        }

        return $d;
    }

    /** This server's place (0 to 99) in a staged rollout of one version: stable per server and version, so the same servers get a release first every time. */
    public static function bucket(string $version): int
    {
        $id = Db::setting('server_id');
        if (!$id) {
            $id = bin2hex(random_bytes(8));
            Db::put('server_id', $id);
        }

        return (int) (crc32($id . '|' . $version) % 100);
    }

    /** @return array{offered: bool, reason: string} whether this server may install the release now (staged rollout percent of the feed, owner's version pin) */
    public static function offered(array $d): array
    {
        $pin = Db::setting('update_pin');
        if ($pin && version_compare($d['version'], $pin, '>')) {
            return ['offered' => false, 'reason' => 'This server is pinned to version ' . $pin . '.'];
        }
        $pct = isset($d['rollout']) ? max(0, min(100, (int) $d['rollout'])) : 100;
        if (self::bucket($d['version']) >= $pct) {
            return ['offered' => false, 'reason' => "Release {$d['version']} is rolling out slowly ($pct% of servers so far). It will appear here when your turn comes."];
        }

        return ['offered' => true, 'reason' => ''];
    }

    public static function check(): array
    {
        $d = self::fetchFeed();
        $o = self::offered($d);
        $newer = version_compare($d['version'], Config::version(), '>');

        return ['current' => Config::version(), 'latest' => $d['version'], 'update' => $newer && $o['offered'], 'waiting' => $newer && !$o['offered'] ? $o['reason'] : '', 'pin' => Db::setting('update_pin') ?: null, 'notes' => $d['notes'] ?? '',
            'auto' => Db::setting('auto_update', '1') === '1', 'checked_at' => Db::setting('update_checked_at')];
    }

    /** Pin this server to a version (never update beyond it) or clear the pin with an empty string. */
    public static function pin(string $version): ?string
    {
        if ($version !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new PanelError('A version looks like 1.2.3.', 422);
        }
        Db::put('update_pin', $version);

        return $version !== '' ? $version : null;
    }

    public static function releases(): string
    {
        return Config::ensure(Config::home() . '/../releases');
    }

    /** Download, verify, unpack to releases/<version> and point "current" at it. The caller restarts the panel service. */
    public static function apply(): array
    {
        $d = self::fetchFeed();
        if (!version_compare($d['version'], Config::version(), '>')) {
            throw new PanelError('The panel is already up to date.', 422);
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $d['version']) || !preg_match('/^[a-f0-9]{64}$/', $d['sha256'])) {
            throw new PanelError('The release information is not valid.', 502);
        }
        if (!($o = self::offered($d))['offered']) {
            throw new PanelError($o['reason'], 422);
        }
        if (!self::verify($d['sha256'], $d['signature'])) {
            throw new PanelError('The signature of this release is not valid. It was not installed.', 422);
        }
        $tmp = Config::ensure(Config::home() . '/tmp') . '/update-' . $d['version'] . '.zip';
        if (str_starts_with($d['url'], '/')) {
            copy($d['url'], $tmp);
        } else {
            if (!str_starts_with($d['url'], 'https://')) {
                throw new PanelError('The download address must be https.', 502);
            }
            Exec::run(['curl', '-fsSL', '--max-time', '600', '-o', $tmp, $d['url']], null, 660);
        }
        if (!is_file($tmp) || !hash_equals($d['sha256'], hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new PanelError('The downloaded file does not match its checksum. It was not installed.', 422);
        }
        $dir = self::releases() . '/' . $d['version'];
        $z = new \ZipArchive();
        if ($z->open($tmp) !== true) {
            throw new PanelError('The release is not a valid zip file.', 422);
        }
        for ($i = 0; $i < $z->numFiles; $i++) {
            if (str_contains((string) $z->getNameIndex($i), '..')) {
                $z->close();
                throw new PanelError('The release contains unsafe paths.', 422);
            }
        }
        Config::ensure($dir);
        $z->extractTo($dir);
        $z->close();
        @unlink($tmp);
        // keep the data and secrets: the data folder lives outside the release folder
        $link = dirname(self::releases()) . '/current';
        $next = $link . '.new';
        @unlink($next);
        symlink($dir, $next);
        rename($next, $link);
        Exec::run(['systemctl', 'restart', 'php' . (Sites::phpVersions()[0] ?? '8.2') . '-fpm']);
        Audit::log(null, 'panel.update', ['from' => Config::version(), 'to' => $d['version']]);

        return ['installed' => $d['version'], 'dir' => $dir];
    }
}
