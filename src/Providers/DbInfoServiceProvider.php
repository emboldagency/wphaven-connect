<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class DbInfoServiceProvider {

    public function register() {
        add_action('rest_api_init', [$this, 'registerDbInfoEndpoint']);
    }

    public function registerDbInfoEndpoint() {
        register_rest_route('wphaven-connect/v1', '/db-info', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'getDbInfo'],
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],  // Use centralized permissions
        ]);
    }

    public function getDbInfo() {
        global $wpdb;

        // Get database version and type
        $dbVersion = $wpdb->db_version();
        $dbType = $wpdb->use_mysqli ? 'MySQL' : 'MariaDB';

        // Check for MariaDB explicitly since it's often masked as MySQL
        $mysqliInfo = mysqli_get_server_info($wpdb->dbh);
        if (stripos($mysqliInfo, 'mariadb') !== false) {
            $dbType = 'MariaDB';
        }

        return new WP_REST_Response([
            'db_type' => $dbType,
            'db_version' => $dbVersion,
        ], 200);
    }
}
