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
        CookieServiceProvider::class,
        CustomAdminLoginProvider::class,
        DisableMailServiceProvider::class,
        EnvironmentIndicatorAdminBarBadgeProvider::class,
        PhpInfoServiceProvider::class,
        ServerInfoServiceProvider::class,
        SettingsServiceProvider::class,
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
        // Whitelisted IP addresses and domains
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
        $client_ip = $_SERVER['REMOTE_ADDR'];

        // Debugging option
        if (isset($_GET['debug'])) {
            return true;
        }

        // Check if IP is whitelisted
        if (!in_array($client_ip, $whitelisted_ips)) {
            return new WP_Error('forbidden', "{$client_ip} is not whitelisted", ['status' => 403]);
        }

        return true;
    }
}
