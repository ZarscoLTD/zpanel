<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Stores small secrets (backup keys, database passwords shown once) encrypted with a key kept in the data folder (mode 0600). */
final class Secrets
{
    public static function key(): string
    {
        $f = Config::ensure(Config::home()) . '/secret.key';
        if (!is_file($f)) {
            file_put_contents($f, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            chmod($f, 0600);
        }

        return (string) file_get_contents($f);
    }

    public static function seal(string $plain): string
    {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($n . sodium_crypto_secretbox($plain, $n, self::key()));
    }

    public static function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());

        return $p === false ? null : $p;
    }

    public static function password(int $len = 20): string
    {
        $a = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $o = '';
        do { // always letters and digits, as the password rule (Auth::checkPassword) asks: 8 digits out of 57 signs miss a 16-sign password now and then
            $o = '';
            for ($i = 0; $i < $len; $i++) {
                $o .= $a[random_int(0, strlen($a) - 1)];
            }
        } while (!preg_match('/[A-Za-z]/', $o) || !preg_match('/\d/', $o));

        return $o;
    }
}
