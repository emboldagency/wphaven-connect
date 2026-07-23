<?php

namespace WPHavenConnect\ContentTransfer;

/**
 * Central access point for the dedicated content-transfer shared secret.
 *
 * This secret is intentionally separate from the WPHaven per-site bearer token
 * (`wphaven_connect_secret`): it must be identical across every environment of a
 * single client site (development, staging, maintenance, production) so that one
 * environment can authenticate to another. It is synced manually by the agency.
 *
 * Resolution order mirrors the plugin's other "constant beats option" settings:
 * the WPHAVEN_CONTENT_TRANSFER_SECRET constant wins, otherwise the
 * wphaven_content_transfer_secret option is used.
 */
class TransferSecret
{
    const OPTION_NAME = 'wphaven_content_transfer_secret';

    const CONSTANT_NAME = 'WPHAVEN_CONTENT_TRANSFER_SECRET';

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
     * Whether the secret is locked by a constant (and therefore not regenerable
     * from the settings UI).
     */
    public static function isLocked(): bool
    {
        return defined(self::CONSTANT_NAME) && (bool) constant(self::CONSTANT_NAME);
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
