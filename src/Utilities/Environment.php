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

        $env = self::configured();

        // Nothing configured: guess, and remember that we did.
        if ($env === null) {
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
     * The first valid WP_ENVIRONMENT_TYPE or legacy WP_ENV, as a constant or
     * environment variable. Read directly: wp_get_environment_type() ignores
     * WP_ENV and reports its own 'production' default like a real setting.
     */
    private static function configured(): ?string
    {
        foreach (['WP_ENVIRONMENT_TYPE', 'WP_ENV'] as $name) {
            $value = defined($name) ? constant($name) : getenv($name);
            $env = $value === 'local' ? 'development' : $value;

            if (in_array($env, self::ALLOWED_ENVS, true)) {
                return $env;
            }
        }

        return null;
    }

    /**
     * Best guess from the site's own home URL, defaulting to production.
     * Deliberately narrow: a live site read as non-production exposes the
     * transfer tooling. The request Host header is never used, since a
     * catch-all vhost would let any client pick the environment.
     */
    private static function infer_from_host(): string
    {
        $host = self::get_host();

        if ($host === '') {
            return 'production';
        }

        if ($host === 'localhost' || self::ends_with_any($host, ['.local', '.localhost', '.embold.dev'])) {
            return 'development';
        }

        if (str_starts_with($host, 'staging.') || self::ends_with_any($host, ['.embold.net', '.wphaven.dev'])) {
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
     * The home URL's host, available in CLI and cron as well as web requests.
     */
    private static function get_host(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $host = (string) wp_parse_url((string) get_option('home', ''), PHP_URL_HOST);

        return trim(strtolower($host));
    }

    /**
     * @param string[] $suffixes
     */
    private static function ends_with_any(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
