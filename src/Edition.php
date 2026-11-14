<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * Full ZARS Panel (paid licence) or the free Community edition (owner 2026-11-10: only the basic needs, not updated regularly,
 * open to contributions). The Community build (bin/make-community.sh) writes an EDITION file and leaves out the source of every
 * other feature; this list is what it still answers.
 */
final class Edition
{
    /** Pages of the web app in the Community edition (the rest is hidden). */
    public const COMMUNITY_PAGES = ['home', 'sites', 'databases', 'cron', 'backups', 'overview', 'firewall', 'security'];

    /** API paths of the Community edition (regular expressions on the path). */
    private const COMMUNITY_API = [
        '#^/api/(brand|login|logout|me|me/language|overview|php-versions|alerts|offer)$#',
        '#^/api/services(/[a-z0-9_.@-]+/[a-z]+)?$#',
        '#^/api/sites(/\d+(/(delete|toggle|ssl|files|files/[a-z_-]+|file|upload|logs|stats|trash|trash/[^/]+/[a-z]+))?)?$#',
        '#^/api/databases(/\d+/(delete|password|import))?$#',
        '#^/api/cron(/\d+(/[a-z]+)?)?$#',
        '#^/api/backups(/.*)?$#',
        '#^/api/firewall(/.*)?$#',
        '#^/api/server(/(processes|processes/\d+/kill|ssh-keys|ssh-keys/\d+/delete|ssh-password|reboot))?$#',
        '#^/api/account/(password|two-step/[a-z]+)$#',
    ];

    public static function name(): string
    {
        return trim((string) @file_get_contents(dirname(__DIR__) . '/EDITION')) === 'community' ? 'community' : 'full';
    }

    public static function community(): bool
    {
        return self::name() === 'community';
    }

    /** Is this API path part of this edition? (always true in the full panel) */
    public static function allows(string $path): bool
    {
        if (!self::community() || !str_starts_with($path, '/api/')) {
            return true;
        }
        foreach (self::COMMUNITY_API as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }

        return false;
    }
}
