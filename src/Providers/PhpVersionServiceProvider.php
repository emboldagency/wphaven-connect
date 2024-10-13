<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class PhpVersionServiceProvider {

    public function register() {
        add_action('rest_api_init', [$this, 'registerPhpVersionEndpoint']);
    }

    public function registerPhpVersionEndpoint() {
        register_rest_route('wphaven-connect/v1', '/php-version', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'getPhpVersion'],
            'permission_callback' => '__return_true', // Adjust as necessary
        ]);
    }

    public function getPhpVersion() {
        $phpVersion = phpversion();
        // also return a basic version like 81, 80, 74, etc
        return new WP_REST_Response([
            'php_version' => $phpVersion,
            'php_version_basic' => substr($phpVersion, 0, 2),
        ], 200);
    }
}
