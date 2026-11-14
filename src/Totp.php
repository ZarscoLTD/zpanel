<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Two-step login codes (RFC 6238, 30 s, 6 digits) compatible with the usual authenticator apps. */
final class Totp
{
    private const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $s = '';
        foreach (str_split(random_bytes(20)) as $c) {
            $s .= self::ALPHA[ord($c) & 31];
        }

        return $s;
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32))) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHA, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), self::decode($secret), true);
        $o = ord($hash[19]) & 0xf;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $time ??= time();
        $code = preg_replace('/\s+/', '', $code);
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals(self::code($secret, $time + $drift), (string) $code)) {
                return true;
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }
}
