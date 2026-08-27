<?php

namespace WPHavenConnect\Utilities;

class Environment
{
    /**
     * The list of final, valid environments this class will return.
     *
     * @var array
     */
    private const ALLOWED_ENVS = ['development', 'staging', 'production'];

    /**
     * @var string|null Caches the determined environment to avoid re-computation.
     */
    private static ?string $environment = null;

    /**
     * @var bool Whether the cached environment was guessed rather than configured.
     */
    private static bool $inferred = false;

    /**
     * Returns the current environment: 'development', 'staging', or 'production'.
     *
     * The result is cached after the first call for performance.
     */
    public static function get_environment(): string
    {
        // Return from cache if already determined
        if (null !== self::$environment) {
            return self::$environment;
        }

        $env = null;

        // Checked explicitly: wp_get_environment_type() reports its own
        // 'production' default the same way it reports a real setting.
        if (self::is_configured()) {
            if (function_exists('wp_get_environment_type')) {
                $env = wp_get_environment_type();
            } elseif (defined('WP_ENVIRONMENT_TYPE')) {
                $env = WP_ENVIRONMENT_TYPE;
            } elseif (defined('WP_ENV')) {
                $env = WP_ENV;
            }

            $env = self::normalize((string) $env);
        }

        // Nothing configured: guess, and remember that we did.
        if (!in_array($env, self::ALLOWED_ENVS, true)) {
            $env = self::infer_from_host();
            self::$inferred = true;
        }

        // Cache and return the final environment
        self::$environment = $env;
        return self::$environment;
    }

    /**
     * Whether the environment was guessed rather than configured.
     */
    public static function is_inferred(): bool
    {
        self::get_environment();

        return self::$inferred;
    }

    /**
     * Whether WP_ENVIRONMENT_TYPE (or the legacy WP_ENV) is actually set.
     */
    private static function is_configured(): bool
    {
        return defined('WP_ENVIRONMENT_TYPE')
            || defined('WP_ENV')
            || getenv('WP_ENVIRONMENT_TYPE') !== false;
    }

    /**
     * Map WordPress' environment names onto the three this class returns.
     */
    private static function normalize(string $env): string
    {
        switch ($env) {
            case 'local':
                return 'development';
            case 'maintenance':
                return 'staging';
            default:
                return $env;
        }
    }

    /**
     * Best guess from the hostname, defaulting to production. Deliberately
     * narrow: a live site read as non-production exposes the transfer tooling.
     */
    private static function infer_from_host(): string
    {
        $host = self::get_host();

        if ($host === '') {
            return 'production';
        }

        if (self::host_matches($host, ['.local', 'localhost', '.embold.dev'])) {
            return 'development';
        }

        if (self::host_matches($host, ['staging.', '.wphaven.dev'])) {
            return 'staging';
        }

        return 'production';
    }

    /**
     * Returns true if the environment is development.
     */
    public static function is_development(): bool
    {
        return self::get_environment() === 'development';
    }

    /**
     * Returns true if the environment is staging.
     */
    public static function is_staging(): bool
    {
        return self::get_environment() === 'staging';
    }

    /**
     * Returns true if the environment is production.
     */
    public static function is_production(): bool
    {
        return self::get_environment() === 'production';
    }

    /**
     * Gets the request host name in a CLI-safe way.
     */
    private static function get_host(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        return trim(strtolower($host));
    }

    /**
     * Checks if a host string contains any of the provided patterns.
     * Uses str_ends_with for suffixes starting with '.' for better accuracy.
     */
    private static function host_matches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // Use str_ends_with for suffixes like '.local' to avoid matching 'local.com'
            if ($pattern[0] === '.' && str_ends_with($host, $pattern)) {
                return true;
            }
            // Use str_contains for prefixes or general substrings
            if ($pattern[0] !== '.' && str_contains($host, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
