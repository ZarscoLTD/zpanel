<?php

declare(strict_types=1);

namespace ZarsPanel;

/**
 * The offer running now on ZARS products (owner 2026-11-11: every offer of the normal apps also applies to ZARS Panel), read from the
 * same ZARS Insights server that serves the updates (Control center > Offers, product "ZARS Panel" or all products). Remembered for
 * 6 hours (also "no offer" and "not reachable"), so the Overview page never waits on the internet. The Community edition shows it
 * too: for its users the offer is the moment to move to the full licence.
 */
final class Offer
{
    public const TTL = 21600;

    /** null when no offer runs (or the offer server is not reachable). */
    public static function current(): ?array
    {
        $cached = json_decode((string) Db::setting('offer.cache'), true);
        if (is_array($cached) && (int) ($cached['at'] ?? 0) > time() - self::TTL) {
            return $cached['offer'] ?? null;
        }
        $offer = self::fetch();
        Db::put('offer.cache', (string) json_encode(['at' => time(), 'offer' => $offer]));

        return $offer;
    }

    /** The offer API next to the update feed: https://<insights>/api/public/offer?product=zarspanel. */
    public static function url(string $path = '/api/public/offer?product=zarspanel'): ?string
    {
        $p = parse_url(Updates::feed());

        return isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['https', 'http'], true)
            ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $path
            : null;
    }

    private static function fetch(): ?array
    {
        $url = self::url();
        if (!$url) {
            return null;
        }
        $r = Exec::run(['curl', '-fsSL', '--max-time', '5', $url]);
        $o = (json_decode((string) ($r['out'] ?? ''), true) ?: [])['offer'] ?? null;

        return is_array($o) && !empty($o['title']) ? self::clean($o) : null;
    }

    /** Only the fields the banner shows, as plain text; links only when https. */
    public static function clean(array $o): array
    {
        $s = fn ($k, $n = 200) => mb_substr(trim(strip_tags((string) ($o[$k] ?? ''))), 0, $n);
        $https = fn ($k) => str_starts_with((string) ($o[$k] ?? ''), 'https://') ? (string) $o[$k] : '';
        $free = ($o['kind'] ?? '') === 'free' && (int) ($o['free_months'] ?? 0) > 0;

        return ['title' => $s('title'), 'text' => $s('text', 400), 'code' => $s('code', 40), 'emoji' => $s('emoji', 8), 'ends_at' => $s('ends_at', 25),
            'free_months' => $free ? (int) $o['free_months'] : null, 'discount_pct' => is_numeric($o['discount_pct'] ?? null) ? (int) $o['discount_pct'] : null,
            'colors' => array_values(array_filter(array_map(fn ($c) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $c) ? $c : null, (array) ($o['colors'] ?? [])))),
            'cta_label' => $s('cta_label', 40), 'cta_url' => $https('cta_url'),
            'claim_url' => self::url('/get-free/zarspanel')]; // the licence request form on the Panel website, with the offer written on it
    }
}
