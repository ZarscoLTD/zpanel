<?php

declare(strict_types=1);

namespace ZarsPanel;

/** White label: the owner's brand (name, colour, logo link), overridden by a reseller's own for their clients. */
final class Brand
{
    public static function default(): array
    {
        return ['name' => 'ZARS Panel', 'color' => '#2563eb', 'logo' => null, 'support' => null];
    }

    public static function owner(): array
    {
        return (json_decode((string) Db::setting('brand', '{}'), true) ?: []) + self::default();
    }

    /** The brand a user sees: their reseller's (or their own, for a reseller), else the owner's. */
    public static function for(array $user): array
    {
        $own = json_decode((string) $user['brand'], true) ?: [];
        if (!$own && $user['parent_id']) {
            $parent = Auth::user((int) $user['parent_id']);
            $own = $parent ? (json_decode((string) $parent['brand'], true) ?: []) : [];
        }

        return $own + self::owner();
    }

    public static function clean(array $in): array
    {
        $out = [];
        if (isset($in['name']) && trim((string) $in['name']) !== '') {
            $out['name'] = mb_substr(trim((string) $in['name']), 0, 40);
        }
        if (isset($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in['color'])) {
            $out['color'] = $in['color'];
        }
        if (!empty($in['logo']) && preg_match('#^https://[^\s"\'<>]+$#', (string) $in['logo'])) {
            $out['logo'] = (string) $in['logo'];
        }
        if (!empty($in['support']) && filter_var($in['support'], FILTER_VALIDATE_EMAIL)) {
            $out['support'] = (string) $in['support'];
        }

        return $out;
    }
}
