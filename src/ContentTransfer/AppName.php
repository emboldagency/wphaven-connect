<?php

namespace WPHavenConnect\ContentTransfer;

/**
 * The site's WP Haven "app name" (slug) — e.g. `nucamprv`. It identifies the
 * site to the WP Haven API when populating the environment list, and is the key
 * from which the sibling environment domains are derived.
 *
 * On non-production hosts the slug is recoverable from the hostname:
 *   staging     nucamprv.embold.net              -> nucamprv
 *   maintenance nucamprv.wphaven.dev             -> nucamprv
 *   development webapp--nucamprv--xan.embold.dev -> nucamprv
 * Production uses the client's real domain, from which the slug can't be
 * derived, so there it must be set explicitly (constant, manual, or populate).
 */
class AppName
{
    const OPTION_KEY = 'app_name';

    const CONSTANT_NAME = 'WPHAVEN_APP_NAME';

    /**
     * The configured app name (constant beats option), lowercased. Empty string
     * when nothing is set.
     */
    public static function get(): string
    {
        if (defined(self::CONSTANT_NAME) && constant(self::CONSTANT_NAME)) {
            return self::clean((string) constant(self::CONSTANT_NAME));
        }

        $opts = get_option('wphaven_connect_options', []);
        $value = is_array($opts) && ! empty($opts[self::OPTION_KEY]) ? $opts[self::OPTION_KEY] : '';

        return self::clean((string) $value);
    }

    /**
     * The configured app name, or the value detected from the hostname when
     * nothing is stored (used to prefill the field before it is saved).
     */
    public static function getOrDetect(): string
    {
        $value = self::get();

        return $value !== '' ? $value : self::detect();
    }

    public static function isLocked(): bool
    {
        return defined(self::CONSTANT_NAME) && (bool) constant(self::CONSTANT_NAME);
    }

    /**
     * Derive the app name from this site's hostname, or '' if it doesn't match a
     * known non-production pattern (e.g. on production).
     */
    public static function detect(): string
    {
        $host = wp_parse_url(site_url(), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower($host);

        if (str_ends_with($host, '.embold.dev')) {
            $label = substr($host, 0, -strlen('.embold.dev'));
            $parts = explode('--', $label);
            return isset($parts[1]) ? self::clean($parts[1]) : '';
        }

        foreach (['.embold.net', '.wphaven.dev'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                $label = substr($host, 0, -strlen($suffix));
                $segments = explode('.', $label);
                return self::clean((string) end($segments));
            }
        }

        return '';
    }

    /**
     * Persist a detected app name when none is stored yet. No-op when locked by
     * a constant, when a value already exists, or when nothing can be detected.
     */
    public static function seedIfEmpty(): void
    {
        if (self::isLocked()) {
            return;
        }

        $opts = get_option('wphaven_connect_options', []);
        if (! is_array($opts)) {
            $opts = [];
        }
        if (! empty($opts[self::OPTION_KEY])) {
            return;
        }

        $detected = self::detect();
        if ($detected !== '') {
            $opts[self::OPTION_KEY] = $detected;
            update_option('wphaven_connect_options', $opts);
        }
    }

    private static function clean(string $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9\-_]/', '', trim($value)) ?? '');
    }
}
