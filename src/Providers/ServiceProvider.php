<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WPHavenConnect\ErrorHandler;

class ServiceProvider {

    private $providers = [
        AssetUrlServiceProvider::class,
        CookieServiceProvider::class,
        WordfenceServiceProvider::class,
        CommandLineServiceProvider::class,
        ServerInfoServiceProvider::class,
        PhpInfoServiceProvider::class,
        ClientAlertsProvider::class,
        WooCommerceServiceProvider::class,
        AdminBarServiceProvider::class,
    ];

    public function __construct() {
        $this->register();
    }

    public function register() {
        // Initialize the ErrorHandler first to catch any errors
        new ErrorHandler();

        // Register other service providers
        foreach ($this->providers as $provider) {
            (new $provider())->register();
        }
    }

    // Centralized permissions check method
    public static function apiPermissionsCheck() {
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
