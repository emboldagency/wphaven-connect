<?php

namespace WPHavenConnect\ContentTransfer;

/**
 * The environment connection secret: a shared key that must be identical across
 * every environment of a client site (development, staging, maintenance,
 * production) so one environment can authenticate to another.
 *
 * It is intentionally separate from the WPHaven per-site bearer token
 * (`wphaven_connect_secret`) and is named generically so it can authenticate
 * future cross-environment features beyond content transfer. It is synced
 * manually by the agency: copy/paste the same value into every environment.
 *
 * Resolution order mirrors the plugin's other "constant beats option" settings:
 * the WPHAVEN_CONNECTION_SECRET constant wins, otherwise the
 * wphaven_connection_secret option is used.
 */
class ConnectionSecret
{
    const OPTION_NAME = 'wphaven_connection_secret';

    const CONSTANT_NAME = 'WPHAVEN_CONNECTION_SECRET';

    /**
     * The configured secret, or null when nothing has been set yet.
     */
    public static function get(): ?string
    {
        if (defined(self::CONSTANT_NAME) && constant(self::CONSTANT_NAME)) {
            return (string) constant(self::CONSTANT_NAME);
        }

        $secret = get_option(self::OPTION_NAME);

        return (is_string($secret) && $secret !== '') ? $secret : null;
    }

    /**
     * Whether the secret is locked by a constant (and therefore not editable or
     * regenerable from the settings UI).
     */
    public static function isLocked(): bool
    {
        return defined(self::CONSTANT_NAME) && (bool) constant(self::CONSTANT_NAME);
    }

    /**
     * Persist a supplied secret (e.g. one pasted in from another environment).
     * No-op when locked by a constant.
     */
    public static function set(string $secret): void
    {
        if (self::isLocked()) {
            return;
        }

        update_option(self::OPTION_NAME, $secret);
    }

    /**
     * Generate a new secret, persist it to the option, and return it. Does
     * nothing and returns the constant value when locked by a constant.
     */
    public static function regenerate(): string
    {
        if (self::isLocked()) {
            return (string) constant(self::CONSTANT_NAME);
        }

        $secret = wp_generate_password(64, false);
        update_option(self::OPTION_NAME, $secret);

        return $secret;
    }

    /**
     * Constant-time comparison of a provided value against the configured secret.
     */
    public static function matches(?string $provided): bool
    {
        $secret = self::get();
        if ($secret === null || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }
}
