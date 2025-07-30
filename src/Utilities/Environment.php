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
     * Returns the current environment: 'development', 'staging', or 'production'.
     *
     * The result is cached after the first call for performance.
     */
    public static function get_environment(): string
    {
        // 1. Return from cache if already determined
        if (null !== self::$environment) {
            return self::$environment;
        }

        $env = 'production'; // Default to production

        // 2. Use WordPress standard methods first
        if (function_exists('wp_get_environment_type')) {
            $env = wp_get_environment_type();
        } elseif (defined('WP_ENVIRONMENT_TYPE')) {
            $env = WP_ENVIRONMENT_TYPE;
        } elseif (defined('WP_ENV')) {
            $env = WP_ENV;
        }

        // 3. Normalize common aliases
        switch ($env) {
            case 'local':
                $env = 'development';
                break;
            case 'maintenance':
                $env = 'staging';
                break;
            default:
                break;
        }

        // 4. Fallback to host-based detection ONLY if the environment is not a valid final value.
        // This allows overriding a 'production' default from constants with more specific host rules.
        if (!in_array($env, self::ALLOWED_ENVS, true)) {
            $host = self::get_host();
            if (!empty($host)) {
                // Development host patterns
                if (self::host_matches($host, ['.local', 'localhost', '.embold.dev'])) {
                    $env = 'development';
                    // Staging host patterns
                } elseif (self::host_matches($host, ['.net', 'staging.', '.wphaven.dev'])) {
                    $env = 'staging';
                }
            }
        }

        // 5. Final validation to ensure a valid type is returned
        if (!in_array($env, self::ALLOWED_ENVS, true)) {
            $env = 'production';
        }

        // Cache and return the final environment
        self::$environment = $env;
        return self::$environment;
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
