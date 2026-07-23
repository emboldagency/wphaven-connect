<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WPHavenConnect\ErrorHandler;

class ServiceProvider
{

    private $providers = [
            // Register the ErrorHandlerProvider first to ensure it initializes early
        ErrorHandlerProvider::class,

        // TextdomainNoticeSuppressionProvider is registered early in main plugin file
        // so we don't include it here to avoid duplicate registration

        // Add other service providers here
        AssetUrlServiceProvider::class,
        ClientAlertsProvider::class,
        CommandLineServiceProvider::class,
        ContentTransferServiceProvider::class,
        CookieServiceProvider::class,
        CustomAdminLoginProvider::class,
        DisableMailServiceProvider::class,
        EnvironmentIndicatorAdminBarBadgeProvider::class,
        HealthServiceProvider::class,
        PhpInfoServiceProvider::class,
        ServerInfoServiceProvider::class,
        SettingsServiceProvider::class,
        SiteHealthServiceProvider::class,
        SupportTicketServiceProvider::class,
        UpdateCommitMessageProvider::class,
        WooCommerceServiceProvider::class,
        WordfenceServiceProvider::class,
    ];

    public function __construct()
    {
        $this->register();
    }

    public function register()
    {
        // Temporary fix: Manually require the CustomAdminLoginProvider class
        require_once __DIR__ . '/CustomAdminLoginProvider.php';

        // Register other service providers
        foreach ($this->providers as $provider) {
            (new $provider())->register();
        }
    }

    // Centralized permissions check method
    public static function apiPermissionsCheck()
    {
        // Preferred: a per-site bearer token provisioned by WPHaven (pushed over
        // its SSH/wp-cli channel). Skipped entirely when no secret is configured,
        // so behavior is unchanged until provisioning exists.
        if (self::hasValidBearerToken()) {
            return true;
        }

        // Debugging option.
        // TODO: pending review before deploy -- this bypasses auth entirely.
        if (isset($_GET['debug'])) {
            return true;
        }

        // Fallback: IP allowlist.
        $whitelisted_ips = [
            '107.10.14.63', // xan
            '127.0.0.1', // localhost
            '8.42.149.40', // office
            '68.183.101.36', // wphaven
            '104.179.124.167', // tyler
            '108.206.74.48', // joren
            '76.181.108.216'  // given
        ];

        // Get the client's IP address
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        // Check if IP is whitelisted
        if (!in_array($client_ip, $whitelisted_ips, true)) {
            return new WP_Error('forbidden', "{$client_ip} is not whitelisted", ['status' => 403]);
        }

        return true;
    }

    /**
     * The per-site shared secret, if one has been provisioned. Set either via the
     * WPHAVEN_CONNECT_SECRET constant or the wphaven_connect_secret option (pushed
     * by WPHaven). Returns null when nothing is configured.
     *
     * @return string|null
     */
    private static function configuredSecret()
    {
        if (defined('WPHAVEN_CONNECT_SECRET') && WPHAVEN_CONNECT_SECRET) {
            return (string) WPHAVEN_CONNECT_SECRET;
        }

        $secret = get_option('wphaven_connect_secret');

        return (is_string($secret) && $secret !== '') ? $secret : null;
    }

    /**
     * Whether the request carries a Bearer token matching the provisioned secret.
     */
    private static function hasValidBearerToken(): bool
    {
        $secret = self::configuredSecret();
        if ($secret === null) {
            return false;
        }

        $provided = self::bearerToken();
        if ($provided === null) {
            return false;
        }

        return hash_equals($secret, $provided);
    }

    /**
     * Extract a Bearer token from the Authorization header, accounting for servers
     * that expose it under REDIRECT_HTTP_AUTHORIZATION or only via getallheaders().
     *
     * @return string|null
     */
    private static function bearerToken()
    {
        $header = '';

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }
}
