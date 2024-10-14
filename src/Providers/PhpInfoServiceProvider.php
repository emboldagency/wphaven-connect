<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class PhpInfoServiceProvider {

    public function register() {
        add_action('rest_api_init', [$this, 'registerPhpInfoEndpoint']);
    }

    public function registerPhpInfoEndpoint() {
        register_rest_route('wphaven-connect/v1', '/php-info', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'getPhpInfo'],
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],  // Use centralized permissions
        ]);
    }

    public function getPhpInfo() {
        $phpVersion = phpversion();

        // first remove the decimal point
        $phpVersionBasic = str_replace('.', '', $phpVersion);
        // then get the first 2 characters
        $phpVersionBasic = substr($phpVersionBasic, 0, 2);

        // also return a basic version like 81, 80, 74, etc
        return new WP_REST_Response([
            'php_version' => $phpVersion,
            'php_version_basic' => $phpVersionBasic,
        ], 200);
    }
}
