<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Paths and switches of one panel installation. Everything lives under one data directory so a test can run the whole panel
 * in a temporary folder: ZARSPANEL_HOME (data), ZARSPANEL_WWW (web roots), ZARSPANEL_ETC (nginx / cron files) and
 * ZARSPANEL_DRY=1 (record system commands instead of running them).
 */
final class Config
{
    public static function home(): string
    {
        return rtrim(getenv('ZARSPANEL_HOME') ?: '/opt/zarspanel/data', '/');
    }

    public static function www(): string
    {
        return rtrim(getenv('ZARSPANEL_WWW') ?: '/www/wwwroot', '/');
    }

    public static function etc(): string
    {
        return rtrim(getenv('ZARSPANEL_ETC') ?: '/etc', '/');
    }

    public static function dry(): bool
    {
        return getenv('ZARSPANEL_DRY') === '1';
    }

    public static function version(): string
    {
        return trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '0.1.0';
    }

    public static function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }

        return $dir;
    }
}
